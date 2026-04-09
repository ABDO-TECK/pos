import { useState, useEffect, useMemo } from 'react'
import { Plus, Minus, Trash2, ShoppingCart, Check, X, Package, Search, FileText, Calendar, ChevronDown, ChevronUp, Eye, Filter, TrendingUp, Hash, DollarSign, Clock } from 'lucide-react'
import BarcodeInput from '../components/pos/BarcodeInput'
import useProductStore from '../store/productStore'
import toast from 'react-hot-toast'
import {
  getSuppliers, createSupplier, updateSupplier, deleteSupplier,
  getProducts, createBulkPurchase, getPurchases,
} from '../api/endpoints'
import { formatCurrency, formatNumber, formatDate, formatShortDate, formatTime } from '../utils/formatters'

export default function Suppliers() {
  const [tab, setTab] = useState(0)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', height: 'calc(100vh - 3rem)' }}>
      {/* Header + tab selector */}
      <div className="page-header" style={{ marginBottom: 0 }}>
        <h2>الموردون</h2>
        <div style={{
          display: 'flex', gap: '0.25rem',
          background: 'var(--bg)', borderRadius: 'var(--radius)',
          padding: '0.25rem', border: '1px solid var(--border)',
          flexShrink: 0,
        }}>
          {['استلام بضاعة', 'سجل المشتريات', 'إدارة الموردين'].map((t, i) => (
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

      {tab === 0 && <ReceiveGoods />}
      {tab === 1 && <PurchaseHistory />}
      {tab === 2 && <ManageSuppliers />}
    </div>
  )
}

/** سطر سلة استلام — نفس منطق وواجهة الصندوق في `components/pos/Cart.jsx` */
function ReceiveGoodsCartLine({ line, onUpdateQty, onUpdateCost, onRemove }) {
  const product     = line.product
  const unitsPerBox = Math.max(1, parseInt(product.units_per_box, 10) || 1)
  const hasBox      = unitsPerBox > 1
  const [unitMode, setUnitMode] = useState('piece')

  const boxCount   = unitMode === 'box' ? Math.max(1, Math.round(line.quantity / unitsPerBox)) : null
  const displayQty = unitMode === 'box' ? boxCount : line.quantity

  const handleUnitModeChange = (mode) => {
    if (mode === unitMode) return
    setUnitMode(mode)
    if (mode === 'piece') {
      onUpdateQty(product.id, 1)
    } else {
      onUpdateQty(product.id, unitsPerBox)
    }
  }

  const handleDecrement = () => {
    if (unitMode === 'box') {
      const newBoxes = Math.max(1, boxCount - 1)
      onUpdateQty(product.id, newBoxes * unitsPerBox)
    } else {
      onUpdateQty(product.id, line.quantity - 1)
    }
  }

  const handleIncrement = () => {
    if (unitMode === 'box') {
      onUpdateQty(product.id, (boxCount + 1) * unitsPerBox)
    } else {
      onUpdateQty(product.id, line.quantity + 1)
    }
  }

  const handleQtyInputChange = (raw) => {
    const val = parseInt(raw, 10) || 1
    if (unitMode === 'box') {
      onUpdateQty(product.id, Math.max(1, val) * unitsPerBox)
    } else {
      onUpdateQty(product.id, Math.max(1, val))
    }
  }

  return (
    <div
      style={{
        padding: '0.6rem 0.75rem',
        background: 'var(--surface)',
        borderRadius: '0.4rem',
        border: '1px solid var(--border)',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.5rem' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div
            style={{
              fontWeight: 600,
              fontSize: '0.88rem',
              lineHeight: 1.4,
              wordBreak: 'break-word',
              overflowWrap: 'anywhere',
            }}
          >
            {product.name}
          </div>
          <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>{product.barcode}</div>
          <div style={{ fontSize: '0.85rem', color: 'var(--secondary)', fontWeight: 700 }}>
            {formatCurrency(line.cost * line.quantity)}
          </div>
        </div>
        <div
          style={{
            fontSize: '0.8rem',
            color: 'var(--text-muted)',
            minWidth: '50px',
            textAlign: 'left',
            flexShrink: 0,
            marginTop: '0.1rem',
          }}
        >
          {formatCurrency(line.cost)}
        </div>
        <button
          type="button"
          className="btn btn-icon"
          style={{
            padding: '0.3rem',
            color: 'var(--danger)',
            background: 'transparent',
            border: 'none',
            flexShrink: 0,
            marginTop: '0.1rem',
          }}
          onClick={onRemove}
          title="إزالة"
        >
          <Trash2 size={16} />
        </button>
      </div>

      <div style={{ marginTop: '0.45rem' }}>
        <label style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.2rem' }}>
          التكلفة للقطعة
        </label>
        <input
          type="number"
          min="0"
          step="0.01"
          className="input"
          style={{ width: '100%', padding: '0.35rem 0.5rem', fontSize: '0.85rem' }}
          value={line.cost}
          onChange={(e) => onUpdateCost(product.id, e.target.value)}
        />
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginTop: '0.45rem', flexWrap: 'wrap' }}>
        {hasBox && (
          <select
            value={unitMode}
            onChange={(e) => handleUnitModeChange(e.target.value)}
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.22rem 0.35rem',
              border: `1px solid ${unitMode === 'box' ? 'var(--secondary)' : 'var(--border)'}`,
              borderRadius: '0.3rem',
              background: unitMode === 'box' ? 'rgba(59,130,246,0.08)' : 'var(--surface)',
              color: unitMode === 'box' ? 'var(--secondary)' : 'var(--text)',
              cursor: 'pointer',
              flexShrink: 0,
              fontFamily: 'inherit',
            }}
          >
            <option value="piece">قطعة</option>
            <option value="box">صندوق ({unitsPerBox})</option>
          </select>
        )}

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flex: 1, minWidth: '140px' }}>
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleDecrement}
          >
            <Minus size={14} />
          </button>
          <input
            type="number"
            min={1}
            value={displayQty}
            onChange={(e) => handleQtyInputChange(e.target.value)}
            style={{
              width: '3rem',
              textAlign: 'center',
              border: '1px solid var(--border)',
              borderRadius: '0.3rem',
              padding: '0.25rem 0.2rem',
              fontSize: '0.9rem',
            }}
          />
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleIncrement}
          >
            <Plus size={14} />
          </button>
        </div>

        <span
          style={{
            fontSize: '0.72rem',
            color: unitMode === 'box' ? 'var(--secondary)' : 'var(--text-muted)',
            fontWeight: 600,
            flexShrink: 0,
            display: 'flex',
            alignItems: 'center',
            gap: '0.2rem',
          }}
        >
          {unitMode === 'box' ? (
            <>
              <Package size={11} /> {formatNumber(line.quantity)} قطعة
            </>
          ) : null}
        </span>
      </div>
    </div>
  )
}

/* ──────────────────────────── Receive Goods (POS-like) ── */
function ReceiveGoods() {
  const [suppliers, setSuppliers]     = useState([])
  const [supplierId, setSupplierId]   = useState('')
  const [allProducts, setAllProducts] = useState([])
  const [search, setSearch]           = useState('')
  const [cart, setCart]               = useState([])
  const [loading, setLoading]         = useState(false)
  const [confirming, setConfirming]   = useState(false)
  const [mobileTab, setMobileTab]     = useState('products')

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

  /* ── Cart helpers ── */
  const addToCart = (product) => {
    setCart((prev) => {
      const existing = prev.find((c) => c.product.id === product.id)
      if (existing) {
        return prev.map((c) =>
          c.product.id === product.id ? { ...c, quantity: c.quantity + 1 } : c
        )
      }
      return [
        ...prev,
        {
          product,
          quantity: 1,
          cost:
            parseFloat(product.cost) > 0
              ? parseFloat(product.cost)
              : parseFloat(product.price) || 0,
        },
      ]
    })
    toast.success(product.name, { duration: 700 })
  }

  const updateLineQuantity = (productId, qty) => {
    const q = Math.max(1, parseInt(qty, 10) || 1)
    setCart((prev) =>
      prev.map((c) => (c.product.id === productId ? { ...c, quantity: q } : c))
    )
  }

  const updateLineCost = (productId, raw) => {
    const v = parseFloat(raw)
    const cost = Number.isFinite(v) && v >= 0 ? v : 0
    setCart((prev) =>
      prev.map((c) => (c.product.id === productId ? { ...c, cost } : c))
    )
  }

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

  /* ── Panels ── */
  const ProductsPanel = (
    <div className="card sup-products-panel">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexShrink: 0 }}>
        <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
        <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
      </div>
      <div className="product-grid">
        {loading ? (
          <div style={{ gridColumn: '1/-1', padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : products.map(p => (
          <ProductCard key={p.id} product={p} onAdd={() => { addToCart(p); setMobileTab('cart') }} />
        ))}
      </div>
    </div>
  )

  const CartPanel = (
    <div className="card sup-cart-panel">
      {/* Supplier select */}
      <div style={{ flexShrink: 0, borderBottom: '1px solid var(--border)', paddingBottom: '0.75rem', marginBottom: '0.5rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontWeight: 700 }}>
            <ShoppingCart size={18} />
            السلة
            {cartCount > 0 && <span className="badge badge-green">{formatNumber(cartCount)}</span>}
          </div>
          {cart.length > 0 && (
            <button
              className="btn btn-ghost btn-sm"
              onClick={() => { setCart([]); toast('تم مسح السلة') }}
              style={{ color: 'var(--danger)' }}
            >
              <Trash2 size={14} /> مسح الكل
            </button>
          )}
        </div>
        <label style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', display: 'block', marginBottom: '0.3rem' }}>المورد</label>
        <select className="input" value={supplierId} onChange={e => setSupplierId(e.target.value)}>
          <option value="">اختر مورد…</option>
          {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </div>

      {/* Items — scrollable, bounded by parent height */}
      <div style={{ flex: 1, minHeight: 0, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
        {cart.length === 0 ? (
          <div className="empty-state" style={{ padding: '2rem' }}>
            <ShoppingCart size={28} style={{ opacity: 0.3 }} />
            <span style={{ fontSize: '0.85rem' }}>اضغط على منتج لإضافته</span>
          </div>
        ) : (
          cart.map((c) => (
            <ReceiveGoodsCartLine
              key={c.product.id}
              line={c}
              onUpdateQty={updateLineQuantity}
              onUpdateCost={updateLineCost}
              onRemove={() => removeFromCart(c.product.id)}
            />
          ))
        )}
      </div>

      {/* Total + confirm */}
      <div style={{ flexShrink: 0, borderTop: '1px solid var(--border)', paddingTop: '0.75rem', marginTop: '0.5rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {cart.length > 0 && (
          <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 700, fontSize: '1rem', paddingBottom: '0.25rem' }}>
            <span>الإجمالي</span>
            <span style={{ color: 'var(--secondary)' }}>{formatCurrency(cartTotal)}</span>
          </div>
        )}
        <button onClick={handleConfirm} disabled={confirming || cart.length === 0 || !supplierId}
          className="btn btn-primary btn-lg" style={{ justifyContent: 'center', width: '100%' }}>
          {confirming ? <span className="spinner" /> : <Check size={18} />}
          تأكيد الاستلام{cart.length > 0 ? ` — ${formatCurrency(cartTotal)}` : ''}
        </button>
      </div>
    </div>
  )

  return (
    <>
      {/* Barcode input — flexShrink:0 so it never grows */}
      <div className="card" style={{ padding: '0.75rem', flexShrink: 0 }}>
        <BarcodeInput
          onFilterChange={setSearch}
          allowOutOfStock
          onAddProduct={(p) => { addToCart(p); setMobileTab('cart') }}
        />
      </div>

      {/* Desktop layout — flex:1 + minHeight:0 so it fills exactly what's left */}
      <div className="sup-desktop">
        {ProductsPanel}
        {CartPanel}
      </div>

      {/* Mobile layout */}
      <div className="sup-mobile">
        <div className="sup-mobile-content">
          {mobileTab === 'products' ? ProductsPanel : CartPanel}
        </div>
        <div className="pos-tab-bar">
          <button className={`pos-tab${mobileTab === 'products' ? ' active' : ''}`} onClick={() => setMobileTab('products')}>
            <Package size={20} />
            <span>المنتجات</span>
          </button>
          <button className={`pos-tab${mobileTab === 'cart' ? ' active' : ''}`} onClick={() => setMobileTab('cart')}>
            <ShoppingCart size={20} />
            <span>السلة</span>
            {cartCount > 0 && <span className="tab-badge">{formatNumber(cartCount)}</span>}
          </button>
        </div>
      </div>

      <style>{`
        /* ── Desktop ── */
        .sup-desktop {
          display: flex;
          gap: 0.75rem;
          flex: 1;
          min-height: 0;      /* KEY: allows children to shrink below content size */
          overflow: hidden;
        }
        .sup-mobile { display: none; }

        /* Products panel */
        .sup-products-panel {
          flex: 1;
          padding: 0.75rem;
          overflow: hidden;
          display: flex;
          flex-direction: column;
          min-width: 0;
          min-height: 0;
        }

        /* Cart panel — fixed width, flex column, NO overflow on panel itself */
        .sup-cart-panel {
          width: 320px;
          flex-shrink: 0;
          padding: 0.75rem;
          display: flex;
          flex-direction: column;
          min-height: 0;      /* KEY */
          overflow: hidden;   /* clip to bounds */
          gap: 0;
        }

        /* ── Mobile ── */
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

          /* ── Cart panel: full width on mobile ── */
          .sup-cart-panel {
            width: 100% !important;
            flex: 1;
            min-height: 0;
            padding: 0.6rem;
            overflow: hidden;
          }

          /* ── Products panel: full width on mobile ── */
          .sup-products-panel {
            width: 100% !important;
            flex: 1;
            min-height: 0;
            padding: 0.6rem;
          }
          .sup-products-panel .product-grid {
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 0.35rem;
            overflow-y: auto;
            flex: 1;
            align-content: start;
          }

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

/* ── Product card — same as POS but shows cost instead of price ── */
function ProductCard({ product, onAdd }) {
  const isOutOfStock = product.quantity <= 0
  const isLowStock   = product.quantity <= product.low_stock_threshold && product.quantity > 0
  const upb          = parseInt(product.units_per_box) || 1

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
        height: '78px',
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
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: '0.2rem', gap: '0.25rem', flexWrap: 'wrap' }}>
        <span style={{ fontSize: '0.82rem', fontWeight: 700, color: 'var(--secondary)' }}>
          {formatCurrency(parseFloat(product.cost) > 0 ? product.cost : product.price)}
        </span>
        <div style={{ display: 'flex', gap: '0.2rem', alignItems: 'center' }}>
          {upb > 1 && (
            <span className="badge badge-blue" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>
              📦 {formatNumber(upb)}
            </span>
          )}
          {isOutOfStock && <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>}
          {isLowStock   && <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>}
        </div>
      </div>
    </button>
  )
}

/* ──────────────────────────── Purchase History ── */
function PurchaseHistory() {
  const [purchases, setPurchases]     = useState([])
  const [suppliers, setSuppliers]     = useState([])
  const [loading, setLoading]         = useState(false)
  const [supplierId, setSupplierId]   = useState('')
  const [dateFrom, setDateFrom]       = useState('')
  const [dateTo, setDateTo]           = useState('')
  const [expandedDays, setExpandedDays] = useState({})
  const [detailPurchase, setDetailPurchase] = useState(null)
  const [showFilters, setShowFilters] = useState(true)

  const load = async () => {
    setLoading(true)
    try {
      const params = {}
      if (supplierId) params.supplier_id = supplierId
      if (dateFrom) params.date_from = dateFrom
      if (dateTo) params.date_to = dateTo
      const res = await getPurchases(params)
      setPurchases(res.data.data ?? [])
    } catch {
      toast.error('فشل تحميل سجل المشتريات')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    getSuppliers().then(r => setSuppliers(r.data.data ?? []))
  }, [])

  useEffect(() => { load() }, [supplierId, dateFrom, dateTo])

  /* ── Group purchases by date ── */
  const grouped = useMemo(() => {
    const map = {}
    purchases.forEach(p => {
      const day = p.created_at ? p.created_at.split(' ')[0] : 'unknown'
      if (!map[day]) map[day] = []
      map[day].push(p)
    })
    // Sort days descending
    return Object.entries(map).sort(([a], [b]) => b.localeCompare(a))
  }, [purchases])

  /* ── Summary stats ── */
  const stats = useMemo(() => {
    const totalCost = purchases.reduce((s, p) => s + parseFloat(p.total || 0), 0)
    const totalItems = purchases.reduce((s, p) => s + parseInt(p.quantity || 0, 10), 0)
    const uniqueSuppliers = new Set(purchases.map(p => p.supplier_id)).size
    const uniqueProducts = new Set(purchases.map(p => p.product_id)).size
    return { totalCost, totalItems, uniqueSuppliers, uniqueProducts }
  }, [purchases])

  const toggleDay = (day) => {
    setExpandedDays(prev => ({ ...prev, [day]: !prev[day] }))
  }

  const clearFilters = () => {
    setSupplierId('')
    setDateFrom('')
    setDateTo('')
  }

  const hasFilters = supplierId || dateFrom || dateTo

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', flex: 1, minHeight: 0, overflow: 'hidden' }}>
      {/* ── Filters ── */}
      <div className="card" style={{ padding: '0.75rem', flexShrink: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: showFilters ? '0.75rem' : 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <Filter size={16} style={{ color: 'var(--primary)' }} />
            <span style={{ fontWeight: 700, fontSize: '0.9rem' }}>فلاتر البحث</span>
            {hasFilters && (
              <span className="badge badge-green" style={{ fontSize: '0.7rem' }}>نشط</span>
            )}
          </div>
          <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
            {hasFilters && (
              <button
                className="btn btn-ghost btn-sm"
                onClick={clearFilters}
                style={{ color: 'var(--danger)', fontSize: '0.78rem' }}
              >
                <X size={14} /> مسح
              </button>
            )}
            <button
              className="btn btn-ghost btn-icon btn-sm"
              onClick={() => setShowFilters(!showFilters)}
              style={{ padding: '0.25rem' }}
            >
              {showFilters ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
            </button>
          </div>
        </div>

        {showFilters && (
          <div className="ph-filters-grid">
            <div>
              <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.25rem' }}>
                المورد
              </label>
              <select className="input" value={supplierId} onChange={e => setSupplierId(e.target.value)}>
                <option value="">جميع الموردين</option>
                {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
            <div>
              <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.25rem' }}>
                <Calendar size={12} style={{ marginLeft: '0.2rem', verticalAlign: 'middle' }} />
                من تاريخ
              </label>
              <input
                type="date"
                className="input"
                value={dateFrom}
                onChange={e => setDateFrom(e.target.value)}
              />
            </div>
            <div>
              <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.25rem' }}>
                <Calendar size={12} style={{ marginLeft: '0.2rem', verticalAlign: 'middle' }} />
                إلى تاريخ
              </label>
              <input
                type="date"
                className="input"
                value={dateTo}
                onChange={e => setDateTo(e.target.value)}
              />
            </div>
          </div>
        )}
      </div>

      {/* ── Stats Cards ── */}
      {purchases.length > 0 && (
        <div className="ph-stats-grid" style={{ flexShrink: 0 }}>
          <div className="ph-stat-card">
            <div className="ph-stat-icon" style={{ background: 'rgba(34,197,94,0.1)', color: 'var(--primary)' }}>
              <DollarSign size={18} />
            </div>
            <div>
              <div className="ph-stat-value">{formatCurrency(stats.totalCost)}</div>
              <div className="ph-stat-label">إجمالي المشتريات</div>
            </div>
          </div>
          <div className="ph-stat-card">
            <div className="ph-stat-icon" style={{ background: 'rgba(59,130,246,0.1)', color: 'var(--secondary)' }}>
              <Hash size={18} />
            </div>
            <div>
              <div className="ph-stat-value">{formatNumber(stats.totalItems)}</div>
              <div className="ph-stat-label">إجمالي القطع</div>
            </div>
          </div>
          <div className="ph-stat-card">
            <div className="ph-stat-icon" style={{ background: 'rgba(168,85,247,0.1)', color: '#a855f7' }}>
              <TrendingUp size={18} />
            </div>
            <div>
              <div className="ph-stat-value">{formatNumber(stats.uniqueProducts)}</div>
              <div className="ph-stat-label">منتجات مختلفة</div>
            </div>
          </div>
          <div className="ph-stat-card">
            <div className="ph-stat-icon" style={{ background: 'rgba(249,115,22,0.1)', color: '#f97316' }}>
              <Package size={18} />
            </div>
            <div>
              <div className="ph-stat-value">{formatNumber(stats.uniqueSuppliers)}</div>
              <div className="ph-stat-label">موردون</div>
            </div>
          </div>
        </div>
      )}

      {/* ── Purchase List Grouped by Date ── */}
      <div className="card" style={{ flex: 1, minHeight: 0, overflow: 'hidden', display: 'flex', flexDirection: 'column', padding: 0 }}>
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '0.75rem', borderBottom: '1px solid var(--border)', flexShrink: 0,
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
            <FileText size={16} style={{ color: 'var(--primary)' }} />
            <span style={{ fontWeight: 700, fontSize: '0.92rem' }}>سجل المشتريات</span>
          </div>
          <span className="badge badge-gray">{formatNumber(purchases.length)} سجل</span>
        </div>

        <div style={{ flex: 1, minHeight: 0, overflowY: 'auto', padding: '0.5rem 0.75rem' }}>
          {loading ? (
            <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
          ) : purchases.length === 0 ? (
            <div className="empty-state" style={{ padding: '3rem' }}>
              <FileText size={36} style={{ opacity: 0.25 }} />
              <span style={{ fontSize: '0.9rem', marginTop: '0.5rem' }}>
                {hasFilters ? 'لا توجد نتائج للفلاتر المحددة' : 'لا توجد مشتريات مسجلة بعد'}
              </span>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
              {grouped.map(([day, items]) => {
                const expanded = expandedDays[day] !== false // default expanded
                const dayTotal = items.reduce((s, p) => s + parseFloat(p.total || 0), 0)
                const dayItems = items.reduce((s, p) => s + parseInt(p.quantity || 0, 10), 0)

                return (
                  <div key={day} className="ph-day-group">
                    <button
                      type="button"
                      onClick={() => toggleDay(day)}
                      className="ph-day-header"
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                        <div className="ph-day-dot" />
                        <div>
                          <span className="ph-day-date">{formatShortDate(day)}</span>
                          <span className="ph-day-meta">
                            {formatNumber(items.length)} عملية  •  {formatNumber(dayItems)} قطعة
                          </span>
                        </div>
                      </div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
                        <span className="ph-day-total">{formatCurrency(dayTotal)}</span>
                        {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                      </div>
                    </button>

                    {expanded && (
                      <div className="ph-day-items">
                        {/* Desktop table */}
                        <div className="ph-table-wrap hide-mobile">
                          <table className="ph-table">
                            <thead>
                              <tr>
                                <th>الوقت</th>
                                <th>المنتج</th>
                                <th>الباركود</th>
                                <th>المورد</th>
                                <th>الكمية</th>
                                <th>التكلفة</th>
                                <th>الإجمالي</th>
                              </tr>
                            </thead>
                            <tbody>
                              {items.map(p => (
                                <tr key={p.id} onClick={() => setDetailPurchase(p)} style={{ cursor: 'pointer' }}>
                                  <td>
                                    <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                                      <Clock size={12} style={{ marginLeft: '0.2rem', verticalAlign: 'middle' }} />
                                      {formatTime(p.created_at)}
                                    </span>
                                  </td>
                                  <td style={{ fontWeight: 600 }}>{p.product_name}</td>
                                  <td style={{ fontSize: '0.8rem', color: 'var(--text-muted)', fontFamily: 'monospace' }}>
                                    {p.product_barcode || '—'}
                                  </td>
                                  <td>
                                    <span className="badge badge-blue" style={{ fontSize: '0.72rem' }}>
                                      {p.supplier_name}
                                    </span>
                                  </td>
                                  <td style={{ fontWeight: 600 }}>{formatNumber(p.quantity)}</td>
                                  <td style={{ color: 'var(--text-muted)' }}>{formatCurrency(p.cost)}</td>
                                  <td style={{ fontWeight: 700, color: 'var(--primary-d)' }}>{formatCurrency(p.total)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>

                        {/* Mobile cards */}
                        <div className="show-mobile" style={{ display: 'none', flexDirection: 'column', gap: '0.4rem' }}>
                          {items.map(p => (
                            <div
                              key={p.id}
                              className="ph-mobile-card"
                              onClick={() => setDetailPurchase(p)}
                            >
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                                <div style={{ flex: 1, minWidth: 0 }}>
                                  <div style={{ fontWeight: 600, fontSize: '0.85rem', lineHeight: 1.3, wordBreak: 'break-word' }}>
                                    {p.product_name}
                                  </div>
                                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: '0.15rem' }}>
                                    {p.supplier_name}  •  {formatTime(p.created_at)}
                                  </div>
                                </div>
                                <div style={{ textAlign: 'left', flexShrink: 0, marginRight: '0.5rem' }}>
                                  <div style={{ fontWeight: 700, fontSize: '0.88rem', color: 'var(--primary-d)' }}>
                                    {formatCurrency(p.total)}
                                  </div>
                                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                                    {formatNumber(p.quantity)} × {formatCurrency(p.cost)}
                                  </div>
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      {/* ── Detail Modal ── */}
      {detailPurchase && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setDetailPurchase(null)}>
          <div className="modal" style={{ maxWidth: '420px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700, fontSize: '1rem' }}>تفاصيل عملية الشراء</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setDetailPurchase(null)}>
                <X size={18} />
              </button>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div className="ph-detail-row">
                <span className="ph-detail-label">رقم العملية</span>
                <span className="ph-detail-value" style={{ fontFamily: 'monospace' }}>#{detailPurchase.id}</span>
              </div>
              <div className="ph-detail-row">
                <span className="ph-detail-label">المنتج</span>
                <span className="ph-detail-value" style={{ fontWeight: 600 }}>{detailPurchase.product_name}</span>
              </div>
              {detailPurchase.product_barcode && (
                <div className="ph-detail-row">
                  <span className="ph-detail-label">الباركود</span>
                  <span className="ph-detail-value" style={{ fontFamily: 'monospace' }}>{detailPurchase.product_barcode}</span>
                </div>
              )}
              <div className="ph-detail-row">
                <span className="ph-detail-label">المورد</span>
                <span className="ph-detail-value">
                  <span className="badge badge-blue">{detailPurchase.supplier_name}</span>
                </span>
              </div>

              <div style={{ borderTop: '1px solid var(--border)', margin: '0.25rem 0' }} />

              <div className="ph-detail-row">
                <span className="ph-detail-label">الكمية</span>
                <span className="ph-detail-value" style={{ fontWeight: 700 }}>{formatNumber(detailPurchase.quantity)} قطعة</span>
              </div>
              <div className="ph-detail-row">
                <span className="ph-detail-label">تكلفة الوحدة</span>
                <span className="ph-detail-value">{formatCurrency(detailPurchase.cost)}</span>
              </div>
              <div className="ph-detail-row" style={{ background: 'rgba(34,197,94,0.06)', padding: '0.5rem 0.6rem', borderRadius: '0.4rem' }}>
                <span className="ph-detail-label" style={{ fontWeight: 700, color: 'var(--text)' }}>الإجمالي</span>
                <span className="ph-detail-value" style={{ fontWeight: 700, fontSize: '1.05rem', color: 'var(--primary-d)' }}>
                  {formatCurrency(detailPurchase.total)}
                </span>
              </div>

              <div style={{ borderTop: '1px solid var(--border)', margin: '0.25rem 0' }} />

              <div className="ph-detail-row">
                <span className="ph-detail-label">التاريخ والوقت</span>
                <span className="ph-detail-value">{formatDate(detailPurchase.created_at)}</span>
              </div>
              {detailPurchase.notes && (
                <div className="ph-detail-row" style={{ flexDirection: 'column', gap: '0.25rem' }}>
                  <span className="ph-detail-label">ملاحظات</span>
                  <span className="ph-detail-value" style={{ fontSize: '0.85rem' }}>{detailPurchase.notes}</span>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      <style>{`
        /* ── Filters Grid ── */
        .ph-filters-grid {
          display: grid;
          grid-template-columns: 1fr 1fr 1fr;
          gap: 0.6rem;
        }

        /* ── Stats Grid ── */
        .ph-stats-grid {
          display: grid;
          grid-template-columns: repeat(4, 1fr);
          gap: 0.5rem;
        }
        .ph-stat-card {
          display: flex;
          align-items: center;
          gap: 0.6rem;
          padding: 0.65rem 0.75rem;
          background: var(--surface);
          border: 1px solid var(--border);
          border-radius: var(--radius);
        }
        .ph-stat-icon {
          width: 36px;
          height: 36px;
          border-radius: 0.5rem;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
        }
        .ph-stat-value {
          font-weight: 700;
          font-size: 0.92rem;
          line-height: 1.2;
        }
        .ph-stat-label {
          font-size: 0.72rem;
          color: var(--text-muted);
          margin-top: 0.1rem;
        }

        /* ── Day Group ── */
        .ph-day-group {
          border: 1px solid var(--border);
          border-radius: var(--radius);
          overflow: hidden;
          background: var(--surface);
        }
        .ph-day-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          width: 100%;
          padding: 0.6rem 0.75rem;
          border: none;
          background: var(--bg);
          cursor: pointer;
          font-family: inherit;
          font-size: 0.85rem;
          color: var(--text);
          transition: background .15s;
        }
        .ph-day-header:hover {
          background: rgba(34,197,94,0.04);
        }
        .ph-day-dot {
          width: 8px;
          height: 8px;
          border-radius: 50%;
          background: var(--primary);
          flex-shrink: 0;
        }
        .ph-day-date {
          font-weight: 700;
          font-size: 0.88rem;
        }
        .ph-day-meta {
          margin-right: 0.5rem;
          font-size: 0.75rem;
          color: var(--text-muted);
        }
        .ph-day-total {
          font-weight: 700;
          font-size: 0.88rem;
          color: var(--primary-d);
        }
        .ph-day-items {
          padding: 0.5rem 0.75rem 0.75rem;
          border-top: 1px solid var(--border);
        }

        /* ── Table ── */
        .ph-table-wrap {
          overflow-x: auto;
        }
        .ph-table {
          width: 100%;
          border-collapse: collapse;
          font-size: 0.82rem;
        }
        .ph-table th {
          text-align: right;
          padding: 0.45rem 0.5rem;
          font-weight: 600;
          font-size: 0.75rem;
          color: var(--text-muted);
          background: var(--bg);
          border-bottom: 1px solid var(--border);
          white-space: nowrap;
        }
        .ph-table td {
          padding: 0.45rem 0.5rem;
          border-bottom: 1px solid var(--border);
          white-space: nowrap;
        }
        .ph-table tbody tr:last-child td {
          border-bottom: none;
        }
        .ph-table tbody tr:hover {
          background: rgba(34,197,94,0.03);
        }

        /* ── Mobile Card ── */
        .ph-mobile-card {
          padding: 0.6rem 0.7rem;
          border: 1px solid var(--border);
          border-radius: 0.4rem;
          background: var(--bg);
          cursor: pointer;
          transition: background .15s;
        }
        .ph-mobile-card:active {
          background: rgba(34,197,94,0.05);
        }

        /* ── Detail Modal ── */
        .ph-detail-row {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 0.5rem;
        }
        .ph-detail-label {
          font-size: 0.82rem;
          color: var(--text-muted);
          flex-shrink: 0;
        }
        .ph-detail-value {
          font-size: 0.88rem;
          text-align: left;
        }

        /* ── Responsive ── */
        @media (max-width: 767px) {
          .ph-filters-grid {
            grid-template-columns: 1fr;
          }
          .ph-stats-grid {
            grid-template-columns: repeat(2, 1fr);
          }
          .ph-day-meta {
            display: none;
          }
          .show-mobile {
            display: flex !important;
          }
        }

        @media (max-width: 480px) {
          .ph-stats-grid {
            grid-template-columns: 1fr 1fr;
          }
          .ph-stat-card {
            padding: 0.5rem 0.6rem;
          }
          .ph-stat-icon {
            width: 30px;
            height: 30px;
          }
          .ph-stat-value {
            font-size: 0.82rem;
          }
        }
      `}</style>
    </div>
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

  const openNew  = () => { setEditing(null); setForm({ name: '', phone: '', email: '', address: '' }); setShowForm(true) }
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
        <button onClick={openNew} className="btn btn-primary"><Plus size={16} /> إضافة مورد</button>
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

      {showForm && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setShowForm(false)}>
          <div className="modal" style={{ maxWidth: '480px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700 }}>{editing ? 'تعديل مورد' : 'إضافة مورد'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setShowForm(false)}><X size={18} /></button>
            </div>
            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              {[
                { key: 'name',    label: 'الاسم *',            type: 'text',  required: true },
                { key: 'phone',   label: 'الهاتف',             type: 'text' },
                { key: 'email',   label: 'البريد الإلكتروني',  type: 'email' },
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
