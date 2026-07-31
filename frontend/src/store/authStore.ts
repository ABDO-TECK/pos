import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { login as loginApi, logout as logoutApi, getCsrfCookie } from '../api/endpoints'
import { setCsrfSignature } from '../api/axios'

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  branch_id: number;
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
  requireReauthentication: () => Promise<void>;
  setUser: (user: User | null) => void;
}

async function clearOfflineCache(): Promise<void> {
  try {
    const { clearAllCache } = await import('../utils/idb')
    await clearAllCache()
  } catch (error) {
    console.error('Failed to clear IDB cache on logout', error)
  }
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
        await clearOfflineCache()
        set({ user: null, token: null, isAuthenticated: false })
      },

      requireReauthentication: async () => {
        await clearOfflineCache()
        set({ user: null, token: null, isAuthenticated: false })
        window.location.assign('/login')
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
