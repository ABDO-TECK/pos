import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { login as loginApi, logout as logoutApi, getCsrfCookie } from '../api/endpoints'

const useAuthStore = create(
  persist(
    (set) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      _hasHydrated: false,

      setHasHydrated: (val) => set({ _hasHydrated: val }),

      login: async (email, password) => {
        try { await getCsrfCookie() } catch(e) {}
        const res = await loginApi({ email, password })
        const { token, user } = res.data.data
        localStorage.removeItem('pos_token') // Clear old ones if migrating
        set({ user, token: null, isAuthenticated: true })
        return user
      },

      logout: async () => {
        try { await logoutApi() } catch {}
        localStorage.removeItem('pos_token')
        set({ user: null, token: null, isAuthenticated: false })
      },

      setUser: (user) => set({ user }),
    }),
    {
      name: 'pos_auth',
      partialize: (s) => ({ user: s.user, isAuthenticated: s.isAuthenticated }),
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true)
      },
    }
  )
)

export default useAuthStore
