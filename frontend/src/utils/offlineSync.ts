import {
  deletePendingSale,
  getPendingSale,
  getPendingSales,
  isPendingSaleOwnedBy,
  updatePendingSaleStatus,
} from './idb'
import type { OfflineSaleOwner, PendingSaleRecord } from './idb'
import { createSale } from '../api/endpoints'
import toast from 'react-hot-toast'

let syncing = false
const MAX_RETRIES = 3
export const OFFLINE_SALES_UPDATED_EVENT = 'offline-sales-updated'

function isSaleCreatePayload(value: unknown): value is SaleCreatePayload {
  if (!value || typeof value !== 'object') return false
  const payload = value as Record<string, unknown>
  return typeof payload.idempotency_key === 'string'
    && Array.isArray(payload.items)
    && typeof payload.discount === 'number'
    && typeof payload.payment_method === 'string'
    && typeof payload.amount_paid === 'number'
    && (payload.status === 'completed' || payload.status === 'reserved')
    && typeof payload.shipping_cost === 'number'
}

/** تحديد نوع الخطأ لمعرفة هل نعيد المحاولة أم لا */
export const classifyError = (err: unknown): 'auth' | 'retry' | 'conflict' | 'permanent' => {
  if (!err || typeof err !== 'object') return 'retry'
  const status = (err as { response?: { status?: number } })?.response?.status
  if (status === 401 || status === 403) return 'auth'
  // 409 = conflict, 422 = validation error, 404 = not found
  if (status === 409 || status === 422 || status === 404) return 'conflict'
  // 400 = bad request — لن تنجح أبداً
  if (status === 400) return 'permanent'
  // أي خطأ آخر (500, network) — أعد المحاولة
  return 'retry'
}

export const syncPendingSales = async (
  owner: OfflineSaleOwner,
  isOwnerActive: () => boolean = () => true,
): Promise<void> => {
  if (syncing || !navigator.onLine) return
  syncing = true

  try {
    const pending = (await getPendingSales(owner))
      .filter((sale) => isPendingSaleOwnedBy(sale, owner))
    if (pending.length === 0 || !isOwnerActive()) return

    console.log(`[OfflineSync] Syncing ${pending.length} pending sale(s)...`)
    let syncedCount = 0
    let failedCount = 0
    let conflictCount = 0

    for (const queuedSale of pending) {
      if (!isOwnerActive()) break

      const sale = await getPendingSale(queuedSale.localId, owner)
      if (!sale || !isPendingSaleOwnedBy(sale, owner) || !isOwnerActive()) continue

      const {
        localId,
        savedAt: _savedAt,
        syncStatus,
        lastError: _lastError,
        retryCount,
        lastAttempt: _lastAttempt,
        ownerUserId: _ownerUserId,
        branchId: _branchId,
        reviewReason: _reviewReason,
        idempotencyKey,
        ...storedSaleData
      } = sale as PendingSaleRecord
      const saleData: Record<string, unknown> = {
        ...storedSaleData,
        ...(typeof idempotencyKey === 'string' && storedSaleData.idempotency_key === undefined
          ? { idempotency_key: idempotencyKey }
          : {}),
      }

      // تخطي العمليات التي فشلت نهائياً أو تجاوزت الحد
      if (syncStatus === 'permanent' || syncStatus === 'review') continue
      if (((retryCount as number) ?? 0) >= MAX_RETRIES && syncStatus === 'failed') {
        console.warn(`[OfflineSync] Sale ${String(localId)} exceeded max retries, skipping.`)
        continue
      }

      try {
        if (!isSaleCreatePayload(saleData)) {
          throw new Error('Pending sale payload is incomplete')
        }
        if (!isOwnerActive()) break
        await createSale(saleData)
        if (!isOwnerActive()) break
        const deleted = await deletePendingSale(localId, owner, isOwnerActive)
        if (!deleted) continue
        console.log(`[OfflineSync] Sale ${String(localId)} synced and removed.`)
        syncedCount++
      } catch (err) {
        if (!isOwnerActive()) break

        const errType = classifyError(err)
        const errMsg = (err as Error).message || 'Unknown error'

        if (errType === 'auth') {
          console.warn(`[OfflineSync] Authentication paused sale ${String(localId)}; queue preserved.`)
          break
        } else if (errType === 'conflict') {
          const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'conflict', errMsg)
          if (!updated) break
          console.warn(`[OfflineSync] Sale ${String(localId)}: conflict — ${errMsg}`)
          conflictCount++
        } else if (errType === 'permanent') {
          const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'permanent', errMsg)
          if (!updated) break
          console.error(`[OfflineSync] Sale ${String(localId)}: permanent failure — ${errMsg}`)
          failedCount++
        } else {
          const updated = await updatePendingSaleStatus(localId, owner, isOwnerActive, 'failed', errMsg)
          if (!updated) break
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
    if (syncedCount > 0 || failedCount > 0 || conflictCount > 0) {
      window.dispatchEvent(new Event(OFFLINE_SALES_UPDATED_EVENT))
    }
  } finally {
    syncing = false
  }
}

// Auto-sync when coming back online
export const initOfflineSync = (
  owner: OfflineSaleOwner | null,
  isOwnerActive: () => boolean = () => true,
): (() => void) => {
  if (!owner) return () => undefined

  const handleOnline = () => {
    console.log('[OfflineSync] Back online, syncing...')
    void syncPendingSales(owner, isOwnerActive)
  }
  window.addEventListener('online', handleOnline)

  // Also try on startup
  const startupTimer = navigator.onLine
    ? window.setTimeout(() => void syncPendingSales(owner, isOwnerActive), 3000)
    : null

  return () => {
    window.removeEventListener('online', handleOnline)
    if (startupTimer !== null) window.clearTimeout(startupTimer)
  }
}

