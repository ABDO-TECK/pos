import { useState, useEffect } from 'react'
import { Printer, X, Settings } from 'lucide-react'
import toast from 'react-hot-toast'
import { formatCurrency, formatNumber, formatPercent, formatDate } from '../../utils/formatters'
import { browserPrint } from '../../utils/receiptBuilder'
import useSettingsStore from '../../store/settingsStore'
import {
    isQZAvailable,
    isQZConnected,
    connectQZ,
    printInvoice,
    listPrinters,
    getSavedPrinter,
    savePrinter,
} from '../../utils/qzPrint'

const METHOD_LABELS = {
    cash:          'نقدي',
    card:          'بطاقة ائتمان',
    vodafone_cash: 'فودافون كاش',
    instapay:      'انستاباي',
    other_wallet:  'محفظة إلكترونية',
    credit:        'آجل',
}

export default function Receipt({ invoice, change, onClose }) {
    const { storeName, taxEnabled, taxRate } = useSettingsStore()
    const settings = { storeName, taxEnabled, taxRate }

    const [qzStatus,         setQzStatus]         = useState('idle')
    const [printers,         setPrinters]         = useState([])
    const [selectedPrinter,  setSelectedPrinter]  = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker,setShowPrinterPicker] = useState(false)
    const [printing,         setPrinting]         = useState(false)

    useEffect(() => {
        if (!isQZAvailable()) { setQzStatus('unavailable'); return }
        if (isQZConnected())  { setQzStatus('ready'); loadPrinters(); return }
        setQzStatus('connecting')
        connectQZ()
            .then(() => { setQzStatus('ready'); loadPrinters() })
            .catch(() => setQzStatus('error'))
    }, [])

    const loadPrinters = async () => {
        try {
            const list = await listPrinters()
            setPrinters(list)
            const saved = getSavedPrinter()
            if (saved && list.includes(saved)) setSelectedPrinter(saved)
            else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
        } catch { /* ignore */ }
    }

    if (!invoice) return null

    const isCash    = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change

    // ── QZ Tray print ──
    const handleQZPrint = async () => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return }
        setPrinting(true)
        try {
            await printInvoice(invoice, change, settings, selectedPrinter)
            toast.success('تمت الطباعة بنجاح')
        } catch (err) {
            toast.error('فشل الطباعة: ' + (err.message ?? ''))
        } finally {
            setPrinting(false)
        }
    }

    // ── Browser print — opens a new clean window (fixes blank-page issue) ──
    const handleBrowserPrint = () => browserPrint(invoice, changeAmt, settings)

    const handlePrinterSelect = (name) => {
        savePrinter(name)
        setSelectedPrinter(name)
        setShowPrinterPicker(false)
        toast.success(`تم اختيار الطابعة: ${name}`)
    }

    return (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="modal" style={{ maxWidth: '420px' }}>

                {/* ── Modal header ── */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>فاتورة #{formatNumber(invoice.id)}</h3>
                    <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
                </div>

                {/* ── Receipt preview (screen only — uses invoice-container CSS) ── */}
                <div style={{
                    border: '1px solid var(--border)', borderRadius: 'var(--radius)',
                    padding: '0.75rem', background: '#fff',
                    fontFamily: "Arial, Tahoma, 'DejaVu Sans', sans-serif",
                    fontSize: '0.82rem', lineHeight: 1.5, color: '#000',
                    maxHeight: '55vh', overflowY: 'auto',
                }}>
                    {/* Header */}
                    <div style={{ textAlign: 'center', marginBottom: '6px', paddingBottom: '6px', borderBottom: '1.5px solid #000' }}>
                        <div style={{ fontWeight: 900, fontSize: '0.95rem' }}>🛒 {storeName || 'سوبر ماركت'}</div>
                        <div style={{ fontSize: '0.75rem', fontWeight: 700 }}>فاتورة رقم: #{formatNumber(invoice.id)}</div>
                    </div>

                    {/* Info rows — time + cashier on same line */}
                    <div style={{ marginBottom: '6px', fontSize: '0.72rem' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', margin: '2px 0' }}>
                            <span><strong>التاريخ:</strong> {formatDate(invoice.created_at)}</span>
                            <span><strong>طريقة الدفع:</strong> {METHOD_LABELS[invoice.payment_method] ?? invoice.payment_method}</span>
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', margin: '2px 0' }}>
                            <span><strong>الوقت:</strong> {new Date(invoice.created_at).toLocaleTimeString('ar-EG-u-nu-latn', { hour: '2-digit', minute: '2-digit' })}</span>
                            <span><strong>الكاشير:</strong> {invoice.cashier_name ?? '—'}</span>
                        </div>
                    </div>

                    {/* Items table — no currency symbol in price/total columns */}
                    <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '6px', fontSize: '0.7rem' }}>
                        <thead>
                            <tr style={{ background: '#f0f0f0' }}>
                                <Th>#</Th>
                                <Th align="right">المنتج</Th>
                                <Th>الكمية</Th>
                                <Th>السعر</Th>
                                <Th>الإجمالي</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {(invoice.items ?? []).map((item, i) => (
                                <tr key={item.id ?? i}>
                                    <Td>{formatNumber(i + 1)}</Td>
                                    <Td align="right">{item.product_name ?? item.name}</Td>
                                    <Td>{formatNumber(item.quantity)}</Td>
                                    <Td>{formatNumber(parseFloat(item.price).toFixed(2))}</Td>
                                    <Td>{formatNumber((parseFloat(item.price) * parseFloat(item.quantity)).toFixed(2))}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {/* Totals */}
                    <div style={{ paddingTop: '4px' }}>
                        <TotalLine label="المجموع الجزئي" value={formatCurrency(invoice.subtotal)} />
                        {parseFloat(invoice.discount) > 0 && (
                            <TotalLine label="الخصم" value={`- ${formatCurrency(invoice.discount)}`} />
                        )}
                        {taxEnabled && parseFloat(invoice.tax) > 0 && (
                            <TotalLine label={`ضريبة (${formatPercent(taxRate)})`} value={formatCurrency(invoice.tax)} />
                        )}
                        <TotalLine label="الإجمالي" value={formatCurrency(invoice.total)} bold />
                        {isCash && (
                            <>
                                <TotalLine label="المدفوع" value={formatCurrency(invoice.amount_paid)} />
                                <TotalLine label="المسترد" value={formatCurrency(changeAmt)} />
                            </>
                        )}
                        {invoice.payment_method === 'credit' && (
                            <>
                                {parseFloat(invoice.amount_paid) > 0 && (
                                    <TotalLine label="عربون مدفوع" value={formatCurrency(invoice.amount_paid)} />
                                )}
                                <TotalLine label="متبقي آجلاً" value={formatCurrency(invoice.amount_due ?? (invoice.total - invoice.amount_paid))} bold />
                            </>
                        )}
                    </div>

                    {/* Footer */}
                    <div style={{ textAlign: 'center', marginTop: '8px', paddingTop: '4px', fontSize: '0.78rem', fontWeight: 700 }}>
                        شكراً لزيارتكم 
                    </div>
                </div>

                {/* ── QZ Tray status bar ── */}
                <QZStatusBar
                    status={qzStatus}
                    printer={selectedPrinter}
                    onPickPrinter={() => setShowPrinterPicker(true)}
                />

                {/* ── Action buttons ── */}
                <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem' }}>
                    {qzStatus === 'ready' ? (
                        <button
                            className="btn btn-primary"
                            style={{ flex: 1, justifyContent: 'center' }}
                            onClick={handleQZPrint}
                            disabled={printing}
                        >
                            {printing ? <span className="spinner" /> : <Printer size={16} />}
                            {printing ? 'جاري الطباعة…' : 'طباعة QZ'}
                        </button>
                    ) : (
                        <button
                            className="btn btn-primary"
                            style={{ flex: 1, justifyContent: 'center' }}
                            onClick={handleBrowserPrint}
                        >
                            <Printer size={16} /> طباعة
                        </button>
                    )}
                    <button
                        className="btn btn-ghost"
                        style={{ flex: 1, justifyContent: 'center' }}
                        onClick={onClose}
                    >
                        إغلاق
                    </button>
                </div>

                {/* Always offer browser print as secondary */}
                {qzStatus === 'ready' && (
                    <button
                        className="btn btn-ghost btn-sm"
                        style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}
                        onClick={handleBrowserPrint}
                    >
                        <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة عبر المتصفح (بديل)
                    </button>
                )}
            </div>

            {/* ── Printer picker modal ── */}
            {showPrinterPicker && (
                <div className="modal-overlay" style={{ zIndex: 1100 }}
                    onClick={(e) => e.target === e.currentTarget && setShowPrinterPicker(false)}>
                    <div className="modal" style={{ maxWidth: '380px' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                            <h3 style={{ fontWeight: 700 }}>اختر الطابعة</h3>
                            <button className="btn btn-ghost btn-icon" onClick={() => setShowPrinterPicker(false)}><X size={18}/></button>
                        </div>
                        {printers.length === 0 ? (
                            <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '1rem' }}>لا توجد طابعات متاحة</p>
                        ) : (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                                {printers.map(p => (
                                    <button key={p} onClick={() => handlePrinterSelect(p)} style={{
                                        padding: '0.65rem 1rem', textAlign: 'right',
                                        border: `2px solid ${p === selectedPrinter ? 'var(--primary)' : 'var(--border)'}`,
                                        borderRadius: 'var(--radius)',
                                        background: p === selectedPrinter ? '#f0fdf4' : 'var(--surface)',
                                        cursor: 'pointer', fontFamily: 'inherit', fontSize: '0.9rem',
                                        fontWeight: p === selectedPrinter ? 600 : 400,
                                    }}>
                                        🖨 {p}
                                    </button>
                                ))}
                            </div>
                        )}
                        <button className="btn btn-ghost"
                            style={{ width: '100%', justifyContent: 'center', marginTop: '0.75rem' }}
                            onClick={() => setShowPrinterPicker(false)}>
                            إغلاق
                        </button>
                    </div>
                </div>
            )}
        </div>
    )
}

// ── Small helper components ────────────────────────────────────────────────

function InfoRow({ label, value }) {
    return (
        <div style={{ display: 'flex', gap: '4px', margin: '2px 0', fontSize: '0.78rem' }}>
            <span style={{ fontWeight: 800 }}>{label}:</span>
            <span>{value}</span>
        </div>
    )
}

function Th({ children, align = 'center' }) {
    return (
        <th style={{
            padding: '3px 4px', fontSize: '0.78rem', fontWeight: 900,
            border: '1.5px solid #000', textAlign: align,
            background: '#fff', color: '#000',
        }}>
            {children}
        </th>
    )
}

function Td({ children, align = 'center' }) {
    return (
        <td style={{
            padding: '3px 4px', fontSize: '0.78rem', fontWeight: 700,
            border: '1.5px solid #000', textAlign: align,
            background: '#fff', color: '#000',
        }}>
            {children}
        </td>
    )
}

function TotalLine({ label, value, bold }) {
    return (
        <div style={{
            display: 'flex', justifyContent: 'space-between',
            padding: bold ? '2px 0' : '1px 0',
            fontWeight: bold ? 900 : 700,
            fontSize: bold ? '0.92rem' : '0.82rem',
            borderTop: bold ? '1.5px solid #000' : 'none',
            borderBottom: bold ? '1.5px solid #000' : 'none',
        }}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    )
}

function QZStatusBar({ status, printer, onPickPrinter }) {
    const cfg = {
        idle:        { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray: جاري التحميل…' },
        connecting:  { bg: '#fef9c3', text: '#854d0e', label: 'QZ Tray: جاري الاتصال…' },
        ready:       { bg: '#dcfce7', text: '#166534',
                       label: printer
                           ? `QZ Tray ✓ — ${printer.length > 28 ? printer.slice(0,28)+'…' : printer}`
                           : 'QZ Tray: متصل — انقر لاختيار الطابعة' },
        error:       { bg: '#fee2e2', text: '#991b1b', label: 'QZ Tray: فشل الاتصال — سيُستخدم طباعة المتصفح' },
        unavailable: { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray غير مثبت — سيُستخدم طباعة المتصفح' },
    }
    const { bg, text, label } = cfg[status] ?? cfg.idle
    return (
        <div onClick={status === 'ready' ? onPickPrinter : undefined}
            title={status === 'ready' ? 'انقر لتغيير الطابعة' : undefined}
            style={{
                marginTop: '0.6rem', padding: '0.4rem 0.7rem',
                background: bg, color: text, borderRadius: 'var(--radius)',
                fontSize: '0.76rem', fontWeight: 600,
                cursor: status === 'ready' ? 'pointer' : 'default',
                display: 'flex', alignItems: 'center', gap: '0.35rem',
            }}>
            {status === 'ready' && <Settings size={12} />}
            {label}
        </div>
    )
}
