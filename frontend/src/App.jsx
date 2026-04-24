import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import { lazy, Suspense, useEffect } from 'react'
import Layout from './components/layout/Layout'
import ErrorBoundary from './components/ui/ErrorBoundary'
import useAuthStore from './store/authStore'
import useSettingsStore from './store/settingsStore'
import useThemeStore from './store/themeStore'

/**
 * Retry wrapper for lazy imports — handles transient network/SSL failures.
 * Retries up to `maxRetries` times with exponential delay before reloading the page.
 */
function lazyRetry(importFn, maxRetries = 2) {
  return lazy(() => {
    const attempt = (retriesLeft) =>
      importFn().catch((err) => {
        if (retriesLeft > 0) {
          return new Promise((resolve) => setTimeout(resolve, 500)).then(() =>
            attempt(retriesLeft - 1)
          )
        }
        // All retries failed — force a full page reload (clears stale chunks)
        if (!sessionStorage.getItem('chunk_reload')) {
          sessionStorage.setItem('chunk_reload', '1')
          window.location.reload()
        }
        throw err
      })
    return attempt(maxRetries)
  })
}

// Code splitting via lazy imports with retry
const Login     = lazyRetry(() => import('./pages/Login'))
const POS       = lazyRetry(() => import('./pages/POS'))
const Products  = lazyRetry(() => import('./pages/Products'))
const Suppliers = lazyRetry(() => import('./pages/Suppliers'))
const Reports   = lazyRetry(() => import('./pages/Reports'))
const Users     = lazyRetry(() => import('./pages/Users'))
const Sales     = lazyRetry(() => import('./pages/Sales'))
const Settings  = lazyRetry(() => import('./pages/Settings'))
const Customers = lazyRetry(() => import('./pages/Customers'))
const Expenses  = lazyRetry(() => import('./pages/Expenses'))

function PageLoader() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100vh' }}>
      <span className="spinner" style={{ width: '2rem', height: '2rem', borderWidth: '3px' }} />
    </div>
  )
}

function PrivateRoute({ children }) {
  const { isAuthenticated, _hasHydrated } = useAuthStore()
  if (!_hasHydrated) return <PageLoader />
  return isAuthenticated ? children : <Navigate to="/login" replace />
}

function AdminRoute({ children }) {
  const { user, _hasHydrated } = useAuthStore()
  if (!_hasHydrated) return <PageLoader />
  return user?.role === 'admin' ? children : <Navigate to="/" replace />
}

function SettingsLoader() {
  const { isAuthenticated } = useAuthStore()
  const fetchSettings = useSettingsStore(s => s.fetchSettings)
  useEffect(() => { if (isAuthenticated) fetchSettings() }, [isAuthenticated])
  return null
}

import ConfirmModal from './components/common/ConfirmModal'

function AppShell() {
  const themeMode = useThemeStore((s) => s.mode)
  const toastStyle = {
    fontFamily: 'Tajawal, sans-serif',
    fontSize: '0.9rem',
    direction: 'rtl',
    ...(themeMode === 'dark'
      ? { background: '#1f2937', color: '#f3f4f6', border: '1px solid #374151' }
      : {}),
  }

  return (
    <BrowserRouter>
      <ConfirmModal />
      <Toaster position="top-center" toastOptions={{ style: toastStyle }} />
      <SettingsLoader />
      <Suspense fallback={<PageLoader />}>
        <Routes>
          <Route path="/login" element={<Login />} />

          <Route path="/" element={
            <PrivateRoute>
              <Layout>
                <POS />
              </Layout>
            </PrivateRoute>
          } />

          <Route path="/products" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Products /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/suppliers" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Suppliers /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/reports" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Reports /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/expenses" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Expenses /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/users" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Users /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/sales" element={
            <PrivateRoute>
              <Layout><Sales /></Layout>
            </PrivateRoute>
          } />

          <Route path="/settings" element={
            <PrivateRoute>
              <AdminRoute>
                <Layout><Settings /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="/customers" element={
            <PrivateRoute>
              <Layout><Customers /></Layout>
            </PrivateRoute>
          } />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <AppShell />
    </ErrorBoundary>
  )
}
