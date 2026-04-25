import { create } from 'zustand'
import { getSettings } from '../api/endpoints'

interface SettingsState {
  storeName: string
  taxEnabled: boolean
  taxRate: number
  loaded: boolean
  fetchSettings: () => Promise<void>
  setSettings: (s: Partial<SettingsState>) => void
}

const useSettingsStore = create<SettingsState>((set) => ({
  storeName: 'سوبر ماركت',
  taxEnabled: false,
  taxRate: 15,
  loaded: false,

  fetchSettings: async () => {
    try {
      const res = await getSettings()
      const s = res.data.data
      set({
        storeName:  s.store_name  ?? 'سوبر ماركت',
        taxEnabled: s.tax_enabled === '1' || s.tax_enabled === true,
        taxRate:    parseFloat(s.tax_rate ?? '15'),
        loaded:     true,
      })
    } catch {
      set({ loaded: true })
    }
  },

  setSettings: (s) => set(s),
}))

export default useSettingsStore
