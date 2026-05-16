declare global {
  interface QZConfig {
    host?: string;
    signUrl?: string;
    certUrl?: string;
    port?: { host: string; port: number } | number;
    keepAlive?: number;
    retries?: number;
    delay?: number;
  }

  interface QZSecurity {
    setCertificatePromise: (promise: (resolve: (cert: string) => void, reject: (err: Error) => void) => void) => void;
    setSignaturePromise: (promise: (toSign: string) => (resolve: (signature: string) => void, reject: (err: Error) => void) => void) => void;
  }

  interface QZPrinters {
    find: (query?: string) => Promise<string | string[]>;
    getDefault: () => Promise<string>;
  }

  interface QZConfigs {
    create: (printer: string, options?: any) => any;
  }

  interface QZPrint {
    print: (config: any, data: any[]) => Promise<void>;
  }

  interface QZWebsocket {
    connect: (config?: QZConfig) => Promise<void>;
    disconnect: () => Promise<void>;
    isActive: () => boolean;
  }

  interface QZ {
    security: QZSecurity;
    printers: QZPrinters;
    configs: QZConfigs;
    print: QZPrint;
    websocket: QZWebsocket;
  }
}

export {};
