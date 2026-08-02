const { app, BrowserWindow, Tray, Menu, nativeImage, ipcMain, protocol, safeStorage, dialog } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');

// Register app scheme as privileged before app ready
protocol.registerSchemesAsPrivileged([
  {
    scheme: 'app',
    privileges: {
      standard: true,
      secure: true,
      supportFetchAPI: true,
      corsEnabled: true
    }
  }
]);

const { startPHP, stopPHP } = require('./services/php-server');
const { startMySQL, stopMySQL, resetDatabase } = require('./services/mysql-server');
const { setupAutoUpdater } = require('./services/auto-updater');
const {
  enableLanAccess,
  startHttpsProxy,
  stopHttpsProxy,
} = require('./services/https-proxy');
const { startQZTray, stopQZTray } = require('./services/qz-tray');
const { ensureQZCerts, getQZCertificate, signQZMessage } = require('./services/qz-certs');
const { configureFirewall } = require('./services/firewall');
const { getPhpRuntimeArgs, resolveSystemTimeZone } = require('./utils/php-runtime');

// Disable code signing auto-discovery to prevent build issues
process.env.CSC_IDENTITY_AUTO_DISCOVERY = 'false';

let mainWindow = null;
let tray = null;
let phpPort = 8080;
let mysqlPort = 3307; // Bundled MySQL port
let dbCredentials = null;
let lastStartupError = null;
let splash = null;
let jobWorkerProcess = null;
let firstRunAdminCredentials = null;
let sessionCookies = {};
const PERSISTED_COOKIE_NAMES = new Set(['pos_token', 'pos_refresh_token', 'XSRF-TOKEN']);

function assertTrustedAppRenderer(event) {
  const senderUrl = event.senderFrame?.url || '';
  if (!senderUrl.startsWith('app://pos-app/')) {
    throw new Error('Untrusted renderer');
  }
}

function assertRecoveryRenderer(event) {
  const senderUrl = event.senderFrame?.url || '';
  if (!senderUrl.startsWith('file://') || !senderUrl.endsWith('/recovery.html')) {
    throw new Error('Untrusted recovery renderer');
  }
}

function stopJobWorker() {
  const worker = jobWorkerProcess;
  jobWorkerProcess = null;
  if (!worker || worker.killed) {
    return Promise.resolve();
  }
  return new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      resolve();
    };
    worker.once('exit', finish);
    worker.once('error', finish);
    try {
      worker.kill();
    } catch {
      finish();
      return;
    }
    setTimeout(finish, 2000);
  });
}

/**
 * Start the worker as an Electron-owned child. Keeping this in one helper is
 * important because database restore temporarily stops both PHP and the
 * worker, then must bring the exact same runtime back online.
 */
function startJobWorker() {
  if (jobWorkerProcess && !jobWorkerProcess.killed) {
    return;
  }
  if (!dbCredentials) {
    throw new Error('Database credentials are not available for the job worker');
  }

  const {
    getPhpPath,
    getBackendDir,
    getDataDir,
    getLogsDir,
    getTempDir,
    getEnvPath,
    isPackaged,
  } = require('./utils/paths');
  const { createBackendEnv } = require('./services/php-server');
  const phpPath = getPhpPath();
  const backendDir = getBackendDir();
  const phpRuntimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
  const workerArgs = isPackaged()
    ? [...phpRuntimeArgs, path.join(backendDir, 'backend.phar'), 'process-jobs', '--daemon']
    : [...phpRuntimeArgs, path.join(backendDir, 'cli', 'process-jobs.php'), '--daemon'];
  const isLanDeployment = process.env.POS_LAN_ENABLED === 'true';
  const workerEnv = {
    ...createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort }),
    APP_ENV: isLanDeployment ? (process.env.APP_ENV || 'production') : 'development',
    DEPLOYMENT_MODE: isLanDeployment ? 'lan' : 'desktop',
    APP_TIMEZONE: resolveSystemTimeZone(),
    APP_STORAGE_DIR: getDataDir(),
    ENV_PATH: getEnvPath(),
    LOGS_PATH: getLogsDir(),
  };

  const worker = spawn(phpPath, workerArgs, {
    stdio: 'ignore',
    detached: false,
    windowsHide: true,
    env: workerEnv,
  });
  jobWorkerProcess = worker;
  worker.on('error', (err) => console.warn('[JobWorker] Failed to start:', err.message));
  worker.on('exit', () => {
    if (jobWorkerProcess === worker) jobWorkerProcess = null;
  });
}

function runBackendCli(args, input = null) {
  const {
    getPhpPath,
    getBackendDir,
    getTempDir,
    isPackaged,
  } = require('./utils/paths');
  const { createBackendEnv } = require('./services/php-server');
  if (!dbCredentials) {
    return Promise.reject(new Error('Database credentials are not available'));
  }

  const phpPath = getPhpPath();
  const backendDir = getBackendDir();
  const runtimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
  const entryArgs = isPackaged()
    ? [path.join(backendDir, 'backend.phar'), ...args]
    : [path.join(backendDir, 'cli', `${args[0]}.php`), ...args.slice(1)];
  const commandArgs = [...runtimeArgs, ...entryArgs];
  const env = createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort });

  return new Promise((resolve, reject) => {
    const child = spawn(phpPath, commandArgs, {
      env,
      windowsHide: true,
      shell: false,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    const maxOutput = 12 * 1024;
    child.stdout.on('data', (data) => {
      stdout = (stdout + data.toString()).slice(-maxOutput);
    });
    child.stderr.on('data', (data) => {
      stderr = (stderr + data.toString()).slice(-maxOutput);
    });
    child.once('error', (error) => reject(new Error(`Failed to start backend command: ${error.message}`)));
    child.once('close', (code, signal) => {
      if (code === 0) {
        resolve({ stdout, stderr });
        return;
      }
      const detail = stderr.trim() || stdout.trim() || `exit code ${code}${signal ? `, signal ${signal}` : ''}`;
      reject(new Error(detail.slice(-2000)));
    });
    if (input !== null) {
      child.stdin.end(input);
    } else {
      child.stdin.end();
    }
  });
}

function validateDesktopSqlPath(filePath) {
  if (typeof filePath !== 'string' || filePath.trim() === '') {
    throw new Error('No backup file was selected');
  }
  const resolvedPath = fs.realpathSync(path.resolve(filePath));
  if (path.extname(resolvedPath).toLowerCase() !== '.sql') {
    throw new Error('The selected file must have a .sql extension');
  }
  const stat = fs.statSync(resolvedPath);
  try {
    fs.accessSync(resolvedPath, fs.constants.R_OK);
  } catch {
    throw new Error('The selected backup file cannot be read');
  }
  if (!stat.isFile()) {
    throw new Error('The selected backup file cannot be read');
  }
  if (stat.size > 50 * 1024 * 1024) {
    throw new Error('The backup file exceeds the 50 MB limit');
  }
  return resolvedPath;
}

async function restartPhpAndWorker(options = {}) {
  const { startWorker = true } = options;
  const phpServer = require('./services/php-server');
  const phpServerInfo = await phpServer.startPhpServer({
    preferredPort: phpPort,
    mysqlPort,
    dbCredentials,
  });
  phpPort = phpServerInfo.port;
  await phpServer.waitForHealth(phpServerInfo.baseUrl, { maxTime: 30000 });
  if (startWorker) startJobWorker();
  return phpServerInfo;
}

function parseBackendJson(stdout) {
  const lines = String(stdout || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .reverse();
  for (const line of lines) {
    try {
      const parsed = JSON.parse(line);
      if (parsed && typeof parsed === 'object') return parsed;
    } catch {
      // CLI bootstrap output may include harmless PHP startup notices. Only
      // accept the final valid JSON object.
    }
  }
  throw new Error('The backend bootstrap returned an invalid response');
}

async function initializeFreshRuntime({ seed = false } = {}) {
  if (seed) {
    await runBackendCli(['seed']);
  }

  const { stdout } = await runBackendCli(['initialize-admin']);
  const result = parseBackendJson(stdout);
  if (result.created === true) {
    if (typeof result.email !== 'string' || typeof result.password !== 'string' || !result.email || !result.password) {
      throw new Error('The backend returned incomplete administrator credentials');
    }
    firstRunAdminCredentials = {
      email: result.email,
      name: String(result.name || 'Administrator'),
      password: result.password,
      forcePasswordChange: result.force_password_change === true,
    };
  }
  return result;
}

function isPathInside(childPath, parentPath) {
  const child = path.resolve(childPath);
  const parent = path.resolve(parentPath);
  const relative = path.relative(parent, child);
  return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}

function clearDirectoryContents(directory, parentDirectory) {
  if (!isPathInside(directory, parentDirectory)) {
    throw new Error('Refusing to clear an unmanaged directory');
  }
  if (fs.existsSync(directory) && fs.lstatSync(directory).isSymbolicLink()) {
    throw new Error('Refusing to clear a symbolic-link directory');
  }
  fs.mkdirSync(directory, { recursive: true });
  for (const entry of fs.readdirSync(directory)) {
    const target = path.join(directory, entry);
    fs.rmSync(target, { recursive: true, force: true });
  }
}

function removeManagedFile(filePath, parentDirectory) {
  if (!isPathInside(filePath, parentDirectory)) {
    throw new Error('Refusing to remove an unmanaged file');
  }
  if (fs.existsSync(filePath)) {
    if (fs.lstatSync(filePath).isSymbolicLink()) {
      throw new Error('Refusing to remove a symbolic-link file');
    }
    fs.rmSync(filePath, { force: true });
  }
}

function clearFactoryResetRuntimeState() {
  const {
    getDataDir,
    getConfigDir,
    getLogsDir,
    getTempDir,
    getRuntimeMetadataPath,
    getRuntimePortsPath,
    getMigrationsFlagPath,
    getRecoveryAuthPath,
    getCookiesPath,
  } = require('./utils/paths');
  const dataDir = getDataDir();
  const configDir = getConfigDir();

  // Keep the directory itself and only remove app-managed runtime contents.
  // User-created backups are intentionally preserved for recovery.
  clearDirectoryContents(getLogsDir(), dataDir);
  clearDirectoryContents(getTempDir(), dataDir);
  removeManagedFile(getRuntimeMetadataPath(), configDir);
  removeManagedFile(getRuntimePortsPath(), configDir);
  removeManagedFile(getMigrationsFlagPath(), dataDir);
  removeManagedFile(getRecoveryAuthPath(), configDir);
  removeManagedFile(getCookiesPath(), configDir);
}

async function factoryResetDesktop() {
  if (process.env.POS_LAN_ENABLED === 'true') {
    throw new Error('Factory reset is available only while the desktop runtime is local');
  }

  const phpServer = require('./services/php-server');
  firstRunAdminCredentials = null;
  sessionCookies = {};
  await stopJobWorker();
  phpServer.stopPhpServer();
  await stopHttpsProxy();

  try {
    dbCredentials = await resetDatabase(mysqlPort);
    clearFactoryResetRuntimeState();

    // Start PHP only while the schema is migrated and default data/admin are
    // recreated. The worker must not observe a half-reset database.
    await restartPhpAndWorker({ startWorker: false });
    await initializeFreshRuntime({ seed: true });
    startJobWorker();
    await startHttpsProxy(phpPort, 8443);
    await clearDesktopSession();
    await clearDesktopRendererStorage();

    if (mainWindow && !mainWindow.isDestroyed()) {
      await mainWindow.loadURL('app://pos-app/index.html');
    }
    return { success: true, adminSetupRequired: firstRunAdminCredentials !== null };
  } catch (error) {
    try {
      if (!phpServer.getPhpServerInfo()) {
        await restartPhpAndWorker();
      } else {
        startJobWorker();
      }
    } catch (restartError) {
      console.error('[Factory Reset] Failed to restart PHP after reset error:', restartError.message);
    }
    throw error;
  }
}

async function clearDesktopSession() {
  const { session } = require('electron');
  const { getCookiesPath } = require('./utils/paths');
  const cookieNames = ['pos_token', 'pos_refresh_token', 'XSRF-TOKEN'];
  for (const host of ['127.0.0.1', 'localhost']) {
    for (const name of cookieNames) {
      try {
        await session.defaultSession.cookies.remove(`http://${host}:${phpPort}`, name);
      } catch {
        // A cookie may not exist on one of the two local host aliases.
      }
    }
  }
  try {
    if (fs.existsSync(getCookiesPath())) fs.unlinkSync(getCookiesPath());
  } catch (error) {
    console.warn('[Session] Failed to remove persisted session cookies:', error.message);
  }
  sessionCookies = {};
}

async function clearDesktopRendererStorage() {
  const { session } = require('electron');
  // The renderer keeps the offline catalog and service-worker cache outside
  // the SQL database. Clear those stores so a factory reset cannot show
  // products or pending operations from the previous installation.
  await session.defaultSession.clearStorageData({
    storages: [
      'cookies',
      'indexdb',
      'localstorage',
      'serviceworkers',
      'cachestorage',
      'filesystem',
      'websql',
      'appcache',
    ],
  });
}

async function restoreDesktopBackup(filePath) {
  if (process.env.POS_LAN_ENABLED === 'true') {
    throw new Error('Database restore is available only while the desktop runtime is local');
  }

  const resolvedPath = validateDesktopSqlPath(filePath);
  const phpServer = require('./services/php-server');
  if (!phpServer.getPhpServerInfo()) {
    throw new Error('The local PHP server is not running');
  }

  sessionCookies = {};
  await stopJobWorker();
  phpServer.stopPhpServer();
  try {
    await runBackendCli(['restore-backup', resolvedPath]);
    await restartPhpAndWorker();
    await clearDesktopSession();
    await clearDesktopRendererStorage();
    // Restore invalidates all application tokens. Reload so the renderer
    // returns to the login screen instead of using stale in-memory state.
    if (mainWindow && !mainWindow.isDestroyed()) {
      await mainWindow.loadURL('app://pos-app/index.html');
    }
    return { success: true };
  } catch (error) {
    try {
      if (phpServer.getPhpServerInfo()) phpServer.stopPhpServer();
      await restartPhpAndWorker();
    } catch (restartError) {
      console.error('[Backup] Failed to restart PHP after restore error:', restartError.message);
    }
    throw error;
  }
}

function saveSessionCookies(cookiesPath, cookies) {
  if (!safeStorage.isEncryptionAvailable()) {
    throw new Error('Secure credential storage is unavailable');
  }

  const filtered = Object.fromEntries(
    Object.entries(cookies).filter(([name]) => PERSISTED_COOKIE_NAMES.has(name))
  );
  fs.writeFileSync(cookiesPath, safeStorage.encryptString(JSON.stringify(filtered)));
}

function loadSessionCookies(cookiesPath) {
  if (!fs.existsSync(cookiesPath)) return {};
  return JSON.parse(safeStorage.decryptString(fs.readFileSync(cookiesPath)));
}

// منع تشغيل أكثر من نسخة
const gotTheLock = app.requestSingleInstanceLock();
if (!gotTheLock) { app.quit(); }

app.on('second-instance', () => {
  if (mainWindow) {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.focus();
  }
});

app.whenReady().then(async () => {
  // ── Session Cookie Proxy ──
  const { session } = require('electron');
  const { getCookiesPath } = require('./utils/paths');

  // Restore session cookies from disk at startup
  try {
    const cookiesPath = getCookiesPath();
    if (fs.existsSync(cookiesPath)) {
      sessionCookies = loadSessionCookies(cookiesPath);
      console.log('[CookieProxy] Restored cookies from disk');
    }
  } catch (err) {
    console.error('[CookieProxy] Failed to restore cookies from disk:', err.message);
    try {
      const cookiesPath = getCookiesPath();
      if (fs.existsSync(cookiesPath) && fs.readFileSync(cookiesPath, 'utf8').trim().startsWith('{')) {
        fs.unlinkSync(cookiesPath);
        console.warn('[CookieProxy] Removed legacy plaintext cookie storage; sign-in is required.');
      }
    } catch {
      // A failed cleanup must not prevent application startup.
    }
  }

  session.defaultSession.webRequest.onHeadersReceived((details, callback) => {
    const responseHeaders = { ...details.responseHeaders };
    try {
      // 5. Origin guard
      const initiator = details.initiator || '';
      const isTrustedInitiator = !initiator || initiator.startsWith('app://');
      if (isTrustedInitiator) {
        // 1. Cookie capture scope (Only capture Set-Cookie from the local PHP backend runtime URL)
        let parsedUrl;
        try {
          parsedUrl = new URL(details.url);
        } catch (e) {
          parsedUrl = null;
        }

        if (parsedUrl) {
          const host = parsedUrl.hostname;
          const isLocalHost = host === '127.0.0.1' || host === 'localhost' || host === '::1' || host === '[::1]';
          const isHttp = parsedUrl.protocol === 'http:';
          const port = parsedUrl.port ? parseInt(parsedUrl.port, 10) : 80;
          
          // 6. API port binding: check against dynamic phpPort
          const isLocalPhpBackend = isHttp && isLocalHost && port === phpPort;

          if (isLocalPhpBackend) {
            const setCookieKey = Object.keys(responseHeaders).find(k => k.toLowerCase() === 'set-cookie');
            if (setCookieKey) {
              const setCookieHeaders = responseHeaders[setCookieKey];
              const cookies = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders];
              let hasChanged = false;
              cookies.forEach(c => {
                const parts = c.split(';')[0].split('=');
                if (parts.length === 2) {
                  const name = parts[0].trim();
                  const value = parts[1].trim();
                  if (!PERSISTED_COOKIE_NAMES.has(name)) return;
                  const isDelete = value === '' || 
                                   /expires=Thu, 01 Jan 1970/i.test(c) || 
                                   /Max-Age=0/i.test(c) || 
                                   /Max-Age=-/i.test(c);
                  if (isDelete) {
                    if (sessionCookies[name] !== undefined) {
                      delete sessionCookies[name];
                      hasChanged = true;
                    }
                  } else {
                    if (sessionCookies[name] !== value) {
                      sessionCookies[name] = value;
                      hasChanged = true;
                    }
                  }
                }
              });
              if (hasChanged) {
                try {
                  saveSessionCookies(getCookiesPath(), sessionCookies);
                  console.log('[CookieProxy] Saved updated cookies to disk');
                } catch (writeErr) {
                  console.error('[CookieProxy] Failed to write cookies to disk:', writeErr.message);
                }
              }
            }
          }
        }
      }
    } catch (err) {
      // 7. Failure behavior: do not reload or throw, fallback gracefully
      // 4. Cookie logging: do not log raw cookies or session IDs
      console.error('[CookieProxy] Error capturing headers safely');
    }
    callback({ responseHeaders });
  });

  session.defaultSession.webRequest.onBeforeSendHeaders((details, callback) => {
    const requestHeaders = { ...details.requestHeaders };
    try {
      // 5. Origin guard
      const initiator = details.initiator || '';
      const isTrustedInitiator = !initiator || initiator.startsWith('app://');
      if (isTrustedInitiator) {
        // 2. Cookie injection scope (Only inject into outgoing requests targeting same local PHP backend URL)
        let parsedUrl;
        try {
          parsedUrl = new URL(details.url);
        } catch (e) {
          parsedUrl = null;
        }

        if (parsedUrl) {
          const host = parsedUrl.hostname;
          const isLocalHost = host === '127.0.0.1' || host === 'localhost' || host === '::1' || host === '[::1]';
          const isHttp = parsedUrl.protocol === 'http:';
          const port = parsedUrl.port ? parseInt(parsedUrl.port, 10) : 80;
          
          // 6. API port binding: check against dynamic phpPort
          const isLocalPhpBackend = isHttp && isLocalHost && port === phpPort;

          if (isLocalPhpBackend) {
            const cookieList = [];
            for (const [name, value] of Object.entries(sessionCookies)) {
              cookieList.push(`${name}=${value}`);
            }
            // Clear any existing cookie headers case-insensitively to avoid duplicates
            for (const key of Object.keys(requestHeaders)) {
              if (key.toLowerCase() === 'cookie') {
                delete requestHeaders[key];
              }
            }
            if (cookieList.length > 0) {
              requestHeaders['Cookie'] = cookieList.join('; ');
            }
          }
        }
      }
    } catch (err) {
      // 7. Failure behavior: fallback gracefully
      // 4. Cookie logging: do not log headers
      console.error('[CookieProxy] Error injecting headers safely');
    }
    callback({ requestHeaders });
  });

  // ── Register app:// protocol handler ──
  const { net } = require('electron');
  const url = require('url');

  protocol.handle('app', async (request) => {
    const requestUrl = new URL(request.url);
    let filePath = decodeURIComponent(requestUrl.pathname);
    
    // Standardize separators for Windows compatibility
    filePath = filePath.replace(/\\/g, '/');

    // Prevent path traversal
    if (filePath.includes('..') || filePath.includes('%2e%2e') || filePath.includes('\\')) {
      return new Response('Access Denied', { status: 403 });
    }

    const distDir = app.isPackaged
      ? path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'frontend', 'dist')
      : path.join(app.getAppPath(), 'frontend', 'dist');
    let targetFile = path.join(distDir, filePath);

    // Resolve and verify normalized target is within dist directory
    const normalizedTarget = path.normalize(targetFile);
    const normalizedDist = path.normalize(distDir);
    if (!normalizedTarget.startsWith(normalizedDist)) {
      return new Response('Access Denied', { status: 403 });
    }

    try {
      if (fs.existsSync(targetFile)) {
        const stat = fs.statSync(targetFile);
        if (stat.isFile()) {
          return net.fetch(url.pathToFileURL(targetFile).toString());
        }
      }
    } catch (err) {
      console.error('[Protocol] Error resolving file:', err.message);
    }

    // SPA fallback to index.html for unknown routes
    const indexHtmlPath = path.join(distDir, 'index.html');
    return net.fetch(url.pathToFileURL(indexHtmlPath).toString());
  });

  // ── Prepare Runtime Directories ──
  try {
    const { ensureRuntimeDirs } = require('./utils/paths');
    ensureRuntimeDirs();
  } catch (err) {
    console.error('[Paths] Failed to prepare runtime directories:', err);
  }

  // ── Run Runtime Migration ──
  try {
    const { runRuntimeMigration } = require('./utils/runtimeMigrator');
    const migrationResult = runRuntimeMigration({ dryRun: false, safeMode: true });
    if (migrationResult && migrationResult.success === false && migrationResult.error) {
      if (migrationResult.error.includes('ENOENT') || migrationResult.error.includes('EACCES') || migrationResult.error.includes('EPERM')) {
        throw new Error(`Critical migration foundation error: ${migrationResult.error}`);
      }
    }
  } catch (err) {
    console.error('[Migration] Critical failure during runtime migration check:', err.message);
    throw err;
  }

  // ── Configure Windows Firewall rules (production) ──
  if (process.env.POS_LAN_ENABLED === 'true') {
    configureFirewall().catch(err => console.error('[Firewall] Failed:', err));
  }

  ipcMain.handle('get-version', (event) => {
    assertTrustedAppRenderer(event);
    return app.getVersion();
  });
  ipcMain.handle('get-api-base-url', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      const ports = JSON.parse(data);
      return ports.apiBaseUrl;
    } catch (err) {
      console.error('[IPC] Failed to read API base URL:', err.message);
      return null;
    }
  });
  ipcMain.handle('network:enable-lan', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      const proxyInfo = await enableLanAccess(phpPort, 8443);
      if (!proxyInfo.running) {
        return {
          enabled: false,
          port: 8443,
          protocol: 'https',
          error: proxyInfo.error || 'Unable to bind the LAN HTTPS service',
        };
      }

      // Keep subsequent recovery/restart workers aligned with the explicit
      // user choice made from the network settings section.
      process.env.POS_LAN_ENABLED = 'true';
      const firewallConfigured = await configureFirewall();
      return {
        enabled: true,
        port: proxyInfo.port || 8443,
        protocol: 'https',
        firewallConfigured,
        firewallRequired: process.platform === 'win32' && !firewallConfigured,
      };
    } catch (err) {
      console.error('[LAN] Failed to enable phone access:', err.message);
      return {
        enabled: false,
        port: 8443,
        protocol: 'https',
        error: 'LAN access could not be enabled. Check the local firewall and try again.',
      };
    }
  });
  ipcMain.handle('qz-get-cert', (event) => {
    assertTrustedAppRenderer(event);
    return getQZCertificate();
  });
  ipcMain.handle('qz-sign', (event, data) => {
    assertTrustedAppRenderer(event);
    if (
      typeof data !== 'string'
      || Buffer.byteLength(data, 'utf8') > 128
      || !/^[a-f0-9]{64}$/i.test(data.trim())
    ) {
      throw new TypeError('Invalid QZ signing payload');
    }
    return signQZMessage(data.trim().toLowerCase());
  });

  ipcMain.handle('setup:get-initial-admin', (event) => {
    assertTrustedAppRenderer(event);
    return firstRunAdminCredentials;
  });

  ipcMain.handle('setup:acknowledge-initial-admin', (event) => {
    assertTrustedAppRenderer(event);
    firstRunAdminCredentials = null;
    return { success: true };
  });

  ipcMain.handle('system:factory-reset', async (event, options) => {
    assertTrustedAppRenderer(event);
    if (!options || options.confirmationToken !== 'RESET_POS_DATA') {
      return { success: false, error: 'Invalid factory reset confirmation.' };
    }
    const confirmation = await dialog.showMessageBox(mainWindow, {
      type: 'warning',
      title: 'Factory reset',
      message: 'This permanently deletes the POS database and local session/cache data.',
      detail: 'Backup files are preserved. Continue only if you intend to start from a clean system.',
      buttons: ['Cancel', 'Reset everything'],
      defaultId: 0,
      cancelId: 0,
      noLink: true,
    });
    if (confirmation.response !== 1) {
      return { success: false, cancelled: true };
    }
    try {
      return await factoryResetDesktop();
    } catch (error) {
      console.error('[Factory Reset] Failed:', error.message);
      return {
        success: false,
        error: 'Factory reset failed. Check the error log for the reference.',
      };
    }
  });

  ipcMain.handle('backup:restore', async (event) => {
    assertTrustedAppRenderer(event);
    const selection = await dialog.showOpenDialog(mainWindow, {
      title: 'Select POS SQL backup',
      properties: ['openFile'],
      filters: [{ name: 'SQL backup', extensions: ['sql'] }],
    });
    if (selection.canceled || selection.filePaths.length === 0) {
      return { success: false, cancelled: true };
    }

    try {
      return await restoreDesktopBackup(selection.filePaths[0]);
    } catch (error) {
      console.error('[Backup] Desktop restore failed:', error.message);
      return {
        success: false,
        error: 'Database restore failed. Check the error log for the reference.',
      };
    }
  });

  ipcMain.handle('auth:recover-password', async (event, payload) => {
    assertTrustedAppRenderer(event);
    if (process.env.POS_LAN_ENABLED === 'true') {
      return {
        success: false,
        error: 'Local password recovery is disabled while LAN access is enabled. Ask an administrator to reset the account.',
      };
    }
    if (!payload || typeof payload !== 'object') {
      return { success: false, error: 'Invalid recovery details.' };
    }

    const email = typeof payload.email === 'string' ? payload.email.trim() : '';
    const password = typeof payload.password === 'string' ? payload.password : '';
    if (email.length > 254 || password.length > 256) {
      return { success: false, error: 'Invalid recovery details.' };
    }

    const phpServer = require('./services/php-server');
    sessionCookies = {};
    await stopJobWorker();
    phpServer.stopPhpServer();
    try {
      await runBackendCli(['reset-password'], JSON.stringify({ email, password }));
      await restartPhpAndWorker();
      return { success: true };
    } catch (error) {
      try {
        if (phpServer.getPhpServerInfo()) phpServer.stopPhpServer();
        await restartPhpAndWorker();
      } catch (restartError) {
        console.error('[Auth] Failed to restart PHP after password recovery error:', restartError.message);
      }
      console.error('[Auth] Local password recovery failed:', error.message);
      return { success: false, error: error.message || 'Password recovery failed.' };
    }
  });

  // ── Recovery Mode Handlers ──
  ipcMain.handle('recovery:get-last-error', (event) => {
    assertRecoveryRenderer(event);
    return lastStartupError;
  });
  ipcMain.handle('recovery:get-diagnostics', (event) => {
    assertRecoveryRenderer(event);
    const { getDataDir, getConfigDir, getLogsDir } = require('./utils/paths');
    const phpServer = require('./services/php-server');
    let runtimePorts = null;
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      runtimePorts = JSON.parse(data);
    } catch (err) {
      console.warn('[Recovery Diagnostics] Failed to read runtime ports:', err.message);
    }

    let runtimeMetadata = null;
    let migrationState = 'unknown';
    let lastMigrationEvent = null;
    try {
      const { readRuntimeMetadata } = require('./utils/runtimeMigrator');
      const meta = readRuntimeMetadata();
      if (meta) {
        runtimeMetadata = {
          appVersion: meta.appVersion,
          lastSuccessfulVersion: meta.lastSuccessfulVersion,
          migrationState: meta.migrationState,
          dataDir: meta.dataDir,
          configDir: meta.configDir,
          archivedPaths: meta.archivedPaths,
          foundationSafeModeOnly: meta.foundationSafeModeOnly,
          realDataMigrationPerformed: meta.realDataMigrationPerformed,
          safeFileMigrationPerformed: meta.safeFileMigrationPerformed,
          fileMigrations: meta.fileMigrations,
          mysqlMigration: meta.mysqlMigration ? {
            phase: meta.mysqlMigration.phase,
            status: meta.mysqlMigration.status,
            activeMigrationPerformed: meta.mysqlMigration.activeMigrationPerformed,
            mysqlDataMigrationPerformed: meta.mysqlMigration.mysqlDataMigrationPerformed,
            activeMysqlPathChanged: meta.mysqlMigration.activeMysqlPathChanged,
            sourcePath: meta.mysqlMigration.sourcePath,
            activePath: meta.mysqlMigration.activePath,
            preflightBackupPath: meta.mysqlMigration.preflightBackupPath,
            rollbackAvailable: meta.mysqlMigration.rollbackAvailable,
            legacySourcePreserved: meta.mysqlMigration.legacySourcePreserved,
            candidates: meta.mysqlMigration.candidates ? meta.mysqlMigration.candidates.map(c => ({
              path: c.path,
              valid: c.valid,
              locked: c.locked,
              lockState: c.lockState,
              sizeBytes: c.sizeBytes,
              reason: c.reason
            })) : [],
            backup: meta.mysqlMigration.backup ? {
              path: meta.mysqlMigration.backup.path,
              status: meta.mysqlMigration.backup.status,
              timestamp: meta.mysqlMigration.backup.timestamp,
              sizeBytes: meta.mysqlMigration.backup.sizeBytes
            } : null,
            lastError: meta.mysqlMigration.lastError
          } : null
        };
        migrationState = meta.migrationState || 'idle';
        if (meta.events && meta.events.length > 0) {
          lastMigrationEvent = meta.events[meta.events.length - 1];
        }
      }
    } catch (err) {
      console.warn('[Recovery Diagnostics] Failed to read runtime metadata:', err.message);
    }

    let fileMigrations = runtimeMetadata ? runtimeMetadata.fileMigrations : null;
    let mysqlMigration = runtimeMetadata ? runtimeMetadata.mysqlMigration : null;
    let conflicts = [];
    if (fileMigrations) {
      for (const [key, val] of Object.entries(fileMigrations)) {
        if (val && (val.status === 'conflict' || val.status === 'migrated_with_conflict_copy')) {
          conflicts.push({ key, ...val });
        }
      }
    }

    return {
      selectedDataDir: getDataDir(),
      configDir: getConfigDir(),
      logsDir: getLogsDir(),
      runtimePorts,
      phpServerInfo: phpServer.getPhpServerInfo(),
      lastHealthResult: phpServer.getLastHealthResponse(),
      runtimeMetadata,
      migrationState,
      lastMigrationEvent,
      fileMigrations,
      mysqlMigration,
      conflicts
    };
  });
  ipcMain.handle('recovery:retry-startup', async (event) => {
    assertRecoveryRenderer(event);
    console.log('[Recovery] Retry startup requested by user.');
    try {
      const phpServer = require('./services/php-server');
      console.log('[Recovery] Stopping PHP and WebSocket servers...');
      phpServer.stopPhpServer();
      
      console.log('[Recovery] Restarting PHP server...');
      const phpServerInfo = await phpServer.startPhpServer({
        preferredPort: phpPort,
        mysqlPort,
        dbCredentials,
      });
      phpPort = phpServerInfo.port;
      console.log(`[Recovery] PHP Server restarted on port ${phpPort}`);

      console.log('[Recovery] Awaiting readiness check...');
      await phpServer.waitForHealth(phpServerInfo.baseUrl, { maxTime: 15000 });

      console.log('[Recovery] Startup success! Transitioning to main application frontend...');
      mainWindow.loadURL('app://pos-app/index.html');
      lastStartupError = null;
      return { success: true };
    } catch (err) {
      console.error('[Recovery] Retry startup failed:', err.message);
      const phpServer = require('./services/php-server');
      const lastResponse = phpServer.getLastHealthResponse();
      lastStartupError = {
        message: err.message,
        timestamp: new Date().toISOString(),
        apiBaseUrl: `http://127.0.0.1:${phpPort}`,
        attempts: 0,
        failedChecks: lastResponse ? lastResponse.checks : null
      };
      return { success: false, error: err.message };
    }
  });

  ipcMain.handle('recovery:get-rollback-readiness', (event) => {
    assertRecoveryRenderer(event);
    try {
      const { readRuntimeMetadata, getRollbackReadiness } = require('./utils/runtimeMigrator');
      const meta = readRuntimeMetadata();
      return getRollbackReadiness(meta);
    } catch (err) {
      console.error('[IPC] Failed to get rollback readiness:', err.message);
      return { available: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:run-rollback-dry-run', (event) => {
    assertRecoveryRenderer(event);
    try {
      const { runMysqlRollbackDryRun } = require('./utils/runtimeMigrator');
      return runMysqlRollbackDryRun();
    } catch (err) {
      console.error('[IPC] Failed to run rollback dry-run:', err.message);
      return { status: 'failed', reason: err.message };
    }
  });

  ipcMain.handle('recovery:execute-mysql-rollback', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK') {
        throw new Error('Forbidden: Invalid confirmation token.');
      }
      const { executeMysqlRollback } = require('./utils/runtimeMigrator');
      return executeMysqlRollback(null, options);
    } catch (err) {
      console.error('[IPC] Failed to execute mysql rollback:', err.message);
      return { success: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:prepare-rollback-restore-staging', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK_RESTORE' || options.enableRollbackRestore !== true || options.dryRun === true) {
        throw new Error('Forbidden: Invalid confirmation token or rollback restore disabled.');
      }
      const { runAuthorizedMysqlRollbackRestore } = require('./utils/runtimeMigrator');
      return runAuthorizedMysqlRollbackRestore(null, options);
    } catch (err) {
      console.error('[IPC] Failed to prepare rollback restore staging:', err.message);
      return { success: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:run-final-rollback-switch', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_FINAL_MYSQL_ROLLBACK_SWITCH' || options.enableFinalRollbackSwitch !== true || options.dryRun === true) {
        throw new Error('Forbidden: Invalid confirmation token or final rollback switch disabled.');
      }
      const { runFinalMysqlRollbackSwitch } = require('./utils/runtimeMigrator');
      return runFinalMysqlRollbackSwitch(null, options);
    } catch (err) {
      console.error('[IPC] Failed to execute final rollback switch:', err.message);
      return { success: false, reason: err.message };
    }
  });

  // 1. شاشة تحميل (Splash)
  splash = new BrowserWindow({
    width: 400, height: 300,
    frame: false, transparent: true, alwaysOnTop: true,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    }
  });
  splash.loadFile(path.join(__dirname, 'assets', 'splash.html'));

  try {
    // 2. تشغيل MySQL المدمج
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل قاعدة البيانات...'`
    );
    dbCredentials = await startMySQL(mysqlPort);

    // 3. تشغيل PHP
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل الخادم...'`
    );
    const phpServerInfo = await startPHP(phpPort, mysqlPort, dbCredentials);
    phpPort = phpServerInfo.port;
    console.log(`[Main] PHP Server started successfully on port ${phpPort}`);

    // ── Wait for Health Check ──
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'التحقق من جاهزية النظام...'`
    );
    const { waitForHealth } = require('./services/php-server');
    await waitForHealth(phpServerInfo.baseUrl);

    // Fresh desktop installs contain no interactive user by design. Seed the
    // packaged defaults and create a one-time administrator credential only
    // when the database is empty; existing upgrades retain their data.
    await initializeFreshRuntime({ seed: dbCredentials.freshInstall === true });

    await startHttpsProxy(phpPort, 8443);

    // Run log maintenance and the job worker without delaying startup.
    const {
      getPhpPath,
      getBackendDir,
      getDataDir,
      getEnvPath,
      getLogsDir,
      getTempDir,
      isPackaged,
    } = require('./utils/paths');
    const phpPath = getPhpPath();
    const backendDir = getBackendDir();
    const isLanDeployment = process.env.POS_LAN_ENABLED === 'true';
    const { createBackendEnv } = require('./services/php-server');
    const maintenanceEnv = {
      ...createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort }),
      APP_ENV: isLanDeployment ? (process.env.APP_ENV || 'production') : 'development',
      DEPLOYMENT_MODE: isLanDeployment ? 'lan' : 'desktop',
      APP_TIMEZONE: resolveSystemTimeZone(),
      APP_STORAGE_DIR: getDataDir(),
      ENV_PATH: getEnvPath(),
      LOGS_PATH: getLogsDir(),
    };
    const phpRuntimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
    const cleanupArgs = isPackaged()
      ? [...phpRuntimeArgs, path.join(backendDir, 'backend.phar'), 'cleanup-logs']
      : [...phpRuntimeArgs, path.join(backendDir, 'cli', 'cleanup-logs.php')];
    const cleanupProcess = spawn(phpPath, cleanupArgs, {
      stdio: 'ignore',
      detached: true,
      windowsHide: true,
      env: maintenanceEnv,
    });
    cleanupProcess.on('error', err => console.warn('[LogCleanup] Failed to start:', err.message));
    cleanupProcess.unref();

    // Keep the worker owned by Electron. A detached worker survives app
    // restarts and can continue running an older backend indefinitely.
    startJobWorker();

    // 3.5. تشغيل QZ Tray (الطباعة المباشرة)
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل خدمة الطباعة...'`
    );
    try {
      const { getJavaPath, getQZTrayPath } = require('./utils/paths');
      const qzTrayDir = require('path').dirname(getQZTrayPath());
      await ensureQZCerts(qzTrayDir, getJavaPath());
    } catch (err) {
      console.warn('[QZ Certs] Skipped cert generation:', err.message);
    }
    await startQZTray();

    // 4. فتح النافذة الرئيسية
    mainWindow = new BrowserWindow({
      width: 1280, height: 800,
      minWidth: 1024, minHeight: 600,
      icon: path.join(__dirname, 'assets', 'icon.png'),
      titleBarStyle: 'hidden',
      titleBarOverlay: {
        color: '#1a1d2e', // Matches the sidebar/header background color
        symbolColor: '#f3f4f6', // Light gray icons
        height: 36 // Matches standard title bar height
      },
      webPreferences: {
        preload: path.join(__dirname, 'preload.js'),
        contextIsolation: true,
        nodeIntegration: false,
        webSecurity: true,
        sandbox: true
      }
    });

    // تحميل الـ frontend عبر custom protocol
    mainWindow.loadURL('app://pos-app/index.html');
    mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    mainWindow.webContents.on('will-navigate', (event, url) => {
      if (!url.startsWith('app://pos-app/')) event.preventDefault();
    });
    mainWindow.setMenu(null); // إخفاء القائمة العلوية
    mainWindow.maximize(); // تكبير الشاشة بالكامل

    // تمكين الـ Zoom وتحديث الصفحة
    mainWindow.webContents.on('before-input-event', (event, input) => {
      // تحديث الصفحة عن طريق F5 أو Ctrl+R
      if (input.key === 'F5' || (input.control && input.key.toLowerCase() === 'r')) {
        event.preventDefault();
        mainWindow.webContents.reload();
      }

      // التقريب والإبعاد عن طريق Ctrl + / Ctrl - / Ctrl 0
      if (input.control && ['+', '=', '-', '0'].includes(input.key)) {
        event.preventDefault();
        const currentZoom = mainWindow.webContents.getZoomFactor();
        if (input.key === '+' || input.key === '=') {
          mainWindow.webContents.setZoomFactor(currentZoom + 0.1);
        } else if (input.key === '-') {
          mainWindow.webContents.setZoomFactor(currentZoom - 0.1);
        } else if (input.key === '0') {
          mainWindow.webContents.setZoomFactor(1.0);
        }
      }
    });

    splash.close();

    // 5. System Tray
    createTray();

    // 6. فحص التحديثات التلقائية
    setupAutoUpdater(mainWindow);

    mainWindow.on('close', (e) => {
      if (!forceQuit) {
        e.preventDefault();
        mainWindow.hide(); // إخفاء بدل الإغلاق
      }
    });

  } catch (err) {
    console.error('[Recovery] Entering Recovery Mode due to startup failure:', err.message);
    enterRecoveryMode(err);
  }
});

function enterRecoveryMode(err) {
  if (splash) {
    try {
      splash.close();
    } catch (e) {}
  }

  const phpServer = require('./services/php-server');
  const lastResponse = phpServer.getLastHealthResponse();

  lastStartupError = {
    message: err.message,
    timestamp: new Date().toISOString(),
    apiBaseUrl: `http://127.0.0.1:${phpPort}`,
    attempts: 0,
    failedChecks: lastResponse ? lastResponse.checks : null
  };

  if (!mainWindow) {
    mainWindow = new BrowserWindow({
      width: 1280, height: 800,
      minWidth: 1024, minHeight: 600,
      icon: path.join(__dirname, 'assets', 'icon.png'),
      titleBarStyle: 'hidden',
      titleBarOverlay: {
        color: '#1a1d2e',
        symbolColor: '#f3f4f6',
        height: 36
      },
      webPreferences: {
        preload: path.join(__dirname, 'recovery-preload.js'),
        contextIsolation: true,
        nodeIntegration: false,
        webSecurity: true,
        sandbox: true
      }
    });
    mainWindow.setMenu(null);
    mainWindow.maximize();
  }

  mainWindow.loadFile(path.join(__dirname, 'assets', 'recovery.html'));
}

function createTray() {
  const icon = nativeImage.createFromPath(path.join(__dirname, 'assets', 'icon.png'));
  tray = new Tray(icon.resize({ width: 16, height: 16 }));
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'فتح النظام', click: () => mainWindow.show() },
    { type: 'separator' },
    { label: 'إغلاق', click: () => { forceQuit = true; app.quit(); } }
  ]));
  tray.on('double-click', () => mainWindow.show());
}

let forceQuit = false;
app.on('before-quit', async () => {
  forceQuit = true;
  stopJobWorker();
  stopHttpsProxy();
  stopQZTray();
  stopPHP();
  await stopMySQL();
});
