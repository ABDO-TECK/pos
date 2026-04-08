import { useState, useEffect } from 'react'
import { Plus, Trash2, ShoppingCart, Check, X, Package } from 'lucide-react'
import BarcodeInput from '../components/pos/BarcodeInput'
import useProductStore from '../store/productStore'
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
      {/* Header + tab selector */}
      <div className="page-header" style={{ marginBottom: 0 }}>
        <h2>الموردون</h2>
        <div style={{
          display: 'flex', gap: '0.25rem',
          background: 'var(--bg)', borderRadius: 'var(--radius)',
          padding: '0.25rem', border: '1px solid var(--border)',
          flexShrink: 0,
        }}>
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
                whiteSpace: 'nowrap',
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
  const [allProducts, setAllProducts] = useState([])
  const [search, setSearch]         = useState('')
  const [cart, setCart]             = useState([])
  const [loading, setLoading]       = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [mobileTab, setMobileTab]   = useState('products') // mobile only

  const q = search.trim().toLowerCase()
  const products = q
    ? allProducts.filter((p) => {
        const nm = (p.name || '').toLowerCase().includes(q)
        const bc = (p.barcode || '').toLowerCase().includes(q)
        const ex = (p.additional_barcodes || []).some((b) => String(b).toLowerCase().includes(q))
        return nm || bc || ex
      })
    : allProducts

  useEffect(() => {
    getSuppliers().then(r => setSuppliers(r.data.data ?? []))
    setLoading(true)
    getProducts({ limit: 9999 })
      .then((r) => {
        const list = r.data.data ?? []
        setAllProducts(list)
        useProductStore.getState().setProducts(list)
      })
      .catch(() => toast.error('فشل تحميل المنتجات'))
      .finally(() => setLoading(false))
  }, [])

  const addToCart = (product) => {
    setCart(prev => {
      const existing = prev.find(c => c.product.id === product.id)
      if (existing) return prev.map(c => c.product.id === product.id ? { ...c, quantity: c.quantity + 1 } : c)
      return [...prev, { product, quantity: 1, cost: product.cost ?? product.price }]
    })
    toast.success(product.name, { duration: 700 })
  }

  const updateCartItem = (id, field, val) =>
    setCart(prev => prev.map(c => c.product.id === id ? { ...c, [field]: parseFloat(val) || 0 } : c))

  const removeFromCart = (id) => setCart(prev => prev.filter(c => c.product.id !== id))

  const cartTotal = cart.reduce((s, c) => s + c.cost * c.quantity, 0)
  const cartCount = cart.reduce((s, c) => s + c.quantity, 0)

  const handleConfirm = async () => {
    if (!supplierId) { toast.error('يرجى اختيار مورد'); return }
    if (cart.length === 0) { toast.error('السلة فارغة'); return }
    setConfirming(true)
    try {
      await createBulkPurchase({
        supplier_id: parseInt(supplierId),
        items: cart.map(c => ({ product_id: c.product.id, quantity: c.quantity, cost: c.cost, update_cost: true })),
      })
      toast.success('تم تسجيل الشراء وتحديث المخزون')
      setCart([])
      setMobileTab('products')
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل تسجيل الشراء')
    } finally {
      setConfirming(false)
    }
  }

  /* ── Shared panels ── */
  const ProductsPanel = (
    <div className="card pos-products-panel" style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: 0, overflow: 'hidden' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexShrink: 0 }}>
        <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
        <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
      </div>

      {/* Grid */}
      <div className="product-grid" style={{ overflowY: 'auto', flex: 1, alignContent: 'start' }}>
        {loading ? (
          <div style={{ gridColumn: '1/-1', padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : products.map(p => (
          <ProductCard key={p.id} product={p} onAdd={() => { addToCart(p); setMobileTab('cart') }} />
        ))}
      </div>
    </div>
  )

  const CartPanel = (
    <div style={{
      display: 'flex', flexDirection: 'column', flex: 1,
      background: 'var(--surface)', border: '1px solid var(--border)',
      borderRadius: 'var(--radius)', boxShadow: 'var(--shadow)', overflow: 'hidden',
    }}>
      {/* Supplier select */}
      <div style={{ padding: '0.75rem', borderBottom: '1px solid var(--border)' }}>
        <label style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.3rem' }}>المورد</label>
        <select className="input" value={supplierId} onChange={e => setSupplierId(e.target.value)}>
          <option value="">اختر مورد…</option>
          {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </div>

      {/* Items */}
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
                <button onClick={() => removeFromCart(c.product.id)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--danger)', padding: 0 }}>
                  <X size={13}/>
                </button>
              </div>
              <div style={{ display: 'flex', gap: '0.4rem' }}>
                <div style={{ flex: 1 }}>
                  <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>الكمية</label>
                  <input type="number" min="1" className="input"
                    style={{ padding: '0.3rem 0.5rem', fontSize: '0.82rem', marginTop: '0.15rem' }}
                    value={c.quantity} onChange={e => updateCartItem(c.product.id, 'quantity', e.target.value)}
                  />
                </div>
                <div style={{ flex: 1 }}>
                  <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>التكلفة</label>
                  <input type="number" min="0" step="0.5" className="input"
                    style={{ padding: '0.3rem 0.5rem', fontSize: '0.82rem', marginTop: '0.15rem' }}
                    value={c.cost} onChange={e => updateCartItem(c.product.id, 'cost', e.target.value)}
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
        <button onClick={handleConfirm} disabled={confirming || cart.length === 0 || !supplierId}
          className="btn btn-primary" style={{ justifyContent: 'center', width: '100%' }}>
          {confirming ? <span className="spinner" /> : <Check size={16}/>}
          تأكيد الاستلام
        </button>
      </div>
    </div>
  )

  return (
    <>
      {/* ── Search + barcode (نفس سلوك نقطة البيع: تركيز دائم ومسح متكرر) ── */}
      <div className="card" style={{ padding: '0.75rem', marginBottom: '0.75rem', flexShrink: 0 }}>
        <BarcodeInput
          onFilterChange={setSearch}
          allowOutOfStock
          onAddProduct={(p) => {
            addToCart(p)
            setMobileTab('cart')
          }}
        />
      </div>

      {/* ── Desktop layout ── */}
      <div className="sup-desktop">
        {ProductsPanel}
        <div style={{ width: '300px', flexShrink: 0, display: 'flex', flexDirection: 'column' }}>
          {CartPanel}
        </div>
      </div>

      {/* ── Mobile layout ── */}
      <div className="sup-mobile">
        {/* Tab content */}
        <div className="sup-mobile-content">
          {mobileTab === 'products' ? ProductsPanel : CartPanel}
        </div>

        {/* Bottom tab bar */}
        <div className="pos-tab-bar">
          <button className={`pos-tab${mobileTab === 'products' ? ' active' : ''}`} onClick={() => setMobileTab('products')}>
            <Package size={20} />
            <span>المنتجات</span>
          </button>
          <button className={`pos-tab${mobileTab === 'cart' ? ' active' : ''}`} onClick={() => setMobileTab('cart')}>
            <ShoppingCart size={20} />
            <span>الفاتورة</span>
            {cartCount > 0 && <span className="tab-badge">{formatNumber(cartCount)}</span>}
          </button>
        </div>
      </div>

      <style>{`
        .sup-desktop {
          display: flex;
          gap: 1rem;
          flex: 1;
          min-height: 0;
          overflow: hidden;
        }
        .sup-mobile { display: none; }

        @media (max-width: 767px) {
          .sup-desktop { display: none !important; }
          .sup-mobile {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
          }
          .sup-mobile-content {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
          }
          /* Reuse POS tab-bar styles */
          .pos-tab-bar {
            display: flex;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-top: 0.5rem;
            overflow: hidden;
            flex-shrink: 0;
          }
          .pos-tab {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
            padding: 0.6rem 0.5rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            position: relative;
            transition: background .15s;
          }
          .pos-tab.active {
            color: var(--primary);
            background: rgba(34,197,94,.08);
          }
          .tab-badge {
            position: absolute;
            top: 4px;
            right: calc(50% - 22px);
            background: var(--primary);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 9999px;
            padding: 0 5px;
            min-width: 18px;
            text-align: center;
            line-height: 18px;
          }
        }
      `}</style>
    </>
  )
}

function ProductCard({ product, onAdd }) {
  const isOutOfStock = product.quantity <= 0
  const isLowStock   = product.quantity <= product.low_stock_threshold && product.quantity > 0

  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onAdd}
      style={{
        background: 'var(--surface)',
        border: `1px solid ${isLowStock ? 'var(--warning)' : isOutOfStock ? '#fca5a5' : 'var(--border)'}`,
        borderRadius: 'var(--radius)',
        padding: '0.5rem 0.6rem',
        cursor: 'pointer',
        textAlign: 'right',
        transition: 'transform .1s, box-shadow .1s',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        height: '72px',
        overflow: 'hidden',
        touchAction: 'manipulation',
        fontFamily: 'inherit',
        width: '100%',
      }}
    >
      <div style={{
        fontSize: '0.78rem', fontWeight: 600, lineHeight: 1.3,
        overflow: 'hidden', display: '-webkit-box',
        WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
        color: 'var(--text)',
      }}>
        {product.name}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: '0.2rem' }}>
        <span style={{ fontSize: '0.82rem', fontWeight: 700, color: 'var(--primary)' }}>
          {formatCurrency(product.cost > 0 ? product.cost : product.price)}
        </span>
        {isOutOfStock && <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>}
        {isLowStock   && <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>}
      </div>
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

  const openNew = () => { setEditing(null); setForm({ name: '', phone: '', email: '', address: '' }); setShowForm(true) }
  const openEdit = (s) => { setEditing(s); setForm({ name: s.name, phone: s.phone ?? '', email: s.email ?? '', address: s.address ?? '' }); setShowForm(true) }

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
        <button onClick={openNew} className="btn btn-primary"><Plus size={16}/> إضافة مورد</button>
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
                  <th className="hide-mobile">الهاتف</th>
                  <th className="hide-mobile">البريد</th>
                  <th className="hide-mobile">العنوان</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {suppliers.map(s => (
                  <tr key={s.id}>
                    <td style={{ fontWeight: 600 }}>
                      {s.name}
                      {/* Show phone under name on mobile */}
                      {s.phone && <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', fontWeight: 400 }} className="show-mobile">{s.phone}</div>}
                    </td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.phone ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.email ?? '—'}</td>
                    <td style={{ color: 'var(--text-muted)' }} className="hide-mobile">{s.address ?? '—'}</td>
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
                { key: 'name',    label: 'الاسم *',            type: 'text',  required: true },
                { key: 'phone',   label: 'الهاتف',             type: 'text' },
                { key: 'email',   label: 'البريد الإلكتروني', type: 'email' },
                { key: 'address', label: 'العنوان',            type: 'text' },
              ].map(fi => (
                <div key={fi.key}>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '0.3rem' }}>{fi.label}</label>
                  <input type={fi.type} className="input" required={fi.required}
                    value={form[fi.key]} onChange={e => setForm({ ...form, [fi.key]: e.target.value })}
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
