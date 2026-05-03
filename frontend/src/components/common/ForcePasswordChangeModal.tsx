import { useState } from 'react'
import useAuthStore from '../../store/authStore'
import { updateUser } from '../../api/endpoints'
import toast from 'react-hot-toast'

export default function ForcePasswordChangeModal() {
  const { user, setUser } = useAuthStore()
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [loading, setLoading] = useState(false)

  if (user?.force_password_change !== 1) {
    return null
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (password.length < 6) {
      toast.error('كلمة المرور يجب أن تكون 6 أحرف على الأقل')
      return
    }
    if (password !== confirmPassword) {
      toast.error('كلمات المرور غير متطابقة')
      return
    }
    
    setLoading(true)
    try {
      await updateUser(user.id, {
        name: user.name,
        email: user.email,
        role: user.role,
        is_active: 1,
        password: password,
      } as any)
      sessionStorage.removeItem('fpc_reload')
      toast.success('تم تغيير كلمة المرور بنجاح')
      setUser({ ...user, force_password_change: 0 })
    } catch (err: any) {
      // Error handled by axios interceptor
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" style={{ zIndex: 9999 }}>
      <div className="modal-content" style={{ maxWidth: '400px' }}>
        <h2 style={{ marginBottom: '1rem', color: 'var(--color-danger)' }}>
          <i className="fa-solid fa-shield-halved" style={{ marginLeft: '0.5rem' }}></i>
          تغيير كلمة المرور إلزامي
        </h2>
        <p style={{ marginBottom: '1.5rem', color: 'var(--color-text-light)' }}>
          لأسباب أمنية، يجب عليك تغيير كلمة المرور الافتراضية قبل التمكن من استخدام النظام.
        </p>

        <form onSubmit={handleSubmit}>
          <div className="form-group" style={{ marginBottom: '1rem' }}>
            <label>كلمة المرور الجديدة</label>
            <input
              type="password"
              className="input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              required
              minLength={6}
            />
          </div>
          <div className="form-group" style={{ marginBottom: '1.5rem' }}>
            <label>تأكيد كلمة المرور</label>
            <input
              type="password"
              className="input"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              placeholder="••••••••"
              required
              minLength={6}
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', padding: '0.75rem' }}
            disabled={loading}
          >
            {loading ? <span className="spinner" /> : 'تحديث كلمة المرور والمتابعة'}
          </button>
        </form>
      </div>
    </div>
  )
}
