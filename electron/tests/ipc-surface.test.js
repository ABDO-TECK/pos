const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const repositoryRoot = path.resolve(__dirname, '..', '..');

function readRepositoryFile(relativePath) {
  return fs.readFileSync(path.join(repositoryRoot, relativePath), 'utf8');
}

function loadPreloadBridge(relativePath = 'electron/preload.js') {
  const exposed = {};
  const invocations = [];
  const listeners = new Map();
  const ipcRenderer = {
    invoke(channel, ...args) {
      invocations.push({ channel, args });
      return Promise.resolve(channel);
    },
    on(channel, listener) {
      listeners.set(channel, listener);
    },
    removeListener(channel, listener) {
      if (listeners.get(channel) === listener) {
        listeners.delete(channel);
      }
    },
  };

  vm.runInNewContext(readRepositoryFile(relativePath), {
    require(moduleName) {
      assert.equal(moduleName, 'electron');
      return {
        contextBridge: {
          exposeInMainWorld(name, value) {
            exposed[name] = value;
          },
        },
        ipcRenderer,
      };
    },
  });

  return { exposed, invocations, listeners };
}

test('preload exposes only IPC methods used by the frontend', async () => {
  const { exposed, invocations, listeners } = loadPreloadBridge();

  assert.deepEqual(
    Object.keys(exposed.electronAPI).sort(),
    ['auth', 'backup', 'getQZCert', 'getVersion', 'setup', 'signQZMessage', 'updater']
  );
  assert.deepEqual(Object.keys(exposed.electronAPI.auth).sort(), ['recoverPassword']);
  assert.deepEqual(Object.keys(exposed.electronAPI.backup).sort(), ['restore']);
  assert.deepEqual(
    Object.keys(exposed.electronAPI.setup).sort(),
    ['acknowledgeInitialAdmin', 'factoryReset', 'getInitialAdmin']
  );
  assert.deepEqual(
    Object.keys(exposed.electronAPI.updater).sort(),
    ['download', 'getStatus', 'install', 'onStatus']
  );
  assert.deepEqual(Object.keys(exposed.posRuntime).sort(), ['enableLanAccess', 'getApiBaseUrl']);

  await exposed.electronAPI.getVersion();
  await exposed.electronAPI.getQZCert();
  await exposed.electronAPI.signQZMessage('payload');
  await exposed.electronAPI.backup.restore();
  await exposed.electronAPI.auth.recoverPassword({ email: 'user@example.com', password: 'Password1' });
  await exposed.electronAPI.setup.getInitialAdmin();
  await exposed.electronAPI.setup.acknowledgeInitialAdmin();
  await exposed.electronAPI.setup.factoryReset({ confirmationToken: 'RESET_POS_DATA' });
  await exposed.electronAPI.updater.getStatus();
  await exposed.electronAPI.updater.download();
  await exposed.electronAPI.updater.install();
  await exposed.posRuntime.getApiBaseUrl();
  await exposed.posRuntime.enableLanAccess();

  assert.deepEqual(
    invocations.map(({ channel }) => channel),
    [
      'get-version',
      'qz-get-cert',
      'qz-sign',
      'backup:restore',
      'auth:recover-password',
      'setup:get-initial-admin',
      'setup:acknowledge-initial-admin',
      'system:factory-reset',
      'updater:get-status',
      'updater:download',
      'updater:install',
      'get-api-base-url',
      'network:enable-lan',
    ]
  );

  let publishedStatus = null;
  const unsubscribe = exposed.electronAPI.updater.onStatus((status) => {
    publishedStatus = status;
  });
  listeners.get('updater:status')({}, { state: 'idle' });
  assert.deepEqual(publishedStatus, { state: 'idle' });
  unsubscribe();
  assert.equal(listeners.has('updater:status'), false);
});

test('recovery preload exposes only sender-validated methods used by recovery UI', async () => {
  const { exposed, invocations } = loadPreloadBridge('electron/recovery-preload.js');
  const recoveryContract = {
    getLastError: ['recovery:get-last-error'],
    getDiagnostics: ['recovery:get-diagnostics'],
    retryStartup: ['recovery:retry-startup'],
    getRollbackReadiness: ['recovery:get-rollback-readiness'],
    runRollbackDryRun: ['recovery:run-rollback-dry-run'],
    executeRollback: ['recovery:execute-mysql-rollback', { confirmationToken: 'test' }],
    prepareRollbackRestoreStaging: [
      'recovery:prepare-rollback-restore-staging',
      { confirmationToken: 'test' },
    ],
    runFinalRollbackSwitch: ['recovery:run-final-rollback-switch', { confirmationToken: 'test' }],
  };

  assert.deepEqual(Object.keys(exposed), ['posRecovery']);
  assert.deepEqual(Object.keys(exposed.posRecovery), Object.keys(recoveryContract));

  for (const [methodName, [, options]] of Object.entries(recoveryContract)) {
    await exposed.posRecovery[methodName](options);
  }
  assert.deepEqual(
    invocations.map(({ channel }) => channel),
    Object.values(recoveryContract).map(([channel]) => channel)
  );

  const mainSource = readRepositoryFile('electron/main.js');
  const recoveryUiSource = readRepositoryFile('electron/assets/recovery.html');
  for (const [methodName, [channel]] of Object.entries(recoveryContract)) {
    const escapedChannel = channel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const registrationPattern = new RegExp(
      `ipcMain\\.handle\\('${escapedChannel}',\\s*(?:async\\s*)?\\(event[^)]*\\)\\s*=>\\s*\\{\\s*(?:try\\s*\\{\\s*)?assertRecoveryRenderer\\(event\\);`
    );

    assert.match(mainSource, registrationPattern, `${channel} must validate its sender`);
    assert.match(recoveryUiSource, new RegExp(`\\brecoveryApi\\.${methodName}\\b`));
  }
});

test('every exposed invoke channel has a trusted handler and active consumer', () => {
  const mainSource = readRepositoryFile('electron/main.js');
  const updaterSource = readRepositoryFile('electron/services/auto-updater.js');
  const consumers = {
    'get-version': readRepositoryFile('frontend/src/store/updateStore.ts'),
    'qz-get-cert': readRepositoryFile('frontend/src/utils/qzPrint.ts'),
    'qz-sign': readRepositoryFile('frontend/src/utils/qzPrint.ts'),
    'updater:get-status': readRepositoryFile('frontend/src/pages/settings/UpdateSection.tsx'),
    'updater:download': readRepositoryFile('frontend/src/pages/settings/UpdateSection.tsx'),
    'updater:install': readRepositoryFile('frontend/src/pages/settings/UpdateSection.tsx'),
    'get-api-base-url': readRepositoryFile('frontend/src/main.tsx'),
    'network:enable-lan': readRepositoryFile('frontend/src/pages/settings/NetworkAccessSection.tsx'),
    'backup:restore': readRepositoryFile('frontend/src/api/endpoints.ts'),
    'auth:recover-password': readRepositoryFile('frontend/src/api/endpoints.ts'),
    'setup:get-initial-admin': readRepositoryFile('frontend/src/pages/Login.tsx'),
    'setup:acknowledge-initial-admin': readRepositoryFile('frontend/src/pages/Login.tsx'),
    'system:factory-reset': readRepositoryFile('frontend/src/pages/settings/FactoryResetSection.tsx'),
  };
  const consumerMethodNames = {
    'get-version': 'getVersion',
    'qz-get-cert': 'getQZCert',
    'qz-sign': 'signQZMessage',
    'updater:get-status': 'getStatus',
    'updater:download': 'download',
    'updater:install': 'install',
    'get-api-base-url': 'getApiBaseUrl',
    'network:enable-lan': 'enableLanAccess',
    'backup:restore': 'restore',
    'auth:recover-password': 'recoverPassword',
    'setup:get-initial-admin': 'getInitialAdmin',
    'setup:acknowledge-initial-admin': 'acknowledgeInitialAdmin',
    'system:factory-reset': 'factoryReset',
  };

  for (const [channel, consumerSource] of Object.entries(consumers)) {
    const registrationSource = channel.startsWith('updater:') ? updaterSource : mainSource;
    const validatorName = channel.startsWith('updater:')
      ? 'assertTrustedRenderer'
      : 'assertTrustedAppRenderer';
    const escapedChannel = channel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const registrationPattern = new RegExp(
      `ipcMain\\.handle\\('${escapedChannel}',\\s*(?:async\\s*)?\\(event[^)]*\\)\\s*=>\\s*\\{\\s*${validatorName}\\(event\\);`
    );

    assert.match(registrationSource, registrationPattern, `${channel} must validate its sender`);
    assert.match(consumerSource, new RegExp(`\\b${consumerMethodNames[channel]}\\b`));
  }
});

test('runtime and documentation do not advertise the removed POS WebSocket service', () => {
  const runtimeSources = [
    'electron/main.js',
    'electron/preload.js',
    'electron/services/https-proxy.js',
    'electron/services/php-server.js',
    'frontend/src/main.tsx',
    'frontend/src/types/electron.d.ts',
    'backend/controllers/HealthController.php',
    'README.md',
  ].map(readRepositoryFile).join('\n');

  assert.doesNotMatch(runtimeSources, /\b(?:WS_BASE_URL|wsBaseUrl|wsPort|ws_port|targetWsPort)\b/);
  assert.doesNotMatch(readRepositoryFile('README.md'), /admin@pos\.com|\|\s*password\s*\|/i);
});
