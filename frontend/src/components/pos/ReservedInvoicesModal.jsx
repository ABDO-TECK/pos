import { useState, useEffect } from 'react'
import { X, CheckCircle2, Trash2, Calendar, Clock } from 'lucide-react'
import { getSales, getSale, deleteSale } from '../../api/endpoints'
import { formatCurrency, formatShortDate } from '../../utils/formatters'
import toast from 'react-hot-toast'

export default function ReservedInvoicesModal({ onClose, onResumeSale }) {
  const [invoices, setInvoices] = useState([])
  const [loading, setLoading] = useState(true)

  const loadReserved = async () => {
    setLoading(true)
    try {
      // Get sales with status reserved
      const res = await getSales({ status: 'reserved', limit: 50 })
      setInvoices(res.data.data?.data || res.data.data || [])
    } catch (err) {
      toast.error('فشل تحميل الفواتير المحجوزة')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadReserved()
  }, [])

  const handleCancel = async (id) => {
    if (!confirm('هل أنت متأكد من إلغاء هذه الفاتورة المحجوزة واسترداد المخزون؟')) return
    try {
      await deleteSale(id)
      toast.success('تم إلغاء الفاتورة وإرجاع المنتجات')
      loadReserved()
    } catch (err) {
      toast.error('فشل إلغاء الفاتورة')
    }
  }

  const handleComplete = async (invoice) => {
    try {
      setLoading(true)
      const res = await getSale(invoice.id)
      const fullInvoice = res.data.data
      onResumeSale(fullInvoice)
    } catch (err) {
      toast.error('فشل تحميل تفاصيل الفاتورة')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="modal-overlay" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '700px', width: '100%' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h2 style={{ fontSize: '1.2rem', fontWeight: 700 }}>الفواتير المحجوزة 🕒</h2>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></div>
        ) : invoices.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-muted)' }}>
            لا توجد فواتير محجوزة حالياً
          </div>
        ) : (
          <div className="table-responsive">
            <table className="table">
              <thead>
                <tr>
                  <th>رقم</th>
                  <th>التاريخ</th>
                  <th>العميل</th>
                  <th>الإجمالي</th>
                  <th>المدفوع</th>
                  <th>المتبقي</th>
                  <th style={{ width: '120px' }}>إجراء</th>
                </tr>
              </thead>
              <tbody>
                {invoices.map(inv => (
                  <tr key={inv.id}>
                    <td style={{ fontWeight: 600 }}>#{inv.id}</td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', fontSize: '0.85rem' }}>
                        <Clock size={12} style={{ color: 'var(--text-muted)' }} />
                        {formatShortDate(inv.created_at)}
                      </div>
                    </td>
                    <td>{inv.customer_name || <span style={{ color: 'var(--text-muted)' }}>—</span>}</td>
                    <td style={{ fontWeight: 600 }}>{formatCurrency(inv.total)}</td>
                    <td style={{ color: 'var(--success)' }}>{formatCurrency(inv.amount_paid)}</td>
                    <td style={{ color: 'var(--danger)', fontWeight: 600 }}>{formatCurrency(inv.amount_due)}</td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.4rem' }}>
                        <button className="btn btn-primary btn-sm" onClick={() => handleComplete(inv)} title="استلام ودفع">
                          <CheckCircle2 size={14} /> إكمال
                        </button>
                        <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleCancel(inv.id)} title="إلغاء الحجز">
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
