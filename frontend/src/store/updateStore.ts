import { create } from 'zustand'
import { checkUpdate } from '../api/endpoints'

interface ChangelogEntry {
  version: string;
  date: string;
  changes: string[];
}

interface UpdateData {
  has_update: boolean;
  updateAvailable?: boolean;
  latest_version: string | null;
  latestVersion?: string | null;
  current_version: string | null;
  currentVersion?: string | null;
  changelog: ChangelogEntry[];
  status?: string;
  message?: string;
  checkedUrl?: string;
  errorCode?: string | null;
  details?: string | null;
  updates_disabled?: boolean;
  updates_unreachable?: boolean;
}

interface UpdateState {
  hasUpdate: boolean;
  latestVersion: string | null;
  currentVersion: string | null;
  changelog: ChangelogEntry[];
  lastChecked: number | null;
  isChecking: boolean;
  updatesDisabled: boolean;
  updatesDisabledMessage: string;
  updatesUnreachable: boolean;
  updateErrorMessage: string;
  silentCheck: () => Promise<void>;
  forceCheck: () => Promise<UpdateData | undefined>;
}

const useUpdateStore = create<UpdateState>((set, get) => ({
  hasUpdate: false,
  latestVersion: null,
  currentVersion: null,
  changelog: [],
  lastChecked: null,
  isChecking: false,
  updatesDisabled: false,
  updatesDisabledMessage: '',
  updatesUnreachable: false,
  updateErrorMessage: '',

  silentCheck: async () => {
    // Only check once every 6 hours
    const now = Date.now()
    const last = get().lastChecked
    if (last && (now - last) < 6 * 60 * 60 * 1000) {
      return
    }

    set({ isChecking: true })
    try {
      const res = await checkUpdate({ hideGlobalError: true })
      const data = res.data.data as any
      
      let localVersion = data?.current_version || null;
      if (window.electronAPI && window.electronAPI.getVersion) {
        localVersion = await window.electronAPI.getVersion();
      }

      set({
        hasUpdate: data?.has_update || false,
        latestVersion: data?.latest_version || null,
        currentVersion: localVersion,
        changelog: data?.changelog || [],
        lastChecked: now,
        updatesDisabled: data?.updates_disabled || false,
        updatesDisabledMessage: data?.updates_disabled ? (data?.message || 'خادم التحديثات غير مهيأ.') : '',
        updatesUnreachable: data?.updates_unreachable || false,
        updateErrorMessage: data?.updates_unreachable ? formatUpdateError(data) : '',
      })
    } catch (err) {
      // Catch all errors quietly, never toast/throw/rethrow
      set({
        updatesUnreachable: true,
        updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
      })
    } finally {
      set({ isChecking: false })
    }
  },

  forceCheck: async () => {
    set({ isChecking: true })
    try {
      const res = await checkUpdate({ hideGlobalError: true })
      const data = res.data.data as any
      
      let localVersion = data?.current_version || null;
      if (window.electronAPI && window.electronAPI.getVersion) {
        localVersion = await window.electronAPI.getVersion();
      }

      const stateUpdate = {
        hasUpdate: data?.has_update || false,
        latestVersion: data?.latest_version || null,
        currentVersion: localVersion,
        changelog: data?.changelog || [],
        lastChecked: Date.now(),
        updatesDisabled: data?.updates_disabled || false,
        updatesDisabledMessage: data?.updates_disabled ? (data?.message || 'خادم التحديثات غير مهيأ.') : '',
        updatesUnreachable: data?.updates_unreachable || false,
        updateErrorMessage: data?.updates_unreachable ? formatUpdateError(data) : '',
      }
      set(stateUpdate)
      return { ...data, current_version: localVersion }
    } catch (err) {
      set({
        updatesUnreachable: true,
        updateErrorMessage: 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
      })
      throw err
    } finally {
      set({ isChecking: false })
    }
  }
}))

function formatUpdateError(data: Partial<UpdateData> | undefined): string {
  const baseMessage = data?.message || 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
  const errorCode = data?.errorCode || data?.status
  const details = data?.details
  const checkedUrl = data?.checkedUrl

  const technicalParts = [
    errorCode ? `code: ${errorCode}` : null,
    details ? `details: ${details}` : null,
    checkedUrl ? `url: ${checkedUrl}` : null,
  ].filter(Boolean)

  return technicalParts.length ? `${baseMessage} (${technicalParts.join(' | ')})` : baseMessage
}

export default useUpdateStore
