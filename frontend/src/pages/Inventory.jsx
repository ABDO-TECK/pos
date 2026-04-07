import { useState, useEffect, useRef } from 'react'
import { AlertTriangle, Warehouse, Search, Pencil, X } from 'lucide-react'
import { getInventory, getLowStock, adjustInventory } from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'

export default function Inventory() {
  const [products, setProducts] = useState([])
  const [lowStock, setLowStock] = useState([])
  const [search, setSearch]     = useState('')
  const [loading, setLoading]   = useState(false)
  const [editModal, setEditModal] = useState(null)
  const [newQty, setNewQty]     = useState(0)
  const searchTimer             = useRef(null)

  const load = async (s = '') => {
    setLoading(true)
    try {
      const [invRes, lowRes] = await Promise.all([getInventory({ search: s }), getLowStock()])
      setProducts(invRes.data.data)
      setLowStock(lowRes.data.data)
    } catch { toast.error('فشل تحميل المخزون') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const handleSearch = (val) => {
    setSearch(val)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(val), 400)
  }

  const handleAdjust = async () => {
    try {
      await adjustInventory(editModal.id, { quantity: newQty })
      toast.success('تم تحديث الكمية')
      setEditModal(null)
      load(search)
    } catch (err) {
      toast.error(err.response?.data?.message || 'حدث خطأ')
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h2 style={{ fontWeight: 700 }}>إدارة المخزون</h2>

      {/* Low stock alert */}
      {lowStock.length > 0 && (
        <div style={{ background: '#fef9c3', border: '1px solid #fbbf24', borderRadius: 'var(--radius)', padding: '0.75rem 1rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700, color: '#92400e', marginBottom: '0.5rem' }}>
            <AlertTriangle size={18} /> {formatNumber(lowStock.length)} منتج بمخزون منخفض
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
            {lowStock.map((p) => (
              <span key={p.id} className="badge badge-yellow">{p.name} ({formatNumber(p.quantity)})</span>
            ))}
          </div>
        </div>
      )}

      {/* Search */}
      <div className="card" style={{ padding: '0.75rem' }}>
        <div style={{ position: 'relative' }}>
          <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
          <input className="input" style={{ paddingRight: '2.5rem' }} placeholder="بحث..." value={search}
            onChange={(e) => handleSearch(e.target.value)} />
        </div>
      </div>

      {/* Stats */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem' }}>
        <StatCard icon={<Warehouse size={22} color="var(--secondary)" />} label="إجمالي المنتجات" value={formatNumber(products.length)} />
        <StatCard icon={<AlertTriangle size={22} color="var(--warning)" />} label="مخزون منخفض" value={formatNumber(lowStock.length)} color="var(--warning)" />
        <StatCard icon={<span style={{ fontSize: '1.2rem' }}>📦</span>} label="إجمالي الوحدات"
          value={formatNumber(products.reduce((s, p) => s + p.quantity, 0))} />
        <StatCard icon={<span style={{ fontSize: '1.2rem' }}>💰</span>} label="قيمة المخزون"
          value={formatCurrency(products.reduce((s, p) => s + p.quantity * p.cost, 0))} />
      </div>

      {/* Table */}
      <div className="card">
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>المنتج</th>
                <th>الباركود</th>
                <th>الكمية الحالية</th>
                <th>حد التنبيه</th>
                <th>الحالة</th>
                <th>قيمة المخزون</th>
                <th>تعديل</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
              ) : products.map((p) => {
                const isNeg = p.quantity < 0
                const isOut = p.quantity === 0
                const isLow = p.quantity > 0 && p.quantity <= p.low_stock_threshold
                return (
                  <tr key={p.id}>
                    <td style={{ fontWeight: 600 }}>{p.name}</td>
                    <td><code style={{ fontSize: '0.8rem' }}>{p.barcode}</code></td>
                    <td style={{ fontWeight: 700, fontSize: '1rem', color: isNeg ? '#ef4444' : undefined }}>{formatNumber(p.quantity)}</td>
                    <td>{formatNumber(p.low_stock_threshold)}</td>
                    <td>
                      {isNeg ? <span className="badge badge-red">سالب</span>
                        : isOut ? <span className="badge badge-red">نفد</span>
                        : isLow ? <span className="badge badge-yellow">منخفض</span>
                        : <span className="badge badge-green">جيد</span>}
                    </td>
                    <td>{formatCurrency(p.quantity * p.cost)}</td>
                    <td>
                      <button className="btn btn-ghost btn-sm" onClick={() => { setEditModal(p); setNewQty(p.quantity) }}>
                        <Pencil size={13} /> تعديل
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      {/* Edit modal */}
      {editModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setEditModal(null)}>
          <div className="modal" style={{ maxWidth: '380px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
              <h3 style={{ fontWeight: 700 }}>تعديل الكمية</h3>
              <button className="btn btn-ghost btn-icon" onClick={() => setEditModal(null)}><X size={18} /></button>
            </div>
            <p style={{ marginBottom: '1rem', color: 'var(--text-muted)' }}>المنتج: <strong>{editModal.name}</strong></p>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>الكمية الجديدة</label>
            <input className="input input-lg" type="number" min={0} value={newQty} onChange={(e) => setNewQty(parseInt(e.target.value) || 0)} />
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleAdjust}>حفظ</button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setEditModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function StatCard({ icon, label, value, color }) {
  return (
    <div className="stat-card">
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
        {icon}
        <span className="stat-label">{label}</span>
      </div>
      <div className="stat-value" style={{ color: color || 'var(--text)' }}>{value}</div>
    </div>
  )
}
