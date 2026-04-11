import { useState, useEffect } from 'react'
import { Plus, Minus, Trash2, ShoppingCart, Check, Package } from 'lucide-react'
import BarcodeInput from '../../components/pos/BarcodeInput'
import useProductStore from '../../store/productStore'
import toast from 'react-hot-toast'
import { getSuppliers, getProducts, createBulkPurchase } from '../../api/endpoints'
import { formatCurrency, formatNumber } from '../../utils/formatters'

/* ── Credit Purchase Section ── */
function CreditPurchaseSection({ paymentType, setPaymentType, deposit, setDeposit, cartTotal }) {
  const amountDue = paymentType === 'credit' ? Math.max(0, cartTotal - deposit) : 0

  return (
    <div style={{
      border: `1px solid ${paymentType === 'credit' ? 'rgba(239,68,68,.3)' : 'var(--border)'}`,
      borderRadius: 'var(--radius)',
      background: paymentType === 'credit' ? 'rgba(239,68,68,.03)' : 'var(--surface)',
      padding: '0.65rem', display: 'flex', flexDirection: 'column', gap: '0.5rem',
    }}>
      <div style={{ display: 'flex', gap: '0.35rem' }}>
        {[
          { id: 'cash', label: '💵 نقدي' },
          { id: 'credit', label: '⏳ آجل' },
        ].map(m => (
          <button key={m.id} onClick={() => setPaymentType(m.id)}
            style={{
              flex: 1, padding: '0.35rem', fontSize: '0.82rem', fontWeight: 600,
              borderRadius: 'var(--radius)',
              border: `2px solid ${paymentType === m.id ? (m.id === 'credit' ? 'var(--danger)' : 'var(--primary)') : 'var(--border)'}`,
              background: paymentType === m.id ? (m.id === 'credit' ? 'rgba(239,68,68,.1)' : '#dcfce7') : 'var(--surface)',
              color: paymentType === m.id ? (m.id === 'credit' ? 'var(--danger)' : 'var(--primary-d)') : 'var(--text)',
              cursor: 'pointer',
            }}>
            {m.label}
          </button>
        ))}
      </div>

      {paymentType === 'credit' && (
        <>
          <div>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, display: 'block', marginBottom: '0.2rem' }}>
              العربون / المبلغ المقدَّم (ج.م) — اختياري
            </label>
            <input className="input" type="number" min={0} max={cartTotal} step="0.5"
              placeholder="0.00" value={deposit || ''}
              onChange={e => setDeposit(Math.min(parseFloat(e.target.value) || 0, cartTotal))} />
            {amountDue > 0 && (
              <div style={{ fontSize: '0.78rem', color: 'var(--danger)', marginTop: '0.2rem', fontWeight: 600 }}>
                ⬅ المتبقي على الذمة: {formatCurrency(amountDue)}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}

/* ── Product Card ── */
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
        minHeight: '92px',
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

/* ── Cart Line ── */
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
export default function ReceiveGoods({ cart, setCart, supplierId, setSupplierId, invoiceId, setInvoiceId }) {
  const [suppliers, setSuppliers]     = useState([])
  const [allProducts, setAllProducts] = useState([])
  const [search, setSearch]           = useState('')
  const [loading, setLoading]         = useState(false)
  const [confirming, setConfirming]   = useState(false)
  const [mobileTab, setMobileTab]     = useState('products')
  const [paymentType, setPaymentType] = useState('cash')  // 'cash' | 'credit'
  const [deposit, setDeposit]         = useState(0)

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
      const amountDue = paymentType === 'credit' ? Math.max(0, cartTotal - deposit) : 0
      await createBulkPurchase({
        replace_invoice_id: invoiceId,
        supplier_id: parseInt(supplierId),
        items: cart.map(c => ({ product_id: c.product.id, quantity: c.quantity, cost: c.cost, update_cost: true })),
        payment_type: paymentType,
        deposit: paymentType === 'credit' ? deposit : 0,
      })
      toast.success(
        invoiceId ? 'تم تحديث الفاتورة والمخزون'
        : paymentType === 'credit'
          ? `تم تسجيل الشراء الآجل 📋 — المتبقي ${formatCurrency(amountDue)}`
          : 'تم تسجيل الشراء وتحديث المخزون'
      )
      setCart([])
      if(setInvoiceId) setInvoiceId(null)
      setPaymentType('cash')
      setDeposit(0)
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
              onClick={() => { setCart([]); if(setInvoiceId) setInvoiceId(null); toast('تم مسح السلة') }}
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

      {/* Total + credit purchase + confirm */}
      <div style={{ flexShrink: 0, borderTop: '1px solid var(--border)', paddingTop: '0.75rem', marginTop: '0.5rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {cart.length > 0 && (
          <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 700, fontSize: '1rem', paddingBottom: '0.25rem' }}>
            <span>الإجمالي</span>
            <span style={{ color: 'var(--secondary)' }}>{formatCurrency(cartTotal)}</span>
          </div>
        )}

        {/* خيار الشراء بالآجل */}
        {cart.length > 0 && (
          <CreditPurchaseSection
            paymentType={paymentType}
            setPaymentType={setPaymentType}
            deposit={deposit}
            setDeposit={setDeposit}
            cartTotal={cartTotal}
          />
        )}

        <button onClick={handleConfirm} disabled={confirming || cart.length === 0 || !supplierId}
          className="btn btn-primary btn-lg" style={{
            justifyContent: 'center', width: '100%',
            ...(paymentType === 'credit' ? { background: 'var(--danger)', borderColor: 'var(--danger)' } : {})
          }}>
          {confirming ? <span className="spinner" /> : <Check size={18} />}
          {invoiceId ? 'تحديث الفاتورة' : paymentType === 'credit' ? 'تأكيد استلام آجل' : 'تأكيد الاستلام'}{cart.length > 0 ? ` — ${formatCurrency(cartTotal)}` : ''}
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
          min-height: 0;
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
          min-height: 0;
          overflow: hidden;
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

          .sup-cart-panel {
            width: 100% !important;
            flex: 1;
            min-height: 0;
            padding: 0.6rem;
            overflow: hidden;
          }

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
