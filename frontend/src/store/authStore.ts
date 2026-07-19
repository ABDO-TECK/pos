import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { login as loginApi, logout as logoutApi, getCsrfCookie } from '../api/endpoints'
import { setCsrfSignature } from '../api/axios'

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  force_password_change?: number;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  _hasHydrated: boolean;
  setHasHydrated: (val: boolean) => void;
  login: (email: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  setUser: (user: User | null) => void;
}

const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      _hasHydrated: false,

      setHasHydrated: (val) => set({ _hasHydrated: val }),

      login: async (email, password) => {
        try {
          const csrfRes = await getCsrfCookie()
          const sig = csrfRes?.data?.data?.csrf_token ?? null
          setCsrfSignature(sig)
        } catch(e) {}
        const res = await loginApi({ email, password })
        const { user } = res.data.data as unknown as { user: User }
        set({ user, token: null, isAuthenticated: true })
        return user
      },

      logout: async () => {
        try { await logoutApi() } catch (err) { }
        
        // Clear IndexedDB cache to prevent ghost offline data
        try {
          const { clearAllCache } = await import('../utils/idb')
          await clearAllCache()
        } catch (e) {
          console.error('Failed to clear IDB cache on logout', e)
        }

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
