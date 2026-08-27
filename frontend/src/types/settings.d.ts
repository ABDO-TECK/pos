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

  interface ReleaseInfo {
    title: string | null;
    tag_name: string | null;
    changelog: { version?: string; date?: string; changes?: string[] }[] | string[];
    released_at: string | null;
    files_count: number | null;
    release_url: string | null;
    download_url: string | null;
    full_package_url?: string | null;
  }

  interface UpdateStateData {
    state: 'pending' | 'downloading' | 'verifying' | 'backing_up' | 'applying' | 'migrating' | 'completed' | 'failed' | 'rolled_back' | 'idle';
    started_at?: string;
    updated_at?: string;
    error?: string | null;
    applied_files?: string[];
    backup_snapshot?: string | null;
  }

  interface UpdateStatusData {
    current_version: string;
    latest_version: string | null;
    update_available: boolean;
    type: 'delta' | 'full';
    release_info: ReleaseInfo;
    update_state: UpdateStateData | null;
    interrupted_update?: {
      interrupted: boolean;
      state: string | null;
      snapshot_path: string | null;
      message: string | null;
      details: unknown;
    };
  }


  interface UpdateCheckResult {
    current_version: string | null;
    latest_version: string | null;
    has_update: boolean;
    released_at: string | null;
    changelog: { version: string; date: string; changes: string[] }[];
    requires_npm_install?: boolean;
    status?: string;
    message?: string;
    checkedUrl?: string;
    errorCode?: string | null;
    details?: string | null;
    updates_disabled?: boolean;
    updates_unreachable?: boolean;
    update_type?: 'delta' | 'full';
    is_delta?: boolean;
    files_count?: number | null;
    source?: string;
    release_tag?: string;
    manifest_url?: string | null;
    signature_url?: string | null;
    delta_url?: string | null;
    fallback_reason?: string | null;
  }

  interface UpdateApplyResult {
    message?: string;
    latest_version?: string;
    update_type?: string;
    applied_files?: string[];
    logs?: string[];
    job_id?: number;
    status?: string;
  }

  interface UpdateHistoryRecord {
    id: number;
    from_version: string;
    to_version: string;
    type: 'delta' | 'full';
    source: string | null;
    release_tag: string | null;
    status: 'success' | 'failed' | 'rolled_back';
    files_count: number;
    backup_path: string | null;
    download_url: string | null;
    error_message: string | null;
    created_at: string;
  }

  interface UpdateSnapshot {
    snapshot_name: string;
    snapshot_path: string;
    from_version: string;
    to_version: string;
    timestamp: string;
    files_count: number;
    has_db_backup: boolean;
    db_backup_path: string | null;
  }

  interface UpdateRollbackResult {
    ok: boolean;
    snapshot?: string;
    logs?: string[];
    error?: string | null;
  }

  interface UpdateJobResult {
    id: number;
    job_name: 'apply_update';
    status: 'pending' | 'processing' | 'completed' | 'failed';
    attempts: number;
    max_attempts: number;
    last_error: string | null;
    failure_code: number | null;
    created_at: string;
    completed_at: string | null;
  }
}

export {};
