// @ts-nocheck
import { useState, useEffect } from 'react'
import { X, CheckCircle2 } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useSettingsStore from '../../store/settingsStore'
import { formatCurrency, formatNumber, roundCurrency } from '../../utils/formatters'
import { createSale } from '../../api/endpoints'
import { savePendingSale } from '../../utils/idb'
import toast from 'react-hot-toast'
import PaymentSummary from './payment/PaymentSummary'
import PaymentMethodSelector, { PAYMENT_METHODS } from './payment/PaymentMethodSelector'
import CustomerSection from './payment/CustomerSection'
import styles from './PaymentModal.module.css'
import { extractApiError } from '../../utils/apiError'

export default function PaymentModal({ onClose, onSuccess }) {
  const { items, setPaymentMethod, setAmountPaid, setDiscount, paymentMethod, rebillingInvoiceId, rebillingCustomerId, rebillingAmountPaid } = useCartStore()
  const { taxEnabled, taxRate } = useSettingsStore()

  const [loading, setLoading]                 = useState(false)
  const [localDiscount, setLocalDiscount]     = useState(0)
  const [localAmountPaid, setLocalAmountPaid] = useState(0)

  // ── آجل states ──────────────────────────────────────────────
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(rebillingCustomerId ?? null)
  const [deposit, setDeposit]             = useState(0)           // العربون
  const [newCustomerData, setNewCustomerData] = useState<any>(null)

  const handleCustomerSelect = (customerId: number | null, newCustomer: any) => {
    setSelectedCustomerId(customerId)
    setNewCustomerData(newCustomer)
  }

  const isCreditSale = paymentMethod === 'credit'

  const rate             = taxEnabled ? (taxRate / 100) : 0
  const computedSubtotal = items.reduce((s, i) => s + i.subtotal, 0)
  const clampedDiscount  = Math.min(localDiscount, computedSubtotal)
  const computedTaxable  = computedSubtotal - clampedDiscount
  const computedTax      = roundCurrency(computedTaxable * rate)
  const computedTotal    = roundCurrency(computedTaxable + computedTax)
  const computedChange   = Math.max(0, localAmountPaid - (computedTotal - rebillingAmountPaid))
  const remainingToPay   = roundCurrency(Math.max(0, computedTotal - rebillingAmountPaid))
  const amountDue        = isCreditSale ? Math.max(0, computedTotal - deposit - rebillingAmountPaid) : 0

  const currentMethod = PAYMENT_METHODS.find(m => m.id === paymentMethod) ?? PAYMENT_METHODS[0]

  useEffect(() => { setLocalAmountPaid(remainingToPay) }, [remainingToPay])

  const [showCustomer, setShowCustomer] = useState(!!rebillingCustomerId)
  const isCustomerNeeded = isCreditSale || showCustomer

  // تحميل العملاء عند الحاجة (إما آجل أو أراد المستخدم ربط الفاتورة)
  // تم نقله إلى CustomerSection

  // إعادة ضبط deposit عند التغيير
  useEffect(() => { if (!isCreditSale) setDeposit(0) }, [isCreditSale])

  // Enter shortcut for checkout
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter' && !loading) {
        // Prevent default double triggering if a button is focused
        if (e.target instanceof HTMLElement && e.target.tagName !== 'BUTTON') {
          e.preventDefault()
          handleCheckout('completed')
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  })

  const handleCheckout = async (status = 'completed') => {
    if (items.length === 0) return

    if (currentMethod.cashInput && localAmountPaid < remainingToPay) {
      toast.error('المبلغ المدفوع أقل من الإجمالي')
      return
    }

    // التحقق من بيانات العميل (مطلوب في الآجل، واختياري في الكاش)
    let customerId: number | null = selectedCustomerId
    let newCustomer: any = newCustomerData
    if (isCreditSale && !customerId && !newCustomer) {
      toast.error('اختر عميلاً أو أنشئ جديداً')
      return
    }

    setDiscount(clampedDiscount)
    const finalAmountPaid = status === 'reserved' 
      ? (isCreditSale ? deposit : 0) 
      : (isCreditSale ? (deposit + rebillingAmountPaid) : (currentMethod.cashInput ? (localAmountPaid + rebillingAmountPaid) : computedTotal))
    
    setAmountPaid(finalAmountPaid)

    const salePayload = {
      items:          items.map(i => ({ product_id: i.id, quantity: i.quantity, price: i.price })),
      discount:       clampedDiscount,
      payment_method: paymentMethod,
      amount_paid:    finalAmountPaid,
      ...(customerId ? { customer_id: customerId } : {}),
      ...(newCustomer ? { new_customer: newCustomer } : {}),
      ...(isCreditSale ? { deposit } : {}),
      ...(rebillingInvoiceId ? { invoice_id: rebillingInvoiceId } : {}),
      status,
    }

    setLoading(true)
    try {
      const res = await createSale(salePayload as any)
      const { invoice, low_stock_alerts } = res.data.data
      toast.success(
        status === 'reserved'
          ? `تم حجز الفاتورة بنجاح 🕒`
          : rebillingInvoiceId
            ? `تم تحديث الفاتورة #${formatNumber(rebillingInvoiceId)}`
            : isCreditSale
              ? `تم تسجيل البيع الآجل 📋 — المتبقي ${formatCurrency(amountDue)}`
              : 'تمت عملية البيع بنجاح! 🎉',
        { duration: 3000 }
      )
      if (low_stock_alerts && low_stock_alerts.length > 0) {
        low_stock_alerts.forEach((p: any) =>
          toast(`تحذير: ${p.name} — كمية منخفضة (${formatNumber(p.quantity)})`, { icon: '⚠️', duration: 5000 })
        )
      }
      onSuccess(invoice, isCreditSale ? 0 : computedChange)
    } catch (err) {
      if (!navigator.onLine) {
        await savePendingSale(salePayload)
        toast('لا يوجد إنترنت — تم حفظ العملية للمزامنة لاحقًا', { icon: '📴', duration: 5000 })
        onClose()
      } else {
        toast.error(extractApiError(err, 'فشل في إتمام البيع'))
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '520px' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.15rem', fontWeight: 700 }}>
            {rebillingInvoiceId ? `تحديث فاتورة #${formatNumber(rebillingInvoiceId)}` : 'إتمام الدفع'}
          </h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {/* Summary */}
        <PaymentSummary
          subtotal={computedSubtotal}
          discount={clampedDiscount}
          taxEnabled={taxEnabled}
          taxRate={taxRate}
          tax={computedTax}
          total={computedTotal}
          rebillingAmountPaid={rebillingAmountPaid}
          remainingToPay={remainingToPay}
          isCreditSale={isCreditSale}
          deposit={deposit}
          amountDue={amountDue}
        />

        {/* Discount */}
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
            الخصم (ج.م)
          </label>
          <input type="number" min={0} max={computedSubtotal} step="0.5" className="input"
            value={localDiscount} onChange={e => setLocalDiscount(parseFloat(e.target.value) || 0)} />
        </div>

        {/* Payment method */}
        <PaymentMethodSelector paymentMethod={paymentMethod} onSelect={setPaymentMethod} />

        {/* Cash input */}
        {currentMethod.cashInput && !isCreditSale && (
          <div style={{ marginBottom: '1rem' }}>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>المبلغ المدفوع (ج.م)</label>
            <input type="number" min={0} step="0.5" className="input input-lg"
              value={localAmountPaid} onChange={e => setLocalAmountPaid(parseFloat(e.target.value) || 0)} />
          </div>
        )}

        {currentMethod.cashInput && !isCreditSale && (
          <div 
            className={computedChange > 0 ? styles.changeBoxActive : ""}
            style={{
            background: computedChange > 0 ? undefined : 'var(--bg)',
            borderRadius: 'var(--radius)', padding: '0.75rem 1rem',
            display: 'flex', justifyContent: 'space-between',
            fontWeight: 700, fontSize: '1.05rem', marginBottom: '1rem',
          }}>
            <span>الباقي</span>
            <span style={{ color: computedChange > 0 ? 'inherit' : 'var(--primary)' }}>{formatCurrency(computedChange)}</span>
          </div>
        )}

        {/* خيار إضافة عميل للمبيعات النقدية */}
        {!isCreditSale && (
          <div style={{ marginBottom: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <input 
              type="checkbox" 
              id="linkCustomer" 
              checked={showCustomer} 
              onChange={e => setShowCustomer(e.target.checked)} 
              style={{ width: '1.2rem', height: '1.2rem', cursor: 'pointer' }}
            />
            <label htmlFor="linkCustomer" style={{ fontSize: '0.9rem', fontWeight: 600, cursor: 'pointer' }}>
              ربط الفاتورة بحساب عميل (لتسجيلها في كشف حسابه)
            </label>
          </div>
        )}

        {/* ── قسم بيانات العميل (آجل أو كاش مرتبط) ────────────────────────── */}
        {isCustomerNeeded && (
          <CustomerSection
            isCreditSale={isCreditSale}
            rebillingCustomerId={rebillingCustomerId}
            computedTotal={computedTotal}
            amountDue={amountDue}
            deposit={deposit}
            onDepositChange={setDeposit}
            onCustomerSelect={handleCustomerSelect}
          />
        )}

        {/* Checkout button */}
        <div style={{ display: 'flex', gap: '0.5rem', width: '100%' }}>
          <button className={`btn ${isCreditSale ? 'btn-danger' : 'btn-primary'} btn-lg`}
            style={{ flex: 2, justifyContent: 'center', fontSize: '1.05rem' }}
            onClick={() => handleCheckout('completed')} disabled={loading}>
            {loading ? <span className="spinner" /> : <CheckCircle2 size={20} />}
            {rebillingInvoiceId ? 'حفظ التعديل — '
              : isCreditSale ? 'تأكيد الآجل — '
              : 'تأكيد البيع — '}
            {formatCurrency(remainingToPay)}
            <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>Enter</kbd>
          </button>
          
          <button className="btn btn-warning btn-lg"
            style={{ flex: 1, justifyContent: 'center', fontSize: '1rem' }}
            onClick={() => handleCheckout('reserved')} disabled={loading}>
            حجز 🕒
          </button>
        </div>
      </div>
    </div>
  )
}


