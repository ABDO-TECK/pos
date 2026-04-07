import { useState, useEffect } from 'react'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts'
import { getDailyReport, getMonthlyReport, getTopProducts, getReportSummary, getProfitReport } from '../api/endpoints'
import { formatCurrency, formatShortDate, formatNumber, formatPercent, formatTime } from '../utils/formatters'

export default function Reports() {
  const [tab, setTab] = useState('summary')
  const [summary, setSummary] = useState(null)
  const [daily, setDaily] = useState(null)
  const [monthly, setMonthly] = useState(null)
  const [topProducts, setTopProducts] = useState([])
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [month, setMonth] = useState(new Date().getMonth() + 1)
  const [year, setYear] = useState(new Date().getFullYear())
  const [loading, setLoading] = useState(false)
  const [profit, setProfit]   = useState(null)

  useEffect(() => {
    getReportSummary().then((r) => setSummary(r.data.data))
    getTopProducts({ limit: 10 }).then((r) => setTopProducts(r.data.data))
  }, [])

  const loadDaily = async () => {
    setLoading(true)
    const res = await getDailyReport({ date })
    setDaily(res.data.data)
    setLoading(false)
  }

  const loadMonthly = async () => {
    setLoading(true)
    const res = await getMonthlyReport({ month, year })
    setMonthly(res.data.data)
    setLoading(false)
  }

  const loadProfit = async () => {
    setLoading(true)
    try {
      const res = await getProfitReport({ month, year })
      setProfit(res.data.data)
    } catch { /* ignore */ }
    finally { setLoading(false) }
  }

  useEffect(() => { if (tab === 'daily')  loadDaily()  }, [tab, date])
  useEffect(() => { if (tab === 'monthly') loadMonthly() }, [tab, month, year])
  useEffect(() => { if (tab === 'profit')  loadProfit()  }, [tab, month, year])

  const tabs = [
    { id: 'summary',  label: 'الملخص' },
    { id: 'daily',    label: 'يومي' },
    { id: 'monthly',  label: 'شهري' },
    { id: 'products', label: 'المنتجات' },
    { id: 'profit',   label: 'الأرباح' },
  ]

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>التقارير والتحليلات</h1>

      {/* Tabs */}
      <div className="tabs-scroll">
        {tabs.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)} style={{
            padding: '0.5rem 1.1rem', background: 'none', border: 'none', cursor: 'pointer',
            fontWeight: tab === t.id ? 700 : 400,
            color: tab === t.id ? 'var(--primary)' : 'var(--text-muted)',
            borderBottom: `2px solid ${tab === t.id ? 'var(--primary)' : 'transparent'}`,
            marginBottom: '-2px', fontSize: '0.9rem',
          }}>{t.label}</button>
        ))}
      </div>

      {/* Summary tab */}
      {tab === 'summary' && summary && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem' }}>
            <SCard label="إيرادات اليوم" value={formatCurrency(summary.today_revenue)} color="var(--primary)" icon="💰" />
            <SCard label="إيرادات الشهر" value={formatCurrency(summary.month_revenue)} color="var(--secondary)" icon="📅" />
            <SCard label="فواتير اليوم" value={formatNumber(summary.today_invoices)} icon="🧾" />
            <SCard label="إجمالي المنتجات" value={formatNumber(summary.total_products)} icon="📦" />
            <SCard label="مخزون منخفض" value={formatNumber(summary.low_stock_count)} color={summary.low_stock_count > 0 ? 'var(--warning)' : undefined} icon="⚠️" />
            <SCard label="الموردون" value={formatNumber(summary.total_suppliers)} icon="🚚" />
          </div>

          {/* Top products chart */}
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
      )}

      {/* Daily tab */}
      {tab === 'daily' && (
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
                <SCard label="الخصومات" value={formatCurrency(daily.summary?.total_discount)} icon="🏷️" />
                <SCard label="الضرائب" value={formatCurrency(daily.summary?.total_tax)} icon="📊" />
              </div>

              <div className="card">
                <div className="table-wrapper">
                  <table>
                    <thead><tr><th>#</th><th>الكاشير</th><th>الإجمالي</th><th>الدفع</th><th>الوقت</th></tr></thead>
                    <tbody>
                      {daily.invoices?.length === 0 ? (
                        <tr><td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد فواتير</td></tr>
                      ) : daily.invoices?.map((inv) => (
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
      )}

      {/* Monthly tab */}
      {tab === 'monthly' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
            <select className="input" style={{ maxWidth: '150px' }} value={month} onChange={(e) => setMonth(parseInt(e.target.value))}>
              {Array.from({ length: 12 }, (_, i) => (
                <option key={i + 1} value={i + 1}>{new Date(2000, i).toLocaleString('ar-SA', { month: 'long' })}</option>
              ))}
            </select>
            <input type="number" className="input" style={{ maxWidth: '100px' }} value={year} onChange={(e) => setYear(parseInt(e.target.value))} />
            <button className="btn btn-primary" onClick={loadMonthly} disabled={loading}>
              {loading ? <span className="spinner" /> : '🔄'} عرض
            </button>
          </div>

          {monthly && (
            <>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
                <SCard label="الإيرادات الشهرية" value={formatCurrency(monthly.total_revenue)} color="var(--primary)" icon="💰" />
                <SCard label="الفواتير" value={formatNumber(monthly.total_invoices)} icon="🧾" />
              </div>

              {monthly.daily_breakdown?.length > 0 && (
                <div className="card" style={{ padding: '1.25rem' }}>
                  <h3 style={{ fontWeight: 700, marginBottom: '1rem', fontSize: '1rem' }}>الإيرادات اليومية</h3>
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={monthly.daily_breakdown.map((d) => ({ ...d, label: formatShortDate(d.date) }))}
                      margin={{ top: 5, right: 10, left: 10, bottom: 20 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                      <XAxis dataKey="label" tick={{ fontSize: 10 }} angle={-30} textAnchor="end" />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip formatter={(v) => [formatCurrency(v), 'الإيراد']} />
                      <Bar dataKey="total_revenue" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Top products tab */}
      {tab === 'products' && (
        <div className="card">
          <div className="table-wrapper">
            <table>
              <thead><tr><th>#</th><th>المنتج</th><th>الوحدات المباعة</th><th>الإيرادات</th><th>الأرباح</th></tr></thead>
              <tbody>
                {topProducts.length === 0 ? (
                  <tr><td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا بيانات</td></tr>
                ) : topProducts.map((p, i) => (
                  <tr key={p.id}>
                    <td style={{ color: 'var(--text-muted)', fontWeight: 600 }}>#{formatNumber(i + 1)}</td>
                    <td style={{ fontWeight: 600 }}>{p.name}</td>
                    <td><span className="badge badge-blue">{formatNumber(p.total_sold)}</span></td>
                    <td style={{ color: 'var(--primary)', fontWeight: 700 }}>{formatCurrency(p.total_revenue)}</td>
                    <td style={{ color: 'var(--secondary)', fontWeight: 600 }}>{formatCurrency(p.total_profit)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Profit tab */}
      {tab === 'profit' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {/* Month/year selector */}
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
              {/* KPI cards */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: '0.75rem' }}>
                <SCard label="إجمالي الإيرادات" value={formatCurrency(profit.total_revenue)} color="var(--primary)" icon="💰" />
                <SCard label="إجمالي التكاليف"  value={formatCurrency(profit.total_cost)}    color="var(--warning)"  icon="📦" />
                <SCard label="صافي الأرباح"      value={formatCurrency(profit.total_profit)}  color="var(--secondary)" icon="📈" />
                <SCard label="هامش الربح"         value={formatPercent(profit.profit_margin)}   color={profit.profit_margin >= 0 ? 'var(--secondary)' : 'var(--danger)'} icon="%" />
              </div>

              {/* Revenue vs Cost vs Profit chart */}
              {profit.daily_breakdown?.length > 0 && (
                <div className="card" style={{ padding: '1.25rem' }}>
                  <h3 style={{ fontWeight: 700, marginBottom: '1rem', fontSize: '1rem' }}>الإيرادات مقابل التكاليف والأرباح</h3>
                  <ResponsiveContainer width="100%" height={280}>
                    <BarChart
                      data={profit.daily_breakdown.map(d => ({ ...d, label: formatShortDate(d.date) }))}
                      margin={{ top: 5, right: 10, left: 10, bottom: 25 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                      <XAxis dataKey="label" tick={{ fontSize: 10 }} angle={-30} textAnchor="end" />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip formatter={(v, name) => [formatCurrency(v), name]} />
                      <Legend />
                      <Bar dataKey="revenue" name="الإيرادات" fill="#3b82f6" radius={[4,4,0,0]} />
                      <Bar dataKey="cost"    name="التكاليف"  fill="#f97316" radius={[4,4,0,0]} />
                      <Bar dataKey="profit"  name="الأرباح"   fill="#22c55e" radius={[4,4,0,0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}

              {/* Top profitable products */}
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
                        {profit.top_products.map((p, i) => (
                          <tr key={p.id}>
                            <td style={{ color: 'var(--text-muted)', fontWeight: 600 }}>#{formatNumber(i + 1)}</td>
                            <td style={{ fontWeight: 600 }}>{p.name}</td>
                            <td><span className="badge badge-blue">{formatNumber(p.total_sold)}</span></td>
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
      )}
    </div>
  )
}

function SCard({ label, value, color, icon }) {
  return (
    <div className="stat-card">
      <div style={{ fontSize: '1.4rem' }}>{icon}</div>
      <div className="stat-value" style={{ color: color || 'var(--text)' }}>{value}</div>
      <div className="stat-label">{label}</div>
    </div>
  )
}
