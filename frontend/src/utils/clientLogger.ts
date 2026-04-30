/**
 * clientLogger — يرسل أخطاء الفرونت إند إلى الباك إند لحفظها في ملفات اللوج.
 *
 * يتم تجميع الأخطاء (batching) وإرسالها دفعة واحدة لتقليل الطلبات.
 * يتم تخزين الأخطاء مؤقتاً في ذاكرة المتصفح وإرسالها كل 5 ثوانٍ.
 */
import api from '../api/axios'

interface ClientLogEntry {
  level: 'error' | 'warning' | 'info'
  message: string
  context?: Record<string, unknown>
}

// ── تجميع الأخطاء ─────────────────────────────────────────────
const queue: ClientLogEntry[] = []
let flushTimer: ReturnType<typeof setTimeout> | null = null
const FLUSH_INTERVAL = 5_000 // 5 ثوانٍ
const MAX_QUEUE = 20

function scheduleFlush() {
  if (flushTimer) return
  flushTimer = setTimeout(flush, FLUSH_INTERVAL)
}

async function flush() {
  flushTimer = null
  if (queue.length === 0) return

  const batch = queue.splice(0, MAX_QUEUE)

  for (const entry of batch) {
    try {
      await api.post('/client-log', entry, {
        // @ts-ignore - custom config to suppress global error toast
        hideGlobalError: true,
      })
    } catch {
      // إذا فشل الإرسال، لا نعيد المحاولة لتجنب الحلقات اللانهائية
      console.warn('[clientLogger] Failed to send log entry:', entry.message)
    }
  }

  // إذا بقيت عناصر في الطابور، جدول دفعة أخرى
  if (queue.length > 0) {
    scheduleFlush()
  }
}

// ── API العامة ────────────────────────────────────────────────

function enqueue(entry: ClientLogEntry) {
  // تجنب تكرار نفس الخطأ
  const isDuplicate = queue.some(
    (e) => e.message === entry.message && e.level === entry.level,
  )
  if (isDuplicate) return

  queue.push(entry)

  if (queue.length >= MAX_QUEUE) {
    flush()
  } else {
    scheduleFlush()
  }
}

/**
 * تسجيل خطأ (Error).
 */
export function logError(message: string, context?: Record<string, unknown>) {
  console.error('[clientLogger]', message, context)
  enqueue({
    level: 'error',
    message,
    context: {
      url: window.location.href,
      userAgent: navigator.userAgent,
      timestamp: new Date().toISOString(),
      ...context,
    },
  })
}

/**
 * تسجيل تحذير (Warning).
 */
export function logWarning(message: string, context?: Record<string, unknown>) {
  console.warn('[clientLogger]', message, context)
  enqueue({
    level: 'warning',
    message,
    context: {
      url: window.location.href,
      timestamp: new Date().toISOString(),
      ...context,
    },
  })
}

/**
 * تسجيل معلومات (Info).
 */
export function logInfo(message: string, context?: Record<string, unknown>) {
  enqueue({
    level: 'info',
    message,
    context: {
      url: window.location.href,
      timestamp: new Date().toISOString(),
      ...context,
    },
  })
}

/**
 * إرسال جميع الأخطاء المتبقية فوراً (قبل إغلاق الصفحة مثلاً).
 */
export function flushNow() {
  flush()
}
