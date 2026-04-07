import { useState, useEffect, useRef } from 'react'
import { Eye, X, Printer } from 'lucide-react'
import toast from 'react-hot-toast'
import { getSales, getSale } from '../api/endpoints'
import { formatCurrency, formatDate, formatNumber } from '../utils/formatters'
import { browserPrint } from '../utils/receiptBuilder'
import useSettingsStore from '../store/settingsStore'

const METHOD_LABELS = {
  cash:          'نقدي',
  card:          'بطاقة',
  vodafone_cash: 'فودافون كاش',
  instapay:      'انستاباي',
  other_wallet:  'محفظة أخرى',
}

export default function Sales() {
  const [sales, setSales]       = useState([])
  const [loading, setLoading]   = useState(false)
  const [selected, setSelected] = useState(null)
  const [detailLoading, setDL]  = useState(false)
  const [filters, setFilters]   = useState({ date: '', month: '', year: '' })
  const searchTimer             = useRef(null)
  const settings                = useSettingsStore()

  const load = async (f = filters) => {
    setLoading(true)
    try {
      const params = {}
      if (f.date)  params.date  = f.date
      if (f.month) params.month = f.month
      if (f.year)  params.year  = f.year
      const res = await getSales(params)
      setSales(res.data.data ?? [])
    } catch {
      toast.error('فشل تحميل المبيعات')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  const handleFilter = (key, val) => {
    const next = { ...filters, [key]: val }
    setFilters(next)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(next), 400)
  }

  const clearFilters = () => {
    const cleared = { date: '', month: '', year: '' }
    setFilters(cleared)
    load(cleared)
  }

  const openDetail = async (id) => {
    setDL(true)
    try {
      const res = await getSale(id)
      setSelected(res.data.data)
    } catch {
      toast.error('فشل تحميل تفاصيل الفاتورة')
    } finally {
      setDL(false)
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>سجل المبيعات</h1>

      {/* Filters */}
      <div className="card" style={{ padding: '1rem' }}>
        <div className="filter-bar">
          <div className="form-group">
            <label style={labelSt}>تاريخ محدد</label>
            <input type="date" className="input" value={filters.date} onChange={e => handleFilter('date', e.target.value)} />
          </div>
          <div className="form-group">
            <label style={labelSt}>الشهر</label>
            <select className="input" value={filters.month} onChange={e => handleFilter('month', e.target.value)}>
              <option value="">كل الأشهر</option>
              {Array.from({ length: 12 }, (_, i) => (
                <option key={i + 1} value={i + 1}>
                  {new Date(2000, i).toLocaleString('ar-EG', { month: 'long' })}
                </option>
              ))}
            </select>
          </div>
          <div className="form-group">
            <label style={labelSt}>السنة</label>
            <select className="input" value={filters.year} onChange={e => handleFilter('year', e.target.value)}>
              <option value="">كل السنوات</option>
              {[2024, 2025, 2026, 2027].map(y => <option key={y} value={y}>{formatNumber(y)}</option>)}
            </select>
          </div>
          <button onClick={clearFilters} className="btn btn-ghost btn-sm" style={{ alignSelf: 'flex-end' }}>مسح الفلاتر</button>
        </div>
      </div>

      {/* Table */}
      <div className="card">
        {loading ? (
          <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : sales.length === 0 ? (
          <div className="empty-state">لا توجد مبيعات</div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th># الفاتورة</th>
                  <th>الكاشير</th>
                  <th>الإجمالي</th>
                  <th>طريقة الدفع</th>
                  <th>التاريخ</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {sales.map(s => (
                  <tr key={s.id}>
                    <td style={{ color: 'var(--text-muted)' }}>#{formatNumber(s.id)}</td>
                    <td>{s.cashier_name ?? '—'}</td>
                    <td style={{ fontWeight: 700, color: 'var(--primary-d)' }}>{formatCurrency(s.total)}</td>
                    <td>
                      <span className="badge badge-blue">
                        {METHOD_LABELS[s.payment_method] ?? s.payment_method}
                      </span>
                    </td>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatDate(s.created_at)}</td>
                    <td>
                      <button
                        className="btn btn-ghost btn-sm"
                        onClick={() => openDetail(s.id)}
                        style={{ gap: '0.3rem' }}
                      >
                        <Eye size={14}/> عرض
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Detail Modal */}
      {(selected || detailLoading) && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setSelected(null)}>
          <div className="modal" style={{ maxWidth: '600px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700, fontSize: '1.1rem' }}>
                {selected ? `فاتورة #${formatNumber(selected.id)}` : 'جاري التحميل…'}
              </h2>
              <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
                {selected && (
                  <button
                    className="btn btn-ghost btn-sm"
                    onClick={() => browserPrint(
                      { ...selected, items: (selected.items ?? []).map(i => ({ ...i, product_name: i.product_name ?? i.name })) },
                      parseFloat(selected.change_due) || 0,
                      settings
                    )}
                    title="طباعة الفاتورة"
                  >
                    <Printer size={15} /> طباعة
                  </button>
                )}
                <button className="btn btn-ghost btn-icon" onClick={() => setSelected(null)}><X size={18}/></button>
              </div>
            </div>

            {detailLoading && (
              <div style={{ padding: '2rem', textAlign: 'center' }}><span className="spinner" /></div>
            )}

            {selected && (
              <>
                {/* Invoice meta */}
                <div className="resp-2col" style={{ marginBottom: '1rem' }}>
                  <InfoCard label="الكاشير"      value={selected.cashier_name ?? '—'} />
                  <InfoCard label="طريقة الدفع"  value={METHOD_LABELS[selected.payment_method] ?? selected.payment_method} />
                  <InfoCard label="التاريخ"       value={formatDate(selected.created_at)} />
                  <InfoCard label="المبلغ المدفوع" value={formatCurrency(selected.amount_paid)} />
                </div>

                {/* Items table */}
                <div className="table-wrapper" style={{ marginBottom: '1rem' }}>
                  <table style={{ fontSize: '0.88rem' }}>
                    <thead>
                      <tr>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(selected.items ?? []).map((item, idx) => (
                        <tr key={idx}>
                          <td>{item.product_name ?? item.name}</td>
                          <td>{formatNumber(item.quantity)}</td>
                          <td>{formatCurrency(item.price)}</td>
                          <td style={{ fontWeight: 600 }}>{formatCurrency(item.price * item.quantity)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Totals */}
                <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', fontSize: '0.9rem', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                  <TotalRow label="المجموع الجزئي" value={formatCurrency(selected.subtotal)} />
                  {parseFloat(selected.discount) > 0 && (
                    <TotalRow label="الخصم" value={`- ${formatCurrency(selected.discount)}`} danger />
                  )}
                  {parseFloat(selected.tax) > 0 && (
                    <TotalRow label="الضريبة" value={formatCurrency(selected.tax)} />
                  )}
                  <div style={{ borderTop: '2px solid var(--border)', margin: '0.3rem 0' }} />
                  <TotalRow label="الإجمالي" value={formatCurrency(selected.total)} bold green />
                  {parseFloat(selected.change_due) > 0 && (
                    <TotalRow label="الباقي" value={formatCurrency(selected.change_due)} />
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function InfoCard({ label, value }) {
  return (
    <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.6rem 0.8rem' }}>
      <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.15rem' }}>{label}</p>
      <p style={{ fontWeight: 600 }}>{value}</p>
    </div>
  )
}

function TotalRow({ label, value, bold, green, danger }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
      <span style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span style={{
        fontWeight: bold ? 700 : 500,
        color: green ? 'var(--primary-d)' : danger ? 'var(--danger)' : 'var(--text)',
        fontSize: bold ? '1rem' : undefined,
      }}>{value}</span>
    </div>
  )
}

const labelSt = {
  display: 'block',
  fontSize: '0.8rem',
  fontWeight: 600,
  color: 'var(--text-muted)',
  marginBottom: '0.3rem',
}
