import { useState, useEffect } from 'react'
import { ShoppingCart, Trash2, CreditCard, Grid3X3 } from 'lucide-react'
import BarcodeInput from '../components/pos/BarcodeInput'
import Cart from '../components/pos/Cart'
import PaymentModal from '../components/pos/PaymentModal'
import Receipt from '../components/pos/Receipt'
import useCartStore from '../store/cartStore'
import useProductStore from '../store/productStore'
import useSettingsStore from '../store/settingsStore'
import { formatCurrency, formatNumber, formatPercent } from '../utils/formatters'
import toast from 'react-hot-toast'

export default function POS() {
  const [showPayment, setShowPayment] = useState(false)
  const [invoice, setInvoice]         = useState(null)
  const [change, setChange]           = useState(0)
  const [mobileTab, setMobileTab]     = useState('products') // 'products' | 'cart'

  const { items, clearCart, itemCount } = useCartStore()
  const { fetchProducts, products }     = useProductStore()
  const { taxEnabled, taxRate }         = useSettingsStore()

  const subtotal = items.reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0)
  const rate     = taxEnabled ? taxRate / 100 : 0
  const tax      = Math.round(subtotal * rate * 100) / 100
  const total    = Math.round((subtotal + tax) * 100) / 100

  useEffect(() => { fetchProducts() }, [])

  const handleSuccess = (inv, ch) => {
    setInvoice(inv)
    setChange(ch)
    setShowPayment(false)
    clearCart()
    setMobileTab('products')
  }

  // Switch to cart tab automatically when an item is added on mobile
  const handleAddItem = (product) => {
    useCartStore.getState().addItem(product)
    toast.success(product.name, { duration: 800 })
  }

  return (
    <>
      {/* ── Desktop layout ── */}
      <div className="pos-desktop">
        {/* Barcode */}
        <div className="card" style={{ padding: '0.75rem', marginBottom: '0.75rem' }}>
          <BarcodeInput />
        </div>

        <div style={{ display: 'flex', flex: 1, gap: '0.75rem', overflow: 'hidden', minHeight: 0 }}>
          {/* Products grid */}
          <div className="card pos-products-panel">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
              <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
              <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
            </div>
            <div className="product-grid">
              {products.slice(0, 60).map(p => (
                <ProductCard key={p.id} product={p} onAdd={() => handleAddItem(p)} />
              ))}
            </div>
          </div>

          {/* Cart panel */}
          <div className="card pos-cart-panel">
            <CartHeader items={items} clearCart={clearCart} itemCount={itemCount} />
            <Cart />
            <CartTotals items={items} subtotal={subtotal} tax={tax} total={total} taxEnabled={taxEnabled} taxRate={taxRate} />
            <button
              className="btn btn-primary btn-lg"
              style={{ justifyContent: 'center', marginTop: 'auto' }}
              disabled={items.length === 0}
              onClick={() => setShowPayment(true)}
            >
              <CreditCard size={20} />
              إتمام البيع — {formatCurrency(total)}
            </button>
          </div>
        </div>
      </div>

      {/* ── Mobile layout ── */}
      <div className="pos-mobile">
        {/* Barcode */}
        <div className="card" style={{ padding: '0.6rem', marginBottom: '0.6rem' }}>
          <BarcodeInput />
        </div>

        {/* Tab content */}
        <div className="card pos-mobile-content">
          {mobileTab === 'products' ? (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
                <h3 style={{ fontSize: '0.9rem', fontWeight: 700 }}>المنتجات</h3>
                <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
              </div>
              <div className="product-grid">
                {products.slice(0, 60).map(p => (
                  <ProductCard key={p.id} product={p} onAdd={() => {
                    handleAddItem(p)
                    if (itemCount >= 0) setMobileTab('cart')
                  }} />
                ))}
              </div>
            </>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', gap: '0.5rem' }}>
              <CartHeader items={items} clearCart={clearCart} itemCount={itemCount} />
              <Cart />
              <CartTotals items={items} subtotal={subtotal} tax={tax} total={total} taxEnabled={taxEnabled} taxRate={taxRate} />
              <button
                className="btn btn-primary btn-lg"
                style={{ justifyContent: 'center' }}
                disabled={items.length === 0}
                onClick={() => setShowPayment(true)}
              >
                <CreditCard size={18} />
                إتمام البيع — {formatCurrency(total)}
              </button>
            </div>
          )}
        </div>

        {/* Bottom tab bar */}
        <div className="pos-tab-bar">
          <button
            className={`pos-tab${mobileTab === 'products' ? ' active' : ''}`}
            onClick={() => setMobileTab('products')}
          >
            <Grid3X3 size={20} />
            <span>المنتجات</span>
          </button>
          <button
            className={`pos-tab${mobileTab === 'cart' ? ' active' : ''}`}
            onClick={() => setMobileTab('cart')}
          >
            <ShoppingCart size={20} />
            <span>السلة</span>
            {itemCount > 0 && <span className="tab-badge">{formatNumber(itemCount)}</span>}
          </button>
        </div>
      </div>

      {/* Modals */}
      {showPayment && (
        <PaymentModal onClose={() => setShowPayment(false)} onSuccess={handleSuccess} />
      )}
      {invoice && (
        <Receipt invoice={invoice} change={change} onClose={() => setInvoice(null)} />
      )}

      {/* POS-specific styles */}
      <style>{`
        /* Desktop */
        .pos-desktop {
          display: flex;
          flex-direction: column;
          height: calc(100vh - 3rem);
        }
        .pos-mobile { display: none; }

        .pos-products-panel {
          flex: 1;
          padding: 0.75rem;
          overflow: hidden;
          display: flex;
          flex-direction: column;
          min-width: 0;
        }
        .pos-cart-panel {
          width: 340px;
          flex-shrink: 0;
          padding: 0.75rem;
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
          overflow: hidden;
        }
        .product-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
          gap: 0.4rem;
          overflow-y: auto;
          flex: 1;
          align-content: start;
        }

        /* Mobile */
        @media (max-width: 767px) {
          .pos-desktop { display: none !important; }
          .pos-mobile {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 56px - 2rem);
          }
          .pos-mobile-content {
            flex: 1;
            padding: 0.6rem;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
          }
          .pos-mobile-content .product-grid {
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

// ── Shared sub-components ──────────────────────────────────────────────────

function CartHeader({ items, clearCart, itemCount }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontWeight: 700 }}>
        <ShoppingCart size={18} />
        السلة
        {itemCount > 0 && <span className="badge badge-green">{formatNumber(itemCount)}</span>}
      </div>
      {items.length > 0 && (
        <button
          className="btn btn-ghost btn-sm"
          onClick={() => { clearCart(); toast('تم مسح السلة') }}
          style={{ color: 'var(--danger)' }}
        >
          <Trash2 size={14} /> مسح
        </button>
      )}
    </div>
  )
}

function CartTotals({ items, subtotal, tax, total, taxEnabled, taxRate }) {
  if (!items.length) return null
  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
      <TotalRow label="المجموع" value={formatCurrency(subtotal)} />
      {taxEnabled && (
        <TotalRow label={`الضريبة ${formatPercent(taxRate)}`} value={formatCurrency(tax)} muted />
      )}
      <TotalRow label="الإجمالي" value={formatCurrency(total)} bold green />
    </div>
  )
}

function ProductCard({ product, onAdd }) {
  const isOutOfStock = product.quantity <= 0
  const isLowStock   = product.quantity <= product.low_stock_threshold && product.quantity > 0

  return (
    <button
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
          {formatCurrency(product.price)}
        </span>
        {isOutOfStock && <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>}
        {isLowStock   && <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>}
      </div>
    </button>
  )
}

function TotalRow({ label, value, bold, green, muted }) {
  return (
    <div style={{
      display: 'flex', justifyContent: 'space-between',
      fontWeight: bold ? 700 : 400,
      fontSize: bold ? '1rem' : '0.88rem',
      color: muted ? 'var(--text-muted)' : green ? 'var(--primary)' : 'var(--text)',
    }}>
      <span>{label}</span>
      <span>{value}</span>
    </div>
  )
}
