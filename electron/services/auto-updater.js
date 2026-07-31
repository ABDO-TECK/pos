const { app, dialog, ipcMain } = require('electron');
const { autoUpdater } = require('electron-updater');

const CHANNEL = 'updater:status';

autoUpdater.autoDownload = false;
autoUpdater.autoInstallOnAppQuit = false;
autoUpdater.logger = app.isPackaged ? null : console;

let mainWindow = null;
let status = {
  state: 'idle',
  isPackaged: app.isPackaged,
  updateInfo: null,
  progress: null,
  error: null,
  canInstall: false,
};

function setupAutoUpdater(window) {
  mainWindow = window;
  registerUpdaterEvents();
  registerUpdaterIpc();
  publishStatus('idle');
}

function registerUpdaterEvents() {
  if (registerUpdaterEvents.registered) return;
  registerUpdaterEvents.registered = true;

  autoUpdater.on('checking-for-update', () => {
    publishStatus('checking');
  });

  autoUpdater.on('update-available', (info) => {
    publishStatus('update_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('update-not-available', (info) => {
    publishStatus('update_not_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('download-progress', (progress) => {
    publishStatus('downloading', { progress, error: null, canInstall: false });
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(Math.max(0, Math.min(1, (progress.percent || 0) / 100)));
    }
  });

  autoUpdater.on('update-downloaded', (info) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }
    publishStatus('ready_to_install', { updateInfo: info, progress: null, error: null, canInstall: true });
  });

  autoUpdater.on('error', (err) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }
    publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  });
}

function registerUpdaterIpc() {
  if (registerUpdaterIpc.registered) return;
  registerUpdaterIpc.registered = true;

  const assertTrustedRenderer = (event) => {
    const senderUrl = event.senderFrame?.url || '';
    if (!senderUrl.startsWith('app://pos-app/')) {
      throw new Error('Untrusted renderer');
    }
  };

  ipcMain.handle('updater:get-status', (event) => {
    assertTrustedRenderer(event);
    return status;
  });
  ipcMain.handle('updater:download', async (event) => {
    assertTrustedRenderer(event);
    return downloadUpdate();
  });
  ipcMain.handle('updater:install', async (event) => {
    assertTrustedRenderer(event);
    return installUpdate();
  });
}

async function checkForUpdates() {
  if (!app.isPackaged) {
    return publishStatus('developer_only', {
      error: 'Electron updater is available only in packaged production builds.',
    });
  }

  try {
    publishStatus('checking', { error: null });
    await autoUpdater.checkForUpdates();
    return status;
  } catch (err) {
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

async function downloadUpdate() {
  if (!app.isPackaged) {
    return publishStatus('developer_only', {
      error: 'Electron updater download is available only in packaged production builds.',
    });
  }

  try {
    if (status.state !== 'update_available') {
      await checkForUpdates();
      if (status.state !== 'update_available') {
        return status;
      }
    }

    publishStatus('downloading', { error: null, canInstall: false });
    await autoUpdater.downloadUpdate();
    return status;
  } catch (err) {
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

async function installUpdate() {
  if (!status.canInstall) {
    return publishStatus('error', {
      error: 'Update is not downloaded yet.',
      canInstall: false,
    });
  }

  const response = await dialog.showMessageBox(mainWindow, {
    type: 'question',
    title: 'تثبيت التحديث',
    message: 'تم تحميل التحديث. هل تريد إعادة تشغيل التطبيق الآن لتثبيته؟',
    buttons: ['إعادة التشغيل الآن', 'لاحقاً'],
    defaultId: 0,
    cancelId: 1,
  });

  if (response.response !== 0) {
    return status;
  }

  publishStatus('restarting', { error: null });
  autoUpdater.quitAndInstall(false, true);
  return status;
}

function publishStatus(state, patch = {}) {
  status = {
    ...status,
    state,
    isPackaged: app.isPackaged,
    ...patch,
  };

  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send(CHANNEL, status);
  }

  return status;
}

function normalizeUpdaterError(err) {
  const message = err && err.message ? err.message : String(err || 'Unknown updater error');
  const lower = message.toLowerCase();

  if (lower.includes('latest.yml') || lower.includes('latest-mac.yml')) {
    return `update metadata not found: missing latest.yml (${message})`;
  }

  if (lower.includes('404') || lower.includes('not found')) {
    return `no GitHub release found or update metadata not found (${message})`;
  }

  if (lower.includes('ssl') || lower.includes('certificate')) {
    return `network/SSL error: ${message}`;
  }

  return message;
}

module.exports = {
  setupAutoUpdater,
  checkForUpdates,
  downloadUpdate,
  installUpdate,
};
