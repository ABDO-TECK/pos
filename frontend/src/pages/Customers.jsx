import { useState, useEffect, useMemo } from 'react'
import {
  UserPlus, Search, ChevronRight, X, Trash2, Edit2,
  PlusCircle, Phone, MapPin, BookOpen, ArrowRight, Download,
} from 'lucide-react'
import { exportCustomerLedgerPDF } from '../utils/pdfExport'
import {
  getCustomers, getCustomer, createCustomer,
  updateCustomer, deleteCustomer, addCustomerPayment, updateCustomerLedgerEntry,
} from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'

// ── helpers ──────────────────────────────────────────────────────────────────
/** تاريخ/وقت كشف الحساب بالإنجليزية (أسماء الأشهر والأرقام اللاتينية) */
const fmtDate = (s) => {
  if (!s) return '—'
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d)
}

const emptyForm = { name: '', phone: '', address: '', initial_balance: '', balance_direction: 'debit' }

// ─────────────────────────────────────────────────────────────────────────────
export default function Customers() {
  const [customers, setCustomers]       = useState([])
  const [loading, setLoading]           = useState(true)
  const [search, setSearch]             = useState('')

  // كشف الحساب
  const [ledgerData, setLedgerData]     = useState(null)   // { customer, entries, balance }
  const [ledgerLoading, setLedgerLoading] = useState(false)

  // modal العميل (إضافة / تعديل)
  const [modal, setModal]               = useState(null)   // 'create' | 'edit'
  const [form, setForm]                 = useState(emptyForm)
  const [editId, setEditId]             = useState(null)
  const [saving, setSaving]             = useState(false)

  // modal الدفعة
  const [payModal, setPayModal]         = useState(false)
  const [payAmount, setPayAmount]       = useState('')
  const [payDesc, setPayDesc]           = useState('دفعة نقدية')
  const [payType, setPayType]           = useState('credit')
  const [payLoading, setPayLoading]     = useState(false)

  // modal التعديل للقيد
  const [editEntryModal, setEditEntryModal] = useState(null)
  const [editEntryForm, setEditEntryForm] = useState({ type: 'debit', amount: '', description: '' })
  const [editEntryLoading, setEditEntryLoading] = useState(false)

  // ── data ──
  const load = async () => {
    setLoading(true)
    try { setCustomers((await getCustomers()).data.data) }
    catch { toast.error('فشل تحميل العملاء') }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const filtered = useMemo(() =>
    customers.filter(c =>
      c.name.toLowerCase().includes(search.toLowerCase()) ||
      (c.phone || '').includes(search)
    ), [customers, search])

  // ── ledger ──
  const openLedger = async (c) => {
    setLedgerLoading(true)
    setLedgerData({ customer: c, entries: [], balance: 0 })
    try {
      const res = await getCustomer(c.id)
      setLedgerData(res.data.data)
    } catch { toast.error('فشل تحميل كشف الحساب') }
    finally { setLedgerLoading(false) }
  }

  // ── CRUD ──
  const openCreate = () => { setForm(emptyForm); setEditId(null); setModal('create') }
  const openEdit   = (c, e) => {
    e.stopPropagation()
    setForm({
      name: c.name, phone: c.phone || '', address: c.address || '',
      initial_balance: Math.abs(c.initial_balance || 0) || '',
      balance_direction: (c.initial_balance || 0) < 0 ? 'credit' : 'debit',
    })
    setEditId(c.id)
    setModal('edit')
  }

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('الاسم مطلوب'); return }
    setSaving(true)
    try {
      const rawBal = parseFloat(form.initial_balance) || 0
      const payload = {
        ...form,
        initial_balance: form.balance_direction === 'credit' ? -Math.abs(rawBal) : Math.abs(rawBal),
      }
      delete payload.balance_direction
      if (modal === 'create') {
        await createCustomer(payload)
        toast.success('تم إضافة العميل')
      } else {
        await updateCustomer(editId, payload)
        toast.success('تم تحديث العميل')
        // تحديث كشف الحساب إذا كان مفتوحاً لنفس العميل
        if (ledgerData?.customer?.id === editId) {
          const res = await getCustomer(editId)
          setLedgerData(res.data.data)
        }
      }
      setModal(null)
      load()
    } catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ') }
    finally { setSaving(false) }
  }

  const handleDelete = async (c, e) => {
    e.stopPropagation()
    if (!window.confirm(`هل تريد حذف العميل "${c.name}"؟`)) return
    try {
      await deleteCustomer(c.id)
      toast.success('تم الحذف')
      if (ledgerData?.customer?.id === c.id) setLedgerData(null)
      load()
    } catch (err) { toast.error(err.response?.data?.message || 'فشل الحذف') }
  }

  // ── payment ──
  const handlePayment = async () => {
    const amount = parseFloat(payAmount)
    if (!amount || amount <= 0) { toast.error('أدخل مبلغاً صحيحاً'); return }
    setPayLoading(true)
    try {
      const res = await addCustomerPayment(ledgerData.customer.id, { amount, description: payDesc, type: payType })
      setLedgerData(res.data.data)
      setPayModal(false)
      setPayAmount('')
      setPayDesc('دفعة نقدية')
      setPayType('credit')
      toast.success(`تم تسجيل دفعة ${formatCurrency(amount)}`)
      load() // تحديث رصيد البطاقة
    } catch (err) { toast.error(err.response?.data?.message || 'فشل التسجيل') }
    finally { setPayLoading(false) }
  }

  const handleEditEntry = async () => {
    if (!editEntryForm.amount || isNaN(editEntryForm.amount)) {
      toast.error('الرجاء إدخال مبلغ صحيح')
      return
    }
    setEditEntryLoading(true)
    try {
      const res = await updateCustomerLedgerEntry(editEntryModal.id, {
        type: editEntryForm.type,
        amount: parseFloat(editEntryForm.amount),
        description: editEntryForm.description,
      })
      toast.success('تم تعديل القيد')
      setLedgerData(res.data.data) // Update UI
      setEditEntryModal(null)
      load() // Refresh list balances
    } catch {
      toast.error('فشل تعديل القيد')
    } finally {
      setEditEntryLoading(false)
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className={`split-layout ${ledgerData ? 'has-detail' : ''}`}>

      {/* ── القائمة ───────────────────────────────────────────────────────── */}
      <div className={`split-list ${ledgerData ? 'is-split' : 'full-width'}`}>
        {/* رأس */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <h1 style={{ fontSize: '1.2rem', fontWeight: 700, flex: 1 }}>العملاء</h1>
          <button className="btn btn-primary btn-sm" onClick={openCreate}>
            <UserPlus size={15} /> إضافة عميل
          </button>
        </div>

        {/* بحث */}
        <div style={{ position: 'relative' }}>
          <Search size={15} style={{ position: 'absolute', right: '0.65rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input className="input" style={{ paddingRight: '2rem' }} placeholder="ابحث بالاسم أو الهاتف..." value={search} onChange={e => setSearch(e.target.value)} />
        </div>

        {/* قائمة */}
        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
        ) : filtered.length === 0 ? (
          <div className="empty-state"><BookOpen size={36} color="var(--border)" /><p>لا يوجد عملاء</p></div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
            {filtered.map(c => (
              <CustomerCard
                key={c.id}
                customer={c}
                active={ledgerData?.customer?.id === c.id}
                onClick={() => openLedger(c)}
                onEdit={(e) => openEdit(c, e)}
                onDelete={(e) => handleDelete(c, e)}
              />
            ))}
          </div>
        )}
      </div>

      {/* ── كشف الحساب ───────────────────────────────────────────────────── */}
      {ledgerData && (
        <div className="split-detail">

          {/* رأس كشف الحساب */}
          <div className="ledger-header">
            <div className="ledger-header-title">
              <button className="btn btn-ghost btn-icon" onClick={() => setLedgerData(null)}>
                <ArrowRight size={18} />
              </button>
              <div style={{ flex: 1, minWidth: 0 }}>
                <h2 style={{ fontSize: '1.1rem', fontWeight: 700, margin: 0, wordBreak: 'break-word' }}>
                  كشف حساب — {ledgerData.customer.name}
                </h2>
                {ledgerData.customer.phone && (
                  <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                    <Phone size={11} style={{ verticalAlign: 'middle' }} /> {ledgerData.customer.phone}
                  </span>
                )}
              </div>
            </div>

            <div className="ledger-header-actions">
              {/* الرصيد الإجمالي */}
              <div className="ledger-balance" style={{
                background: ledgerData.balance > 0 ? 'rgba(239,68,68,.08)' : 'rgba(34,197,94,.08)',
                border: `1px solid ${ledgerData.balance > 0 ? '#fca5a5' : '#86efac'}`,
                borderRadius: 'var(--radius)', padding: '0.4rem 0.9rem', textAlign: 'center',
              }}>
                <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginBottom: '0.1rem' }}>الرصيد المستحق</div>
                <div style={{ fontSize: '1.1rem', fontWeight: 800, color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                  {formatCurrency(Math.abs(ledgerData.balance))}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '0.4rem' }}>
                <button className="btn btn-primary btn-sm" style={{ padding: '0.4rem 0.8rem', justifyContent: 'center' }} onClick={() => setPayModal(true)}>
                  <PlusCircle size={15} /> تسجيل دفعة
                </button>
                <button
                  className="btn btn-ghost btn-sm"
                  style={{ padding: '0.4rem 0.8rem', justifyContent: 'center', gap: '0.3rem' }}
                  onClick={() => exportCustomerLedgerPDF(ledgerData)}
                  title="تصدير كشف الحساب PDF"
                >
                  <Download size={15} /> تصدير
                </button>
              </div>
            </div>
          </div>

          {/* جدول كشف الحساب */}
          {ledgerLoading ? (
            <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
          ) : (
            <div style={{ flex: 1, overflowY: 'auto', borderRadius: 'var(--radius)', border: '1px solid var(--border)' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
                <thead>
                  <tr style={{ background: 'var(--surface)', position: 'sticky', top: 0, zIndex: 1 }}>
                    {['التاريخ', 'البيان', 'مدين', 'دائن', 'الرصيد', ''].map(h => (
                      <th key={h} style={{
                        padding: '0.65rem 0.75rem', fontWeight: 700, textAlign: h === 'مدين' || h === 'دائن' || h === 'الرصيد' ? 'left' : 'right',
                        borderBottom: '2px solid var(--border)', whiteSpace: 'nowrap', width: h === '' ? '40px' : 'auto'
                      }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {ledgerData.entries.length === 0 ? (
                    <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد حركات بعد</td></tr>
                  ) : ledgerData.entries.map((row, i) => (
                    <LedgerRow key={row.id ?? `init-${i}`} row={row} onEdit={() => {
                      setEditEntryModal(row)
                      setEditEntryForm({
                        type: row.type,
                        amount: row.type === 'debit' ? (row.debit || 0) : (row.credit || 0),
                        description: row.description || ''
                      })
                    }} />
                  ))}
                </tbody>
                {ledgerData.entries.length > 0 && (
                  <tfoot>
                    <tr style={{ background: 'var(--surface)', fontWeight: 700, borderTop: '2px solid var(--border)' }}>
                      <td colSpan={2} style={{ padding: '0.6rem 0.75rem' }}>الإجمالي</td>
                      <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--danger)' }}>
                        {formatCurrency(ledgerData.entries.reduce((s, r) => s + r.debit, 0))}
                      </td>
                      <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--primary)' }}>
                        {formatCurrency(ledgerData.entries.reduce((s, r) => s + r.credit, 0))}
                      </td>
                      <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)', fontSize: '1rem' }}>
                        {formatCurrency(ledgerData.balance)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          )}
        </div>
      )}

      {/* ── modal إضافة / تعديل عميل ────────────────────────────────────── */}
      {modal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setModal(null)}>
          <div className="modal" style={{ maxWidth: '440px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
              <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>
                {modal === 'create' ? 'إضافة عميل جديد' : 'تعديل بيانات العميل'}
              </h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setModal(null)}><X size={18} /></button>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div>
                <label className="label">الاسم *</label>
                <input className="input" placeholder="اسم العميل" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} />
              </div>
              <div>
                <label className="label">رقم الهاتف</label>
                <input className="input" placeholder="05xxxxxxxx" value={form.phone} onChange={e => setForm(f => ({ ...f, phone: e.target.value }))} />
              </div>
              <div>
                <label className="label">العنوان</label>
                <input className="input" placeholder="العنوان (اختياري)" value={form.address} onChange={e => setForm(f => ({ ...f, address: e.target.value }))} />
              </div>
              <div style={{ background: 'rgba(59,130,246,.06)', border: '1px dashed var(--secondary)', borderRadius: 'var(--radius)', padding: '0.65rem 0.75rem' }}>
                <label className="label">📒 رصيد مبدئي (ج.م)</label>
                <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: '0 0 0.4rem' }}>
                  حدد اتجاه الرصيد المبدئي ثم أدخل المبلغ، وإلا اتركه 0
                </p>
                <div style={{ display: 'flex', gap: '0.35rem', marginBottom: '0.5rem' }}>
                  {[
                    { id: 'debit',  label: '⬅ هو مدين لي',  color: 'var(--danger)',  bg: 'rgba(239,68,68,.1)' },
                    { id: 'credit', label: '➡ أنا مدين له', color: 'var(--secondary)', bg: 'rgba(59,130,246,.1)' },
                  ].map(d => (
                    <button key={d.id} type="button" onClick={() => setForm(f => ({ ...f, balance_direction: d.id }))}
                      style={{
                        flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                        borderRadius: 'var(--radius)',
                        border: `2px solid ${form.balance_direction === d.id ? d.color : 'var(--border)'}`,
                        background: form.balance_direction === d.id ? d.bg : 'var(--surface)',
                        color: form.balance_direction === d.id ? d.color : 'var(--text-muted)',
                        cursor: 'pointer', transition: 'all .15s',
                      }}
                    >{d.label}</button>
                  ))}
                </div>
                <input className="input" type="number" min="0" step="0.01" placeholder="0.00"
                  value={form.initial_balance}
                  onChange={e => setForm(f => ({ ...f, initial_balance: e.target.value }))} />
              </div>
            </div>

            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem', justifyContent: 'flex-end' }}>
              <button className="btn btn-ghost" onClick={() => setModal(null)}>إلغاء</button>
              <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
                {saving ? <span className="spinner" /> : null}
                {modal === 'create' ? 'إضافة' : 'حفظ التعديلات'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── modal تسجيل دفعة ─────────────────────────────────────────────── */}
      {payModal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setPayModal(false)}>
          <div className="modal" style={{ maxWidth: '380px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
              <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>تسجيل دفعة</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setPayModal(false)}><X size={18} /></button>
            </div>

            <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.75rem 1rem', marginBottom: '1rem', display: 'flex', justifyContent: 'space-between' }}>
              <span style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>الرصيد المستحق</span>
              <span style={{ fontWeight: 700, color: 'var(--danger)' }}>{formatCurrency(ledgerData?.balance)}</span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div>
                <label className="label">نوع الدفعة</label>
                <div style={{ display: 'flex', gap: '0.35rem' }}>
                  {[
                    { id: 'credit', label: 'استلام دفعة من العميل', color: 'var(--primary)', bg: 'rgba(34,197,94,.1)' },
                    { id: 'debit',  label: 'دفع مبلغ للعميل',      color: 'var(--danger)', bg: 'rgba(239,68,68,.1)' },
                  ].map(d => (
                    <button key={d.id} type="button" onClick={() => setPayType(d.id)}
                      style={{
                        flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                        borderRadius: 'var(--radius)',
                        border: `2px solid ${payType === d.id ? d.color : 'var(--border)'}`,
                        background: payType === d.id ? d.bg : 'var(--surface)',
                        color: payType === d.id ? d.color : 'var(--text-muted)',
                        cursor: 'pointer', transition: 'all .15s',
                      }}
                    >{d.label}</button>
                  ))}
                </div>
              </div>
              <div>
                <label className="label">المبلغ (ج.م) *</label>
                <input className="input input-lg" type="number" min="0.01" step="0.01"
                  placeholder="0.00" value={payAmount}
                  onChange={e => setPayAmount(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handlePayment()} />
              </div>
              <div>
                <label className="label">البيان</label>
                <input className="input" placeholder="دفعة نقدية" value={payDesc}
                  onChange={e => setPayDesc(e.target.value)} />
              </div>
            </div>

            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem', justifyContent: 'flex-end' }}>
              <button className="btn btn-ghost" onClick={() => setPayModal(false)}>إلغاء</button>
              <button className="btn btn-primary" onClick={handlePayment} disabled={payLoading}>
                {payLoading ? <span className="spinner" /> : <PlusCircle size={16} />}
                تسجيل الدفعة
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── modal تعديل القيد ────────────────────────────────────────────── */}
      {editEntryModal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setEditEntryModal(null)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
              <h2 style={{ fontSize: '1.1rem', fontWeight: 700 }}>تعديل القيد</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setEditEntryModal(null)}><X size={18} /></button>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
              <div>
                <label className="label">نوع القيد</label>
                <div style={{ display: 'flex', gap: '0.35rem' }}>
                  {[
                    { id: 'debit',  label: 'مدين (مستحق لي)',  color: 'var(--danger)',  bg: 'rgba(239,68,68,.1)' },
                    { id: 'credit', label: 'دائن (دفعة/مستحق له)', color: 'var(--secondary)', bg: 'rgba(59,130,246,.1)' },
                  ].map(d => (
                    <button key={d.id} type="button" onClick={() => setEditEntryForm(f => ({ ...f, type: d.id }))}
                      style={{
                        flex: 1, padding: '0.4rem', fontSize: '0.82rem', fontWeight: 600,
                        borderRadius: 'var(--radius)',
                        border: `2px solid ${editEntryForm.type === d.id ? d.color : 'var(--border)'}`,
                        background: editEntryForm.type === d.id ? d.bg : 'var(--surface)',
                        color: editEntryForm.type === d.id ? d.color : 'var(--text-muted)',
                        cursor: 'pointer', transition: 'all .15s',
                      }}
                    >{d.label}</button>
                  ))}
                </div>
              </div>
              <div>
                <label className="label">المبلغ</label>
                <input className="input" type="number" min="0" step="0.01" value={editEntryForm.amount} onChange={e => setEditEntryForm({ ...editEntryForm, amount: e.target.value })} autoFocus />
              </div>
              <div>
                <label className="label">البيان (اختياري)</label>
                <input className="input" value={editEntryForm.description} onChange={e => setEditEntryForm({ ...editEntryForm, description: e.target.value })} />
              </div>
            </div>

            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setEditEntryModal(null)}>إلغاء</button>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleEditEntry} disabled={editEntryLoading}>
                {editEntryLoading ? <span className="spinner" /> : <Edit2 size={16} />}
                تأكيد التعديل
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// ── CustomerCard ──────────────────────────────────────────────────────────────
function CustomerCard({ customer, active, onClick, onEdit, onDelete }) {
  const balance = parseFloat(customer.balance) || 0
  return (
    <div
      onClick={onClick}
      style={{
        padding: '0.7rem 0.85rem',
        background: 'var(--surface)',
        borderRadius: 'var(--radius)',
        border: `1px solid ${active ? 'var(--primary)' : 'var(--border)'}`,
        cursor: 'pointer',
        display: 'flex', alignItems: 'center', gap: '0.6rem',
        transition: 'border-color .15s',
      }}
    >
      {/* أيقونة */}
      <div style={{
        width: '36px', height: '36px', borderRadius: '50%',
        background: balance > 0 ? 'rgba(239,68,68,.12)' : 'rgba(34,197,94,.12)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: '1rem', flexShrink: 0,
      }}>👤</div>

      {/* بيانات */}
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{customer.name}</div>
        {customer.phone && (
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
            <Phone size={10} style={{ verticalAlign: 'middle' }} /> {customer.phone}
          </div>
        )}
      </div>

      {/* رصيد */}
      <div style={{ textAlign: 'left', flexShrink: 0 }}>
        <div style={{ fontSize: '0.82rem', fontWeight: 700, color: balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
          {balance > 0 ? formatCurrency(balance) : '✓ مُسدَّد'}
        </div>
      </div>

      {/* أزرار */}
      <div style={{ display: 'flex', gap: '0.2rem', flexShrink: 0 }} onClick={e => e.stopPropagation()}>
        <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem' }} onClick={onEdit}><Edit2 size={14} /></button>
        <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem', color: 'var(--danger)' }} onClick={onDelete}><Trash2 size={14} /></button>
      </div>

      <ChevronRight size={16} color="var(--text-muted)" style={{ flexShrink: 0, transform: 'scaleX(-1)' }} />
    </div>
  )
}

// ── LedgerRow ─────────────────────────────────────────────────────────────────
function LedgerRow({ row, onEdit }) {
  const isDebit  = row.debit  > 0
  const isCredit = row.credit > 0
  return (
    <tr style={{ borderBottom: '1px solid var(--border)', transition: 'background .1s' }}
        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg)'}
        onMouseLeave={e => e.currentTarget.style.background = ''}>
      <td style={{ padding: '0.55rem 0.75rem', whiteSpace: 'nowrap', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
        {fmtDate(row.date)}
      </td>
      <td style={{ padding: '0.55rem 0.75rem' }}>
        <span style={{ fontSize: '0.85rem' }}>{row.description || '—'}</span>
        {row.type === 'initial' && (
          <span style={{ marginRight: '0.5rem', fontSize: '0.7rem', background: 'rgba(59,130,246,.1)', color: 'var(--secondary)', borderRadius: '3px', padding: '0.1rem 0.35rem' }}>رصيد مبدئي</span>
        )}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: isDebit ? 700 : 400, color: isDebit ? 'var(--danger)' : 'var(--text-muted)' }}>
        {isDebit ? formatCurrency(row.debit) : '—'}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: isCredit ? 700 : 400, color: isCredit ? 'var(--primary)' : 'var(--text-muted)' }}>
        {isCredit ? formatCurrency(row.credit) : '—'}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: 700, color: row.balance > 0 ? 'var(--danger)' : row.balance < 0 ? 'var(--primary)' : 'var(--text)' }}>
        {formatCurrency(Math.abs(row.balance))}
        {row.balance > 0 && <span style={{ fontSize: '0.65rem', color: 'var(--danger)', marginRight: '0.3rem' }}>د</span>}
        {row.balance < 0 && <span style={{ fontSize: '0.65rem', color: 'var(--primary)', marginRight: '0.3rem' }}>ر</span>}
      </td>
      <td style={{ padding: '0.2rem 0.5rem', textAlign: 'center' }}>
        {row.id && row.type !== 'initial' && (
          <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem', color: 'var(--text-muted)' }} onClick={onEdit} title="تعديل القيد">
            <Edit2 size={13} />
          </button>
        )}
      </td>
    </tr>
  )
}
