import { openDB } from 'idb'

const DB_NAME = 'pos_offline'
const DB_VERSION = 1

let dbPromise = null

const getDB = () => {
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, DB_VERSION, {
      upgrade(db) {
        if (!db.objectStoreNames.contains('products')) {
          const store = db.createObjectStore('products', { keyPath: 'id' })
          store.createIndex('barcode', 'barcode', { unique: true })
        }
        if (!db.objectStoreNames.contains('pending_sales')) {
          db.createObjectStore('pending_sales', { keyPath: 'localId', autoIncrement: true })
        }
      },
    })
  }
  return dbPromise
}

export const saveProductsToIDB = async (products) => {
  const db = await getDB()
  const tx = db.transaction('products', 'readwrite')
  await Promise.all([...products.map((p) => tx.store.put(p)), tx.done])
}

export const getProductsFromIDB = async () => {
  const db = await getDB()
  return db.getAll('products')
}

export const getProductByBarcodeFromIDB = async (barcode) => {
  const db = await getDB()
  return db.getFromIndex('products', 'barcode', barcode)
}

export const savePendingSale = async (saleData) => {
  const db = await getDB()
  return db.add('pending_sales', { ...saleData, savedAt: new Date().toISOString() })
}

export const getPendingSales = async () => {
  const db = await getDB()
  return db.getAll('pending_sales')
}

export const deletePendingSale = async (localId) => {
  const db = await getDB()
  return db.delete('pending_sales', localId)
}
