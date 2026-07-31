import { useState, useEffect } from 'react'
import { getCustomers } from '../../../api/endpoints'
import { formatCurrency } from '../../../utils/formatters'
import toast from 'react-hot-toast'
import styles from './CustomerSection.module.css'
interface Props {
  isCreditSale: boolean
  rebillingCustomerId: number | null
  computedTotal: number
  amountDue: number
  deposit: number
  onDepositChange: (val: number) => void
  onCustomerSelect: (customerId: number | null, newCustomer: NewCustomer | null) => void
}

interface NewCustomer {
  name: string
  phone: string
  address: string
}

export default function CustomerSection({ isCreditSale, rebillingCustomerId, computedTotal, amountDue, deposit, onDepositChange, onCustomerSelect }: Props) {
  const [customers, setCustomers] = useState<any[]>([])
  const [customersLoading, setCustomersLoading] = useState(false)
  const [customerMode, setCustomerMode] = useState('existing')
  const [selectedCustomerId, setSelectedCustomerId] = useState(rebillingCustomerId ? String(rebillingCustomerId) : '')
  const [newCust, setNewCust] = useState({ name: '', phone: '', address: '' })

  useEffect(() => {
    setCustomersLoading(true)
    getCustomers()
      .then((r: any) => { const d = r.data.data; setCustomers(Array.isArray(d) ? d : (d?.data ?? [])) })
      .catch(() => toast.error('فشل تحميل العملاء'))
      .finally(() => setCustomersLoading(false))
  }, [])

  const notifyNewCustomer = (customer: NewCustomer) => {
    onCustomerSelect(
      null,
      customer.name.trim()
        ? { ...customer, name: customer.name.trim() }
        : null,
    )
  }

  const handleModeChange = (mode: 'existing' | 'new') => {
    setCustomerMode(mode)
    if (mode === 'existing') {
      onCustomerSelect(selectedCustomerId ? Number(selectedCustomerId) : null, null)
    } else {
      notifyNewCustomer(newCust)
    }
  }

  const handleExistingCustomerChange = (value: string) => {
    setSelectedCustomerId(value)
    onCustomerSelect(value ? Number(value) : null, null)
  }

  const handleNewCustomerChange = (field: keyof NewCustomer, value: string) => {
    const nextCustomer = { ...newCust, [field]: value }
    setNewCust(nextCustomer)
    notifyNewCustomer(nextCustomer)
  }

  return (
    <div style={{
      border: isCreditSale ? '1px solid rgba(239,68,68,.3)' : '1px solid var(--border)',
      borderRadius: 'var(--radius)',
      background: isCreditSale ? 'rgba(239,68,68,.03)' : 'var(--bg)',
      padding: '0.85rem', marginBottom: '1rem',
      display: 'flex', flexDirection: 'column', gap: '0.65rem',
    }}>
      <div style={{ fontSize: '0.85rem', fontWeight: 700, color: isCreditSale ? 'var(--danger)' : 'var(--primary)' }}>
        {isCreditSale ? '⏳ بيع بالآجل (مطلوب)' : '👤 تسجيل الفاتورة على عميل (اختياري)'}
      </div>

      <div style={{ display: 'flex', gap: '0.4rem' }}>
        {(['existing', 'new'] as const).map(mode => (
          <button key={mode} type="button" onClick={() => handleModeChange(mode)}
            className={`${styles.custModeBtn} ${customerMode === mode ? styles.active : ''}`}>
            {mode === 'existing' ? '👤 عميل موجود' : '➕ عميل جديد'}
          </button>
        ))}
      </div>

      {customerMode === 'existing' && (
        <select className="input" value={selectedCustomerId} onChange={e => handleExistingCustomerChange(e.target.value)}
          style={{ fontFamily: 'inherit' }}>
          <option value="">{customersLoading ? 'جارٍ التحميل...' : '— اختر عميلاً —'}</option>
          {customers.map(c => (
            <option key={c.id} value={c.id}>
              {c.name}{c.phone ? ` — ${c.phone}` : ''}{parseFloat(c.balance) > 0 ? ` (رصيد: ${formatCurrency(c.balance)})` : ''}
            </option>
          ))}
        </select>
      )}

      {customerMode === 'new' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <input className="input" placeholder="اسم العميل *" value={newCust.name}
            onChange={e => handleNewCustomerChange('name', e.target.value)} />
          <input className="input" placeholder="رقم الهاتف (اختياري)" value={newCust.phone}
            onChange={e => handleNewCustomerChange('phone', e.target.value)} />
          <input className="input" placeholder="العنوان (اختياري)" value={newCust.address}
            onChange={e => handleNewCustomerChange('address', e.target.value)} />
        </div>
      )}

      {isCreditSale && (
        <div>
          <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.25rem' }}>
            العربون / المبلغ المقدَّم (ج.م) — اختياري
          </label>
          <input className="input" type="number" min={0} max={computedTotal} step="0.5"
            placeholder="0.00" value={deposit || ''}
            onChange={e => onDepositChange(Math.min(parseFloat(e.target.value) || 0, computedTotal))} />
          {amountDue > 0 && (
            <div style={{ fontSize: '0.78rem', color: 'var(--danger)', marginTop: '0.25rem', fontWeight: 600 }}>
              ⬅ المتبقي على الذمة: {formatCurrency(amountDue)}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
