import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  getMe: vi.fn(),
  logout: vi.fn(),
}))

vi.hoisted(() => {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: vi.fn(),
      setItem: vi.fn(),
      removeItem: vi.fn(),
    },
  })
})

vi.mock('../api/endpoints', () => ({
  getCsrfCookie: vi.fn(),
  getMe: mocks.getMe,
  login: vi.fn(),
  logout: mocks.logout,
}))

vi.mock('../utils/idb', () => ({
  clearAllCache: vi.fn().mockResolvedValue(undefined),
}))

import useAuthStore from './authStore'

describe('auth session refresh', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuthStore.setState({
      user: {
        id: 1,
        name: 'Administrator',
        email: 'admin@example.test',
        role: 'admin',
        branch_id: 1,
        force_password_change: 1,
      },
      token: null,
      isAuthenticated: true,
      _hasHydrated: true,
      sessionRefreshRequired: true,
    })
  })

  it('replaces stale persisted user flags with the authenticated backend user', async () => {
    mocks.getMe.mockResolvedValue({
      data: {
        data: {
          id: 1,
          name: 'Administrator',
          email: 'admin@example.test',
          role: 'admin',
          branch_id: 1,
          force_password_change: 0,
        },
      },
    })

    await useAuthStore.getState().refreshSession()

    expect(mocks.getMe).toHaveBeenCalledTimes(1)
    expect(useAuthStore.getState()).toMatchObject({
      isAuthenticated: true,
      user: expect.objectContaining({
        id: 1,
        force_password_change: 0,
      }),
      sessionRefreshRequired: false,
    })
  })

  it('clears the desktop session when the server logout request fails', async () => {
    mocks.logout.mockRejectedValueOnce(new Error('offline'))
    const clearSession = vi.fn().mockResolvedValue({ success: true })
    Object.defineProperty(window, 'electronAPI', {
      configurable: true,
      value: { auth: { clearSession } },
    })

    await useAuthStore.getState().logout()

    expect(clearSession).toHaveBeenCalledTimes(1)
    expect(useAuthStore.getState()).toMatchObject({
      user: null,
      isAuthenticated: false,
      sessionRefreshRequired: false,
    })
  })
})
