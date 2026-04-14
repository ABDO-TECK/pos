import { useState, useEffect } from 'react'
import { X, CreditCard, Banknote, CheckCircle2, Smartphone, Wallet, Clock, UserPlus, ChevronDown } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useSettingsStore from '../../store/settingsStore'
import { formatCurrency, formatNumber, formatPercent } from '../../utils/formatters'
import { createSale, getCustomers } from '../../api/endpoints'
import { savePendingSale } from '../../utils/idb'
import toast from 'react-hot-toast'

const PAYMENT_METHODS = [
  { id: 'cash',          label: 'نقدي',          icon: <Banknote size={16}/>,     cashInput: true  },
  { id: 'card',          label: 'بطاقة',          icon: <CreditCard size={16}/>,   cashInput: false },
  { id: 'vodafone_cash', label: 'فودافون كاش',   icon: <Smartphone size={16}/>,   cashInput: false },
  { id: 'instapay',      label: 'انستاباي',       icon: <Wallet size={16}/>,       cashInput: false },
  { id: 'other_wallet',  label: 'محفظة أخرى',    icon: <Wallet size={16}/>,       cashInput: false },
  { id: 'credit',        label: 'آجل',            icon: <Clock size={16}/>,        cashInput: false },
]

export default function PaymentModal({ onClose, onSuccess }) {
  const { items, setPaymentMethod, setAmountPaid, setDiscount, paymentMethod, rebillingInvoiceId } = useCartStore()
  const { taxEnabled, taxRate } = useSettingsStore()

  const [loading, setLoading]                 = useState(false)
  const [localDiscount, setLocalDiscount]     = useState(0)
  const [localAmountPaid, setLocalAmountPaid] = useState(0)

  // ── آجل states ──────────────────────────────────────────────
  const [customers, setCustomers]         = useState([])
  const [customersLoading, setCustomersLoading] = useState(false)
  const [customerMode, setCustomerMode]   = useState('existing') // 'existing' | 'new'
  const [selectedCustomerId, setSelectedCustomerId] = useState('')
  const [deposit, setDeposit]             = useState(0)           // العربون
  const [newCust, setNewCust]             = useState({ name: '', phone: '', address: '' })

  const isCreditSale = paymentMethod === 'credit'

  const rate             = taxEnabled ? (taxRate / 100) : 0
  const computedSubtotal = items.reduce((s, i) => s + i.subtotal, 0)
  const clampedDiscount  = Math.min(localDiscount, computedSubtotal)
  const computedTaxable  = computedSubtotal - clampedDiscount
  const computedTax      = Math.round(computedTaxable * rate * 100) / 100
  const computedTotal    = Math.round((computedTaxable + computedTax) * 100) / 100
  const computedChange   = Math.max(0, localAmountPaid - computedTotal)
  const amountDue        = isCreditSale ? Math.max(0, computedTotal - deposit) : 0

  const currentMethod = PAYMENT_METHODS.find(m => m.id === paymentMethod) ?? PAYMENT_METHODS[0]

  useEffect(() => { setLocalAmountPaid(computedTotal) }, [computedTotal])

  // تحميل العملاء عند اختيار آجل
  useEffect(() => {
    if (!isCreditSale) return
    setCustomersLoading(true)
    getCustomers()
      .then(r => setCustomers(r.data.data))
      .catch(() => toast.error('فشل تحميل العملاء'))
      .finally(() => setCustomersLoading(false))
  }, [isCreditSale])

  // إعادة ضبط deposit عند التغيير
  useEffect(() => { if (!isCreditSale) setDeposit(0) }, [isCreditSale])

  const handleCheckout = async () => {
    if (items.length === 0) return

    if (currentMethod.cashInput && localAmountPaid < computedTotal) {
      toast.error('المبلغ المدفوع أقل من الإجمالي')
      return
    }

    // التحقق من بيانات الآجل
    let customerId  = null
    let newCustomer = null
    if (isCreditSale) {
      if (customerMode === 'existing') {
        if (!selectedCustomerId) { toast.error('اختر عميلاً أو أنشئ جديداً'); return }
        customerId = parseInt(selectedCustomerId)
      } else {
        if (!newCust.name.trim()) { toast.error('أدخل اسم العميل'); return }
        // تُرسل بيانات العميل للباكند ليُنشئه داخل الـ transaction
        newCustomer = { name: newCust.name.trim(), phone: newCust.phone, address: newCust.address }
      }
    }

    setDiscount(clampedDiscount)
    setAmountPaid(isCreditSale ? deposit : localAmountPaid)

    const salePayload = {
      items:          items.map(i => ({ product_id: i.id, quantity: i.quantity, price: i.price })),
      discount:       clampedDiscount,
      payment_method: paymentMethod,
      amount_paid:    isCreditSale ? deposit : (currentMethod.cashInput ? localAmountPaid : computedTotal),
      ...(isCreditSale ? {
        customer_id:  customerId,
        deposit,
        ...(newCustomer ? { new_customer: newCustomer } : {}),
      } : {}),
      ...(rebillingInvoiceId ? { invoice_id: rebillingInvoiceId } : {}),
    }

    setLoading(true)
    try {
      const res = await createSale(salePayload)
      const { invoice, low_stock_alerts } = res.data.data
      toast.success(
        rebillingInvoiceId
          ? `تم تحديث الفاتورة #${formatNumber(rebillingInvoiceId)}`
          : isCreditSale
            ? `تم تسجيل البيع الآجل 📋 — المتبقي ${formatCurrency(amountDue)}`
            : 'تمت عملية البيع بنجاح! 🎉',
        { duration: 3000 }
      )
      if (low_stock_alerts?.length > 0) {
        low_stock_alerts.forEach(p =>
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
        toast.error(err.response?.data?.message || 'فشل في إتمام البيع')
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
        <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', marginBottom: '1rem' }}>
          <Row label="المجموع الجزئي" value={formatCurrency(computedSubtotal)} />
          {clampedDiscount > 0 && <Row label="الخصم" value={`- ${formatCurrency(clampedDiscount)}`} />}
          {taxEnabled && <Row label={`ضريبة (${formatPercent(taxRate)})`} value={formatCurrency(computedTax)} />}
          <div style={{ borderTop: '2px solid var(--border)', margin: '0.5rem 0' }} />
          <Row label="الإجمالي" value={formatCurrency(computedTotal)} bold />
          {isCreditSale && deposit > 0 && <Row label="عربون" value={`- ${formatCurrency(deposit)}`} />}
          {isCreditSale && <Row label="المتبقي آجلاً" value={formatCurrency(amountDue)} bold color={amountDue > 0 ? 'var(--danger)' : 'var(--primary)'} />}
        </div>

        {/* Discount */}
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>
            الخصم (ج.م)
          </label>
          <input type="number" min={0} max={computedSubtotal} step="0.5" className="input"
            value={localDiscount} onChange={e => setLocalDiscount(parseFloat(e.target.value) || 0)} />
        </div>

        {/* Payment method */}
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.5rem' }}>طريقة الدفع</label>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '0.5rem' }}>
            {PAYMENT_METHODS.map(m => (
              <PayBtn key={m.id} active={paymentMethod === m.id}
                onClick={() => setPaymentMethod(m.id)} icon={m.icon} label={m.label}
                isCredit={m.id === 'credit'} />
            ))}
          </div>
        </div>

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
            className={computedChange > 0 ? "change-box-active" : ""}
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

        {/* ── قسم الآجل ────────────────────────────────────────────────── */}
        {isCreditSale && (
          <div style={{
            border: '1px solid rgba(239,68,68,.3)', borderRadius: 'var(--radius)',
            background: 'rgba(239,68,68,.03)', padding: '0.85rem', marginBottom: '1rem',
            display: 'flex', flexDirection: 'column', gap: '0.65rem',
          }}>
            <div style={{ fontSize: '0.85rem', fontWeight: 700, color: 'var(--danger)' }}>⏳ بيع بالآجل</div>

            {/* اختيار: عميل موجود أم جديد */}
            <div style={{ display: 'flex', gap: '0.4rem' }}>
              {['existing', 'new'].map(mode => (
                <button key={mode} onClick={() => setCustomerMode(mode)}
                  className={`cust-mode-btn ${customerMode === mode ? 'active' : ''}`}>
                  {mode === 'existing' ? '👤 عميل موجود' : '➕ عميل جديد'}
                </button>
              ))}
            </div>

            {/* عميل موجود */}
            {customerMode === 'existing' && (
              <select className="input" value={selectedCustomerId} onChange={e => setSelectedCustomerId(e.target.value)}
                style={{ fontFamily: 'inherit' }}>
                <option value="">{customersLoading ? 'جارٍ التحميل...' : '— اختر عميلاً —'}</option>
                {customers.map(c => (
                  <option key={c.id} value={c.id}>
                    {c.name}{c.phone ? ` — ${c.phone}` : ''}{parseFloat(c.balance) > 0 ? ` (رصيد: ${formatCurrency(c.balance)})` : ''}
                  </option>
                ))}
              </select>
            )}

            {/* عميل جديد */}
            {customerMode === 'new' && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                <input className="input" placeholder="اسم العميل *" value={newCust.name}
                  onChange={e => setNewCust(n => ({ ...n, name: e.target.value }))} />
                <input className="input" placeholder="رقم الهاتف (اختياري)" value={newCust.phone}
                  onChange={e => setNewCust(n => ({ ...n, phone: e.target.value }))} />
                <input className="input" placeholder="العنوان (اختياري)" value={newCust.address}
                  onChange={e => setNewCust(n => ({ ...n, address: e.target.value }))} />
              </div>
            )}

            {/* العربون */}
            <div>
              <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.25rem' }}>
                العربون / المبلغ المقدَّم (ج.م) — اختياري
              </label>
              <input className="input" type="number" min={0} max={computedTotal} step="0.5"
                placeholder="0.00" value={deposit || ''}
                onChange={e => setDeposit(Math.min(parseFloat(e.target.value) || 0, computedTotal))} />
              {amountDue > 0 && (
                <div style={{ fontSize: '0.78rem', color: 'var(--danger)', marginTop: '0.25rem', fontWeight: 600 }}>
                  ⬅ المتبقي على الذمة: {formatCurrency(amountDue)}
                </div>
              )}
            </div>
          </div>
        )}

        {/* Checkout button */}
        <button className={`btn ${isCreditSale ? 'btn-danger' : 'btn-primary'} btn-lg`}
          style={{ width: '100%', justifyContent: 'center', fontSize: '1.05rem' }}
          onClick={handleCheckout} disabled={loading}>
          {loading ? <span className="spinner" /> : <CheckCircle2 size={20} />}
          {rebillingInvoiceId ? 'حفظ التعديل — '
            : isCreditSale ? 'تأكيد البيع الآجل — '
            : 'تأكيد البيع — '}
          {formatCurrency(computedTotal)}
        </button>
      </div>
    </div>
  )
}

function Row({ label, value, bold, color }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.3rem 0', fontWeight: bold ? 700 : 400, fontSize: bold ? '1rem' : '0.9rem' }}>
      <span>{label}</span>
      <span style={{ color: color || 'inherit' }}>{value}</span>
    </div>
  )
}

function PayBtn({ active, onClick, icon, label, isCredit }) {
  const activeClass = active ? (isCredit ? 'active-credit' : 'active-normal') : '';
  return (
    <button onClick={onClick} className={`pay-btn ${activeClass}`}>
      {icon} {label}
    </button>
  )
}
