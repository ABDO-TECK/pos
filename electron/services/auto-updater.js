const { autoUpdater } = require('electron-updater');
const { dialog } = require('electron');

// تعطيل التحميل التلقائي — نريد التحكم يدوياً
autoUpdater.autoDownload = false;
autoUpdater.autoInstallOnAppQuit = true;

// تسجيل الأحداث في الـ console
autoUpdater.logger = require('electron').app.isPackaged ? null : console;

function setupAutoUpdater(mainWindow) {
  // لا تفحص التحديثات في وضع التطوير
  if (!require('electron').app.isPackaged) {
    console.log('[AutoUpdater] Skipping update check in dev mode');
    return;
  }

  // ══════════════════════════════════════════════════
  //  الأحداث
  // ══════════════════════════════════════════════════

  autoUpdater.on('checking-for-update', () => {
    console.log('[AutoUpdater] Checking for updates...');
  });

  autoUpdater.on('update-available', (info) => {
    console.log('[AutoUpdater] Update available:', info.version);

    dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'تحديث جديد متاح',
      message: `الإصدار ${info.version} متاح للتحميل.\nهل تريد تحميل التحديث الآن؟`,
      buttons: ['تحميل الآن', 'لاحقاً'],
      defaultId: 0,
    }).then((result) => {
      if (result.response === 0) {
        autoUpdater.downloadUpdate();
      }
    });
  });

  autoUpdater.on('update-not-available', () => {
    console.log('[AutoUpdater] App is up to date');
  });

  autoUpdater.on('download-progress', (progress) => {
    const percent = Math.round(progress.percent);
    console.log(`[AutoUpdater] Download: ${percent}%`);
    // إرسال نسبة التحميل للنافذة الرئيسية
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(progress.percent / 100);
    }
  });

  autoUpdater.on('update-downloaded', (info) => {
    console.log('[AutoUpdater] Update downloaded:', info.version);

    // إزالة شريط التقدم
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }

    dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'التحديث جاهز',
      message: `تم تحميل الإصدار ${info.version}.\nسيتم إعادة تشغيل التطبيق لتثبيت التحديث.`,
      buttons: ['إعادة التشغيل الآن', 'لاحقاً'],
      defaultId: 0,
    }).then((result) => {
      if (result.response === 0) {
        autoUpdater.quitAndInstall(false, true);
      }
    });
  });

  autoUpdater.on('error', (err) => {
    console.error('[AutoUpdater] Error:', err.message);
  });

  // ══════════════════════════════════════════════════
  //  بدء الفحص — بعد 10 ثوانٍ من فتح التطبيق
  // ══════════════════════════════════════════════════
  setTimeout(() => {
    autoUpdater.checkForUpdates().catch((err) => {
      console.error('[AutoUpdater] Check failed:', err.message);
    });
  }, 10000);

  // فحص كل 4 ساعات
  setInterval(() => {
    autoUpdater.checkForUpdates().catch(() => {});
  }, 4 * 60 * 60 * 1000);
}

module.exports = { setupAutoUpdater };
