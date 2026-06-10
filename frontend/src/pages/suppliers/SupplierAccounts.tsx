
import { useState, useEffect, useMemo } from 'react'
import { Plus, Edit2, Search, X, Package } from 'lucide-react'
import useSettingsStore from '../../store/settingsStore'
import { exportSupplierLedgerPDF } from '../../utils/pdfExport'
import useQZPrinter from '../../hooks/useQZPrinter'
import { QZPrinterPicker, QZPrintButton } from '../../components/QZPrinterUI'
import toast from 'react-hot-toast'
import { getSuppliers, getSupplier, addSupplierPayment, updateSupplierLedgerEntry } from '../../api/endpoints'
import { formatCurrency } from '../../utils/formatters'
import SupplierPaymentModal from './components/SupplierPaymentModal'
import SupplierEditEntryModal from './components/SupplierEditEntryModal'
import SupplierLedgerTable from './components/SupplierLedgerTable'
import { extractApiError } from '../../utils/apiError'

const fmtLedgerDate = (s: any) => {
  if (!s) return '—'
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(d)
}

export default function SupplierAccounts() {
  const [suppliers, setSuppliers]       = useState<any[]>([])
  const [loading, setLoading]           = useState(true)
  const [search, setSearch]             = useState('')

  // كشف الحساب
  const [ledgerData, setLedgerData]     = useState<any>(null)
  const [ledgerLoading, setLedgerLoading] = useState(false)

  // modal الدفعة
  const [payModal, setPayModal]         = useState(false)
  const [payAmount, setPayAmount]       = useState('')
  const [payDesc, setPayDesc]           = useState('دفعة نقدية للمورد')
  const [payType, setPayType]           = useState('credit')
  const [payLoading, setPayLoading]     = useState(false)

  // modal التعديل للقيد
  const [editEntryModal, setEditEntryModal] = useState<any>(null)
  const [editEntryForm, setEditEntryForm] = useState({ type: 'debit', amount: '', description: '' })
  const [editEntryLoading, setEditEntryLoading] = useState(false)

  const qz = useQZPrinter()
  const settings = useSettingsStore()

  const load = async () => {
    setLoading(true)
    try {
      const res = (await getSuppliers()).data
      const list = Array.isArray(res.data) ? res.data : ((res as any).data?.data ?? [])
      setSuppliers(list)
    }
    catch (err) { toast.error(extractApiError(err, 'فشل تحميل الموردين')) }
    finally { setLoading(false) }
  }

  useEffect(() => { load() }, [])

  const filtered = useMemo(() =>
    suppliers.filter(s =>
      s.name.toLowerCase().includes(search.toLowerCase()) ||
      (s.phone || '').includes(search)
    ), [suppliers, search])

  // ── ledger ──
  const openLedger = async (s: any) => {
    setLedgerLoading(true)
    setLedgerData({ supplier: s, entries: [], balance: 0 })
    try {
      const res = await getSupplier(s.id)
      setLedgerData(res.data.data)
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل كشف الحساب')) }
    finally { setLedgerLoading(false) }
  }

  // ── payment ──
  const handlePayment = async () => {
    const amount = parseFloat(payAmount)
    if (!amount || amount <= 0) { toast.error('أدخل مبلغاً صحيحاً'); return }
    setPayLoading(true)
    try {
      const res = await addSupplierPayment(ledgerData.supplier.id, { amount, description: payDesc, type: payType })
      setLedgerData(res.data.data)
      setPayModal(false)
      setPayAmount('')
      setPayDesc('دفعة نقدية للمورد')
      setPayType('credit')
      toast.success(`تم تسجيل دفعة ${formatCurrency(amount)}`)
      load()
    } catch (err) { toast.error(extractApiError(err, 'فشل التسجيل')) }
    finally { setPayLoading(false) }
  }

  const handleEditEntry = async () => {
    if (!editEntryForm.amount || Number.isNaN(Number(editEntryForm.amount))) {
      toast.error('الرجاء إدخال مبلغ صحيح')
      return
    }
    setEditEntryLoading(true)
    try {
      const res = await updateSupplierLedgerEntry(Number(editEntryModal.id), {
        type: editEntryForm.type,
        amount: parseFloat(editEntryForm.amount),
        description: editEntryForm.description,
      })
      toast.success('تم تعديل القيد')
      setLedgerData(res.data.data) // Update UI
      setEditEntryModal(null)
      load() // Refresh list balances
    } catch (err) { toast.error(extractApiError(err, 'فشل تعديل القيد')) } finally {
      setEditEntryLoading(false)
    }
  }

  return (
    <div className={`split-layout ${ledgerData ? 'has-detail' : ''}`}>

      {/* ── القائمة ── */}
      <div className={`split-list ${ledgerData ? 'is-split' : 'full-width'}`}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <h1 style={{ fontSize: '1.2rem', fontWeight: 700, flex: 1 }}>حسابات الموردين</h1>
        </div>

        <div style={{ position: 'relative' }}>
          <Search size={15} style={{ position: 'absolute', right: '0.65rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input className="input" style={{ paddingRight: '2rem' }} placeholder="ابحث بالاسم أو الهاتف..." value={search} onChange={e => setSearch(e.target.value)} />
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
        ) : filtered.length === 0 ? (
          <div className="empty-state"><Package size={36} color="var(--border)" /><p>لا يوجد موردون</p></div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
            {filtered.map(s => {
              const bal = parseFloat(s.balance) || 0
              return (
                <div
                  key={s.id}
                  onClick={() => openLedger(s)}
                  style={{
                    padding: '0.7rem 0.85rem', background: 'var(--surface)',
                    borderRadius: 'var(--radius)',
                    border: `1px solid ${ledgerData?.supplier?.id === s.id ? 'var(--primary)' : 'var(--border)'}`,
                    cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '0.6rem',
                    transition: 'border-color .15s',
                  }}
                >
                  <div style={{
                    width: '36px', height: '36px', borderRadius: '50%',
                    background: bal > 0 ? 'rgba(239,68,68,.12)' : 'var(--sup-primary-soft)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    fontSize: '1rem', flexShrink: 0,
                  }}>🏭</div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: '0.9rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.name}</div>
                    {s.phone && (
                      <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>📞 {s.phone}</div>
                    )}
                  </div>
                  <div style={{ textAlign: 'left', flexShrink: 0 }}>
                    <div style={{ fontSize: '0.82rem', fontWeight: 700, color: bal > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                      {bal > 0 ? formatCurrency(bal) : '✓ مُسدَّد'}
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* ── كشف الحساب ── */}
      {ledgerData && (
        <div className="split-detail">

          <div className="ledger-header">
            <div className="ledger-header-title">
              <button className="btn btn-ghost btn-icon" onClick={() => setLedgerData(null)}>
                <span style={{ transform: 'scaleX(-1)', display: 'inline-block' }}>←</span>
              </button>
              <div style={{ flex: 1, minWidth: 0 }}>
                <h2 style={{ fontSize: '1.1rem', fontWeight: 700, margin: 0, wordBreak: 'break-word' }}>
                  كشف حساب — {ledgerData.supplier.name}
                </h2>
                {ledgerData.supplier.phone && (
                  <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                    📞 {ledgerData.supplier.phone}
                  </span>
                )}
              </div>
            </div>

            <div className="ledger-header-actions">
              <div className="ledger-balance" style={{
                background: ledgerData.balance > 0 ? 'rgba(239,68,68,.08)' : 'var(--sup-primary-soft)',
                border: `1px solid ${ledgerData.balance > 0 ? '#fca5a5' : 'var(--sup-settled-border)'}`,
                borderRadius: 'var(--radius)', padding: '0.4rem 0.9rem', textAlign: 'center',
              }}>
                <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginBottom: '0.1rem' }}>الرصيد المستحق</div>
                <div style={{ fontSize: '1.1rem', fontWeight: 800, color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)' }}>
                  {formatCurrency(Math.abs(ledgerData.balance))}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '0.4rem' }}>
                <button className="btn btn-primary btn-sm" style={{ padding: '0.4rem 0.8rem', justifyContent: 'center' }} onClick={() => setPayModal(true)}>
                  <Plus size={15} /> تسجيل دفعة
                </button>
                <QZPrintButton
                  qzReady={qz.qzReady}
                  printing={qz.printing}
                  onQZPrint={async () => {
                    const b64 = await exportSupplierLedgerPDF(ledgerData.supplier.id, true)
                    if (!b64) return
                    const r = await qz.qzPrintPDF(b64)
                    if (r.ok) toast.success('تمت الطباعة بنجاح')
                    else if (r.error) toast.error('فشل الطباعة: ' + r.error)
                  }}
                  onPickPrinter={() => qz.setShowPrinterPicker(true)}
                  onBrowserPrint={() => exportSupplierLedgerPDF(ledgerData.supplier.id)}
                  label="طباعة وتصدير"
                />
              </div>
            </div>
          </div>

          <SupplierLedgerTable
            ledgerLoading={ledgerLoading}
            ledgerData={ledgerData}
            setEditEntryModal={setEditEntryModal}
            setEditEntryForm={setEditEntryForm}
          />
        </div>
      )}

      {/* ── modal تسجيل دفعة ── */}
      <SupplierPaymentModal
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

      {/* ── modal تعديل القيد ── */}
      <SupplierEditEntryModal
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
          onSelect={(name: string) => { qz.handlePrinterSelect(name); toast.success(`تم اختيار الطابعة: ${name}`) }}
          onClose={() => qz.setShowPrinterPicker(false)}
        />
      )}
    </div>
  )
}
