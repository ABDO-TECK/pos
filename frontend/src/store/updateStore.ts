import { create } from 'zustand'
import { checkUpdate } from '../api/endpoints'

interface ChangelogEntry {
  version: string;
  date: string;
  changes: string[];
}

interface UpdateData {
  has_update: boolean;
  latest_version: string | null;
  current_version: string | null;
  changelog: ChangelogEntry[];
}

interface UpdateState {
  hasUpdate: boolean;
  latestVersion: string | null;
  currentVersion: string | null;
  changelog: ChangelogEntry[];
  lastChecked: number | null;
  isChecking: boolean;
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

  silentCheck: async () => {
    // Only check once every 6 hours
    const now = Date.now()
    const last = get().lastChecked
    if (last && (now - last) < 6 * 60 * 60 * 1000) {
      return
    }

    set({ isChecking: true })
    try {
      const res = await checkUpdate()
      const data = res.data.data as UpdateData
      set({
        hasUpdate: data.has_update,
        latestVersion: data.latest_version,
        currentVersion: data.current_version,
        changelog: data.changelog || [],
        lastChecked: now,
      })
    } catch {
      // Silently fail on background check
    } finally {
      set({ isChecking: false })
    }
  },

  forceCheck: async () => {
    set({ isChecking: true })
    try {
      const res = await checkUpdate()
      const data = res.data.data as UpdateData
      set({
        hasUpdate: data.has_update,
        latestVersion: data.latest_version,
        currentVersion: data.current_version,
        changelog: data.changelog || [],
        lastChecked: Date.now(),
      })
      return data
    } finally {
      set({ isChecking: false })
    }
  }
}))

export default useUpdateStore
