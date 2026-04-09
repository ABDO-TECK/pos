import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Store, Eye, EyeOff, Moon, Sun } from 'lucide-react'
import useAuthStore from '../store/authStore'
import useThemeStore from '../store/themeStore'
import toast from 'react-hot-toast'

export default function Login() {
  const [email, setEmail] = useState('admin@pos.com')
  const [password, setPassword] = useState('password')
  const [showPass, setShowPass] = useState(false)
  const [loading, setLoading] = useState(false)
  const { login, isAuthenticated, _hasHydrated } = useAuthStore()
  const themeMode = useThemeStore((s) => s.mode)
  const toggleTheme = useThemeStore((s) => s.toggle)
  const navigate = useNavigate()

  // Redirect if already logged in
  useEffect(() => {
    if (_hasHydrated && isAuthenticated) {
      navigate('/', { replace: true })
    }
  }, [_hasHydrated, isAuthenticated])

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    try {
      await login(email, password)
      toast.success('مرحبًا بك!')
      navigate('/')
    } catch (err) {
      toast.error(err.response?.data?.message || 'بيانات غير صحيحة')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <button
        type="button"
        className="login-theme-toggle"
        onClick={toggleTheme}
        aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
        title={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
      >
        {themeMode === 'dark' ? <Sun size={20} /> : <Moon size={20} />}
      </button>
      <div className="card" style={{ width: '380px', padding: '2rem', maxWidth: 'calc(100vw - 2rem)' }}>
        <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
          <Store size={40} color="var(--primary)" style={{ margin: '0 auto 0.75rem' }} />
          <h1 style={{ fontSize: '1.4rem', fontWeight: 700 }}>نظام الكاشير</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>تسجيل الدخول للمتابعة</p>
        </div>

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.35rem' }}>البريد الإلكتروني</label>
            <input
              type="email" className="input" required
              value={email} onChange={(e) => setEmail(e.target.value)}
              placeholder="admin@pos.com"
            />
          </div>

          <div>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.35rem' }}>كلمة المرور</label>
            <div style={{ position: 'relative' }}>
              <input
                type={showPass ? 'text' : 'password'} className="input" required
                value={password} onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                style={{ paddingLeft: '2.5rem' }}
              />
              <button
                type="button"
                onClick={() => setShowPass(!showPass)}
                style={{ position: 'absolute', top: '50%', left: '0.75rem', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}
              >
                {showPass ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </div>

          <button type="submit" className="btn btn-primary btn-lg" style={{ justifyContent: 'center', marginTop: '0.5rem' }} disabled={loading}>
            {loading ? <span className="spinner" /> : null}
            تسجيل الدخول
          </button>
        </form>

        <p style={{ textAlign: 'center', fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '1.5rem' }}>
          Admin: admin@pos.com / password<br />
          Cashier: cashier@pos.com / password
        </p>
      </div>
    </div>
  )
}
