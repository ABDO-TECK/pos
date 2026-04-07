import { useState, useEffect } from 'react'
import { ShoppingCart, Trash2, CreditCard } from 'lucide-react'
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
  const [invoice, setInvoice] = useState(null)
  const [change, setChange] = useState(0)

  const { items, clearCart, itemCount } = useCartStore()
  const { fetchProducts, products } = useProductStore()
  const { taxEnabled, taxRate } = useSettingsStore()

  // Subtotals computed inline — respects live settings
  const subtotal = items.reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0)
  const rate  = taxEnabled ? (taxRate / 100) : 0
  const tax   = Math.round(subtotal * rate * 100) / 100
  const total = Math.round((subtotal + tax) * 100) / 100

  useEffect(() => {
    fetchProducts()
  }, [])

  const handleSuccess = (inv, ch) => {
    setInvoice(inv)
    setChange(ch)
    setShowPayment(false)
    clearCart()
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: 'calc(100vh - 3rem)', gap: '0.75rem' }}>
      {/* Barcode input */}
      <div className="card" style={{ padding: '0.75rem' }}>
        <BarcodeInput />
      </div>

      {/* Main area */}
      <div style={{ display: 'flex', flex: 1, gap: '0.75rem', overflow: 'hidden' }}>
        {/* Product Quick Grid */}
        <div className="card" style={{ flex: 1, padding: '0.75rem', overflow: 'auto', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
            <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
          </div>
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(110px, 1fr))',
            gap: '0.4rem',
            overflowY: 'auto',
            flex: 1,
            alignContent: 'start',
          }}>
            {products.slice(0, 60).map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </div>

        {/* Cart */}
        <div className="card" style={{ width: '340px', flexShrink: 0, padding: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
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

          <Cart />

          {/* Totals */}
          {items.length > 0 && (
            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
              <TotalRow label="المجموع" value={formatCurrency(subtotal)} />
              {taxEnabled && (
                <TotalRow label={`الضريبة ${formatPercent(taxRate)}`} value={formatCurrency(tax)} muted />
              )}
              <TotalRow label="الإجمالي" value={formatCurrency(total)} bold green />
            </div>
          )}

          {/* Checkout */}
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

      {/* Modals */}
      {showPayment && (
        <PaymentModal onClose={() => setShowPayment(false)} onSuccess={handleSuccess} />
      )}
      {invoice && (
        <Receipt invoice={invoice} change={change} onClose={() => setInvoice(null)} />
      )}
    </div>
  )
}

function ProductCard({ product }) {
  const addItem = useCartStore((s) => s.addItem)
  const isOutOfStock = product.quantity <= 0
  const isLowStock = product.quantity <= product.low_stock_threshold && product.quantity > 0

  return (
    <button
      onClick={() => {
        addItem(product)
        toast.success(product.name, { duration: 800 })
      }}
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
      }}
      onMouseEnter={(e) => {
        e.currentTarget.style.transform = 'scale(1.04)'
        e.currentTarget.style.boxShadow = '0 2px 8px rgba(0,0,0,.1)'
      }}
      onMouseLeave={(e) => {
        e.currentTarget.style.transform = 'scale(1)'
        e.currentTarget.style.boxShadow = 'none'
      }}
    >
      {/* Name */}
      <div style={{
        fontSize: '0.78rem', fontWeight: 600, lineHeight: 1.3,
        overflow: 'hidden', display: '-webkit-box',
        WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
        color: 'var(--text)',
      }}>
        {product.name}
      </div>

      {/* Price + badge row */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: '0.2rem' }}>
        <span style={{ fontSize: '0.85rem', fontWeight: 700, color: 'var(--primary)' }}>
          {formatCurrency(product.price)}
        </span>
        {isOutOfStock && (
          <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>
        )}
        {isLowStock && (
          <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>
        )}
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
