import { useState, useEffect } from 'react'
import { getDailyReport } from '../../api/endpoints'
import { formatCurrency, formatNumber, formatTime } from '../../utils/formatters'
import useSettingsStore from '../../store/settingsStore'
import { SCard, profitColor } from './components/SCard'
import { Coins, Box, TrendingUp, TrendingDown, Sparkles, FileText, Tag, BarChart3, RefreshCw } from 'lucide-react'

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
        <button className="btn btn-primary" onClick={loadDaily} disabled={loading} style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
          {loading ? <span className="spinner" /> : <RefreshCw size={14} />} عرض
        </button>
      </div>

      {daily && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
            <SCard label="الفواتير" value={formatNumber(daily.summary?.total_invoices ?? 0)} icon={FileText} />
            <SCard label="الإيرادات" value={formatCurrency(daily.summary?.total_revenue)} color="var(--primary)" icon={Coins} />
            <SCard label="إجمالي التكاليف" value={formatCurrency(daily.summary?.total_cost ?? 0)} color="var(--warning)" icon={Box} title="تكلفة البضاعة المباعة — للتاريخ المختار" />
            <SCard label="ربح المبيعات" value={formatCurrency(daily.summary?.total_profit ?? 0)} color="var(--primary-d)" icon={TrendingUp} title="الإيرادات − التكاليف — للتاريخ المختار" />
            <SCard label="المصروفات" value={formatCurrency(daily.summary?.total_expenses ?? 0)} color="var(--danger)" icon={TrendingDown} />
            <SCard label="صافي الربح الفعلي" value={formatCurrency(daily.summary?.net_profit ?? 0)} color={profitColor(daily.summary?.net_profit)} icon={Sparkles} title="ربح المبيعات − المصروفات" />
            <SCard label="الخصومات" value={formatCurrency(daily.summary?.total_discount)} icon={Tag} />
            {taxEnabled && (
              <SCard label="الضرائب" value={formatCurrency(daily.summary?.total_tax)} icon={BarChart3} />
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
