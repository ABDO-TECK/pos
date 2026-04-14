import { useState, useEffect, useRef } from 'react'
import { Save, Download, Upload, Store, Percent, Database, RefreshCw, CloudDownload, List } from 'lucide-react'
import toast from 'react-hot-toast'
import { updateSettings, downloadBackup, restoreBackup, applyUpdate } from '../api/endpoints'
import useSettingsStore from '../store/settingsStore'
import useUpdateStore from '../store/updateStore'

export default function Settings() {
  const { storeName, taxEnabled, taxRate, fetchSettings, setSettings } = useSettingsStore()

  const [form, setForm]       = useState({ store_name: '', tax_enabled: '0', tax_rate: '15' })
  const [saving, setSaving]   = useState(false)
  const [backing, setBacking] = useState(false)
  const [restoring, setRestoring] = useState(false)
  const restoreInputRef = useRef(null)

  // Update State
  const [applyingUpdate, setApplyingUpdate] = useState(false)
  const [showChangelog, setShowChangelog]   = useState(false)
  const { hasUpdate, currentVersion, latestVersion, changelog, isChecking, forceCheck } = useUpdateStore()

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

  const handleRestorePick = () => restoreInputRef.current?.click()

  const handleRestoreFile = async (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.sql')) {
      toast.error('اختر ملفاً بصيغة .sql')
      return
    }
    if (
      !confirm(
        'سيتم استبدال قاعدة البيانات الحالية بالكامل بمحتوى الملف. لن يمكن التراجع تلقائياً. هل تريد المتابعة؟'
      )
    ) {
      return
    }
    setRestoring(true)
    try {
      const fd = new FormData()
      fd.append('sql_file', file)
      await restoreBackup(fd)
      toast.success('تمت استعادة قاعدة البيانات')
      await fetchSettings()
      const s = useSettingsStore.getState()
      setForm({
        store_name: s.storeName,
        tax_enabled: s.taxEnabled ? '1' : '0',
        tax_rate: String(s.taxRate),
      })
    } catch (err) {
      toast.error(err.response?.data?.message || 'فشلت الاستعادة')
    } finally {
      setRestoring(false)
    }
  }

  const handleCheckUpdate = async () => {
    try {
      const data = await forceCheck()
      if (data?.has_update) {
        toast.success('تم العثور على تحديث جديد!')
        setShowChangelog(true)
      } else {
        toast.success('النظام محدّث لأحدث إصدار')
      }
    } catch {
      toast.error('فشل التحقق من التحديثات')
    }
  }

  const [updateLogs, setUpdateLogs]       = useState([])

  const handleApplyUpdate = async () => {
    if (!confirm('سيتم إنشاء نسخة احتياطية من قاعدة البيانات ثم تحديث ملفات النظام والمكتبات تلقائياً. هل أنت متأكد من رغبتك بالاستمرار؟ (قد يستغرق الأمر دقيقة أو اثنتين)')) return
    
    setApplyingUpdate(true)
    setUpdateLogs([])
    try {
      const res = await applyUpdate()
      const logs = res.data?.data?.logs || []
      setUpdateLogs(logs)
      toast.success('تم تطبيق التحديث بنجاح! جاري إعادة التحميل...')
      setTimeout(() => window.location.reload(), 3000)
    } catch (err) {
      const msg = err.response?.data?.message || 'فشل تطبيق التحديث.'
      const logs = err.response?.data?.errors?.logs || err.response?.data?.data?.logs || []
      setUpdateLogs(logs)
      toast.error(msg)
      if (Array.isArray(logs) && logs.length) {
        console.error('سجل التحديث:', logs)
      }
      const diag = err.response?.data?.errors?.diagnostics || err.response?.data?.data?.diagnostics
      if (diag) {
        console.error('تشخيص البيئة:', diag)
      }
      setApplyingUpdate(false)
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
          تحميل نسخة احتياطية كاملة من قاعدة البيانات بصيغة SQL، أو استعادة نسخة سابقة من ملف تم تصديره من هنا.
        </p>
        <input
          ref={restoreInputRef}
          type="file"
          accept=".sql,text/plain"
          style={{ display: 'none' }}
          onChange={handleRestoreFile}
        />
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
          <button
            type="button"
            onClick={handleBackup}
            disabled={backing || restoring}
            className="btn btn-secondary"
          >
            {backing ? <span className="spinner" /> : <Download size={16}/>}
            {backing ? 'جاري التحميل…' : 'تحميل نسخة احتياطية'}
          </button>
          <button
            type="button"
            onClick={handleRestorePick}
            disabled={backing || restoring}
            className="btn btn-danger"
          >
            {restoring ? <span className="spinner" /> : <Upload size={16}/>}
            {restoring ? 'جاري الاستعادة…' : 'استعادة من ملف SQL'}
          </button>
        </div>
        <p style={{ fontSize: '0.78rem', color: 'var(--danger)', margin: 0 }}>
          تحذير: الاستعادة تمسح البيانات الحالية وتستبدلها بمحتوى الملف. استخدم نسخاً احتياطياً موثوقاً فقط.
        </p>
      </section>

      {/* ── Updates ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<RefreshCw size={16}/>} label="تحديثات النظام التلقائية" />
        <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)' }}>
          التحقق من توفر تحديثات جديدة للنظام من المطور وتطبيقها بضغطة زر واحدة مجاناً بفضل نظام التشغيل السحابي.
        </p>
        
        <div style={{
          padding: '1rem', 
          borderRadius: 'var(--radius)', 
          background: hasUpdate ? 'rgba(40, 167, 69, 0.1)' : 'var(--bg)',
          border: hasUpdate ? '1px solid rgba(40, 167, 69, 0.3)' : '1px solid var(--border)'
        }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '1rem', color: hasUpdate ? 'var(--success)' : 'var(--text)' }}>
            {hasUpdate ? '🎉 تحديث جديد متوفر!' : '✅ النظام مُحدَّث'}
          </h3>
          <p style={{ margin: '0 0 0.2rem 0', fontSize: '0.85rem' }}>
            الإصدار الحالي الديك: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
          {latestVersion && (
            <p style={{ margin: 0, fontSize: '0.85rem' }}>
              أحدث إصدار متاح: <strong>v{latestVersion}</strong>
            </p>
          )}
        </div>

        {(applyingUpdate || updateLogs.length > 0) && (
          <div style={{ padding: '1rem', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginTop: '0.5rem' }}>
            {applyingUpdate && (
              <div style={{ fontWeight: 600, color: 'var(--primary-d)', marginBottom: '0.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <span className="spinner" style={{ borderColor: 'currentColor', borderRightColor: 'transparent' }} /> جاري التحديث... الرجاء عدم إغلاق هذه الصفحة
              </div>
            )}
            <div
              style={{
                background: '#1a1a2e',
                color: '#e0e0e0',
                padding: '0.8rem 1rem',
                borderRadius: '6px',
                fontSize: '0.82rem',
                fontFamily: 'Consolas, monospace',
                maxHeight: '260px',
                overflowY: 'auto',
                direction: 'ltr',
                textAlign: 'left',
                display: 'flex',
                flexDirection: 'column',
                gap: '0.2rem',
              }}
            >
              {updateLogs.length > 0 ? (
                updateLogs.map((line, i) => (
                  <div key={i} style={{ 
                    color: line.startsWith('✅') ? '#4ade80' : line.startsWith('⚠️') ? '#fbbf24' : line.startsWith('❌') ? '#f87171' : line.startsWith('🎉') ? '#34d399' : '#e0e0e0',
                    lineHeight: 1.5,
                  }}>
                    {line || '\u00A0'}
                  </div>
                ))
              ) : (
                <div style={{ color: '#888' }}>في انتظار استجابة الخادم...</div>
              )}
            </div>
          </div>
        )}

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
          <button
            type="button"
            onClick={handleCheckUpdate}
            disabled={isChecking || applyingUpdate}
            className="btn btn-secondary"
          >
            {isChecking ? <span className="spinner" /> : <RefreshCw size={16}/>}
            {isChecking ? 'جاري التحقق…' : 'التحقق من التحديثات'}
          </button>

          {changelog.length > 0 && (
            <button
              type="button"
              onClick={() => setShowChangelog(!showChangelog)}
              disabled={isChecking || applyingUpdate}
              className="btn btn-ghost"
            >
              <List size={16}/>
              {showChangelog ? 'إخفاء ملاحظات الإصدار' : 'عرض ملاحظات الإصدار'}
            </button>
          )}

           <button
             type="button"
             onClick={handleApplyUpdate}
             disabled={applyingUpdate}
             className="btn btn-primary"
             style={{ background: 'var(--success)', border: 'none' }}
           >
             {applyingUpdate ? 'جاري التحديث...' : 'تحديث الآن (فرض التحديث)'}
           </button>
        </div>

        {showChangelog && changelog.length > 0 && (
          <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: 'var(--radius)', maxHeight: '300px', overflowY: 'auto' }}>
            <h4 style={{ margin: '0 0 0.8rem 0', fontSize: '0.9rem' }}>أهم المميزات والإصلاحات الجديدة:</h4>
            <ul style={{ margin: 0, padding: '0 1.2rem', fontSize: '0.85rem', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
              {changelog.map((c, i) => (
                <li key={i}>
                  <strong>{typeof c === 'string' ? c : c.message}</strong>
                </li>
              ))}
            </ul>
          </div>
        )}
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
