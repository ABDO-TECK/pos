import { useState, useCallback } from 'react'
import { Menu, Store } from 'lucide-react'
import Sidebar from './Sidebar'
import useSettingsStore from '../../store/settingsStore'

export default function Layout({ children }) {
  const [open, setOpen] = useState(false)
  const { storeName } = useSettingsStore()

  const close = useCallback(() => setOpen(false), [])

  return (
    <div className="app-layout">

      {/* ── Mobile top header ── */}
      <header className="mobile-header">
        <button className="mh-btn" onClick={() => setOpen(true)} aria-label="القائمة">
          <Menu size={22} />
        </button>
        <Store size={20} color="#22c55e" />
        <span className="mh-title">{storeName || 'نظام الكاشير'}</span>
      </header>

      {/* ── Sidebar overlay (mobile) ── */}
      <div
        className={`sidebar-overlay${open ? ' open' : ''}`}
        onClick={close}
        aria-hidden="true"
      />

      {/* ── Sidebar ── */}
      <div className={`sidebar-wrap${open ? ' open' : ''}`}>
        <Sidebar onClose={close} />
      </div>

      {/* ── Page content ── */}
      <main className="main-content">
        {children}
      </main>

    </div>
  )
}
