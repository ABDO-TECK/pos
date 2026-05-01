const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  print: (html) => ipcRenderer.invoke('print-receipt', html),
  getPrinters: () => ipcRenderer.invoke('get-printers'),
  getApiPort: () => ipcRenderer.invoke('get-api-port'),
  platform: process.platform,
});
