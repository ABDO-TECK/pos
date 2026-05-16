declare global {
  interface AppSettings {
    store_name?: string;
    tax_rate?: string;
    currency?: string;
    receipt_header?: string;
    receipt_footer?: string;
    [key: string]: string | undefined;
  }

  interface UpdateCheckResult {
    current_version: string;
    latest_version: string;
    has_update: boolean;
    released_at: string | null;
    changelog: { version: string; date: string; changes: string[] }[];
    requires_npm_install: boolean;
  }

  interface UpdateApplyResult {
    message: string;
    latest_version: string;
    changelog: { version: string; date: string; changes: string[] }[];
    logs: string[];
  }
}

export {};
