
import { useState, useEffect } from 'react'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts'
import { getProfitReport } from '../../api/endpoints'
import { formatCurrency, formatNumber, formatPercent, formatShortDate } from '../../utils/formatters'
import { SCard, profitColor } from './components/SCard'

export default function ProfitTab() {
  const [month, setMonth] = useState(new Date().getMonth() + 1)
  const [year, setYear] = useState(new Date().getFullYear())
  const [profit, setProfit] = useState<any>(null)
  const [loading, setLoading] = useState(false)

  const loadProfit = async () => {
    setLoading(true)
    try {
      const res = await getProfitReport({ month, year })
      setProfit(res.data.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { loadProfit() }, [month, year])

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
        <select className="input" style={{ maxWidth: '150px' }} value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
          {Array.from({ length: 12 }, (_, i) => (
            <option key={i + 1} value={i + 1}>{new Date(2000, i).toLocaleString('ar-EG', { month: 'long' })}</option>
          ))}
        </select>
        <input type="number" className="input" style={{ maxWidth: '100px' }} value={year} onChange={(e) => setYear(parseInt(e.target.value))} />
        <button className="btn btn-primary" onClick={loadProfit} disabled={loading}>
          {loading ? <span className="spinner" /> : '🔄'} عرض
        </button>
      </div>

      {profit && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: '0.75rem' }}>
            <SCard label="إجمالي الإيرادات" value={formatCurrency(profit.total_revenue)} color="var(--primary)" icon="💰" />
            <SCard label="إجمالي التكاليف"  value={formatCurrency(profit.total_cost)}    color="var(--warning)"  icon="📦" />
            <SCard label="إجمالي ربح المبيعات" value={formatCurrency(profit.total_profit)} color="var(--primary-d)" icon="📈" />
            <SCard label="إجمالي المصروفات" value={formatCurrency(profit.total_expenses)} color="var(--danger)" icon="💸" />
            <SCard label="صافي الربح الفعلي" value={formatCurrency(profit.net_profit)} color={profitColor(profit.net_profit)} icon="✨" />
            <SCard label="هامش الربح الفعلي" value={formatPercent(profit.profit_margin)}   color={profit.profit_margin >= 0 ? 'var(--secondary)' : 'var(--danger)'} icon="%" />
          </div>

          {profit.daily_breakdown?.length > 0 && (
            <div className="card" style={{ padding: '1.25rem' }}>
              <h3 style={{ fontWeight: 700, marginBottom: '1rem', fontSize: '1rem' }}>الإيرادات مقابل التكاليف والأرباح</h3>
              <ResponsiveContainer width="100%" height={280}>
                <BarChart
                  data={profit.daily_breakdown.map((d: any) => ({ ...d, label: formatShortDate(d.date) }))}
                  margin={{ top: 5, right: 10, left: 10, bottom: 25 }}
                >
                  <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                  <XAxis dataKey="label" tick={{ fontSize: 10 }} angle={-30} textAnchor="end" />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip formatter={(v, name) => [formatCurrency(Number(v)), String(name ?? '')]} />
                  <Legend />
                  <Bar dataKey="revenue" name="الإيرادات" fill="#3b82f6" radius={[4,4,0,0]} />
                  <Bar dataKey="cost"    name="التكاليف"  fill="#f97316" radius={[4,4,0,0]} />
                  <Bar dataKey="profit"  name="الأرباح"   fill="#22c55e" radius={[4,4,0,0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}

          {profit.top_products?.length > 0 && (
            <div className="card">
              <div style={{ fontWeight: 700, padding: '1rem 1rem 0.5rem', fontSize: '1rem' }}>أفضل المنتجات ربحًا</div>
              <div className="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>المنتج</th>
                      <th>الوحدات</th>
                      <th>الإيراد</th>
                      <th>التكلفة</th>
                      <th>الربح</th>
                      <th>الهامش %</th>
                    </tr>
                  </thead>
                  <tbody>
                    {profit.top_products.map((p: any, i: number) => (
                      <tr key={p.id}>
                        <td style={{ color: 'var(--text-muted)', fontWeight: 600 }}>#{formatNumber(i + 1)}</td>
                        <td style={{ fontWeight: 600 }}>{p.name}</td>
                        <td>
                          <span className="badge badge-blue">
                            {parseFloat(p.total_sold) % 1 !== 0 ? `${parseFloat(p.total_sold).toFixed(3)} كجم` : formatNumber(p.total_sold)}
                          </span>
                        </td>
                        <td style={{ color: 'var(--primary)', fontWeight: 700 }}>{formatCurrency(p.revenue)}</td>
                        <td style={{ color: 'var(--warning)' }}>{formatCurrency(p.cost)}</td>
                        <td style={{ color: 'var(--secondary)', fontWeight: 700 }}>{formatCurrency(p.profit)}</td>
                        <td>
                          <span className={`badge ${parseFloat(p.margin_pct) >= 0 ? 'badge-green' : 'badge-red'}`}>
                            {formatPercent(p.margin_pct)}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
