const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  print: (html) => ipcRenderer.invoke('print-receipt', html),
  getPrinters: () => ipcRenderer.invoke('get-printers'),
  getApiPort: () => ipcRenderer.invoke('get-api-port'),
  getVersion: () => ipcRenderer.invoke('get-version'),
  getQZCert: () => ipcRenderer.invoke('qz-get-cert'),
  signQZMessage: (data) => ipcRenderer.invoke('qz-sign', data),
  platform: process.platform,
});
