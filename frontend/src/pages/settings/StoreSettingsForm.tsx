import { useState, useEffect } from 'react'
import { Save, Store, Percent, List } from 'lucide-react'
import toast from 'react-hot-toast'
import { updateSettings } from '../../api/endpoints'
import useSettingsStore from '../../store/settingsStore'
import SectionTitle from '../../components/common/SectionTitle'
import Toggle from '../../components/common/Toggle'
import { extractApiError } from '../../utils/apiError'
import styles from '../Settings.module.css'

export default function StoreSettingsForm() {
  const { fetchSettings, setSettings } = useSettingsStore()

  const labelStyle = {
    display: 'block',
    fontSize: '0.88rem',
    fontWeight: 600,
    color: 'var(--text)',
    marginBottom: '0.4rem',
  }

  const [form, setForm] = useState({ 
    store_name: '', tax_enabled: '0', tax_rate: '15',
    loyalty_enabled: '0', loyalty_points_per_rial: '1', loyalty_rial_per_point: '0.01'
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    fetchSettings().then(() => {
      const s = useSettingsStore.getState()
      setForm({
        store_name:  s.storeName,
        tax_enabled: s.taxEnabled ? '1' : '0',
        tax_rate:    String(s.taxRate),
        loyalty_enabled: s.loyaltyEnabled ? '1' : '0',
        loyalty_points_per_rial: String(s.loyaltyPointsPerRial),
        loyalty_rial_per_point: String(s.loyaltyRialPerPoint),
      })
    })
  }, [fetchSettings])

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await updateSettings(form)
      setSettings({
        storeName:  form.store_name,
        taxEnabled: form.tax_enabled === '1',
        taxRate:    parseFloat(form.tax_rate),
        loyaltyEnabled: form.loyalty_enabled === '1',
        loyaltyPointsPerRial: parseInt(form.loyalty_points_per_rial, 10),
        loyaltyRialPerPoint: parseFloat(form.loyalty_rial_per_point),
      })
      toast.success('تم حفظ الإعدادات')
    } catch (err: any) { toast.error(extractApiError(err, 'فشل حفظ الإعدادات')) } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSave} className={styles.settingsForm}>
      {/* ── Store Info ── */}
      <section className={`card ${styles.settingsCard}`}>
        <SectionTitle icon={<Store size={16}/>} label="معلومات المحل" />
        <div>
          <label className={styles.settingsLabel}>اسم المحل</label>
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
            <p style={{ fontWeight: 600, fontSize: '0.95rem', margin: 0 }}>تفعيل الضريبة</p>
            <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)', margin: 0 }}>تطبيق ضريبة القيمة المضافة على المبيعات</p>
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

      {/* ── Loyalty ── */}
      <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
        <SectionTitle icon={<List size={16}/>} label="نظام نقاط الولاء" />

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <p style={{ fontWeight: 600, fontSize: '0.95rem', margin: 0 }}>تفعيل نظام الولاء</p>
            <p style={{ fontSize: '0.82rem', color: 'var(--text-muted)', margin: 0 }}>السماح للعملاء باكتساب نقاط عند الشراء</p>
          </div>
          <Toggle
            checked={form.loyalty_enabled === '1'}
            onChange={() => setForm({ ...form, loyalty_enabled: form.loyalty_enabled === '1' ? '0' : '1' })}
          />
        </div>

        {form.loyalty_enabled === '1' && (
          <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
            <div>
              <label style={labelStyle}>النقاط المكتسبة لكل 1 ريال</label>
              <input
                type="number"
                className="input"
                min="1"
                style={{ maxWidth: '160px' }}
                value={form.loyalty_points_per_rial}
                onChange={e => setForm({ ...form, loyalty_points_per_rial: e.target.value })}
              />
            </div>
            <div>
              <label style={labelStyle}>قيمة النقطة الواحدة (ريال)</label>
              <input
                type="number"
                className="input"
                min="0.01"
                step="0.01"
                style={{ maxWidth: '160px' }}
                value={form.loyalty_rial_per_point}
                onChange={e => setForm({ ...form, loyalty_rial_per_point: e.target.value })}
              />
            </div>
          </div>
        )}
      </section>

      <button type="submit" disabled={saving} className="btn btn-primary" style={{ alignSelf: 'flex-start' }}>
        {saving ? <span className="spinner" /> : <Save size={16}/>}
        {saving ? 'جاري الحفظ…' : 'حفظ الإعدادات'}
      </button>
    </form>
  )
}
