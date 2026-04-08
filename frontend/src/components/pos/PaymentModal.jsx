import { useState, useEffect } from 'react'
import { X, CreditCard, Banknote, CheckCircle2, Smartphone, Wallet } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useSettingsStore from '../../store/settingsStore'
import { formatCurrency, formatNumber, formatPercent } from '../../utils/formatters'
import { createSale } from '../../api/endpoints'
import { savePendingSale } from '../../utils/idb'
import toast from 'react-hot-toast'

const PAYMENT_METHODS = [
  { id: 'cash',          label: 'نقدي',          icon: <Banknote size={16}/>,    cashInput: true  },
  { id: 'card',          label: 'بطاقة',          icon: <CreditCard size={16}/>,  cashInput: false },
  { id: 'vodafone_cash', label: 'فودافون كاش',   icon: <Smartphone size={16}/>,  cashInput: false },
  { id: 'instapay',      label: 'انستاباي',       icon: <Wallet size={16}/>,      cashInput: false },
  { id: 'other_wallet',  label: 'محفظة أخرى',    icon: <Wallet size={16}/>,      cashInput: false },
]

export default function PaymentModal({ onClose, onSuccess }) {
  const { items, setPaymentMethod, setAmountPaid, setDiscount, paymentMethod, rebillingInvoiceId } = useCartStore()
  const { taxEnabled, taxRate } = useSettingsStore()

  const [loading, setLoading]             = useState(false)
  const [localDiscount, setLocalDiscount] = useState(0)
  const [localAmountPaid, setLocalAmountPaid] = useState(0)

  const rate          = taxEnabled ? (taxRate / 100) : 0
  const computedSubtotal = items.reduce((s, i) => s + i.subtotal, 0)
  const clampedDiscount  = Math.min(localDiscount, computedSubtotal) // max 100%
  const computedTaxable  = computedSubtotal - clampedDiscount
  const computedTax      = Math.round(computedTaxable * rate * 100) / 100
  const computedTotal    = Math.round((computedTaxable + computedTax) * 100) / 100
  const computedChange   = Math.max(0, localAmountPaid - computedTotal)

  const currentMethod = PAYMENT_METHODS.find(m => m.id === paymentMethod) ?? PAYMENT_METHODS[0]

  // Auto-fill amount paid whenever total changes
  useEffect(() => {
    setLocalAmountPaid(computedTotal)
  }, [computedTotal])

  const handleCheckout = async () => {
    if (items.length === 0) return

    if (currentMethod.cashInput && localAmountPaid < computedTotal) {
      toast.error('المبلغ المدفوع أقل من الإجمالي')
      return
    }

    setDiscount(clampedDiscount)
    setAmountPaid(localAmountPaid)

    const salePayload = {
      items: items.map((i) => ({ product_id: i.id, quantity: i.quantity })),
      discount: clampedDiscount,
      payment_method: paymentMethod,
      amount_paid: currentMethod.cashInput ? localAmountPaid : computedTotal,
      ...(rebillingInvoiceId ? { invoice_id: rebillingInvoiceId } : {}),
    }

    setLoading(true)
    try {
      const res = await createSale(salePayload)
      const { invoice, low_stock_alerts } = res.data.data
      toast.success(
        rebillingInvoiceId
          ? `تم تحديث الفاتورة #${formatNumber(rebillingInvoiceId)} بنفس الرقم`
          : 'تمت عملية البيع بنجاح!',
        { icon: '🎉', duration: 3000 }
      )
      if (low_stock_alerts?.length > 0) {
        low_stock_alerts.forEach((p) =>
          toast(`تحذير: ${p.name} — كمية منخفضة (${formatNumber(p.quantity)})`, { icon: '⚠️', duration: 5000 })
        )
      }
      onSuccess(invoice, computedChange)
    } catch (err) {
      if (!navigator.onLine) {
        await savePendingSale(salePayload)
        toast('لا يوجد إنترنت — تم حفظ العملية للمزامنة لاحقًا', { icon: '📴', duration: 5000 })
        onClose()
      } else {
        toast.error(err.response?.data?.message || 'فشل في إتمام البيع')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '520px' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
          <h2 style={{ fontSize: '1.15rem', fontWeight: 700 }}>
            {rebillingInvoiceId ? `تحديث فاتورة #${formatNumber(rebillingInvoiceId)}` : 'إتمام الدفع'}
          </h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {/* Summary */}
        <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', marginBottom: '1rem' }}>
          <Row label="المجموع الجزئي" value={formatCurrency(computedSubtotal)} />
          {clampedDiscount > 0 && (
            <Row label="الخصم" value={`- ${formatCurrency(clampedDiscount)}`} />
          )}
          {taxEnabled && (
            <Row label={`ضريبة القيمة المضافة (${formatPercent(taxRate)})`} value={formatCurrency(computedTax)} />
          )}
          <div style={{ borderTop: '2px solid var(--border)', margin: '0.5rem 0' }} />
          <Row label="الإجمالي" value={formatCurrency(computedTotal)} bold />
        </div>

        {/* Discount */}
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
            الخصم (ج.م) — الحد الأقصى {formatCurrency(computedSubtotal)}
          </label>
          <input
            type="number"
            min={0}
            max={computedSubtotal}
            step="0.5"
            className="input"
            value={localDiscount}
            onChange={(e) => setLocalDiscount(parseFloat(e.target.value) || 0)}
          />
        </div>

        {/* Payment method */}
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.5rem' }}>طريقة الدفع</label>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '0.5rem' }}>
            {PAYMENT_METHODS.map(m => (
              <PayBtn
                key={m.id}
                active={paymentMethod === m.id}
                onClick={() => setPaymentMethod(m.id)}
                icon={m.icon}
                label={m.label}
              />
            ))}
          </div>
        </div>

        {/* Amount paid (cash only) */}
        {currentMethod.cashInput && (
          <div style={{ marginBottom: '1rem' }}>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>المبلغ المدفوع (ج.م)</label>
            <input
              type="number"
              min={0}
              step="0.5"
              className="input input-lg"
              value={localAmountPaid}
              onChange={(e) => setLocalAmountPaid(parseFloat(e.target.value) || 0)}
            />
          </div>
        )}

        {/* Change */}
        {currentMethod.cashInput && (
          <div style={{
            background: computedChange > 0 ? '#dcfce7' : 'var(--bg)',
            borderRadius: 'var(--radius)', padding: '0.75rem 1rem',
            display: 'flex', justifyContent: 'space-between',
            fontWeight: 700, fontSize: '1.05rem', marginBottom: '1rem',
          }}>
            <span>الباقي</span>
            <span style={{ color: 'var(--primary)' }}>{formatCurrency(computedChange)}</span>
          </div>
        )}

        {/* Checkout button */}
        <button
          className="btn btn-primary btn-lg"
          style={{ width: '100%', justifyContent: 'center', fontSize: '1.05rem' }}
          onClick={handleCheckout}
          disabled={loading}
        >
          {loading ? <span className="spinner" /> : <CheckCircle2 size={20} />}
          {rebillingInvoiceId ? 'حفظ التعديل على الفاتورة — ' : 'تأكيد البيع — '}
          {formatCurrency(computedTotal)}
        </button>
      </div>
    </div>
  )
}

function Row({ label, value, bold }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.3rem 0', fontWeight: bold ? 700 : 400, fontSize: bold ? '1rem' : '0.9rem' }}>
      <span>{label}</span>
      <span>{value}</span>
    </div>
  )
}

function PayBtn({ active, onClick, icon, label }) {
  return (
    <button
      onClick={onClick}
      style={{
        display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '0.3rem',
        padding: '0.5rem 0.4rem', borderRadius: 'var(--radius)', fontWeight: 600, fontSize: '0.82rem',
        border: `2px solid ${active ? 'var(--primary)' : 'var(--border)'}`,
        background: active ? '#dcfce7' : 'var(--surface)',
        color: active ? 'var(--primary-d)' : 'var(--text)',
        cursor: 'pointer',
        whiteSpace: 'nowrap',
      }}
    >
      {icon} {label}
    </button>
  )
}
