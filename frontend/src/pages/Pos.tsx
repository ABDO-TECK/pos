import { useState, useEffect, useMemo } from 'react'
import { ShoppingCart, CreditCard, Grid3X3 } from 'lucide-react'
import BarcodeInput from '../components/pos/BarcodeInput'
import Cart from '../components/pos/Cart'
import PaymentModal from '../components/pos/PaymentModal'
import Receipt from '../components/pos/Receipt'
import ReservedInvoicesModal from '../components/pos/ReservedInvoicesModal'
import { CartHeader, CartTotals, ProductCard } from '../components/pos/PosHelpers'
import useCartStore from '../store/cartStore'
import useProductStore from '../store/productStore'
import useSettingsStore from '../store/settingsStore'
import { formatCurrency, formatNumber, roundCurrency } from '../utils/formatters'
import toast from 'react-hot-toast'

export default function POS() {
  const [showPayment, setShowPayment] = useState(false)
  const [showReserved, setShowReserved] = useState(false)
  const [invoice, setInvoice]         = useState<any>(null)
  const [change, setChange]           = useState(0)
  const [mobileTab, setMobileTab]     = useState('products') // 'products' | 'cart'
  const [productSearch, setProductSearch] = useState('')
  const [barcodeInputKey, setBarcodeInputKey] = useState(0)

  const { items, clearCart, itemCount } = useCartStore()
  const { fetchProducts, products }     = useProductStore()
  const { taxEnabled, taxRate }         = useSettingsStore()

  const filteredProducts = useMemo(() => {
    const t = productSearch.trim().toLowerCase()
    if (!t) return products
    return products.filter((p) => {
      const nm = (p.name || '').toLowerCase().includes(t)
      const bc = (p.barcode || '').toLowerCase().includes(t)
      const ex = (p.additional_barcodes || []).some((b: any) => String(b).toLowerCase().includes(t))
      return nm || bc || ex
    })
  }, [products, productSearch])

  /** بدون بحث: حد معقول للأداء؛ مع بحث: كل النتائج المطابقة */
  const gridProducts = useMemo(() => {
    if (productSearch.trim()) return filteredProducts
    return filteredProducts.slice(0, 100)
  }, [filteredProducts, productSearch])

  const subtotal = items.reduce((s, i) => s + (parseFloat(String(i.subtotal)) || 0), 0)
  const rate     = taxEnabled ? taxRate / 100 : 0
  const tax      = roundCurrency(subtotal * rate)
  const total    = roundCurrency(subtotal + tax)

  useEffect(() => {
    fetchProducts()
    const intervalId = setInterval(() => {
      fetchProducts({}, true) // Auto-refresh (bypasses 5-minute store cache)
    }, 10000)
    return () => clearInterval(intervalId)
  }, [])

  // F12 Shortcut for checkout
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F12') {
        e.preventDefault()
        if (items.length > 0) setShowPayment(true)
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [items])

  const handleSuccess = (inv: any, ch: number) => {
    setInvoice(inv)
    setChange(ch)
    setShowPayment(false)
    clearCart()
    setMobileTab('products')
    setProductSearch('')
    setBarcodeInputKey((k) => k + 1)
    fetchProducts({}, true) // Refresh quantities instantly
  }

  // Switch to cart tab automatically when an item is added on mobile
  const handleAddItem = (product: any) => {
    useCartStore.getState().addItem(product)
    toast.success(product.name, { duration: 800 })
  }

  return (
    <>
      {/* ── Desktop layout ── */}
      <div className="pos-desktop">
        {/* Barcode & Top Actions */}
        <div className="card" style={{ padding: '0.75rem', marginBottom: '0.75rem', display: 'flex', gap: '0.5rem' }}>
          <div style={{ flex: 1 }}>
            <BarcodeInput key={barcodeInputKey} onFilterChange={setProductSearch} />
          </div>
          <button className="btn btn-ghost" onClick={() => setShowReserved(true)}>
             الفواتير المحجوزة 🕒
          </button>
        </div>

        <div style={{ display: 'flex', flex: 1, gap: '0.75rem', overflow: 'hidden', minHeight: 0 }}>
          {/* Products grid */}
          <div className="card pos-products-panel">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
              <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
              <span className="badge badge-gray">
                {productSearch.trim()
                  ? `${formatNumber(filteredProducts.length)} مطابقة`
                  : `${formatNumber(products.length)} منتج`}
              </span>
            </div>
            <div className="product-grid">
              {gridProducts.map(p => (
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
              <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>F12</kbd>
            </button>
          </div>
        </div>
      </div>

      {/* ── Mobile layout ── */}
      <div className="pos-mobile">
        {/* Barcode & Top Actions */}
        <div className="card" style={{ padding: '0.6rem', marginBottom: '0.6rem', display: 'flex', gap: '0.4rem' }}>
          <div style={{ flex: 1 }}>
            <BarcodeInput key={barcodeInputKey} onFilterChange={setProductSearch} />
          </div>
          <button className="btn btn-ghost btn-icon" onClick={() => setShowReserved(true)} title="المحجوزات">
             🕒
          </button>
        </div>

        {/* Tab content */}
        <div className="card pos-mobile-content">
          {mobileTab === 'products' ? (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
                <h3 style={{ fontSize: '0.9rem', fontWeight: 700 }}>المنتجات</h3>
                <span className="badge badge-gray">
                  {productSearch.trim()
                    ? `${formatNumber(filteredProducts.length)} مطابقة`
                    : `${formatNumber(products.length)} منتج`}
                </span>
              </div>
              <div className="product-grid">
                {gridProducts.map(p => (
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
                <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>F12</kbd>
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
      {showReserved && (
        <ReservedInvoicesModal 
          onClose={() => setShowReserved(false)} 
          onResumeSale={(inv: any) => {
            useCartStore.getState().mergeInvoiceLines(inv.items, inv.id, inv.customer_id, parseFloat(inv.amount_paid) || 0)
            setShowReserved(false)
          }} 
        />
      )}

    </>
  )
}
