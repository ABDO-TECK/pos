import { NavLink, useNavigate } from 'react-router-dom'
import {
  ShoppingCart, Package, Truck, BarChart2,
  Users, LogOut, Store, Receipt, Settings, X, UserCheck,
} from 'lucide-react'
import useAuthStore from '../../store/authStore'
import useSettingsStore from '../../store/settingsStore'
import toast from 'react-hot-toast'

const navItems = [
  { to: '/',           label: 'نقطة البيع',   icon: ShoppingCart, roles: ['admin', 'cashier'] },
  { to: '/sales',      label: 'سجل المبيعات', icon: Receipt,      roles: ['admin', 'cashier'] },
  { to: '/products',   label: 'المنتجات',     icon: Package,      roles: ['admin'] },
  { to: '/customers',  label: 'العملاء',       icon: UserCheck,    roles: ['admin'] },
  { to: '/suppliers',  label: 'الموردون',      icon: Truck,        roles: ['admin'] },
  { to: '/reports',    label: 'التقارير',      icon: BarChart2,    roles: ['admin'] },
  { to: '/users',      label: 'المستخدمون',   icon: Users,        roles: ['admin'] },
  { to: '/settings',   label: 'الإعدادات',    icon: Settings,     roles: ['admin'] },
]

export default function Sidebar({ onClose }) {
  const { user, logout } = useAuthStore()
  const { storeName }    = useSettingsStore()
  const navigate         = useNavigate()

  const handleLogout = async () => {
    await logout()
    toast.success('تم تسجيل الخروج')
    navigate('/login')
    onClose?.()
  }

  const visibleItems = navItems.filter(item => item.roles.includes(user?.role))

  return (
    <aside style={{
      width: '100%', height: '100vh', background: '#1a1d2e',
      display: 'flex', flexDirection: 'column', overflowY: 'auto',
    }}>

      {/* Logo row */}
      <div style={{
        padding: '1.25rem 1rem',
        display: 'flex', alignItems: 'center', gap: '0.6rem',
        borderBottom: '1px solid #2d3147',
        minHeight: '60px',
      }}>
        <Store size={24} color="#22c55e" style={{ flexShrink: 0 }} />
        <span className="sidebar-logo-text" style={{ color: '#fff', fontWeight: 700, fontSize: '1rem', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {storeName || 'نظام الكاشير'}
        </span>
        {/* Close button — mobile only */}
        <button
          onClick={onClose}
          style={{
            display: 'none',
            background: 'transparent', border: 'none',
            color: '#9ca3af', cursor: 'pointer', padding: '0.3rem',
            borderRadius: '0.3rem', flexShrink: 0,
          }}
          className="sidebar-close-btn"
          aria-label="إغلاق القائمة"
        >
          <X size={18} />
        </button>
      </div>

      {/* Nav links */}
      <nav style={{ flex: 1, padding: '0.75rem 0.5rem', display: 'flex', flexDirection: 'column', gap: '0.2rem' }}>
        {visibleItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            onClick={onClose}
            style={({ isActive }) => ({
              display: 'flex', alignItems: 'center', gap: '0.7rem',
              padding: '0.65rem 0.75rem', borderRadius: '0.4rem',
              color: isActive ? '#22c55e' : '#9ca3af',
              background: isActive ? 'rgba(34,197,94,.12)' : 'transparent',
              fontWeight: isActive ? 600 : 400,
              fontSize: '0.9rem', transition: 'all .15s',
              textDecoration: 'none',
            })}
          >
            <item.icon size={18} style={{ flexShrink: 0 }} />
            <span className="nav-label">{item.label}</span>
          </NavLink>
        ))}
      </nav>

      {/* User info + logout */}
      <div style={{ padding: '0.85rem 1rem', borderTop: '1px solid #2d3147' }}>
        <div className="user-info" style={{ color: '#9ca3af', fontSize: '0.8rem', marginBottom: '0.5rem' }}>
          <div style={{ color: '#fff', fontWeight: 600 }}>{user?.name}</div>
          <div>{user?.role === 'admin' ? 'مدير النظام' : 'كاشير'}</div>
        </div>
        <button
          onClick={handleLogout}
          style={{
            display: 'flex', alignItems: 'center', gap: '0.5rem',
            background: 'transparent', border: 'none',
            color: '#ef4444', cursor: 'pointer',
            fontSize: '0.9rem', padding: '0.4rem 0',
          }}
        >
          <LogOut size={16} /> تسجيل الخروج
        </button>
      </div>
    </aside>
  )
}
