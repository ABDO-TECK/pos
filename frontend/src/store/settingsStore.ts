import { create } from 'zustand'
import { getSettings } from '../api/endpoints'

interface SettingsState {
  storeName: string
  storeLogo: string | null
  taxEnabled: boolean
  taxRate: number
  loyaltyEnabled: boolean
  loyaltyPointsPerRial: number
  loyaltyRialPerPoint: number
  loaded: boolean
  fetchSettings: () => Promise<void>
  setSettings: (s: Partial<SettingsState>) => void
}

const useSettingsStore = create<SettingsState>((set) => ({
  storeName: 'سوبر ماركت',
  storeLogo: null,
  taxEnabled: false,
  taxRate: 15,
  loyaltyEnabled: false,
  loyaltyPointsPerRial: 1,
  loyaltyRialPerPoint: 0.01,
  loaded: false,

  fetchSettings: async () => {
    try {
      const res = await getSettings()
      const s = res.data.data as any
      set({
        storeName:  s.store_name  ?? 'سوبر ماركت',
        storeLogo:  s.store_logo  ?? null,
        taxEnabled: s.tax_enabled === '1' || s.tax_enabled === true,
        taxRate:    parseFloat(s.tax_rate ?? '15'),
        loyaltyEnabled: s.loyalty_enabled === '1' || s.loyalty_enabled === true,
        loyaltyPointsPerRial: parseInt(s.loyalty_points_per_rial ?? '1', 10),
        loyaltyRialPerPoint: parseFloat(s.loyalty_rial_per_point ?? '0.01'),
        loaded:     true,
      })
    } catch (err) { set({ loaded: true })
    }
  },

  setSettings: (s) => set(s),
}))

export default useSettingsStore
