// @ts-nocheck
import { useState, useEffect } from 'react'
import { X, CheckCircle2, Truck, DollarSign, Calendar, FileText } from 'lucide-react'
import { formatCurrency, formatNumber } from '../../../utils/formatters'
import CreditPurchaseSection from './CreditPurchaseSection'
import toast from 'react-hot-toast'

interface ReceiveConfirmModalProps {
  supplier: any
  cart: any[]
  cartTotal: number
  cartCount: number
  paymentType: string
  setPaymentType: (val: string) => void
  deposit: number
  setDeposit: (val: number) => void
  onClose: () => void
  onConfirm: (deliveryData: any) => void
  loading: boolean
}

export default function ReceiveConfirmModal({
  supplier,
  cart,
  cartTotal,
  cartCount,
  paymentType,
  setPaymentType,
  deposit,
  setDeposit,
  onClose,
  onConfirm,
  loading,
}: ReceiveConfirmModalProps) {
  // Delivery info state
  const [driverName, setDriverName] = useState('')
  const [vehicleNumber, setVehicleNumber] = useState('')
  const [deliveryDate, setDeliveryDate] = useState(new Date().toISOString().split('T')[0])
  const [deliveryNotes, setDeliveryNotes] = useState('')
  const [activeTab, setActiveTab] = useState<'items' | 'payment' | 'delivery'>('items')

  // F12 key shortcut to trigger confirm
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F12' && !loading) {
        e.preventDefault()
        handleSubmit()
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [loading, driverName, vehicleNumber, deliveryDate, deliveryNotes, paymentType, deposit])

  const handleSubmit = () => {
    if (paymentType === 'credit' && deposit >= cartTotal) {
      toast.error('العربون يجب أن يكون أقل من إجمالي الفاتورة في الشراء الآجل')
      return
    }
    onConfirm({
      driver_name: driverName.trim() || undefined,
      vehicle_number: vehicleNumber.trim() || undefined,
      delivery_date: deliveryDate || undefined,
      delivery_notes: deliveryNotes.trim() || undefined,
    })
  }

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '560px', width: '90%' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h2 style={{ fontSize: '1.2rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            تأكيد استلام البضائع — {supplier?.name || 'مورد'}
          </h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose} disabled={loading}>
            <X size={18} />
          </button>
        </div>

        {/* Invoice Summary */}
        <div className="resp-2col" style={{ marginBottom: '1rem', gap: '0.75rem' }}>
          <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.75rem', textAlign: 'center', border: '1px solid var(--border)' }}>
            <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>عدد الأصناف</p>
            <p style={{ fontWeight: 700, fontSize: '1.1rem' }}>{formatNumber(cartCount)}</p>
          </div>
          <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.75rem', textAlign: 'center', border: '1px solid var(--border)' }}>
            <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>إجمالي الفاتورة</p>
            <p style={{ fontWeight: 700, fontSize: '1.1rem', color: 'var(--primary-d)' }}>{formatCurrency(cartTotal)}</p>
          </div>
        </div>

        {/* Tab Header */}
        <div style={{
          display: 'flex',
          borderBottom: '1px solid var(--border)',
          marginBottom: '1rem',
          gap: '0.25rem',
          background: 'var(--bg)',
          borderRadius: 'var(--radius)',
          padding: '0.2rem',
        }}>
          <button
            onClick={() => setActiveTab('items')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'items' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'items' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <FileText size={16} />
            <span>الأصناف المستلمة</span>
          </button>
          <button
            onClick={() => setActiveTab('payment')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'payment' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'payment' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <DollarSign size={16} />
            <span>الدفع والحساب</span>
          </button>
          <button
            onClick={() => setActiveTab('delivery')}
            type="button"
            className="btn"
            style={{
              flex: 1,
              padding: '0.5rem',
              fontSize: '0.85rem',
              fontWeight: 600,
              background: activeTab === 'delivery' ? 'var(--primary)' : 'transparent',
              color: activeTab === 'delivery' ? 'white' : 'var(--text)',
              border: 'none',
              borderRadius: 'calc(var(--radius) - 2px)',
              justifyContent: 'center',
              gap: '0.3rem',
              transition: 'all 0.2s',
              boxShadow: 'none'
            }}
          >
            <Truck size={16} />
            <span>بيانات التوصيل</span>
          </button>
        </div>

        {/* Tab Body */}
        <div style={{ minHeight: '190px' }}>
          {activeTab === 'items' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginBottom: '1.25rem' }}>
              {/* Collapsible Items Preview */}
              <div style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius)', overflow: 'hidden' }}>
                <div style={{ background: 'var(--bg)', padding: '0.5rem 0.75rem', fontWeight: 600, fontSize: '0.85rem', borderBottom: '1px solid var(--border)' }}>
                  معاينة المنتجات المستلمة ({cart.length})
                </div>
                <div style={{ maxHeight: '140px', overflowY: 'auto', padding: '0.5rem' }}>
                  <table style={{ width: '100%', fontSize: '0.8rem', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'right', color: 'var(--text-muted)' }}>
                        <th style={{ padding: '0.25rem' }}>المنتج</th>
                        <th style={{ padding: '0.25rem', textAlign: 'center' }}>الكمية</th>
                        <th style={{ padding: '0.25rem', textAlign: 'left' }}>التكلفة</th>
                      </tr>
                    </thead>
                    <tbody>
                      {cart.map((line, idx) => (
                        <tr key={idx} style={{ borderBottom: '1px dashed var(--border)' }}>
                          <td style={{ padding: '0.25rem' }}>
                            {line.product.name}
                            {line.product.size_name && <span style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}> ({line.product.size_name})</span>}
                          </td>
                          <td style={{ padding: '0.25rem', textAlign: 'center', fontWeight: 600 }}>{formatNumber(line.quantity)}</td>
                          <td style={{ padding: '0.25rem', textAlign: 'left' }}>{formatCurrency(line.cost)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'payment' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginBottom: '1.25rem' }}>
              <CreditPurchaseSection
                paymentType={paymentType}
                setPaymentType={setPaymentType}
                deposit={deposit}
                setDeposit={setDeposit}
                cartTotal={cartTotal}
              />
            </div>
          )}

          {activeTab === 'delivery' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginBottom: '1.25rem' }}>
              <div className="resp-2col" style={{ gap: '0.75rem' }}>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>اسم السائق</label>
                  <input
                    type="text"
                    className="input"
                    placeholder="مثال: أحمد محمد..."
                    value={driverName}
                    onChange={(e) => setDriverName(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>رقم السيارة</label>
                  <input
                    type="text"
                    className="input"
                    placeholder="مثال: أ ب ج 123..."
                    value={vehicleNumber}
                    onChange={(e) => setVehicleNumber(e.target.value)}
                  />
                </div>
              </div>
              <div className="resp-2col" style={{ gap: '0.75rem' }}>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>تاريخ التسليم</label>
                  <input
                    type="date"
                    className="input"
                    value={deliveryDate}
                    onChange={(e) => setDeliveryDate(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.25rem', display: 'block' }}>ملاحظات التسليم</label>
                  <input
                    type="text"
                    className="input"
                    placeholder="ملاحظات حول طريقة الشحن والتسليم..."
                    value={deliveryNotes}
                    onChange={(e) => setDeliveryNotes(e.target.value)}
                  />
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Actions */}
        <div style={{ display: 'flex', gap: '0.5rem', width: '100%', borderTop: '1px solid var(--border)', paddingTop: '1rem', marginTop: '1rem' }}>
          <button
            className={`btn ${paymentType === 'credit' ? 'btn-danger' : 'btn-primary'} btn-lg`}
            style={{ flex: 2, justifyContent: 'center', fontSize: '1.05rem' }}
            onClick={handleSubmit}
            disabled={loading}
          >
            {loading ? <span className="spinner" /> : <CheckCircle2 size={20} />}
            {paymentType === 'credit' ? 'تأكيد الاستلام الآجل — ' : 'تأكيد الاستلام — '}
            {formatCurrency(paymentType === 'credit' ? Math.max(0, cartTotal - deposit) : cartTotal)}
            <kbd style={{ fontSize: '0.75rem', padding: '0.1rem 0.4rem', background: 'rgba(255,255,255,0.2)', borderRadius: '4px', marginRight: '0.5rem', fontFamily: 'sans-serif' }}>F12</kbd>
          </button>
          <button
            className="btn btn-ghost btn-lg"
            style={{ flex: 1, justifyContent: 'center' }}
            onClick={onClose}
            disabled={loading}
          >
            إلغاء
          </button>
        </div>
      </div>
    </div>
  )
}

