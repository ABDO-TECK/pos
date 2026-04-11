import { create } from 'zustand'
import { getProducts, getCategories, getProductByBarcode } from '../api/endpoints'
import {
  saveProductsToIDB,
  getProductsFromIDB,
  getProductByBarcodeFromIDB,
  isCacheStale,
} from '../utils/idb'

/** مدة صلاحية الكاش: 5 دقائق */
const CACHE_TTL_MS = 5 * 60 * 1000

const useProductStore = create((set, get) => ({
  products: [],
  categories: [],
  loading: false,
  lastFetched: null,

  /**
   * تحميل المنتجات:
   * 1. يحاول من API أولاً ويحفظ في IDB
   * 2. عند فشل الشبكة → يستخدم IDB كـ fallback
   * 3. يتخطى التحميل إذا كان الكاش حديثاً (أقل من 5 دقائق)
   */
  fetchProducts: async (params = {}, forceRefresh = false) => {
    const state = get()

    // تخطي إذا كان الكاش حديثاً (إلا أن يكون force أو بحث)
    if (!forceRefresh && !params.search && !params.category_id) {
      if (state.lastFetched && (Date.now() - state.lastFetched) < CACHE_TTL_MS) {
        return state.products
      }
    }

    set({ loading: true })
    try {
      const res = await getProducts(params)
      const products = res.data.data
      set({ products, loading: false, lastFetched: Date.now() })

      // حفظ في IDB عند تحميل كل المنتجات (بدون فلتر)
      if (!params.search && !params.category_id) {
        saveProductsToIDB(products).catch(() => {
          // تجاهل أخطاء IDB — ليست حرجة
        })
      }
      return products
    } catch (err) {
      // Fallback إلى IndexedDB عند فقد الشبكة
      try {
        const cached = await getProductsFromIDB()
        if (cached && cached.length > 0) {
          set({ products: cached, loading: false })
          console.info('[ProductStore] Loaded from offline cache:', cached.length, 'products')
          return cached
        }
      } catch {
        // IDB أيضاً فشلت
      }
      set({ loading: false })
      throw err
    }
  },

  fetchCategories: async () => {
    const res = await getCategories()
    set({ categories: res.data.data })
  },

  findByBarcode: async (barcode) => {
    const t = String(barcode).trim()
    const checkBox = (p) => {
      if (!p) return null
      if (String(p.box_barcode) === t) {
        return { ...p, scanned_as_box: true }
      }
      return p
    }

    const match = (p) =>
      p.barcode === t || String(p.box_barcode) === t || (p.additional_barcodes || []).includes(t)
    
    const found = get().products.find(match)
    if (found) return checkBox(found)
    
    const idbResult = await getProductByBarcodeFromIDB(t)
    if (idbResult) return checkBox(idbResult)
    
    try {
      const res = await getProductByBarcode(t)
      return checkBox(res.data.data ?? null)
    } catch {
      return null
    }
  },

  setProducts: (products) => set({ products }),

  /** إجبار تحديث الكاش من API */
  invalidateCache: () => set({ lastFetched: null }),
}))

export default useProductStore
