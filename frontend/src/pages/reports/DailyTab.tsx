import { useState, useEffect } from 'react'
import { getDailyReport } from '../../api/endpoints'
import { formatCurrency, formatNumber, formatTime } from '../../utils/formatters'
import useSettingsStore from '../../store/settingsStore'
import { SCard, profitColor } from './components/SCard'

export default function DailyTab() {
  const taxEnabled = useSettingsStore((s) => s.taxEnabled)
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [daily, setDaily] = useState<any>(null)
  const [loading, setLoading] = useState(false)

  const loadDaily = async () => {
    setLoading(true)
    try {
      const res = await getDailyReport({ date })
      setDaily(res.data.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { loadDaily() }, [date])

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center' }}>
        <input type="date" className="input" style={{ maxWidth: '180px' }} value={date}
          onChange={(e) => setDate(e.target.value)} />
        <button className="btn btn-primary" onClick={loadDaily} disabled={loading}>
          {loading ? <span className="spinner" /> : '🔄'} عرض
        </button>
      </div>

      {daily && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
            <SCard label="الفواتير" value={formatNumber(daily.summary?.total_invoices ?? 0)} icon="🧾" />
            <SCard label="الإيرادات" value={formatCurrency(daily.summary?.total_revenue)} color="var(--primary)" icon="💰" />
            <SCard label="إجمالي التكاليف" value={formatCurrency(daily.summary?.total_cost ?? 0)} color="var(--warning)" icon="📦" title="تكلفة البضاعة المباعة — للتاريخ المختار" />
            <SCard label="ربح المبيعات" value={formatCurrency(daily.summary?.total_profit ?? 0)} color="var(--primary-d)" icon="📈" title="الإيرادات − التكاليف — للتاريخ المختار" />
            <SCard label="المصروفات" value={formatCurrency(daily.summary?.total_expenses ?? 0)} color="var(--danger)" icon="💸" />
            <SCard label="صافي الربح الفعلي" value={formatCurrency(daily.summary?.net_profit ?? 0)} color={profitColor(daily.summary?.net_profit)} icon="✨" title="ربح المبيعات − المصروفات" />
            <SCard label="الخصومات" value={formatCurrency(daily.summary?.total_discount)} icon="🏷️" />
            {taxEnabled && (
              <SCard label="الضرائب" value={formatCurrency(daily.summary?.total_tax)} icon="📊" />
            )}
          </div>

          <div className="card">
            <div className="table-wrapper">
              <table>
                <thead><tr><th>#</th><th>الكاشير</th><th>الإجمالي</th><th>الدفع</th><th>الوقت</th></tr></thead>
                <tbody>
                  {daily.invoices?.length === 0 ? (
                    <tr><td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد فواتير</td></tr>
                  ) : daily.invoices?.map((inv: any) => (
                    <tr key={inv.id}>
                      <td>#{formatNumber(inv.id)}</td>
                      <td>{inv.cashier_name}</td>
                      <td style={{ color: 'var(--primary)', fontWeight: 700 }}>{formatCurrency(inv.total)}</td>
                      <td><span className={`badge ${inv.payment_method === 'cash' ? 'badge-green' : 'badge-blue'}`}>{inv.payment_method === 'cash' ? 'نقدي' : 'بطاقة'}</span></td>
                      <td style={{ fontSize: '0.82rem', color: 'var(--text-muted)' }}>{formatTime(inv.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
