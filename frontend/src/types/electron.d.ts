interface ElectronAPI {
  print: (html: string) => Promise<void>;
  getPrinters: () => Promise<string[]>;
  getApiPort: () => Promise<number>;
  getVersion: () => Promise<string>;
  getQZCert: () => Promise<string>;
  signQZMessage: (data: string) => Promise<string>;
  platform: string;

  minimizeWindow: () => Promise<void>;
  maximizeWindow: () => Promise<void>;
  closeWindow: () => Promise<void>;
  isMaximized: () => Promise<boolean>;

  getSystemInfo: () => Promise<{ platform: string, arch: string, nodeVersion: string, electronVersion: string, memory: any }>;

  showSaveDialog: (options: any) => Promise<any>;
  saveFile: (filePath: string, data: any) => Promise<boolean>;

  showNotification: (title: string, body: string) => Promise<void>;

  onUpdateAvailable: (callback: (info: any) => void) => void;
  onUpdateDownloaded: (callback: (info: any) => void) => void;
  installUpdate: () => Promise<void>;
}

interface Window {
  electronAPI?: ElectronAPI;
}
