const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  loadStoredDatabaseCredentials,
  saveDatabaseCredentials,
} = require('../services/mysql-server');
const { getDatabaseCredentialsPath, getConfigDir } = require('../utils/paths');

test('getDatabaseCredentialsPath points to db_credentials.json inside config directory', () => {
  const credentialsPath = getDatabaseCredentialsPath();
  const configDir = getConfigDir();
  assert.equal(credentialsPath, path.join(configDir, 'db_credentials.json'));
});

test('saveDatabaseCredentials saves credentials atomically and loadStoredDatabaseCredentials reads them', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-db-cred-test-'));
  const credentialsPath = path.join(root, 'config', 'db_credentials.json');

  try {
    assert.equal(loadStoredDatabaseCredentials(credentialsPath), null);

    const testCreds = {
      user: 'pos_app',
      password: 'test_app_password_123',
      migrationUser: 'pos_migration',
      migrationPassword: 'test_migration_password_456',
    };

    saveDatabaseCredentials(credentialsPath, testCreds);
    assert.ok(fs.existsSync(credentialsPath));

    const loaded = loadStoredDatabaseCredentials(credentialsPath);
    assert.deepEqual(loaded, testCreds);

    // Corrupted file returns null safely
    fs.writeFileSync(credentialsPath, 'INVALID JSON', 'utf8');
    assert.equal(loadStoredDatabaseCredentials(credentialsPath), null);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
