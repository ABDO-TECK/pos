import { openDB } from 'idb'
import type { IDBPDatabase } from 'idb'

const DB_NAME = 'pos_offline'
const DB_VERSION = 2

let dbPromise: Promise<IDBPDatabase> | null = null

const getDB = () => {
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, DB_VERSION, {
      upgrade(db, oldVersion) {
        // v1 stores
        if (!db.objectStoreNames.contains('products')) {
          const store = db.createObjectStore('products', { keyPath: 'id' })
          store.createIndex('barcode', 'barcode', { unique: true })
        }
        if (!db.objectStoreNames.contains('pending_sales')) {
          db.createObjectStore('pending_sales', { keyPath: 'localId', autoIncrement: true })
        }
        // v2 — cache metadata (timestamp لمعرفة تاريخ آخر تحديث)
        if (oldVersion < 2) {
          if (!db.objectStoreNames.contains('cache_meta')) {
            db.createObjectStore('cache_meta', { keyPath: 'key' })
          }
          if (!db.objectStoreNames.contains('customers')) {
            db.createObjectStore('customers', { keyPath: 'id' })
          }
        }
      },
    })
  }
  return dbPromise
}

// ── Products cache ──

export const saveProductsToIDB = async (products: Array<Product>): Promise<void> => {
  const db = await getDB()
  const tx = db.transaction(['products', 'cache_meta'], 'readwrite')
  const store = tx.objectStore('products')
  const meta  = tx.objectStore('cache_meta')
  // حفظ المنتجات
  for (const p of products) {
    store.put(p)
  }
  // حفظ timestamp آخر تحديث
  meta.put({ key: 'products_updated_at', value: Date.now() })
  await tx.done
}

export const getProductsFromIDB = async (): Promise<Array<Product>> => {
  const db = await getDB()
  return db.getAll('products')
}

export const getProductByBarcodeFromIDB = async (barcode: string): Promise<Product | null> => {
  const t = String(barcode).trim()
  const db = await getDB()
  const direct = await db.getFromIndex('products', 'barcode', t)
  if (direct) return direct
  const all = await db.getAll('products')
  return all.find((p) => String(p.box_barcode) === t || (Array.isArray(p.additional_barcodes) ? p.additional_barcodes as string[] : []).includes(t)) ?? null
}

// ── Customers cache ──

export const saveCustomersToIDB = async (customers: Array<Customer>): Promise<void> => {
  const db = await getDB()
  const tx = db.transaction(['customers', 'cache_meta'], 'readwrite')
  const store = tx.objectStore('customers')
  const meta  = tx.objectStore('cache_meta')
  for (const c of customers) {
    store.put(c)
  }
  meta.put({ key: 'customers_updated_at', value: Date.now() })
  await tx.done
}

export const getCustomersFromIDB = async (): Promise<Array<Customer>> => {
  const db = await getDB()
  return db.getAll('customers')
}

// ── Cache metadata ──

/** اقرأ timestamp آخر تحديث لنوع بيانات محدد */
export const getCacheTimestamp = async (key: string): Promise<number | null> => {
  const db = await getDB()
  const entry = await db.get('cache_meta', `${key}_updated_at`)
  return entry?.value ?? null
}

/** هل الكاش قديم؟ (أقدم من maxAgeMs مللي ثانية) */
export const isCacheStale = async (key: string, maxAgeMs: number = 5 * 60 * 1000): Promise<boolean> => {
  const ts = await getCacheTimestamp(key)
  if (!ts) return true
  return (Date.now() - ts) > maxAgeMs
}

// ── Pending sales ──

export const savePendingSale = async (saleData: Record<string, unknown>): Promise<any> => {
  const db = await getDB()
  return db.add('pending_sales', { ...saleData, savedAt: new Date().toISOString() })
}

export const getPendingSales = async (): Promise<Array<Record<string, unknown>>> => {
  const db = await getDB()
  return db.getAll('pending_sales')
}

export const deletePendingSale = async (localId: IDBValidKey): Promise<void> => {
  const db = await getDB()
  return db.delete('pending_sales', localId)
}

/** تحديث حالة عملية بيع معلقة (failed/pending) مع سبب الخطأ */
export const updatePendingSaleStatus = async (
  localId: IDBValidKey,
  status: 'pending' | 'failed' | 'conflict' | 'permanent',
  errorMsg?: string
): Promise<void> => {
  const db = await getDB()
  const sale = await db.get('pending_sales', localId)
  if (sale) {
    sale.syncStatus = status
    sale.lastError = errorMsg ?? null
    sale.retryCount = (sale.retryCount ?? 0) + 1
    sale.lastAttempt = new Date().toISOString()
    await db.put('pending_sales', sale)
  }
}

