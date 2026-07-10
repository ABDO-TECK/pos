import React from 'react'
import ReactDOM from 'react-dom/client'
import './store/themeStore'
import App from './App'
import './i18n'
// @ts-ignore
import './styles/_index.css'
import { initOfflineSync } from './utils/offlineSync.js'
import { logError, flushNow } from './utils/clientLogger'

initOfflineSync()

// ── تسجيل أخطاء JavaScript غير المعالجة ────────────────────────
window.addEventListener('error', (event) => {
  const isAppProtocol = window.location.protocol === 'app:'
  const msg = event.message || ''
  if (isAppProtocol && (msg.includes('ServiceWorker') || msg.includes('registerSW.js'))) {
    return
  }
  logError(msg || 'Unhandled error', {
    stack: event.error?.stack,
    url: event.filename,
    extra: `line: ${event.lineno}, col: ${event.colno}`,
  })
})

// ── تسجيل Promise rejections غير المعالجة ─────────────────────
window.addEventListener('unhandledrejection', (event) => {
  const isAppProtocol = window.location.protocol === 'app:'
  const reason = event.reason
  const msg = reason?.message || ''
  if (isAppProtocol && (msg.includes('ServiceWorker') || msg.includes('registerSW.js'))) {
    return
  }
  logError(msg || 'Unhandled promise rejection', {
    stack: reason?.stack,
  })
})

// ── إرسال اللوج قبل إغلاق الصفحة ─────────────────────────────
window.addEventListener('beforeunload', () => {
  flushNow()
})

// ── تسجيل الـ Service Worker للمتصفحات (وليس في Electron) ──
const isAppProtocol = window.location.protocol === 'app:';
if (!isAppProtocol && 'serviceWorker' in navigator) {
  import('virtual:pwa-register').then(({ registerSW }) => {
    registerSW({
      immediate: true,
      onOfflineReady() {
        console.log('[SW] App ready to work offline');
      },
      onRegisterError(error) {
        console.error('[SW] Service Worker registration failed:', error);
      }
    });
  }).catch((err) => {
    console.error('[SW] Failed to load PWA register module:', err);
  });
}

// منع تغيير الأرقام في حقول الإدخال عبر تحريك بكرة الماوس (scroll)
document.addEventListener('wheel', (e) => {
  const activeElement = document.activeElement as HTMLInputElement | null;
  if (activeElement?.type === 'number') {
    activeElement.blur()
  }
})

async function initApp() {
  if (window.posRuntime) {
    if (typeof window.posRuntime.getApiBaseUrl === 'function') {
      try {
        const url = await window.posRuntime.getApiBaseUrl();
        if (url) {
          (window as any).API_BASE_URL = url;
          console.log('[Init] Dynamic API base URL resolved:', url);
        }
      } catch (err) {
        console.warn('[Init] Failed to resolve dynamic API base URL:', err);
      }
    }

    if (typeof (window.posRuntime as any).getWsBaseUrl === 'function') {
      try {
        const wsUrl = await (window.posRuntime as any).getWsBaseUrl();
        if (wsUrl) {
          (window as any).WS_BASE_URL = wsUrl;
          console.log('[Init] Dynamic WS base URL resolved:', wsUrl);
        } else {
          (window as any).WS_BASE_URL = 'ws://127.0.0.1:8090';
        }
      } catch (err) {
        console.warn('[Init] Failed to resolve dynamic WS base URL:', err);
        (window as any).WS_BASE_URL = 'ws://127.0.0.1:8090';
      }
    } else {
      (window as any).WS_BASE_URL = 'ws://127.0.0.1:8090';
    }
  } else {
    (window as any).WS_BASE_URL = 'ws://127.0.0.1:8090';
  }

  ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  )
}

initApp();
