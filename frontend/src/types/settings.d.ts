declare global {
  interface AppSettings {
    store_name?: string;
    tax_rate?: string;
    prevent_negative_stock?: string;
    currency?: string;
    receipt_header?: string;
    receipt_footer?: string;
    [key: string]: string | undefined;
  }

  interface UpdateCheckResult {
    current_version: string | null;
    latest_version: string | null;
    has_update: boolean;
    released_at: string | null;
    changelog: { version: string; date: string; changes: string[] }[];
    requires_npm_install: boolean;
    status?: string;
    message?: string;
    checkedUrl?: string;
    errorCode?: string | null;
    details?: string | null;
    updates_disabled?: boolean;
    updates_unreachable?: boolean;
  }

  interface UpdateApplyResult {
    message: string;
    latest_version: string;
    changelog: { version: string; date: string; changes: string[] }[];
    logs: string[];
  }
}

export {};
