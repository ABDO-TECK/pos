import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PendingSaleRecord } from './idb'

const idbMocks = vi.hoisted(() => ({
  openDB: vi.fn(),
  records: new Map<IDBValidKey, PendingSaleRecord>(),
}))

vi.mock('idb', () => ({ openDB: idbMocks.openDB }))

import {
  classifyLegacyPendingSales,
  classifyPendingSaleForUpgrade,
  deletePendingSale,
  getPendingSale,
  getPendingSales,
  getSalesNeedingReview,
  nestCatalogProducts,
  updatePendingSaleStatus,
} from './idb'

const owner = { ownerUserId: 1, branchId: 10 }
const ownerIsActive = () => true

const pendingSale = (
  localId: number,
  ownerUserId: number | undefined,
  branchId: number | undefined,
  syncStatus: PendingSaleRecord['syncStatus'] = 'pending',
): PendingSaleRecord => ({
  localId,
  ownerUserId,
  branchId,
  savedAt: '2026-07-28T10:00:00.000Z',
  syncStatus,
})

const cloneRecord = (record: PendingSaleRecord): PendingSaleRecord => ({ ...record })

const fakePendingSalesDb = {
  getAllFromIndex: vi.fn(async () => [...idbMocks.records.values()].map(cloneRecord)),
  get: vi.fn(async (_storeName: string, localId: IDBValidKey) => {
    const record = idbMocks.records.get(localId)
    return record ? cloneRecord(record) : undefined
  }),
  transaction: vi.fn(() => {
    const store = {
      get: vi.fn(async (localId: IDBValidKey) => {
        const record = idbMocks.records.get(localId)
        return record ? cloneRecord(record) : undefined
      }),
      delete: vi.fn(async (localId: IDBValidKey) => {
        idbMocks.records.delete(localId)
      }),
      put: vi.fn(async (record: PendingSaleRecord) => {
        idbMocks.records.set(record.localId, cloneRecord(record))
      }),
    }
    return {
      objectStore: () => store,
      done: Promise.resolve(),
    }
  }),
}

beforeEach(() => {
  idbMocks.records.clear()
  idbMocks.openDB.mockResolvedValue(fakePendingSalesDb)
})

const product = (id: number, parentProductId: number | null = null): Product => ({
  id,
  barcode: `code-${id}`,
  name: `Product ${id}`,
  category_id: null,
  price: 10,
  cost: 5,
  quantity: 1,
  parent_product_id: parentProductId,
})

describe('product catalog IndexedDB cache', () => {
  it('rebuilds parent/size relationships from flat rows', () => {
    const nested = nestCatalogProducts([product(2, 1), product(1), product(3, 1)])
    expect(nested).toHaveLength(1)
    expect(nested[0].id).toBe(1)
    expect(nested[0].sizes?.map((size) => size.id)).toEqual([2, 3])
  })
})

describe('pending-sales IndexedDB upgrade', () => {
  it('preserves legacy sale data while classifying the record for review', () => {
    const legacy = {
      localId: 41,
      savedAt: '2026-01-02T03:04:05.000Z',
      items: [{ product_id: 9, quantity: 2, price: 12 }],
      payment_method: 'cash',
      retryCount: 2,
    }

    expect(classifyPendingSaleForUpgrade(legacy)).toEqual({
      ...legacy,
      syncStatus: 'review',
      reviewReason: 'legacy_unowned',
      lastError: 'Legacy offline sale has no owner or branch',
    })
  })

  it('leaves an already owned record unchanged', () => {
    const owned = { localId: 42, ownerUserId: 3, branchId: 8, items: [] }
    expect(classifyPendingSaleForUpgrade(owned)).toBe(owned)
  })

  it('walks existing records and updates only legacy records', async () => {
    const updates: Record<string, unknown>[] = []
    const secondCursor = {
      value: { localId: 2, ownerUserId: 8, branchId: 3 },
      update: async (value: Record<string, unknown>) => updates.push(value),
      continue: async () => null,
    }
    const firstCursor = {
      value: { localId: 1, items: [{ product_id: 5 }] },
      update: async (value: Record<string, unknown>) => updates.push(value),
      continue: async () => secondCursor,
    }

    await classifyLegacyPendingSales(firstCursor)
    expect(updates).toHaveLength(1)
    expect(updates[0]).toEqual(expect.objectContaining({
      localId: 1,
      syncStatus: 'review',
      reviewReason: 'legacy_unowned',
    }))
  })
})

describe('pending-sales IndexedDB authorization boundary', () => {
  beforeEach(() => {
    idbMocks.records.set(1, pendingSale(1, 1, 10, 'conflict'))
    idbMocks.records.set(2, pendingSale(2, 2, 10, 'conflict'))
    idbMocks.records.set(3, pendingSale(3, 1, 11, 'permanent'))
    idbMocks.records.set(4, pendingSale(4, undefined, undefined, 'review'))
  })

  it('enumerates only records owned by the exact user and branch', async () => {
    await expect(getPendingSales(owner)).resolves.toEqual([
      expect.objectContaining({ localId: 1 }),
    ])
    await expect(getSalesNeedingReview(owner)).resolves.toEqual([
      expect.objectContaining({ localId: 1 }),
    ])
  })

  it('does not expose ownerless or foreign records by local id', async () => {
    await expect(getPendingSale(2, owner)).resolves.toBeNull()
    await expect(getPendingSale(3, owner)).resolves.toBeNull()
    await expect(getPendingSale(4, owner)).resolves.toBeNull()
  })

  it('refuses to delete another owner or branch record', async () => {
    await expect(deletePendingSale(2, owner, ownerIsActive)).resolves.toBe(false)
    await expect(deletePendingSale(3, owner, ownerIsActive)).resolves.toBe(false)
    await expect(deletePendingSale(4, owner, ownerIsActive)).resolves.toBe(false)
    expect(idbMocks.records.has(2)).toBe(true)
    expect(idbMocks.records.has(3)).toBe(true)
    expect(idbMocks.records.has(4)).toBe(true)

    await expect(deletePendingSale(1, owner, ownerIsActive)).resolves.toBe(true)
    expect(idbMocks.records.has(1)).toBe(false)
  })

  it('refuses to update another owner or branch record', async () => {
    await expect(updatePendingSaleStatus(2, owner, ownerIsActive, 'pending')).resolves.toBe(false)
    await expect(updatePendingSaleStatus(3, owner, ownerIsActive, 'pending')).resolves.toBe(false)
    await expect(updatePendingSaleStatus(4, owner, ownerIsActive, 'pending')).resolves.toBe(false)
    expect(idbMocks.records.get(2)?.syncStatus).toBe('conflict')
    expect(idbMocks.records.get(3)?.syncStatus).toBe('permanent')
    expect(idbMocks.records.get(4)?.syncStatus).toBe('review')

    await expect(updatePendingSaleStatus(1, owner, ownerIsActive, 'pending')).resolves.toBe(true)
    expect(idbMocks.records.get(1)?.syncStatus).toBe('pending')
  })

  it('refuses owned mutations when that identity is no longer active', async () => {
    const inactiveOwner = () => false

    await expect(deletePendingSale(1, owner, inactiveOwner)).resolves.toBe(false)
    await expect(updatePendingSaleStatus(1, owner, inactiveOwner, 'pending')).resolves.toBe(false)
    expect(idbMocks.records.get(1)?.syncStatus).toBe('conflict')
  })
})
