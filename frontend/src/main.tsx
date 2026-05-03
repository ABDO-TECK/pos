import React from 'react'
import ReactDOM from 'react-dom/client'
import './store/themeStore'
import App from './App'
// @ts-ignore
import './index.css'
import { initOfflineSync } from './utils/offlineSync.js'
import { logError, flushNow } from './utils/clientLogger'

initOfflineSync()

// ── تسجيل أخطاء JavaScript غير المعالجة ────────────────────────
window.addEventListener('error', (event) => {
  logError(event.message || 'Unhandled error', {
    stack: event.error?.stack,
    url: event.filename,
    extra: `line: ${event.lineno}, col: ${event.colno}`,
  })
})

// ── تسجيل Promise rejections غير المعالجة ─────────────────────
window.addEventListener('unhandledrejection', (event) => {
  const reason = event.reason
  logError(reason?.message || 'Unhandled promise rejection', {
    stack: reason?.stack,
  })
})

// ── إرسال اللوج قبل إغلاق الصفحة ─────────────────────────────
window.addEventListener('beforeunload', () => {
  flushNow()
})

// منع تغيير الأرقام في حقول الإدخال عبر تحريك بكرة الماوس (scroll)
document.addEventListener('wheel', (e) => {
  const activeElement = document.activeElement as HTMLInputElement | null;
  if (activeElement?.type === 'number') {
    activeElement.blur()
  }
})

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
