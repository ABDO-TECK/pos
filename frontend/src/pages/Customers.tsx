import { useState, useEffect, useMemo } from 'react'
import {
  UserPlus, Search, ChevronRight, X, Trash2, Edit2,
  PlusCircle, Phone, MapPin, BookOpen, ArrowRight, Download,
} from 'lucide-react'
import { exportCustomerLedgerPDF } from '../utils/pdfExport'
import useQZPrinter from '../hooks/useQZPrinter'
import { QZPrinterPicker, QZPrintButton } from '../components/QZPrinterUI'
import useSettingsStore from '../store/settingsStore'
import {
  getCustomers, getCustomer, createCustomer,
  updateCustomer, deleteCustomer, addCustomerPayment, updateCustomerLedgerEntry,
} from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'
import useAuthStore from '../store/authStore'
import { useConfirmStore } from '../store/confirmStore'
import CustomerCard from '../components/customers/CustomerCard'
import CustomerFormModal from './customers/components/CustomerFormModal'
import CustomerPaymentModal from './customers/components/CustomerPaymentModal'
import CustomerEditEntryModal from './customers/components/CustomerEditEntryModal'
import CustomerLedgerTable from './customers/components/CustomerLedgerTable'

// ── helpers ──────────────────────────────────────────────────────────────────

const emptyForm = { name: '', phone: '', address: '', initial_balance: '', balance_direction: 'debit' }

// ─────────────────────────────────────────────────────────────────────────────
export default function Customers() {
  const [customers, setCustomers]       = useState<any[]>([])
  const [loading, setLoading]           = useState(true)
  const [search, setSearch]             = useState('')
  const { user } = useAuthStore()
  const { confirm } = useConfirmStore()

  // كشف الحساب
  const [ledgerData, setLedgerData]     = useState<any>(null)   // { customer, entries, balance }
  const [ledgerLoading, setLedgerLoading] = useState(false)

  // modal العميل (إضافة / تعديل)
  const [modal, setModal]               = useState<any>(null)   // 'create' | 'edit'
  const [form, setForm]                 = useState(emptyForm)
  const [editId, setEditId]             = useState<any>(null)
  const [saving, setSaving]             = useState(false)

  // modal الدفعة
  const [payModal, setPayModal]         = useState(false)
  const [payAmount, setPayAmount]       = useState('')
  const [payDesc, setPayDesc]           = useState('دفعة نقدية')
  const [payType, setPayType]           = useState('credit')
  const [payLoading, setPayLoading]     = useState(false)

  // modal التعديل للقيد
  const [editEntryModal, setEditEntryModal] = useState<any>(null)
  const [editEntryForm, setEditEntryForm] = useState({ type: 'debit', amount: '', description: '' })
  const [editEntryLoading, setEditEntryLoading] = useState(false)

  const settings = useSettingsStore()

  const qz = useQZPrinter()

  // ── data ──
  const load = async () => {
    setLoading(true)
    try {
      const res = (await getCustomers()).data
      const list = Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
      setCustomers(list)
    }
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
      initial_balance: String(Math.abs(c.initial_balance || 0) || ''),
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
    } catch (err: any) { toast.error(err.response?.data?.message || 'حدث خطأ') }
    finally { setSaving(false) }
  }

  const handleDelete = async (c, e) => {
    e.stopPropagation()
    if (!(await confirm(`هل تريد حذف العميل "${c.name}"؟`))) return
    try {
      await deleteCustomer(c.id)
      toast.success('تم الحذف')
      if (ledgerData?.customer?.id === c.id) setLedgerData(null)
      load()
    } catch (err: any) { toast.error(err.response?.data?.message || 'فشل الحذف') }
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
    } catch (err: any) { toast.error(err.response?.data?.message || 'فشل التسجيل') }
    finally { setPayLoading(false) }
  }

  const handleEditEntry = async () => {
    if (!editEntryForm.amount || Number.isNaN(Number(editEntryForm.amount))) {
      toast.error('الرجاء إدخال مبلغ صحيح')
      return
    }
    setEditEntryLoading(true)
    try {
      const res = await updateCustomerLedgerEntry(Number(editEntryModal.id), {
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
                onDelete={user?.role === 'admin' ? (e) => handleDelete(c, e) : null}
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
                <QZPrintButton
                  qzReady={qz.qzReady}
                  printing={qz.printing}
                  onQZPrint={async () => {
                    const b64 = await exportCustomerLedgerPDF(ledgerData.customer.id, true)
                    if (!b64) return
                    const r = await qz.qzPrintPDF(b64)
                    if (r.ok) toast.success('تمت الطباعة بنجاح')
                    else if (r.error) toast.error('فشل الطباعة: ' + r.error)
                  }}
                  onPickPrinter={() => qz.setShowPrinterPicker(true)}
                  onBrowserPrint={() => exportCustomerLedgerPDF(ledgerData.customer.id)}
                  label="طباعة وتصدير"
                />
              </div>
            </div>
          </div>

          {/* جدول كشف الحساب */}
          <CustomerLedgerTable
            ledgerLoading={ledgerLoading}
            ledgerData={ledgerData}
            setEditEntryModal={setEditEntryModal}
            setEditEntryForm={setEditEntryForm}
          />
        </div>
      )}

      {/* ── modal إضافة / تعديل عميل ────────────────────────────────────── */}
      <CustomerFormModal
        modal={modal}
        setModal={setModal}
        form={form}
        setForm={setForm}
        handleSave={handleSave}
        saving={saving}
      />

      {/* ── modal تسجيل دفعة ─────────────────────────────────────────────── */}
      <CustomerPaymentModal
        payModal={payModal}
        setPayModal={setPayModal}
        ledgerData={ledgerData}
        payType={payType}
        setPayType={setPayType}
        payAmount={payAmount}
        setPayAmount={setPayAmount}
        payDesc={payDesc}
        setPayDesc={setPayDesc}
        handlePayment={handlePayment}
        payLoading={payLoading}
      />

      {/* ── modal تعديل القيد ────────────────────────────────────────────── */}
      <CustomerEditEntryModal
        editEntryModal={editEntryModal}
        setEditEntryModal={setEditEntryModal}
        editEntryForm={editEntryForm}
        setEditEntryForm={setEditEntryForm}
        handleEditEntry={handleEditEntry}
        editEntryLoading={editEntryLoading}
      />


      {qz.showPrinterPicker && (
        <QZPrinterPicker
          printers={qz.printers}
          selectedPrinter={qz.selectedPrinter}
          onSelect={(name) => { qz.handlePrinterSelect(name); toast.success(`تم اختيار الطابعة: ${name}`) }}
          onClose={() => qz.setShowPrinterPicker(false)}
        />
      )}
    </div>
  )
}


