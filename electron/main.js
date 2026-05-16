const { app, BrowserWindow, Tray, Menu, nativeImage, ipcMain } = require('electron');
const path = require('path');
const { startPHP, stopPHP } = require('./services/php-server');
const { startWebSocketServer, stopWebSocketServer } = require('./services/websocket-server');
const { startMySQL, stopMySQL } = require('./services/mysql-server');
const { setupAutoUpdater } = require('./services/auto-updater');
const { startHttpsProxy, stopHttpsProxy } = require('./services/https-proxy');
const { startQZTray, stopQZTray } = require('./services/qz-tray');
const { ensureQZCerts, getQZCertificate, signQZMessage } = require('./services/qz-certs');

// Disable code signing auto-discovery to prevent build issues
process.env.CSC_IDENTITY_AUTO_DISCOVERY = 'false';

let mainWindow = null;
let tray = null;
let phpPort = 8080;
let mysqlPort = 3307; // Bundled MySQL port

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
  ipcMain.handle('get-version', () => app.getVersion());
  ipcMain.handle('qz-get-cert', () => getQZCertificate());
  ipcMain.handle('qz-sign', (_event, data) => signQZMessage(data));

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
  const splash = new BrowserWindow({
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
    await startPHP(phpPort, mysqlPort);
    await startHttpsProxy(phpPort, 8443);

    // تشغيل خادم الـ WebSocket
    startWebSocketServer();

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
      }
    });

    // تحميل الـ frontend عبر PHP server (يقدّم API + Frontend معاً)
    mainWindow.loadURL(`http://127.0.0.1:${phpPort}`);
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
    splash.close();
    // عرض رسالة خطأ
    const { dialog } = require('electron');
    dialog.showErrorBox('خطأ في التشغيل', err.message);
    app.quit();
  }
});

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
