import { useState, useEffect, useRef } from 'react'
import { Search, Plus, Trash2, ShoppingCart, Check, X } from 'lucide-react'
import toast from 'react-hot-toast'
import {
  getSuppliers, createSupplier, updateSupplier, deleteSupplier,
  getProducts, createBulkPurchase,
} from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'

export default function Suppliers() {
  const [tab, setTab] = useState(0)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', height: '100%' }}>
      {/* Header + tabs */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
        <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>الموردون</h1>
        <div style={{ display: 'flex', gap: '0.25rem', background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.25rem', border: '1px solid var(--border)' }}>
          {['استلام بضاعة', 'إدارة الموردين'].map((t, i) => (
            <button
              key={i}
              onClick={() => setTab(i)}
              style={{
                padding: '0.35rem 0.9rem',
                borderRadius: 'calc(var(--radius) - 2px)',
                border: 'none',
                fontSize: '0.88rem',
                fontWeight: tab === i ? 600 : 400,
                background: tab === i ? 'var(--surface)' : 'transparent',
                color: tab === i ? 'var(--primary-d)' : 'var(--text-muted)',
                boxShadow: tab === i ? 'var(--shadow)' : 'none',
                cursor: 'pointer',
                transition: 'all .15s',
              }}
            >
              {t}
            </button>
          ))}
        </div>
      </div>

      {tab === 0 ? <ReceiveGoods /> : <ManageSuppliers />}
    </div>
  )
}

/* ──────────────────────────── Receive Goods (POS-like) ── */
function ReceiveGoods() {
  const [suppliers, setSuppliers]   = useState([])
  const [supplierId, setSupplierId] = useState('')
  const [products, setProducts]     = useState([])
  const [search, setSearch]         = useState('')
  const [cart, setCart]             = useState([]) // [{ product, quantity, cost }]
  const [loading, setLoading]       = useState(false)
  const [confirming, setConfirming] = useState(false)
  const searchTimer                 = useRef(null)

  useEffect(() => {
    getSuppliers().then(r => setSuppliers(r.data.data ?? []))
    loadProducts('')
  }, [])

  const loadProducts = (q) => {
    setLoading(true)
    getProducts({ search: q, limit: 100 })
      .then(r => setProducts(r.data.data ?? []))
      .catch(() => toast.error('فشل تحميل المنتجات'))
      .finally(() => setLoading(false))
  }

  const handleSearch = (val) => {
    setSearch(val)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => loadProducts(val), 350)
  }

  const addToCart = (product) => {
    setCart(prev => {
      const existing = prev.find(c => c.product.id === product.id)
      if (existing) {
        return prev.map(c =>
          c.product.id === product.id ? { ...c, quantity: c.quantity + 1 } : c
        )
      }
      return [...prev, { product, quantity: 1, cost: product.cost ?? product.price }]
    })
  }

  const updateCartItem = (id, field, val) =>
    setCart(prev => prev.map(c => c.product.id === id ? { ...c, [field]: parseFloat(val) || 0 } : c))

  const removeFromCart = (id) =>
    setCart(prev => prev.filter(c => c.product.id !== id))

  const cartTotal = cart.reduce((s, c) => s + c.cost * c.quantity, 0)

  const handleConfirm = async () => {
    if (!supplierId) { toast.error('يرجى اختيار مورد'); return }
    if (cart.length === 0) { toast.error('السلة فارغة'); return }
    setConfirming(true)
    try {
      await createBulkPurchase({
        supplier_id: parseInt(supplierId),
        items: cart.map(c => ({
          product_id:  c.product.id,
          quantity:    c.quantity,
          cost:        c.cost,
          update_cost: true,
        })),
      })
      toast.success('تم تسجيل الشراء وتحديث المخزون')
      setCart([])
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل تسجيل الشراء')
    } finally {
      setConfirming(false)
    }
  }

  return (
    <div style={{ display: 'flex', gap: '1rem', flex: 1, minHeight: 0, overflow: 'hidden' }}>

      {/* Left: products grid */}
      <div style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: 0, gap: '0.75rem' }}>
        {/* Search */}
        <div style={{ position: 'relative' }}>
          <Search size={16} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
          <input
            type="text"
            placeholder="بحث بالاسم أو الباركود…"
            className="input"
            style={{ paddingRight: '2.5rem' }}
            value={search}
            onChange={e => handleSearch(e.target.value)}
          />
        </div>

        <div style={{ overflowY: 'auto', flex: 1 }}>
          {loading ? (
            <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
          ) : (
            <div style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fill, minmax(110px, 1fr))',
              gap: '0.5rem',
              alignContent: 'start',
            }}>
              {products.map(p => (
                <ProductCard key={p.id} product={p} onAdd={() => addToCart(p)} />
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Right: Purchase cart */}
      <div style={{
        width: '300px', flexShrink: 0, display: 'flex', flexDirection: 'column',
        background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 'var(--radius)',
        boxShadow: 'var(--shadow)', overflow: 'hidden',
      }}>
        {/* Supplier select */}
        <div style={{ padding: '0.75rem', borderBottom: '1px solid var(--border)' }}>
          <label style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.3rem' }}>المورد</label>
          <select
            className="input"
            value={supplierId}
            onChange={e => setSupplierId(e.target.value)}
          >
            <option value="">اختر مورد…</option>
            {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>

        {/* Cart items */}
        <div style={{ flex: 1, overflowY: 'auto', padding: '0.5rem', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
          {cart.length === 0 ? (
            <div className="empty-state" style={{ padding: '2rem' }}>
              <ShoppingCart size={28} style={{ opacity: 0.3 }} />
              <span style={{ fontSize: '0.85rem' }}>اضغط على منتج لإضافته</span>
            </div>
          ) : (
            cart.map(c => (
              <div key={c.product.id} style={{
                border: '1px solid var(--border)', borderRadius: 'var(--radius)',
                padding: '0.5rem', background: 'var(--bg)', fontSize: '0.82rem',
              }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                  <span style={{ fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '160px' }}>
                    {c.product.name}
                  </span>
                  <button
                    onClick={() => removeFromCart(c.product.id)}
                    style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--danger)', padding: 0 }}
                  >
                    <X size={13}/>
                  </button>
                </div>
                <div style={{ display: 'flex', gap: '0.4rem' }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>الكمية</label>
                    <input
                      type="number" min="1"
                      className="input"
                      style={{ padding: '0.3rem 0.5rem', fontSize: '0.82rem', marginTop: '0.15rem' }}
                      value={c.quantity}
                      onChange={e => updateCartItem(c.product.id, 'quantity', e.target.value)}
                    />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>التكلفة</label>
                    <input
                      type="number" min="0" step="0.5"
                      className="input"
                      style={{ padding: '0.3rem 0.5rem', fontSize: '0.82rem', marginTop: '0.15rem' }}
                      value={c.cost}
                      onChange={e => updateCartItem(c.product.id, 'cost', e.target.value)}
                    />
                  </div>
                </div>
                <div style={{ textAlign: 'left', color: 'var(--secondary)', fontWeight: 600, marginTop: '0.3rem', fontSize: '0.82rem' }}>
                  = {formatCurrency(c.cost * c.quantity)}
                </div>
              </div>
            ))
          )}
        </div>

        {/* Total + confirm */}
        <div style={{ padding: '0.75rem', borderTop: '1px solid var(--border)', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 700, fontSize: '1rem' }}>
            <span>الإجمالي</span>
            <span style={{ color: 'var(--secondary)' }}>{formatCurrency(cartTotal)}</span>
          </div>
          <button
            onClick={handleConfirm}
            disabled={confirming || cart.length === 0 || !supplierId}
            className="btn btn-primary"
            style={{ justifyContent: 'center', width: '100%' }}
          >
            {confirming ? <span className="spinner" /> : <Check size={16}/>}
            تأكيد الاستلام
          </button>
        </div>
      </div>
    </div>
  )
}

function ProductCard({ product, onAdd }) {
  const inStock = product.quantity > 0
  return (
    <button
      onClick={onAdd}
      style={{
        display: 'flex', flexDirection: 'column', alignItems: 'flex-start',
        padding: '0.5rem', background: 'var(--surface)',
        border: '1px solid var(--border)', borderRadius: 'var(--radius)',
        cursor: 'pointer', textAlign: 'right', transition: 'border-color .15s, box-shadow .15s',
        fontFamily: 'inherit',
      }}
      onMouseOver={e => { e.currentTarget.style.borderColor = 'var(--primary)'; e.currentTarget.style.boxShadow = 'var(--shadow)' }}
      onMouseOut={e => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.boxShadow = 'none' }}
    >
      <span style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text)', lineHeight: 1.3, marginBottom: '0.25rem', overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>
        {product.name}
      </span>
      <span style={{ fontSize: '0.82rem', fontWeight: 700, color: 'var(--secondary)' }}>{formatCurrency(product.price)}</span>
      <span style={{ fontSize: '0.75rem', color: inStock ? 'var(--text-muted)' : 'var(--danger)', marginTop: '0.15rem' }}>
        {inStock ? `المخزون: ${formatNumber(product.quantity)}` : 'نفد'}
      </span>
    </button>
  )
}

/* ──────────────────────────── Manage Suppliers ── */
function ManageSuppliers() {
  const [suppliers, setSuppliers] = useState([])
  const [loading, setLoading]     = useState(false)
  const [showForm, setShowForm]   = useState(false)
  const [editing, setEditing]     = useState(null)
  const [form, setForm]           = useState({ name: '', phone: '', email: '', address: '' })

  const load = async () => {
    setLoading(true)
    try { setSuppliers((await getSuppliers()).data.data ?? []) }
    catch { toast.error('فشل تحميل الموردين') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const openNew = () => {
    setEditing(null)
    setForm({ name: '', phone: '', email: '', address: '' })
    setShowForm(true)
  }

  const openEdit = (s) => {
    setEditing(s)
    setForm({ name: s.name, phone: s.phone ?? '', email: s.email ?? '', address: s.address ?? '' })
    setShowForm(true)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    try {
      if (editing) await updateSupplier(editing.id, form)
      else await createSupplier(form)
      toast.success(editing ? 'تم التحديث' : 'تمت الإضافة')
      setShowForm(false)
      load()
    } catch { toast.error('فشلت العملية') }
  }

  const handleDelete = async (id) => {
    if (!confirm('حذف هذا المورد؟')) return
    try { await deleteSupplier(id); toast.success('تم الحذف'); load() }
    catch { toast.error('فشل الحذف') }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div>
        <button onClick={openNew} className="btn btn-primary">
          <Plus size={16}/> إضافة مورد
        </button>
      </div>

      <div className="card">
        {loading ? (
          <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : suppliers.length === 0 ? (
          <div className="empty-state">لا يوجد موردون</div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>الاسم</th>
                  <th>الهاتف</th>
                  <th>البريد</th>
                  <th>العنوان</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {suppliers.map(s => (
                  <tr key={s.id}>
                    <td style={{ fontWeight: 600 }}>{s.name}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{s.phone ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{s.email ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{s.address ?? '—'}</td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                        <button onClick={() => openEdit(s)} className="btn btn-ghost btn-sm">تعديل</button>
                        <button onClick={() => handleDelete(s.id)} className="btn btn-danger btn-sm">حذف</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Form Modal */}
      {showForm && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setShowForm(false)}>
          <div className="modal" style={{ maxWidth: '480px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700 }}>{editing ? 'تعديل مورد' : 'إضافة مورد'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setShowForm(false)}><X size={18}/></button>
            </div>
            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              {[
                { key: 'name',    label: 'الاسم *',             type: 'text',  required: true },
                { key: 'phone',   label: 'الهاتف',              type: 'text' },
                { key: 'email',   label: 'البريد الإلكتروني',  type: 'email' },
                { key: 'address', label: 'العنوان',             type: 'text' },
              ].map(f => (
                <div key={f.key}>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '0.3rem' }}>{f.label}</label>
                  <input
                    type={f.type}
                    className="input"
                    required={f.required}
                    value={form[f.key]}
                    onChange={e => setForm({ ...form, [f.key]: e.target.value })}
                  />
                </div>
              ))}
              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
                <button type="submit" className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }}>
                  {editing ? 'حفظ التعديلات' : 'إضافة'}
                </button>
                <button type="button" onClick={() => setShowForm(false)} className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }}>
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
