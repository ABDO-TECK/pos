import { create } from 'zustand'
import { checkUpdate } from '../api/endpoints'

const useUpdateStore = create((set, get) => ({
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
      const data = res.data.data
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
      const data = res.data.data
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
