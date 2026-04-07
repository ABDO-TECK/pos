import { getPendingSales, deletePendingSale } from './idb'
import { createSale } from '../api/endpoints'

let syncing = false

export const syncPendingSales = async () => {
  if (syncing || !navigator.onLine) return
  syncing = true

  try {
    const pending = await getPendingSales()
    if (pending.length === 0) { syncing = false; return }

    console.log(`[OfflineSync] Syncing ${pending.length} pending sale(s)...`)

    for (const sale of pending) {
      try {
        const { localId, savedAt, ...saleData } = sale
        await createSale(saleData)
        await deletePendingSale(localId)
        console.log(`[OfflineSync] Sale ${localId} synced and removed.`)
      } catch (err) {
        console.error(`[OfflineSync] Failed to sync sale ${sale.localId}:`, err.message)
      }
    }
  } finally {
    syncing = false
  }
}

// Auto-sync when coming back online
export const initOfflineSync = () => {
  window.addEventListener('online', () => {
    console.log('[OfflineSync] Back online, syncing...')
    syncPendingSales()
  })

  // Also try on startup
  if (navigator.onLine) {
    setTimeout(syncPendingSales, 3000)
  }
}
