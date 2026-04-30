import { useState, useEffect } from 'react'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import { getReportSummary, getTopProducts } from '../../api/endpoints'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import { SCard, profitColor } from './components/SCard'

export default function SummaryTab() {
  const [summary, setSummary] = useState<any>(null)
  const [topProducts, setTopProducts] = useState<any[]>([])

  useEffect(() => {
    getReportSummary().then((r) => setSummary(r.data.data))
    getTopProducts({ limit: 10 }).then((r) => { const d = r.data.data as any; setTopProducts(Array.isArray(d) ? d : []) })
  }, [])

  if (!summary) return <div style={{ padding: '2rem', textAlign: 'center' }}><span className="spinner" /> جاري التحميل...</div>

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem' }}>
        <SCard label="إيرادات اليوم" value={formatCurrency(summary.today_revenue)} color="var(--primary)" icon="💰" />
        <SCard label="إجمالي التكاليف (اليوم)" value={formatCurrency(summary.today_cost ?? 0)} color="var(--warning)" icon="📦" />
        <SCard label="ربح المبيعات (اليوم)" value={formatCurrency(summary.today_profit ?? 0)} color="var(--primary-d)" icon="📈" title="ربح المبيعات = الإيرادات − التكاليف" />
        <SCard label="مصروفات اليوم" value={formatCurrency(summary.today_expenses ?? 0)} color="var(--danger)" icon="💸" />
        <SCard label="صافي الربح الفعلي (اليوم)" value={formatCurrency(summary.today_net_profit ?? 0)} color={profitColor(summary.today_net_profit)} icon="✨" title="صافي الربح الفعلي = ربح المبيعات − المصروفات" />

        <SCard label="إيرادات الشهر" value={formatCurrency(summary.month_revenue)} color="var(--secondary)" icon="📅" />
        <SCard label="إجمالي التكاليف (الشهر)" value={formatCurrency(summary.month_cost ?? 0)} color="var(--warning)" icon="📦" />
        <SCard label="ربح المبيعات (الشهر)" value={formatCurrency(summary.month_profit ?? 0)} color="var(--primary-d)" icon="📊" title="ربح المبيعات = الإيرادات − التكاليف" />
        <SCard label="مصروفات الشهر" value={formatCurrency(summary.month_expenses ?? 0)} color="var(--danger)" icon="💸" />
        <SCard label="صافي الربح الفعلي (الشهر)" value={formatCurrency(summary.month_net_profit ?? 0)} color={profitColor(summary.month_net_profit)} icon="💎" title="صافي الربح الفعلي = ربح المبيعات − المصروفات" />

        <SCard label="فواتير اليوم" value={formatNumber(summary.today_invoices)} icon="🧾" />
        <SCard label="مخزون منخفض" value={formatNumber(summary.low_stock_count)} color={summary.low_stock_count > 0 ? 'var(--warning)' : undefined} icon="⚠️" />
      </div>

      {topProducts.length > 0 && (
        <div className="card" style={{ padding: '1.25rem' }}>
          <h3 style={{ fontWeight: 700, marginBottom: '1rem', fontSize: '1rem' }}>أفضل المنتجات مبيعًا</h3>
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={topProducts} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
              <XAxis dataKey="name" tick={{ fontSize: 11 }} width={80} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip formatter={(v) => [v, 'مبيعات']} />
              <Bar dataKey="total_sold" fill="#22c55e" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}
