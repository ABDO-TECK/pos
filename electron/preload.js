const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  getVersion: () => ipcRenderer.invoke('get-version'),
  getQZCert: () => ipcRenderer.invoke('qz-get-cert'),
  signQZMessage: (data) => ipcRenderer.invoke('qz-sign', data),

  // ── Manual Desktop Updates ──
  updater: {
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
});
