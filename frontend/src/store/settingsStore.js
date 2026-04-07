import { create } from 'zustand'
import { getSettings } from '../api/endpoints'

const useSettingsStore = create((set, get) => ({
  storeName: 'سوبر ماركت',
  taxEnabled: true,
  taxRate: 15,
  loaded: false,

  fetchSettings: async () => {
    try {
      const res = await getSettings()
      const s = res.data.data
      set({
        storeName:  s.store_name  ?? 'سوبر ماركت',
        taxEnabled: s.tax_enabled === '1' || s.tax_enabled === true,
        taxRate:    parseFloat(s.tax_rate ?? 15),
        loaded:     true,
      })
    } catch {
      set({ loaded: true })
    }
  },

  setSettings: (s) => set(s),
}))

export default useSettingsStore
