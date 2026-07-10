const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  print: (html) => ipcRenderer.invoke('print-receipt', html),
  getPrinters: () => ipcRenderer.invoke('get-printers'),
  getApiPort: () => ipcRenderer.invoke('get-api-port'),
  getVersion: () => ipcRenderer.invoke('get-version'),
  getQZCert: () => ipcRenderer.invoke('qz-get-cert'),
  signQZMessage: (data) => ipcRenderer.invoke('qz-sign', data),
  platform: process.platform,

  // ── New: Window Controls ──
  minimizeWindow: () => ipcRenderer.invoke('window-minimize'),
  maximizeWindow: () => ipcRenderer.invoke('window-maximize'),
  closeWindow: () => ipcRenderer.invoke('window-close'),
  isMaximized: () => ipcRenderer.invoke('window-is-maximized'),

  // ── New: System Info ──
  getSystemInfo: () => ipcRenderer.invoke('get-system-info'),

  // ── New: File Operations ──
  showSaveDialog: (options) => ipcRenderer.invoke('show-save-dialog', options),
  saveFile: (filePath, data) => ipcRenderer.invoke('save-file', filePath, data),

  // ── New: Notifications ──
  showNotification: (title, body) => ipcRenderer.invoke('show-notification', title, body),

  // ── Manual Desktop Updates ──
  updater: {
    check: () => ipcRenderer.invoke('updater:check'),
    download: () => ipcRenderer.invoke('updater:download'),
    install: () => ipcRenderer.invoke('updater:install'),
    getStatus: () => ipcRenderer.invoke('updater:get-status'),
    onStatus: (callback) => {
      const listener = (_event, status) => callback(status);
      ipcRenderer.on('updater:status', listener);
      return () => ipcRenderer.removeListener('updater:status', listener);
    },
  },
});

contextBridge.exposeInMainWorld('posRuntime', {
  getApiBaseUrl: () => ipcRenderer.invoke('get-api-base-url'),
  getWsBaseUrl: () => ipcRenderer.invoke('get-ws-base-url'),
  getRuntimePorts: () => ipcRenderer.invoke('get-runtime-ports'),
});

contextBridge.exposeInMainWorld('posRecovery', {
  getLastError: () => ipcRenderer.invoke('recovery:get-last-error'),
  getDiagnostics: () => ipcRenderer.invoke('recovery:get-diagnostics'),
  retryStartup: () => ipcRenderer.invoke('recovery:retry-startup'),
  getRollbackReadiness: () => ipcRenderer.invoke('recovery:get-rollback-readiness'),
  runRollbackDryRun: () => ipcRenderer.invoke('recovery:run-rollback-dry-run'),
  executeRollback: (options) => ipcRenderer.invoke('recovery:execute-mysql-rollback', options),
  prepareRollbackRestoreStaging: (options) => ipcRenderer.invoke('recovery:prepare-rollback-restore-staging', options),
  runFinalRollbackSwitch: (options) => ipcRenderer.invoke('recovery:run-final-rollback-switch', options),
});
