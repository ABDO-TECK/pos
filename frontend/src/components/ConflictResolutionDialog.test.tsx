import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ConflictResolutionDialog } from './ConflictResolutionDialog'
import { getSalesNeedingReview } from '../utils/idb'
import type { PendingSaleRecord } from '../utils/idb'
import useAuthStore from '../store/authStore'

vi.mock('../utils/idb', () => ({
  getSalesNeedingReview: vi.fn().mockResolvedValue([]),
  deletePendingSale: vi.fn(),
  isPendingSaleOwnedBy: (
    sale: Pick<PendingSaleRecord, 'ownerUserId' | 'branchId'>,
    owner: { ownerUserId: number; branchId: number },
  ) => sale.ownerUserId === owner.ownerUserId && sale.branchId === owner.branchId,
  updatePendingSaleStatus: vi.fn(),
}))

vi.mock('../utils/offlineSync', () => ({
  OFFLINE_SALES_UPDATED_EVENT: 'offline-sales-updated',
  syncPendingSales: vi.fn(),
}))

vi.mock('react-hot-toast', () => ({
  default: {
    loading: vi.fn(),
    success: vi.fn(),
  },
}))

const mockedGetSalesNeedingReview = vi.mocked(getSalesNeedingReview)
const reviewSale = (localId: number, ownerUserId: number, branchId: number): PendingSaleRecord => ({
  localId,
  ownerUserId,
  branchId,
  savedAt: '2026-07-28T10:00:00.000Z',
  syncStatus: 'conflict',
  lastError: `conflict-${localId}`,
})

describe('ConflictResolutionDialog online listener', () => {
  let addEventListenerSpy: ReturnType<typeof vi.spyOn>
  let removeEventListenerSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    vi.useFakeTimers()
    mockedGetSalesNeedingReview.mockResolvedValue([])
    useAuthStore.persist.setOptions({
      storage: {
        getItem: () => null,
        setItem: () => undefined,
        removeItem: () => undefined,
      },
    })
    useAuthStore.setState({
      user: {
        id: 1,
        name: 'Admin',
        email: 'admin@pos.test',
        role: 'admin',
        branch_id: 10,
      },
      isAuthenticated: true,
      _hasHydrated: true,
    })
    addEventListenerSpy = vi.spyOn(window, 'addEventListener')
    removeEventListenerSpy = vi.spyOn(window, 'removeEventListener')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('removes the exact listener and clears its pending timeout on every unmount', async () => {
    for (let iteration = 0; iteration < 3; iteration += 1) {
      const container = document.createElement('div')
      document.body.appendChild(container)
      const root = createRoot(container)

      await act(async () => {
        root.render(<ConflictResolutionDialog />)
      })

      const onlineRegistration = addEventListenerSpy.mock.calls
        .filter((call: unknown[]) => call[0] === 'online')
        .at(-1)
      expect(onlineRegistration).toBeDefined()

      act(() => {
        window.dispatchEvent(new Event('online'))
      })
      expect(vi.getTimerCount()).toBe(1)

      act(() => {
        root.unmount()
      })

      const onlineRemoval = removeEventListenerSpy.mock.calls
        .filter((call: unknown[]) => call[0] === 'online')
        .at(-1)
      expect(onlineRemoval?.[1]).toBe(onlineRegistration?.[1])
      expect(vi.getTimerCount()).toBe(0)
      container.remove()
    }

    const callsBeforeAdvancingTime = mockedGetSalesNeedingReview.mock.calls.length
    await vi.advanceTimersByTimeAsync(5000)
    expect(mockedGetSalesNeedingReview).toHaveBeenCalledTimes(callsBeforeAdvancingTime)
  })

  it('does not render foreign-user or foreign-branch records even for an admin', async () => {
    mockedGetSalesNeedingReview.mockResolvedValue([
      reviewSale(1, 1, 10),
      reviewSale(2, 2, 10),
      reviewSale(3, 1, 11),
    ])
    const container = document.createElement('div')
    document.body.appendChild(container)
    const root = createRoot(container)

    await act(async () => {
      root.render(<ConflictResolutionDialog />)
    })
    act(() => {
      container.querySelector('button')?.click()
    })

    expect(container.textContent).toContain('conflict-1')
    expect(container.textContent).not.toContain('conflict-2')
    expect(container.textContent).not.toContain('conflict-3')

    act(() => root.unmount())
    container.remove()
  })

  it('immediately hides stale records when the authenticated identity changes', async () => {
    mockedGetSalesNeedingReview.mockResolvedValue([reviewSale(1, 1, 10)])
    const container = document.createElement('div')
    document.body.appendChild(container)
    const root = createRoot(container)

    await act(async () => {
      root.render(<ConflictResolutionDialog />)
    })
    expect(container.textContent).toContain('1 للمراجعة')

    act(() => {
      useAuthStore.setState({
        user: {
          id: 2,
          name: 'Other cashier',
          email: 'other@pos.test',
          role: 'cashier',
          branch_id: 10,
        },
      })
    })
    expect(container.textContent).not.toContain('1 للمراجعة')

    act(() => root.unmount())
    container.remove()
  })
})
