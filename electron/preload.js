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

  // ── New: Auto Update ──
  onUpdateAvailable: (callback) => ipcRenderer.on('update-available', (_e, info) => callback(info)),
  onUpdateDownloaded: (callback) => ipcRenderer.on('update-downloaded', (_e, info) => callback(info)),
  installUpdate: () => ipcRenderer.invoke('install-update'),
});
