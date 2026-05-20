import { create } from 'zustand'
import { getProducts, getCategories, getProductByBarcode } from '../api/endpoints'
import {
  saveProductsToIDB,
  getProductsFromIDB,
  getProductByBarcodeFromIDB,
} from '../utils/idb'

/** مدة صلاحية الكاش: 5 ثواني (بدلاً من 5 دقائق لتحديث المخزون بسرعة) */
const CACHE_TTL_MS = 5 * 1000

interface FetchParams {
  search?: string;
  category_id?: number | string;
  low_stock?: boolean;
  [key: string]: string | number | boolean | undefined;
}

interface ProductState {
  products: Product[];
  categories: Category[];
  loading: boolean;
  lastFetched: number | null;
  fetchProducts: (params?: FetchParams, forceRefresh?: boolean) => Promise<Product[]>;
  fetchCategories: () => Promise<void>;
  findByBarcode: (barcode: string) => Promise<(Product & { scanned_as_box?: boolean }) | null>;
  setProducts: (products: Product[]) => void;
  invalidateCache: () => void;
}

const useProductStore = create<ProductState>((set, get) => ({
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
      // Fallback in case res.data is the array directly, or res.data.data is undefined
      let products = res.data?.data as Product[]
      if (!products) {
        products = Array.isArray(res.data) ? res.data : []
      }
      set({ products, loading: false, lastFetched: Date.now() })

      // حفظ في IDB عند تحميل كل المنتجات (بدون فلتر)
      if (!params.search && !params.category_id) {
        saveProductsToIDB(products).catch(() => {
          // تجاهل أخطاء IDB — ليست حرجة
        })
      }
      return products
    } catch (err: unknown) {
      // Fallback إلى IndexedDB عند فقد الشبكة
      try {
        const cached = await getProductsFromIDB()
        if (cached && cached.length > 0) {
          set({ products: cached, loading: false })
          console.info('[ProductStore] Loaded from offline cache:', cached.length, 'products')
          return cached
        }
      } catch (err) { // IDB أيضاً فشلت
      }
      set({ loading: false })
      throw err
    }
  },

  fetchCategories: async () => {
    const res = await getCategories({ limit: 999 })
    const raw = res.data.data
    const list: Category[] = Array.isArray(raw) ? (raw as Category[]) : ((raw as { data?: Category[] })?.data ?? [])
    set({ categories: list })
  },

  findByBarcode: async (barcode) => {
    const t = String(barcode).trim()
    const checkBox = (p: Product | null) => {
      if (!p) return null
      if (String(p.box_barcode) === t) {
        return { ...p, scanned_as_box: true } as Product & { scanned_as_box: boolean }
      }
      return p
    }

    const match = (p: Product) =>
      p.barcode === t || String(p.box_barcode) === t || (p.additional_barcodes || []).includes(t)
    
    const found = get().products.find(match)
    if (found) return checkBox(found)
    
    const idbResult = await getProductByBarcodeFromIDB(t)
    if (idbResult) return checkBox(idbResult)
    
    try {
      const res = await getProductByBarcode(t)
      return checkBox((res.data.data as Product) ?? null)
    } catch (err) { return null
    }
  },

  setProducts: (products) => set({ products }),

  /** إجبار تحديث الكاش من API */
  invalidateCache: () => set({ lastFetched: null }),
}))

export default useProductStore
