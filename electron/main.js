const { app, BrowserWindow, Tray, Menu, nativeImage } = require('electron');
const path = require('path');
const { startPHP, stopPHP } = require('./services/php-server');
const { startMySQL, stopMySQL } = require('./services/mysql-server');
const { setupAutoUpdater } = require('./services/auto-updater');

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

    // 4. فتح النافذة الرئيسية
    mainWindow = new BrowserWindow({
      width: 1280, height: 800,
      minWidth: 1024, minHeight: 600,
      icon: path.join(__dirname, 'assets', 'icon.png'),
      webPreferences: {
        preload: path.join(__dirname, 'preload.js'),
        contextIsolation: true,
        nodeIntegration: false,
      }
    });

    // تحميل الـ frontend عبر PHP server (يقدّم API + Frontend معاً)
    mainWindow.loadURL(`http://127.0.0.1:${phpPort}`);
    mainWindow.setMenu(null); // إخفاء القائمة العلوية

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
  stopPHP();
  await stopMySQL();
});
