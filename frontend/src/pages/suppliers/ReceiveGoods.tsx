// @ts-nocheck
import { useState, useEffect } from 'react'
import { Trash2, ShoppingCart, Check, Package } from 'lucide-react'
import BarcodeInput from '../../components/pos/BarcodeInput'
import useProductStore from '../../store/productStore'
import toast from 'react-hot-toast'
import { getSuppliers, getProducts, createBulkPurchase } from '../../api/endpoints'
import { formatCurrency, formatNumber } from '../../utils/formatters'
import CreditPurchaseSection from './components/CreditPurchaseSection'
import ReceiveGoodsProductCard from './components/ReceiveGoodsProductCard'
import ReceiveGoodsCartLine from './components/ReceiveGoodsCartLine'
import styles from '../Suppliers.module.css'

/* ──────────────────────────── Receive Goods (POS-like) ── */
export default function ReceiveGoods({ cart, setCart, supplierId, setSupplierId, invoiceId, setInvoiceId }) {
  const [suppliers, setSuppliers]     = useState<any[]>([])
  const [allProducts, setAllProducts] = useState<any[]>([])
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
    const fetchAll = (isInitial = false) => {
      getSuppliers().then(r => { const d = r.data.data as any; setSuppliers(Array.isArray(d) ? d : (d?.data ?? [])) }).catch(() => {})
      if (isInitial) setLoading(true)
      getProducts({ limit: 9999 })
        .then((r) => {
          const raw = r.data.data as any; const list = Array.isArray(raw) ? raw : (raw?.data ?? [])
          setAllProducts(list)
          useProductStore.getState().setProducts(list)
        })
        .catch((err) => {
          if (isInitial) toast.error('فشل تحميل المنتجات')
        })
        .finally(() => {
          if (isInitial) setLoading(false)
        })
    }
    
    fetchAll(true)
    const intervalId = setInterval(() => fetchAll(false), 10000)
    return () => clearInterval(intervalId)
  }, [])

  /* ── Cart helpers ── */
  const addToCart = (product) => {
    const unitsPerBox = Math.max(1, parseInt(product.units_per_box) || 1)
    const qtyToAdd = product.scanned_as_box ? unitsPerBox : 1

    setCart((prev) => {
      const existing = prev.find((c) => c.product.id === product.id)
      if (existing) {
        return prev.map((c) =>
          c.product.id === product.id ? { ...c, quantity: c.quantity + qtyToAdd } : c
        )
      }
      return [
        ...prev,
        {
          product,
          quantity: qtyToAdd,
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
    const q = Math.max(0.001, parseFloat(qty) || 0.001)
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

      // ── تحديث فوري للمنتجات (تجاوز الكاش) ──────────────────
      // نُعيد جلب المنتجات من الخادم مباشرة بعد تأكيد الاستلام
      // حتى تنعكس الكميات الجديدة فوراً دون انتظار انتهاء الكاش
      getProducts({ limit: 9999, _t: Date.now() })
        .then((r) => {
          const raw = r.data?.data as any
          const list = Array.isArray(raw) ? raw : (raw?.data ?? [])
          if (list.length > 0) {
            setAllProducts(list)
            // تحديث productStore المشترك (يُستخدم في POS أيضاً)
            useProductStore.getState().setProducts(list)
            useProductStore.getState().invalidateCache()
          }
        })
        .catch(() => { /* silent — البيانات ستُحدَّث في الدورة القادمة */ })
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل تسجيل الشراء')
    } finally {
      setConfirming(false)
    }
  }

  // F12 Shortcut to confirm receive goods
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F12') {
        e.preventDefault()
        if (cart.length > 0 && supplierId && !confirming) {
          handleConfirm()
        } else if (!supplierId && cart.length > 0) {
          toast.error('يرجى اختيار مورد لإتمام العملية')
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [cart, supplierId, confirming, paymentType, deposit, invoiceId])

  /* ── Panels ── */
  const ProductsPanel = (
    <div className={`card ${styles.productsPanel}`}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexShrink: 0 }}>
        <h3 style={{ fontSize: '0.95rem', fontWeight: 700 }}>المنتجات</h3>
        <span className="badge badge-gray">{formatNumber(products.length)} منتج</span>
      </div>
      <div className="product-grid">
        {loading ? (
          <div style={{ gridColumn: '1/-1', padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : products.map(p => (
          <ReceiveGoodsProductCard key={p.id} product={p} onAdd={() => { addToCart(p); setMobileTab('cart') }} />
        ))}
      </div>
    </div>
  )

  const CartPanel = (
    <div className={`card ${styles.cartPanel}`}>
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
              onClick={() => { 
                setCart([]); 
                if(setInvoiceId) setInvoiceId(null); 
                toast('تم مسح السلة');
                setTimeout(() => document.getElementById('main-barcode-input')?.focus(), 10);
              }}
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
          <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>F12</kbd>
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
      <div className={styles.desktop}>
        {ProductsPanel}
        {CartPanel}
      </div>

      {/* Mobile layout */}
      <div className={styles.mobile}>
        <div className={styles.mobileContent}>
          {mobileTab === 'products' ? ProductsPanel : CartPanel}
        </div>
        <div className="pos-tab-bar">
          <button className={`pos-tab ${mobileTab === 'products' ? 'active' : ''}`} onClick={() => setMobileTab('products')}>
            <Package size={20} />
            <span>المنتجات</span>
          </button>
          <button className={`pos-tab ${mobileTab === 'cart' ? 'active' : ''}`} onClick={() => setMobileTab('cart')}>
            <ShoppingCart size={20} />
            <span>السلة</span>
            {cartCount > 0 && <span className="tab-badge">{formatNumber(cartCount)}</span>}
          </button>
        </div>
      </div>
    </>
  )
}
