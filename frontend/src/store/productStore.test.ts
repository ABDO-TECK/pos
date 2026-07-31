import { beforeEach, describe, expect, it, vi } from 'vitest'

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
})
