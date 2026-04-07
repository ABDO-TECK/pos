import { useState, useEffect } from 'react'
import { Save, Download, Store, Percent, Database } from 'lucide-react'
import toast from 'react-hot-toast'
import { updateSettings, downloadBackup } from '../api/endpoints'
import useSettingsStore from '../store/settingsStore'

export default function Settings() {
  const { storeName, taxEnabled, taxRate, fetchSettings, setSettings } = useSettingsStore()

  const [form, setForm]       = useState({ store_name: '', tax_enabled: '1', tax_rate: '15' })
  const [saving, setSaving]   = useState(false)
  const [backing, setBacking] = useState(false)

  useEffect(() => {
    fetchSettings().then(() => {
      const s = useSettingsStore.getState()
      setForm({
        store_name:  s.storeName,
        tax_enabled: s.taxEnabled ? '1' : '0',
        tax_rate:    String(s.taxRate),
      })
    })
  }, [])

  const handleSave = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      await updateSettings(form)
      setSettings({
        storeName:  form.store_name,
        taxEnabled: form.tax_enabled === '1',
        taxRate:    parseFloat(form.tax_rate),
      })
      toast.success('تم حفظ الإعدادات')
    } catch {
      toast.error('فشل حفظ الإعدادات')
    } finally {
      setSaving(false)
    }
  }

  const handleBackup = async () => {
    setBacking(true)
    try {
      const res  = await downloadBackup()
      const url  = window.URL.createObjectURL(new Blob([res.data]))
      const link = document.createElement('a')
      link.href  = url
      link.download = `pos_backup_${new Date().toISOString().slice(0, 10)}.sql`
      link.click()
      window.URL.revokeObjectURL(url)
      toast.success('تم تحميل النسخة الاحتياطية')
    } catch {
      toast.error('فشل تحميل النسخة الاحتياطية')
    } finally {
      setBacking(false)
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem', maxWidth: '680px' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>الإعدادات</h1>

      <form onSubmit={handleSave} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>

        {/* ── Store Info ── */}
        <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <SectionTitle icon={<Store size={16}/>} label="معلومات المحل" />

          <div>
            <label style={labelStyle}>اسم المحل</label>
            <input
              type="text"
              className="input"
              value={form.store_name}
              onChange={e => setForm({ ...form, store_name: e.target.value })}
              placeholder="اسم المحل"
            />
            <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginTop: '0.3rem' }}>
              يظهر في الفاتورة وعنوان الشريط الجانبي
            </p>
          </div>
        </section>

        {/* ── Tax ── */}
        <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <SectionTitle icon={<Percent size={16}/>} label="إعدادات الضريبة" />

          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <p style={{ fontWeight: 600, fontSize: '0.95rem' }}>تفعيل الضريبة</p>
              <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>تطبيق ضريبة القيمة المضافة على المبيعات</p>
            </div>
            <Toggle
              checked={form.tax_enabled === '1'}
              onChange={() => setForm({ ...form, tax_enabled: form.tax_enabled === '1' ? '0' : '1' })}
            />
          </div>

          {form.tax_enabled === '1' && (
            <div>
              <label style={labelStyle}>نسبة الضريبة (%)</label>
              <input
                type="number"
                className="input"
                min="0"
                max="100"
                step="0.1"
                style={{ maxWidth: '160px' }}
                value={form.tax_rate}
                onChange={e => setForm({ ...form, tax_rate: e.target.value })}
              />
            </div>
          )}
        </section>

        <button type="submit" disabled={saving} className="btn btn-primary" style={{ alignSelf: 'flex-start' }}>
          {saving ? <span className="spinner" /> : <Save size={16}/>}
          {saving ? 'جاري الحفظ…' : 'حفظ الإعدادات'}
        </button>
      </form>

      {/* ── Backup ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<Database size={16}/>} label="النسخ الاحتياطي" />
        <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)' }}>
          تحميل نسخة احتياطية كاملة من قاعدة البيانات بصيغة SQL.
        </p>
        <button
          onClick={handleBackup}
          disabled={backing}
          className="btn btn-secondary"
          style={{ alignSelf: 'flex-start' }}
        >
          {backing ? <span className="spinner" /> : <Download size={16}/>}
          {backing ? 'جاري التحميل…' : 'تحميل نسخة احتياطية'}
        </button>
      </section>
    </div>
  )
}

function SectionTitle({ icon, label }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700, color: 'var(--primary-d)', fontSize: '0.95rem' }}>
      {icon} {label}
    </div>
  )
}

function Toggle({ checked, onChange }) {
  return (
    <button
      type="button"
      onClick={onChange}
      style={{
        position: 'relative',
        width: '44px', height: '24px',
        borderRadius: '9999px',
        border: 'none',
        background: checked ? 'var(--primary)' : 'var(--border)',
        cursor: 'pointer',
        transition: 'background .2s',
        flexShrink: 0,
      }}
    >
      <span style={{
        position: 'absolute',
        top: '3px',
        left: checked ? '23px' : '3px',
        width: '18px', height: '18px',
        borderRadius: '50%',
        background: '#fff',
        boxShadow: '0 1px 3px rgba(0,0,0,.2)',
        transition: 'left .2s',
      }} />
    </button>
  )
}

const labelStyle = {
  display: 'block',
  fontSize: '0.88rem',
  fontWeight: 600,
  color: 'var(--text)',
  marginBottom: '0.4rem',
}
