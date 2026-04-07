import { NavLink, useNavigate } from 'react-router-dom'
import { ShoppingCart, Package, Warehouse, Truck, BarChart2, Users, LogOut, Store, Receipt, Settings } from 'lucide-react'
import useAuthStore from '../../store/authStore'
import useSettingsStore from '../../store/settingsStore'
import toast from 'react-hot-toast'

const navItems = [
  { to: '/',          label: 'نقطة البيع',   icon: ShoppingCart, roles: ['admin', 'cashier'] },
  { to: '/sales',     label: 'سجل المبيعات', icon: Receipt,      roles: ['admin', 'cashier'] },
  { to: '/products',  label: 'المنتجات',     icon: Package,      roles: ['admin'] },
  { to: '/inventory', label: 'المخزون',       icon: Warehouse,    roles: ['admin'] },
  { to: '/suppliers', label: 'الموردون',      icon: Truck,        roles: ['admin'] },
  { to: '/reports',   label: 'التقارير',      icon: BarChart2,    roles: ['admin'] },
  { to: '/users',     label: 'المستخدمون',   icon: Users,        roles: ['admin'] },
  { to: '/settings',  label: 'الإعدادات',    icon: Settings,     roles: ['admin'] },
]

export default function Sidebar() {
  const { user, logout }   = useAuthStore()
  const { storeName }      = useSettingsStore()
  const navigate           = useNavigate()

  const handleLogout = async () => {
    await logout()
    toast.success('تم تسجيل الخروج')
    navigate('/login')
  }

  const visibleItems = navItems.filter((item) => item.roles.includes(user?.role))

  return (
    <aside style={{
      width: '220px', minHeight: '100vh', background: '#1a1d2e',
      display: 'flex', flexDirection: 'column', flexShrink: 0,
    }}>
      {/* Logo */}
      <div style={{ padding: '1.5rem 1rem', display: 'flex', alignItems: 'center', gap: '0.6rem', borderBottom: '1px solid #2d3147' }}>
        <Store size={26} color="#22c55e" />
        <span style={{ color: '#fff', fontWeight: 700, fontSize: '1.05rem' }}>{storeName || 'نظام الكاشير'}</span>
      </div>

      {/* Nav */}
      <nav style={{ flex: 1, padding: '1rem 0.5rem', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
        {visibleItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
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
            <item.icon size={18} />
            {item.label}
          </NavLink>
        ))}
      </nav>

      {/* User info + logout */}
      <div style={{ padding: '1rem', borderTop: '1px solid #2d3147' }}>
        <div style={{ color: '#9ca3af', fontSize: '0.8rem', marginBottom: '0.5rem' }}>
          <div style={{ color: '#fff', fontWeight: 600 }}>{user?.name}</div>
          <div>{user?.role === 'admin' ? 'مدير النظام' : 'كاشير'}</div>
        </div>
        <button
          onClick={handleLogout}
          style={{
            display: 'flex', alignItems: 'center', gap: '0.5rem',
            background: 'transparent', border: 'none', color: '#ef4444',
            cursor: 'pointer', fontSize: '0.9rem', padding: '0.4rem 0',
          }}
        >
          <LogOut size={16} /> تسجيل الخروج
        </button>
      </div>
    </aside>
  )
}
