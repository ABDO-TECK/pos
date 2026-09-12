const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  PRIVILEGED_CLI_ALLOWLIST,
  isPrivilegedCliCommandAllowed,
  createPrivilegedEnv,
  runPrivilegedBackendCli,
} = require('../services/privileged-cli');
const { createBackendEnv } = require('../services/php-server');
const {
  executeRestoreStateMachine,
  RESTORE_STAGES,
} = require('../services/restore-manager');

const mockCredentials = {
  user: 'pos_app',
  password: 'app_secret_password_123',
  migrationUser: 'pos_migration',
  migrationPassword: 'migration_secret_password_456',
};

test('Phase 1.B - normal backend environment does NOT receive migration credentials', () => {
  const env = createBackendEnv({
    mysqlPort: 3307,
    dbCredentials: mockCredentials,
    apiPort: 8080,
  });

  assert.equal(env.DB_USER, 'pos_app');
  assert.equal(env.DB_PASS, 'app_secret_password_123');
  assert.equal(env.DB_MIGRATION_USER, undefined, 'normal backend env must not have DB_MIGRATION_USER');
  assert.equal(env.DB_MIGRATION_PASS, undefined, 'normal backend env must not have DB_MIGRATION_PASS');
});

test('Phase 1.B - privileged CLI environment injects migration credentials', () => {
  const privilegedEnv = createPrivilegedEnv({
    mysqlPort: 3307,
    dbCredentials: mockCredentials,
    apiPort: 8080,
  });

  assert.equal(privilegedEnv.DB_USER, 'pos_app');
  assert.equal(privilegedEnv.DB_PASS, 'app_secret_password_123');
  assert.equal(privilegedEnv.DB_MIGRATION_USER, 'pos_migration');
  assert.equal(privilegedEnv.DB_MIGRATION_PASS, 'migration_secret_password_456');
});

test('Phase 1.B - privileged CLI strictly enforces allowlist', async () => {
  assert.equal(isPrivilegedCliCommandAllowed('restore-backup'), true);
  assert.equal(isPrivilegedCliCommandAllowed('restore-migration-safety'), true);
  assert.equal(isPrivilegedCliCommandAllowed('create-restore-safety'), true);

  // Forbidden / unapproved commands
  assert.equal(isPrivilegedCliCommandAllowed('seed'), false);
  assert.equal(isPrivilegedCliCommandAllowed('reset-password'), false);
  assert.equal(isPrivilegedCliCommandAllowed('initialize-admin'), false);
  assert.equal(isPrivilegedCliCommandAllowed('arbitrary-cmd'), false);
  assert.equal(isPrivilegedCliCommandAllowed(''), false);
  assert.equal(isPrivilegedCliCommandAllowed(null), false);

  // runPrivilegedBackendCli rejects unapproved command
  await assert.rejects(
    () => runPrivilegedBackendCli(['reset-password'], {
      dbCredentials: mockCredentials,
      mysqlPort: 3307,
      phpPort: 8080,
    }),
    /not permitted to run with migration credentials/
  );
});

test('Phase 1.B - privileged CLI never leaks migration password in error text', async () => {
  try {
    await runPrivilegedBackendCli(['restore-backup', 'nonexistent.sql'], {
      dbCredentials: mockCredentials,
      mysqlPort: 3307,
      phpPort: 8080,
      spawnProcess: () => {
        const error = new Error(`Connection failed with pass=${mockCredentials.migrationPassword}`);
        throw error;
      },
    });
    assert.fail('Expected rejection');
  } catch (error) {
    assert.equal(error.message.includes(mockCredentials.migrationPassword), false, 'Password must be sanitized');
  }
});

test('Phase 1.C - failed restore preserves session cookies and does not clear them', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-restore-test-'));
  const testSql = path.join(root, 'backup.sql');
  fs.writeFileSync(testSql, '-- POS Database Backup\nDROP TABLE IF EXISTS test;\n');

  try {
    let sessionCookies = {
      auth_token: 'valid_session_token_abc',
      user_id: '1',
    };

    const stepsExecuted = [];

    const mockHooks = {
      stopWorker: async () => { stepsExecuted.push('stopWorker'); },
      stopPhp: () => { stepsExecuted.push('stopPhp'); },
      createSnapshot: async () => {
        stepsExecuted.push('createSnapshot');
        return { ok: true, backupPath: '/mock/safety.sql', recoveryId: 'rec-123' };
      },
      runRestore: async () => {
        stepsExecuted.push('runRestore');
        throw new Error('1142 ER_TABLEACCESS_DENIED_ERROR');
      },
      runRollback: async () => {
        stepsExecuted.push('runRollback');
        return { ok: true };
      },
      runMigrations: async () => { stepsExecuted.push('runMigrations'); },
      verifyDb: async () => { stepsExecuted.push('verifyDb'); return { ok: true }; },
      restartPhpAndWorker: async () => { stepsExecuted.push('restartPhpAndWorker'); },
      clearSession: async () => {
        stepsExecuted.push('clearSession');
        sessionCookies = {};
      },
      reloadWindow: async () => { stepsExecuted.push('reloadWindow'); },
      enterRecoveryMode: async () => { stepsExecuted.push('enterRecoveryMode'); },
    };

    await assert.rejects(
      () => executeRestoreStateMachine(testSql, mockHooks),
      /ER_TABLEACCESS_DENIED_ERROR/
    );

    // Rollback ran and succeeded
    assert.ok(stepsExecuted.includes('createSnapshot'));
    assert.ok(stepsExecuted.includes('runRestore'));
    assert.ok(stepsExecuted.includes('runRollback'));
    assert.ok(stepsExecuted.includes('restartPhpAndWorker'));

    // Session cookies must NOT have been cleared!
    assert.equal(stepsExecuted.includes('clearSession'), false);
    assert.equal(sessionCookies.auth_token, 'valid_session_token_abc');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('Phase 1.D - successful restore intentionally clears session cookies after verification', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-restore-test-'));
  const testSql = path.join(root, 'backup.sql');
  fs.writeFileSync(testSql, '-- POS Database Backup\nCREATE TABLE test (id INT);\n');

  try {
    let sessionCookies = {
      auth_token: 'old_session_token_xyz',
    };
    let temporarySnapshotDeleted = false;

    const stepsExecuted = [];

    const mockHooks = {
      stopWorker: async () => { stepsExecuted.push('stopWorker'); },
      stopPhp: () => { stepsExecuted.push('stopPhp'); },
      createSnapshot: async () => {
        stepsExecuted.push('createSnapshot');
        return { ok: true, backupPath: '/mock/safety.sql', recoveryId: 'rec-123' };
      },
      runRestore: async () => { stepsExecuted.push('runRestore'); return { ok: true }; },
      runRollback: async () => { stepsExecuted.push('runRollback'); },
      runMigrations: async () => { stepsExecuted.push('runMigrations'); return { ok: true }; },
      verifyDb: async () => { stepsExecuted.push('verifyDb'); return { ok: true }; },
      restartPhpAndWorker: async () => { stepsExecuted.push('restartPhpAndWorker'); },
      clearSession: async () => {
        stepsExecuted.push('clearSession');
        sessionCookies = {};
      },
      reloadWindow: async () => { stepsExecuted.push('reloadWindow'); },
      deleteSnapshot: async () => {
        stepsExecuted.push('deleteSnapshot');
        temporarySnapshotDeleted = true;
      },
      enterRecoveryMode: async () => { stepsExecuted.push('enterRecoveryMode'); },
    };

    const result = await executeRestoreStateMachine(testSql, mockHooks);
    assert.equal(result.success, true);

    // Full sequence verified
    assert.deepEqual(stepsExecuted, [
      'stopWorker',
      'stopPhp',
      'createSnapshot',
      'runRestore',
      'runMigrations',
      'verifyDb',
      'restartPhpAndWorker',
      'clearSession',
      'reloadWindow',
      'deleteSnapshot',
    ]);

    // Session cookies intentionally cleared
    assert.deepEqual(sessionCookies, {});
    assert.equal(temporarySnapshotDeleted, true);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('Phase 1.G - rollback failure triggers Recovery Mode and retains safety artifacts', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-restore-test-'));
  const testSql = path.join(root, 'backup.sql');
  fs.writeFileSync(testSql, '-- POS Database Backup\nDROP TABLE test;\n');

  try {
    const stepsExecuted = [];
    let enteredRecoveryError = null;
    let safetySnapshotRetained = true;

    const mockHooks = {
      stopWorker: async () => { stepsExecuted.push('stopWorker'); },
      stopPhp: () => { stepsExecuted.push('stopPhp'); },
      createSnapshot: async () => {
        stepsExecuted.push('createSnapshot');
        return { ok: true, backupPath: '/mock/safety.sql', recoveryId: 'rec-fail-123' };
      },
      runRestore: async () => {
        stepsExecuted.push('runRestore');
        throw new Error('Initial restore failed');
      },
      runRollback: async () => {
        stepsExecuted.push('runRollback');
        throw new Error('Safety rollback failed too');
      },
      runMigrations: async () => { stepsExecuted.push('runMigrations'); },
      verifyDb: async () => { stepsExecuted.push('verifyDb'); },
      restartPhpAndWorker: async () => { stepsExecuted.push('restartPhpAndWorker'); },
      clearSession: async () => { stepsExecuted.push('clearSession'); },
      reloadWindow: async () => { stepsExecuted.push('reloadWindow'); },
      deleteSnapshot: async () => {
        safetySnapshotRetained = false;
      },
      enterRecoveryMode: async (err) => {
        stepsExecuted.push('enterRecoveryMode');
        enteredRecoveryError = err;
      },
    };

    await assert.rejects(
      () => executeRestoreStateMachine(testSql, mockHooks),
      (err) => err.code === 'DATABASE_RESTORE_RECOVERY_FAILED'
    );

    assert.ok(stepsExecuted.includes('enterRecoveryMode'));
    assert.equal(enteredRecoveryError?.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
    assert.equal(safetySnapshotRetained, true, 'Safety snapshot must NOT be deleted');
    assert.equal(stepsExecuted.includes('restartPhpAndWorker'), false, 'Normal application flow must NOT restart');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('regression: restoreDesktopBackup hooks in electron/main.js have all dependencies in scope', () => {
  const mainPath = path.resolve(__dirname, '../main.js');
  const mainCode = fs.readFileSync(mainPath, 'utf8');

  // Verify runDatabaseMigrations is explicitly imported from php-server
  assert.match(
    mainCode,
    /const\s*\{[^}]*\brunDatabaseMigrations\b[^}]*\}\s*=\s*require\(['"]\.\/services\/php-server['"]\)/,
    'runDatabaseMigrations must be explicitly imported from ./services/php-server in electron/main.js to avoid ReferenceError during restore'
  );
});

test('regression: mysqld starts with --skip-name-resolve and clients bind to 127.0.0.1', () => {
  const mysqlServerPath = path.resolve(__dirname, '../services/mysql-server.js');
  const mysqlCode = fs.readFileSync(mysqlServerPath, 'utf8');

  assert.match(
    mysqlCode,
    /--skip-name-resolve/,
    'mysqld must be started with --skip-name-resolve to prevent Windows reverse DNS lookup delays on local connections'
  );

  assert.match(
    mysqlCode,
    /'-h',\s*'127\.0\.0\.1'/,
    'runMysqlExecutable calls must specify -h 127.0.0.1 to avoid Windows IPv6 localhost connection timeout'
  );
});
