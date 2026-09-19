import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'

const mocks = vi.hoisted(() => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('react-hot-toast', () => ({ default: mocks.toast }))

vi.mock('../api/endpoints', () => ({
  getProducts: vi.fn(),
  getProductCatalogPage: vi.fn(),
  getCategories: vi.fn(),
  getProductByBarcode: vi.fn(),
}))

vi.mock('../utils/idb', () => ({
  applyProductCatalogPage: vi.fn(),
  getProductCatalogState: vi.fn(),
  getProductsFromIDB: vi.fn(),
  getProductByBarcodeFromIDB: vi.fn(),
}))

vi.mock('./authStore', () => ({
  default: {
    getState: () => ({ user: { branch_id: 1 } }),
  },
}))

function makeAxiosError(status?: number, data?: unknown, message = 'Request failed'): AxiosError {
  const err = new AxiosError(message)
  if (status !== undefined) {
    err.response = {
      status,
      statusText: String(status),
      data,
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
  }
  return err
}

import { getProductCatalogPage } from '../api/endpoints'
import {
  applyProductCatalogPage,
  getProductCatalogState,
  getProductsFromIDB,
} from '../utils/idb'
import useProductStore from './productStore'

const changedProduct: Product = {
  id: 10,
  barcode: 'updated-10',
  name: 'Updated product',
  category_id: null,
  price: 20,
  cost: 10,
  quantity: 4,
}

const catalogPage = (checkpoint: string, hasMore: boolean): ProductCatalogPage => ({
  products: [changedProduct],
  scope: 'branch:1',
  version: 9,
  pagination: {
    type: 'cursor',
    mode: 'snapshot',
    limit: 500,
    hasMore,
    truncated: hasMore,
    reset: false,
    nextCheckpoint: checkpoint,
  },
})

describe('product catalog synchronization', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProductStore.setState({
      products: [],
      categories: [],
      loading: false,
      lastFetched: null,
    })
  })

  it('resumes from its checkpoint and requests only the delta page', async () => {
    vi.mocked(getProductCatalogState).mockResolvedValue({
      checkpoint: 'checkpoint-7',
      complete: true,
    })
    vi.mocked(getProductCatalogPage).mockResolvedValue({
      products: [
        changedProduct,
        { id: 11, _deleted: true } as Product,
      ],
      scope: 'branch:1',
      version: 9,
      pagination: {
        type: 'cursor',
        mode: 'delta',
        limit: 500,
        hasMore: false,
        truncated: false,
        reset: false,
        nextCheckpoint: 'checkpoint-9',
      },
    })
    vi.mocked(applyProductCatalogPage).mockResolvedValue()
    vi.mocked(getProductsFromIDB).mockResolvedValue([changedProduct])

    const result = await useProductStore.getState().fetchProducts({}, true)

    expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
    expect(getProductCatalogPage).toHaveBeenCalledWith('checkpoint-7')
    expect(applyProductCatalogPage).toHaveBeenCalledWith(expect.objectContaining({
      products: expect.arrayContaining([
        expect.objectContaining({ id: 10 }),
        expect.objectContaining({ id: 11, _deleted: true }),
      ]),
      checkpoint: 'checkpoint-9',
      reset: false,
    }))
    expect(result).toEqual([changedProduct])
  })

  it('continues cursor synchronization beyond 50,000 products', async () => {
    vi.mocked(getProductCatalogState).mockResolvedValue({ checkpoint: null, complete: false })
    vi.mocked(getProductCatalogPage).mockImplementation(async () => {
      const pageNumber = vi.mocked(getProductCatalogPage).mock.calls.length
      return catalogPage(`checkpoint-${pageNumber}`, pageNumber <= 100)
    })
    vi.mocked(applyProductCatalogPage).mockResolvedValue()
    vi.mocked(getProductsFromIDB).mockResolvedValue([changedProduct])

    await useProductStore.getState().fetchProducts({}, true)

    expect(getProductCatalogPage).toHaveBeenCalledTimes(101)
    expect(getProductCatalogPage).toHaveBeenLastCalledWith('checkpoint-100')
    expect(applyProductCatalogPage).toHaveBeenCalledTimes(101)
  })

  it('falls back to the cached catalog when the next checkpoint is missing', async () => {
    vi.mocked(getProductCatalogState).mockResolvedValue({ checkpoint: null, complete: false })
    vi.mocked(getProductCatalogPage).mockResolvedValue({
      ...catalogPage('unused', true),
      pagination: { ...catalogPage('unused', true).pagination, nextCheckpoint: '' },
    })

    await expect(useProductStore.getState().fetchProducts({}, true))
      .resolves.toEqual([changedProduct])
    expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
    expect(applyProductCatalogPage).not.toHaveBeenCalled()
  })

  it('falls back to the cached catalog when a checkpoint repeats', async () => {
    vi.mocked(getProductCatalogState).mockResolvedValue({ checkpoint: null, complete: false })
    vi.mocked(getProductCatalogPage).mockResolvedValue(catalogPage('checkpoint-1', true))

    await expect(useProductStore.getState().fetchProducts({}, true))
      .resolves.toEqual([changedProduct])
    expect(getProductCatalogPage).toHaveBeenCalledTimes(2)
    expect(applyProductCatalogPage).toHaveBeenCalledTimes(1)
  })

  describe('checkpoint error handling and resilience (Cases A - G)', () => {
    it('Case A: Invalid checkpoint -> HTTP 422 -> successful snapshot retry: no error toast, products loaded, new checkpoint persisted', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'stale-checkpoint-xyz',
        complete: true,
      })
      vi.mocked(getProductCatalogPage)
        .mockRejectedValueOnce(makeAxiosError(422, { message: 'Invalid catalog checkpoint.' }))
        .mockResolvedValueOnce(catalogPage('fresh-checkpoint-new', false))
      vi.mocked(applyProductCatalogPage).mockResolvedValue()
      vi.mocked(getProductsFromIDB).mockResolvedValue([changedProduct])

      const result = await useProductStore.getState().fetchProducts({}, true)

      expect(getProductCatalogPage).toHaveBeenCalledTimes(2)
      expect(getProductCatalogPage).toHaveBeenNthCalledWith(1, 'stale-checkpoint-xyz')
      expect(getProductCatalogPage).toHaveBeenNthCalledWith(2, undefined)
      expect(applyProductCatalogPage).toHaveBeenCalledWith(expect.objectContaining({
        checkpoint: 'fresh-checkpoint-new',
      }))
      expect(result).toEqual([changedProduct])
      expect(mocks.toast.error).not.toHaveBeenCalled()
    })

    it('Case B: Invalid checkpoint -> HTTP 422 -> snapshot retry fails: meaningful error displayed, not silently swallowed', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'stale-checkpoint-xyz',
        complete: true,
      })
      vi.mocked(getProductCatalogPage)
        .mockRejectedValueOnce(makeAxiosError(422, { message: 'Invalid catalog checkpoint.' }))
        .mockRejectedValueOnce(makeAxiosError(500, { message: 'Database query failure' }))
      vi.mocked(getProductsFromIDB).mockResolvedValue([])

      await expect(useProductStore.getState().fetchProducts({}, true)).rejects.toThrow()

      expect(getProductCatalogPage).toHaveBeenCalledTimes(2)
      expect(mocks.toast.error).toHaveBeenCalledWith(expect.stringMatching(/Database query failure|فشل/))
      expect(useProductStore.getState().lastFetched).toBeNull()
    })

    it('Case C: Catalog request fails with HTTP 500: meaningful error displayed', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'checkpoint-1',
        complete: true,
      })
      vi.mocked(getProductCatalogPage).mockRejectedValueOnce(
        makeAxiosError(500, { message: 'Internal Server Error' })
      )
      vi.mocked(getProductsFromIDB).mockResolvedValue([])

      await expect(useProductStore.getState().fetchProducts({}, true)).rejects.toThrow()

      expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
      expect(mocks.toast.error).toHaveBeenCalledWith(expect.stringMatching(/Internal Server Error|فشل/))
    })

    it('Case D: Catalog request fails because of a network error: meaningful error displayed', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: null,
        complete: false,
      })
      vi.mocked(getProductCatalogPage).mockRejectedValueOnce(
        makeAxiosError(undefined, undefined, 'Network Error')
      )
      vi.mocked(getProductsFromIDB).mockResolvedValue([])

      await expect(useProductStore.getState().fetchProducts({}, true)).rejects.toThrow()

      expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
      expect(mocks.toast.error).toHaveBeenCalledWith(expect.stringMatching(/Network Error|فشل/))
    })

    it('Case E: Valid checkpoint: normal incremental synchronization without retry or error toast', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'valid-checkpoint-123',
        complete: true,
      })
      vi.mocked(getProductCatalogPage).mockResolvedValueOnce({
        products: [changedProduct],
        scope: 'branch:1',
        version: 10,
        pagination: {
          type: 'cursor',
          mode: 'delta',
          limit: 500,
          hasMore: false,
          truncated: false,
          reset: false,
          nextCheckpoint: 'valid-checkpoint-124',
        },
      })
      vi.mocked(applyProductCatalogPage).mockResolvedValue()
      vi.mocked(getProductsFromIDB).mockResolvedValue([changedProduct])

      const result = await useProductStore.getState().fetchProducts({}, true)

      expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
      expect(getProductCatalogPage).toHaveBeenCalledWith('valid-checkpoint-123')
      expect(mocks.toast.error).not.toHaveBeenCalled()
      expect(result).toEqual([changedProduct])
    })

    it('Case F: HTTP 422 for an unrelated validation problem: not classified as a recoverable checkpoint error', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'some-checkpoint',
        complete: true,
      })
      vi.mocked(getProductCatalogPage).mockRejectedValueOnce(
        makeAxiosError(422, {
          message: 'Validation failed',
          errors: { limit: ['The limit must be between 1 and 500'] },
        })
      )
      vi.mocked(getProductsFromIDB).mockResolvedValue([])

      await expect(useProductStore.getState().fetchProducts({}, true)).rejects.toThrow()

      expect(getProductCatalogPage).toHaveBeenCalledTimes(1)
      expect(mocks.toast.error).toHaveBeenCalledWith(expect.stringMatching(/Validation failed|فشل/))
    })

    it('Case G: Retry still returns an invalid checkpoint error: stops after permitted retry, no infinite loop', async () => {
      vi.mocked(getProductCatalogState).mockResolvedValue({
        checkpoint: 'corrupt-checkpoint',
        complete: true,
      })
      vi.mocked(getProductCatalogPage)
        .mockRejectedValueOnce(makeAxiosError(422, { message: 'Invalid catalog checkpoint.' }))
        .mockRejectedValueOnce(makeAxiosError(422, { message: 'Invalid catalog checkpoint.' }))
      vi.mocked(getProductsFromIDB).mockResolvedValue([])

      await expect(useProductStore.getState().fetchProducts({}, true)).rejects.toThrow()

      expect(getProductCatalogPage).toHaveBeenCalledTimes(2)
      expect(mocks.toast.error).toHaveBeenCalledWith(expect.stringMatching(/Invalid catalog checkpoint|فشل/))
    })
  })
})
