const { app, BrowserWindow, Tray, Menu, nativeImage, ipcMain, protocol, safeStorage } = require('electron');
const path = require('path');
const fs = require('fs');

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
const { startMySQL, stopMySQL } = require('./services/mysql-server');
const { setupAutoUpdater } = require('./services/auto-updater');
const { startHttpsProxy, stopHttpsProxy } = require('./services/https-proxy');
const { startQZTray, stopQZTray } = require('./services/qz-tray');
const { ensureQZCerts, getQZCertificate, signQZMessage } = require('./services/qz-certs');
const { configureFirewall } = require('./services/firewall');

// Disable code signing auto-discovery to prevent build issues
process.env.CSC_IDENTITY_AUTO_DISCOVERY = 'false';

let mainWindow = null;
let tray = null;
let phpPort = 8080;
let mysqlPort = 3307; // Bundled MySQL port
let dbCredentials = null;
let lastStartupError = null;
let splash = null;
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
  let sessionCookies = {};

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
  ipcMain.handle('qz-get-cert', (event) => {
    assertTrustedAppRenderer(event);
    return getQZCertificate();
  });
  ipcMain.handle('qz-sign', (event, data) => {
    assertTrustedAppRenderer(event);
    if (typeof data !== 'string' || Buffer.byteLength(data, 'utf8') > 64 * 1024) {
      throw new TypeError('Invalid QZ signing payload');
    }
    return signQZMessage(data);
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

    await startHttpsProxy(phpPort, 8443);

    // Run log maintenance and the job worker without delaying startup.
    const { spawn } = require('child_process');
    const {
      getPhpPath,
      getBackendDir,
      getDataDir,
      getEnvPath,
      getLogsDir,
      isPackaged,
    } = require('./utils/paths');
    const phpPath = getPhpPath();
    const backendDir = getBackendDir();
    const maintenanceEnv = {
      ...process.env,
      APP_STORAGE_DIR: getDataDir(),
      ENV_PATH: getEnvPath(),
      LOGS_PATH: getLogsDir(),
    };
    const cleanupArgs = isPackaged()
      ? [path.join(backendDir, 'backend.phar'), 'cleanup-logs']
      : [path.join(backendDir, 'cli', 'cleanup-logs.php')];
    const cleanupProcess = spawn(phpPath, cleanupArgs, {
      stdio: 'ignore',
      detached: true,
      windowsHide: true,
      env: maintenanceEnv,
    });
    cleanupProcess.on('error', err => console.warn('[LogCleanup] Failed to start:', err.message));
    cleanupProcess.unref();

    const workerArgs = isPackaged()
      ? [path.join(backendDir, 'backend.phar'), 'process-jobs', '--daemon']
      : [path.join(backendDir, 'cli', 'process-jobs.php'), '--daemon'];
    const jobWorker = spawn(phpPath, workerArgs, {
      stdio: 'ignore',
      detached: true,
      windowsHide: true,
      env: maintenanceEnv,
    });
    jobWorker.on('error', err => console.warn('[JobWorker] Failed to start:', err.message));
    jobWorker.unref();

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
  stopHttpsProxy();
  stopQZTray();
  stopPHP();
  await stopMySQL();
});
