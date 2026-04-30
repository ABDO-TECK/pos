import { getPendingSales, deletePendingSale, updatePendingSaleStatus } from './idb'
import { createSale } from '../api/endpoints'
import toast from 'react-hot-toast'

let syncing = false
const MAX_RETRIES = 3

/** تحديد نوع الخطأ لمعرفة هل نعيد المحاولة أم لا */
const classifyError = (err: unknown): 'retry' | 'conflict' | 'permanent' => {
  if (!err || typeof err !== 'object') return 'retry'
  const status = (err as any)?.response?.status
  // 409 = conflict, 422 = validation error, 404 = not found
  if (status === 409 || status === 422 || status === 404) return 'conflict'
  // 400 = bad request — لن تنجح أبداً
  if (status === 400) return 'permanent'
  // أي خطأ آخر (500, network) — أعد المحاولة
  return 'retry'
}

export const syncPendingSales = async () => {
  if (syncing || !navigator.onLine) return
  syncing = true

  try {
    const pending = await getPendingSales()
    if (pending.length === 0) { syncing = false; return }

    console.log(`[OfflineSync] Syncing ${pending.length} pending sale(s)...`)
    let syncedCount = 0
    let failedCount = 0
    let conflictCount = 0

    for (const sale of pending) {
      const { localId, savedAt, syncStatus, lastError, retryCount, lastAttempt, ...saleData } =
        sale as Record<string, unknown> & { localId: IDBValidKey; savedAt: string }

      // تخطي العمليات التي فشلت نهائياً أو تجاوزت الحد
      if (syncStatus === 'permanent') continue
      if (((retryCount as number) ?? 0) >= MAX_RETRIES && syncStatus === 'failed') {
        console.warn(`[OfflineSync] Sale ${String(localId)} exceeded max retries, skipping.`)
        continue
      }

      try {
        await createSale(saleData)
        await deletePendingSale(localId)
        console.log(`[OfflineSync] Sale ${String(localId)} synced and removed.`)
        syncedCount++
      } catch (err) {
        const errType = classifyError(err)
        const errMsg = (err as Error).message || 'Unknown error'

        if (errType === 'conflict') {
          await updatePendingSaleStatus(localId, 'conflict', errMsg)
          console.warn(`[OfflineSync] Sale ${String(localId)}: conflict — ${errMsg}`)
          conflictCount++
        } else if (errType === 'permanent') {
          await updatePendingSaleStatus(localId, 'permanent', errMsg)
          console.error(`[OfflineSync] Sale ${String(localId)}: permanent failure — ${errMsg}`)
          failedCount++
        } else {
          await updatePendingSaleStatus(localId, 'failed', errMsg)
          console.error(`[OfflineSync] Sale ${String(localId)}: temporary failure — will retry`)
          failedCount++
        }
      }
    }

    if (syncedCount > 0) {
      toast.success(`تم مزامنة ${syncedCount} عملية بيع بنجاح بعد الاتصال`)
    }
    if (conflictCount > 0) {
      toast.error(`${conflictCount} عملية بيع بها تعارض — تحقق من البيانات`)
    }
    if (failedCount > 0) {
      toast.error(`فشل مزامنة ${failedCount} عملية بيع. سيتم المحاولة لاحقاً.`)
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

