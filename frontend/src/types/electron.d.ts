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

  updater?: {
    check: () => Promise<UpdaterStatus>;
    download: () => Promise<UpdaterStatus>;
    install: () => Promise<UpdaterStatus>;
    getStatus: () => Promise<UpdaterStatus>;
    onStatus: (callback: (status: UpdaterStatus) => void) => () => void;
  };
}

interface Window {
  electronAPI?: ElectronAPI;
}

interface UpdaterStatus {
  state:
    | 'idle'
    | 'checking'
    | 'update_available'
    | 'update_not_available'
    | 'downloading'
    | 'ready_to_install'
    | 'restarting'
    | 'developer_only'
    | 'error';
  isPackaged: boolean;
  updateInfo?: { version?: string; [key: string]: unknown } | null;
  progress?: { percent?: number; transferred?: number; total?: number } | null;
  error?: string | null;
  canInstall?: boolean;
}
