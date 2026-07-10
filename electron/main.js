const { app, BrowserWindow, Tray, Menu, nativeImage, ipcMain, protocol } = require('electron');
const path = require('path');

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
const { startWebSocketServer, stopWebSocketServer } = require('./services/websocket-server');
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
let lastStartupError = null;
let splash = null;

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
  const sessionCookies = {};

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
              cookies.forEach(c => {
                const parts = c.split(';')[0].split('=');
                if (parts.length === 2) {
                  const name = parts[0].trim();
                  const value = parts[1].trim();
                  const isDelete = value === '' || 
                                   /expires=Thu, 01 Jan 1970/i.test(c) || 
                                   /Max-Age=0/i.test(c) || 
                                   /Max-Age=-/i.test(c);
                  if (isDelete) {
                    delete sessionCookies[name];
                  } else {
                    sessionCookies[name] = value;
                  }
                }
              });
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
  const fs = require('fs');
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
  configureFirewall().catch(err => console.error('[Firewall] Failed:', err));

  ipcMain.handle('get-version', () => app.getVersion());
  ipcMain.handle('get-runtime-ports', async () => {
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      return JSON.parse(data);
    } catch (err) {
      console.error('[IPC] Failed to read runtime ports:', err.message);
      return null;
    }
  });
  ipcMain.handle('get-api-base-url', async () => {
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
  ipcMain.handle('get-ws-base-url', async () => {
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      const ports = JSON.parse(data);
      return ports.wsBaseUrl;
    } catch (err) {
      console.error('[IPC] Failed to read WS base URL:', err.message);
      return null;
    }
  });
  ipcMain.handle('qz-get-cert', () => getQZCertificate());
  ipcMain.handle('qz-sign', (_event, data) => signQZMessage(data));

  // ── Recovery Mode Handlers ──
  ipcMain.handle('recovery:get-last-error', () => lastStartupError);
  ipcMain.handle('recovery:get-diagnostics', () => {
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
  ipcMain.handle('recovery:retry-startup', async () => {
    console.log('[Recovery] Retry startup requested by user.');
    try {
      const phpServer = require('./services/php-server');
      const wsServer = require('./services/websocket-server');
      console.log('[Recovery] Stopping PHP and WebSocket servers...');
      phpServer.stopPhpServer();
      wsServer.stopWebSocketServer();
      
      console.log('[Recovery] Restarting PHP server...');
      const phpServerInfo = await phpServer.startPhpServer({ preferredPort: phpPort, mysqlPort });
      phpPort = phpServerInfo.port;
      console.log(`[Recovery] PHP Server restarted on port ${phpPort}`);

      console.log('[Recovery] Restarting WebSocket server...');
      await wsServer.startWebSocketServer();

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

  ipcMain.handle('recovery:get-rollback-readiness', () => {
    try {
      const { readRuntimeMetadata, getRollbackReadiness } = require('./utils/runtimeMigrator');
      const meta = readRuntimeMetadata();
      return getRollbackReadiness(meta);
    } catch (err) {
      console.error('[IPC] Failed to get rollback readiness:', err.message);
      return { available: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:run-rollback-dry-run', () => {
    try {
      const { runMysqlRollbackDryRun } = require('./utils/runtimeMigrator');
      return runMysqlRollbackDryRun();
    } catch (err) {
      console.error('[IPC] Failed to run rollback dry-run:', err.message);
      return { status: 'failed', reason: err.message };
    }
  });

  ipcMain.handle('recovery:execute-mysql-rollback', (_event, options) => {
    try {
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

  ipcMain.handle('recovery:prepare-rollback-restore-staging', (_event, options) => {
    try {
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

  ipcMain.handle('recovery:run-final-rollback-switch', (_event, options) => {
    try {
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

  // ── Window Controls ──
  ipcMain.handle('window-minimize', () => mainWindow?.minimize());
  ipcMain.handle('window-maximize', () => {
    if (mainWindow?.isMaximized()) mainWindow.unmaximize();
    else mainWindow?.maximize();
  });
  ipcMain.handle('window-close', () => mainWindow?.close());
  ipcMain.handle('window-is-maximized', () => mainWindow?.isMaximized() ?? false);

  // ── System Info ──
  ipcMain.handle('get-system-info', () => ({
    platform: process.platform,
    arch: process.arch,
    nodeVersion: process.version,
    electronVersion: process.versions.electron,
    memory: process.memoryUsage(),
  }));

  // ── File Operations ──
  ipcMain.handle('show-save-dialog', async (_e, options) => {
    const { dialog } = require('electron');
    return dialog.showSaveDialog(mainWindow, options);
  });
  ipcMain.handle('save-file', async (_e, filePath, data) => {
    const fs = require('fs');
    fs.writeFileSync(filePath, data);
    return true;
  });

  // ── Notifications ──
  ipcMain.handle('show-notification', (_e, title, body) => {
    const { Notification } = require('electron');
    new Notification({ title, body }).show();
  });

  // 1. شاشة تحميل (Splash)
  splash = new BrowserWindow({
    width: 400, height: 300,
    frame: false, transparent: true, alwaysOnTop: true,
    webPreferences: { nodeIntegration: true, contextIsolation: false }
  });
  splash.loadFile(path.join(__dirname, 'assets', 'splash.html'));

  try {
    // 2. تشغيل MySQL المدمج
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل قاعدة البيانات...'`
    );
    await startMySQL(mysqlPort);

    // 3. تشغيل PHP
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل الخادم...'`
    );
    const phpServerInfo = await startPHP(phpPort, mysqlPort);
    phpPort = phpServerInfo.port;
    console.log(`[Main] PHP Server started successfully on port ${phpPort}`);

    // 3.1. تشغيل خادم الـ WebSocket
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل خادم WebSocket...'`
    );
    const wsServerInfo = await startWebSocketServer();
    console.log(`[Main] WebSocket Server started successfully on port ${wsServerInfo.port}`);

    // ── Wait for Health Check ──
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'التحقق من جاهزية النظام...'`
    );
    const { waitForHealth } = require('./services/php-server');
    await waitForHealth(phpServerInfo.baseUrl);

    await startHttpsProxy(phpPort, 8443, wsServerInfo.port);

    // تشغيل job worker في الخلفية
    const { spawn } = require('child_process');
    const phpPath = require('./utils/paths').getPhpPath();
    const workerPath = path.join(__dirname, '..', 'backend', 'cli', 'process-jobs.php');
    const jobWorker = spawn(phpPath, [workerPath, '--daemon'], { stdio: 'ignore', detached: true });
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
        preload: path.join(__dirname, 'preload.js'),
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
  const { shell } = require('electron');
  const icon = nativeImage.createFromPath(path.join(__dirname, 'assets', 'icon.png'));
  tray = new Tray(icon.resize({ width: 16, height: 16 }));
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'فتح النظام', click: () => mainWindow.show() },
    { label: 'إدارة قاعدة البيانات', click: () => {
      shell.openExternal(`http://127.0.0.1:${phpPort}/adminer-local.php?server=127.0.0.1%3A${mysqlPort}&username=root&db=pos_db`);
    }},
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
  stopWebSocketServer();
  stopPHP();
  await stopMySQL();
});
