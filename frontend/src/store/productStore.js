import { create } from 'zustand'
import { getProducts, getCategories } from '../api/endpoints'
import { saveProductsToIDB, getProductsFromIDB, getProductByBarcodeFromIDB } from '../utils/idb'

const useProductStore = create((set, get) => ({
  products: [],
  categories: [],
  loading: false,
  lastFetched: null,

  fetchProducts: async (params = {}) => {
    set({ loading: true })
    try {
      const res = await getProducts(params)
      const products = res.data.data
      set({ products, loading: false, lastFetched: Date.now() })
      // Cache to IndexedDB for offline
      if (!params.search && !params.category_id) {
        await saveProductsToIDB(products)
      }
      return products
    } catch {
      // Fallback to IndexedDB
      const cached = await getProductsFromIDB()
      set({ products: cached, loading: false })
      return cached
    }
  },

  fetchCategories: async () => {
    const res = await getCategories()
    set({ categories: res.data.data })
  },

  findByBarcode: async (barcode) => {
    // First check in memory
    const found = get().products.find((p) => p.barcode === barcode)
    if (found) return found
    // Then check IndexedDB
    const idbResult = await getProductByBarcodeFromIDB(barcode)
    if (idbResult) return idbResult
    // Finally hit API
    try {
      const res = await getProducts({ search: barcode })
      const product = res.data.data.find((p) => p.barcode === barcode)
      return product || null
    } catch {
      return null
    }
  },

  setProducts: (products) => set({ products }),
}))

export default useProductStore
