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
    const [remoteError,      setRemoteError]      = useState(null)

    useEffect(() => {
        if (!isQZAvailable()) { setQzStatus('unavailable'); return }
        if (isQZConnected())  { setQzStatus('ready'); loadPrinters(); return }
        setQzStatus('connecting')
        connectQZ()
            .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
            .catch((err) => {
                setQzStatus('error')
                if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
            })
    }, [])

    const retryQZ = () => {
        setQzStatus('connecting')
        setRemoteError(null)
        connectQZ()
            .then(() => { setQzStatus('ready'); loadPrinters() })
            .catch((err) => {
                setQzStatus('error')
                if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
            })
    }

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

                {/* ── Receipt preview (Matches Printed Version Exactly) ── */}
                <div style={{
                    background: '#e5e7eb', padding: '1rem',
                    borderRadius: 'var(--radius)',
                    maxHeight: '55vh', overflowY: 'auto',
                    display: 'flex', justifyContent: 'center'
                }}>
                    {/* The 80mm Thermal Paper */}
                    <div style={{
                        background: '#ffffff',
                        width: '80mm', // standard thermal size
                        maxWidth: '100%',
                        padding: '4mm',
                        boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
                        fontFamily: "Arial, Tahoma, 'DejaVu Sans', sans-serif",
                        fontSize: '3mm', lineHeight: 1.2, color: '#000',
                        direction: 'rtl', textShadow: 'none',
                    }}>
                        {/* Header */}
                        <div style={{
                            textAlign: 'center', marginBottom: '2mm',
                            paddingBottom: '2mm', borderBottom: '1.5pt solid #000'
                        }}>
                            <h2 style={{ fontSize: '5mm', margin: '0.5mm 0', fontWeight: 900, color: '#000' }}>
                                {storeName || 'سوبر ماركت'}
                            </h2>
                            <div style={{ fontWeight: 900, fontSize: '3.5mm', marginTop: '1mm' }}>
                                فاتورة رقم: #{formatNumber(invoice.id)}
                            </div>
                        </div>

                        {/* Details */}
                        <div style={{ margin: '1.5mm 0', paddingBottom: '1mm' }}>
                            <InfoRow label="التاريخ" value={formatDate(invoice.created_at)} />
                            <InfoRow label="طريقة الدفع" value={METHOD_LABELS[invoice.payment_method] ?? invoice.payment_method} />
                            <InfoRow label="الوقت" value={new Date(invoice.created_at).toLocaleTimeString('ar-EG-u-nu-latn', { hour: '2-digit', minute: '2-digit' })} />
                            <InfoRow label="الكاشير" value={invoice.cashier_name ?? '—'} />
                        </div>

                        {/* Items table */}
                        <table style={{ width: '100%', borderCollapse: 'collapse', margin: '1.5mm 0' }}>
                            <thead>
                                <tr>
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
                                        <Td align="right" isName>{item.product_name ?? item.name}</Td>
                                        <Td>{formatNumber(item.quantity)}</Td>
                                        <Td>{formatNumber(parseFloat(item.price).toFixed(2))}</Td>
                                        <Td>{formatNumber((parseFloat(item.price) * parseFloat(item.quantity)).toFixed(2))}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {/* Totals */}
                        <div style={{ marginTop: '1mm' }}>
                            <TotalLine label="المجموع الجزئي" value={formatCurrency(invoice.subtotal)} />
                            {parseFloat(invoice.discount) > 0 && (
                                <TotalLine label="الخصم" value={`- ${formatCurrency(invoice.discount)}`} />
                            )}
                            {taxEnabled && parseFloat(invoice.tax) > 0 && (
                                <TotalLine label={`ضريبة (${formatPercent(taxRate)})`} value={formatCurrency(invoice.tax)} />
                            )}
                            <TotalLine label="الإجمالي" value={formatCurrency(invoice.total)} grand />
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
                                    <TotalLine label="متبقي آجلاً" value={formatCurrency(invoice.amount_due ?? (invoice.total - invoice.amount_paid))} grand />
                                </>
                            )}
                        </div>

                        {/* Footer */}
                        <div style={{
                            textAlign: 'center', marginTop: '2mm',
                            fontSize: '3mm', fontWeight: 700, color: '#000'
                        }}>
                            <p style={{ margin: '0.5mm 0' }}>شكراً لزيارتكم — نتمنى لكم تجربة ممتعة</p>
                        </div>
                    </div>
                </div>

                {/* ── QZ Tray status bar ── */}
                <QZStatusBar
                    status={qzStatus}
                    printer={selectedPrinter}
                    onPickPrinter={() => setShowPrinterPicker(true)}
                    remoteError={remoteError}
                    onRetry={retryQZ}
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
                                    <button
                                        key={p}
                                        onClick={() => handlePrinterSelect(p)}
                                        className={`qz-printer-item ${p === selectedPrinter ? 'selected' : ''}`}
                                    >
                                        <Printer size={16} style={{ color: p === selectedPrinter ? 'var(--primary)' : 'var(--text-muted)' }} />
                                        {p}
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
        <div style={{ display: 'flex', justifyContent: 'space-between', margin: '0.8mm 0', fontSize: '3mm' }}>
            <span style={{ fontWeight: 900, whiteSpace: 'nowrap' }}>{label}:</span>
            <span style={{ textAlign: 'left' }}>{value}</span>
        </div>
    )
}

function Th({ children, align = 'center' }) {
    return (
        <th style={{
            padding: '0.8mm 1mm', fontSize: '2.8mm', fontWeight: 900,
            border: '1pt solid #000', textAlign: align,
            background: '#fff', color: '#000', verticalAlign: 'middle',
        }}>
            {children}
        </th>
    )
}

function Td({ children, align = 'center', isName }) {
    return (
        <td style={{
            padding: '0.8mm 1mm', fontSize: '2.8mm', fontWeight: 700,
            border: '1pt solid #000', textAlign: align,
            background: '#fff', color: '#000', verticalAlign: 'middle',
            maxWidth: isName ? '25mm' : 'auto', wordBreak: isName ? 'break-word' : 'normal'
        }}>
            {children}
        </td>
    )
}

function TotalLine({ label, value, grand }) {
    return (
        <div style={{
            display: 'flex', justifyContent: 'space-between',
            margin: grand ? '0.5mm 0 0.8mm 0' : '0.8mm 0',
            padding: grand ? '1mm 0' : '0',
            fontSize: grand ? '4mm' : '3mm',
            fontWeight: grand ? 900 : 700,
            color: '#000',
            borderTop: grand ? '1.5pt solid #000' : 'none',
            borderBottom: grand ? '1.5pt solid #000' : 'none',
        }}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    )
}

function QZStatusBar({ status, printer, onPickPrinter, remoteError, onRetry }) {
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

    if (status === 'error' && remoteError) {
        return (
            <div style={{
                marginTop: '0.6rem', padding: '0.5rem 0.75rem',
                background: '#fef3c7', color: '#92400e', borderRadius: 'var(--radius)',
                fontSize: '0.72rem', fontWeight: 600,
                display: 'flex', flexDirection: 'column', gap: '0.4rem',
            }}>
                <span>🖨️ لتفعيل الطباعة عبر الشبكة:</span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>
                    1. افتح{' '}
                    <a href={remoteError.certUrl} target="_blank" rel="noopener noreferrer"
                        style={{ color: '#1d4ed8', textDecoration: 'underline', fontWeight: 600 }}>
                        هذا الرابط
                    </a>
                    {' '}واقبل الشهادة
                </span>
                <span style={{ fontWeight: 400, fontSize: '0.68rem' }}>2. ارجع هنا واضغط «إعادة المحاولة»</span>
                {onRetry && (
                    <button onClick={onRetry} style={{
                        marginTop: '0.25rem', padding: '0.3rem 0.6rem',
                        background: '#1d4ed8', color: '#fff', border: 'none',
                        borderRadius: 'var(--radius)', cursor: 'pointer',
                        fontSize: '0.7rem', fontWeight: 600,
                    }}>
                        🔄 إعادة المحاولة
                    </button>
                )}
            </div>
        )
    }

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
