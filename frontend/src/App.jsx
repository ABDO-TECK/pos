import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import { lazy, Suspense, useEffect } from 'react'
import Layout from './components/layout/Layout'
import useAuthStore from './store/authStore'
import useSettingsStore from './store/settingsStore'

// Code splitting via lazy imports
const Login     = lazy(() => import('./pages/Login'))
const POS       = lazy(() => import('./pages/POS'))
const Products  = lazy(() => import('./pages/Products'))
const Suppliers = lazy(() => import('./pages/Suppliers'))
const Reports   = lazy(() => import('./pages/Reports'))
const Users     = lazy(() => import('./pages/Users'))
const Sales     = lazy(() => import('./pages/Sales'))
const Settings  = lazy(() => import('./pages/Settings'))
const Customers = lazy(() => import('./pages/Customers'))

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

export default function App() {
  return (
    <BrowserRouter>
      <Toaster
        position="top-center"
        toastOptions={{ style: { fontFamily: 'Tajawal, sans-serif', fontSize: '0.9rem', direction: 'rtl' } }}
      />
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
              <AdminRoute>
                <Layout><Customers /></Layout>
              </AdminRoute>
            </PrivateRoute>
          } />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
