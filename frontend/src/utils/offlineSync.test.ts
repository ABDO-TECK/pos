import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { OfflineSaleOwner, PendingSaleRecord } from './idb'

const mocks = vi.hoisted(() => ({
  createSale: vi.fn(),
  deletePendingSale: vi.fn(),
  getPendingSale: vi.fn(),
  getPendingSales: vi.fn(),
  updatePendingSaleStatus: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('../api/endpoints', () => ({ createSale: mocks.createSale }))
vi.mock('./idb', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./idb')>()),
  deletePendingSale: mocks.deletePendingSale,
  getPendingSale: mocks.getPendingSale,
  getPendingSales: mocks.getPendingSales,
  updatePendingSaleStatus: mocks.updatePendingSaleStatus,
}))
vi.mock('react-hot-toast', () => ({ default: mocks.toast }))

import { initOfflineSync, syncPendingSales } from './offlineSync'

const ownerA: OfflineSaleOwner = { ownerUserId: 1, branchId: 10 }
const queuedSale = (
  localId: number,
  ownerUserId: number | undefined,
  branchId: number | undefined,
): PendingSaleRecord => ({
  localId,
  ownerUserId,
  branchId,
  idempotencyKey: crypto.randomUUID(),
  savedAt: '2026-07-28T10:00:00.000Z',
  syncStatus: 'pending',
  retryCount: 0,
  items: [{ product_id: 5, quantity: 1, price: 20 }],
  discount: 0,
  payment_method: 'cash',
  amount_paid: 20,
  status: 'completed',
  shipping_cost: 0,
})

describe('offline sale ownership and authentication pauses', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useRealTimers()
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true })
    mocks.createSale.mockResolvedValue({ data: { data: {} } })
    mocks.deletePendingSale.mockResolvedValue(true)
    mocks.getPendingSale.mockResolvedValue(null)
    mocks.getPendingSales.mockResolvedValue([])
    mocks.updatePendingSaleStatus.mockResolvedValue(true)
  })

  it('submits only records owned by the exact user and branch', async () => {
    const ownedSale = queuedSale(1, 1, 10)
    mocks.getPendingSales.mockResolvedValue([
      ownedSale,
      queuedSale(2, 2, 10),
      queuedSale(3, 1, 11),
    ])
    mocks.getPendingSale.mockResolvedValue(ownedSale)

    await syncPendingSales(ownerA)
    expect(mocks.createSale).toHaveBeenCalledTimes(1)
    expect(mocks.createSale).toHaveBeenCalledWith(expect.objectContaining({
      idempotency_key: ownedSale.idempotencyKey,
    }))
    expect(mocks.deletePendingSale).toHaveBeenCalledWith(1, ownerA, expect.any(Function))
  })

  it.each([401, 403])('preserves retry state when the API returns %s', async (status) => {
    const sale = queuedSale(1, 1, 10)
    mocks.getPendingSales.mockResolvedValue([sale])
    mocks.getPendingSale.mockResolvedValue(sale)
    mocks.createSale.mockRejectedValue({ message: 'Authentication required', response: { status } })

    await syncPendingSales(ownerA)
    expect(mocks.updatePendingSaleStatus).not.toHaveBeenCalled()
    expect(mocks.deletePendingSale).not.toHaveBeenCalled()
  })

  it('never replays ownerless, foreign-user, or foreign-branch records from a stale query', async () => {
    mocks.getPendingSales.mockResolvedValue([
      queuedSale(1, undefined, undefined),
      queuedSale(2, 2, 10),
      queuedSale(3, 1, 11),
    ])

    await syncPendingSales(ownerA)

    expect(mocks.getPendingSale).not.toHaveBeenCalled()
    expect(mocks.createSale).not.toHaveBeenCalled()
    expect(mocks.deletePendingSale).not.toHaveBeenCalled()
    expect(mocks.updatePendingSaleStatus).not.toHaveBeenCalled()
  })

  it('re-checks record ownership immediately before sending', async () => {
    mocks.getPendingSales.mockResolvedValue([queuedSale(1, 1, 10)])
    mocks.getPendingSale.mockResolvedValue(queuedSale(1, 2, 10))

    await syncPendingSales(ownerA)

    expect(mocks.createSale).not.toHaveBeenCalled()
    expect(mocks.deletePendingSale).not.toHaveBeenCalled()
  })

  it('does not delete after a successful request when the active owner changes', async () => {
    const sale = queuedSale(1, 1, 10)
    mocks.getPendingSales.mockResolvedValue([sale])
    mocks.getPendingSale.mockResolvedValue(sale)
    let checks = 0

    await syncPendingSales(ownerA, () => {
      checks += 1
      return checks < 5
    })

    expect(mocks.createSale).toHaveBeenCalledTimes(1)
    expect(mocks.deletePendingSale).not.toHaveBeenCalled()
  })

  it('does not update retry state when the active owner changes during a failed request', async () => {
    const sale = queuedSale(1, 1, 10)
    mocks.getPendingSales.mockResolvedValue([sale])
    mocks.getPendingSale.mockResolvedValue(sale)
    mocks.createSale.mockRejectedValue(new Error('network error'))
    let checks = 0

    await syncPendingSales(ownerA, () => {
      checks += 1
      return checks < 5
    })

    expect(mocks.updatePendingSaleStatus).not.toHaveBeenCalled()
    expect(mocks.deletePendingSale).not.toHaveBeenCalled()
  })

  it('does not schedule or listen for sync without an owner', async () => {
    vi.useFakeTimers()
    const cleanup = initOfflineSync(null)
    window.dispatchEvent(new Event('online'))
    await vi.advanceTimersByTimeAsync(5000)
    cleanup()
    expect(mocks.getPendingSales).not.toHaveBeenCalled()
  })
})
