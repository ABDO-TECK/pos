import { NavLink, useNavigate } from 'react-router-dom'
import {
  ShoppingCart, Package, Truck, BarChart2,
  Users, LogOut, Store, Receipt, Settings, X, UserCheck, Moon, Sun,
} from 'lucide-react'
import useAuthStore from '../../store/authStore'
import useSettingsStore from '../../store/settingsStore'
import useThemeStore from '../../store/themeStore'
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
  const themeMode        = useThemeStore((s) => s.mode)
  const toggleTheme      = useThemeStore((s) => s.toggle)
  const navigate         = useNavigate()

  const handleLogout = async () => {
    await logout()
    toast.success('تم تسجيل الخروج')
    navigate('/login')
    onClose?.()
  }

  const visibleItems = navItems.filter(item => item.roles.includes(user?.role))

  return (
    <aside className="sidebar-panel">

      {/* Logo row */}
      <div className="sidebar-header">
        <Store size={24} color="#22c55e" style={{ flexShrink: 0 }} />
        <span className="sidebar-logo-text" style={{ color: '#fff', fontWeight: 700, fontSize: '1rem', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {storeName || 'نظام الكاشير'}
        </span>
        <button
          type="button"
          className="sidebar-icon-btn"
          onClick={toggleTheme}
          aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
          title={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
        >
          {themeMode === 'dark' ? <Sun size={20} /> : <Moon size={20} />}
        </button>
        {/* Close button — mobile only */}
        <button
          type="button"
          onClick={onClose}
          style={{ display: 'none' }}
          className="sidebar-close-btn sidebar-icon-btn"
          aria-label="إغلاق القائمة"
        >
          <X size={18} />
        </button>
      </div>

      {/* Nav links */}
      <nav className="sidebar-nav">
        {visibleItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            onClick={onClose}
            className={({ isActive }) =>
              `sidebar-link${isActive ? ' sidebar-link--active' : ''}`
            }
          >
            <item.icon size={18} style={{ flexShrink: 0 }} />
            <span className="nav-label">{item.label}</span>
          </NavLink>
        ))}
      </nav>

      {/* User info + logout */}
      <div className="sidebar-footer">
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
