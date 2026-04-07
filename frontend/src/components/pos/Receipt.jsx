import { useState, useEffect } from 'react'
import { Printer, X, Settings } from 'lucide-react'
import toast from 'react-hot-toast'
import { formatCurrency, formatDate, formatNumber, formatPercent } from '../../utils/formatters'
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
    card:          'بطاقة',
    vodafone_cash: 'فودافون كاش',
    instapay:      'انستاباي',
    other_wallet:  'محفظة أخرى',
}

export default function Receipt({ invoice, change, onClose }) {
    const { storeName, taxEnabled, taxRate } = useSettingsStore()

    const [qzStatus, setQzStatus]       = useState('idle')   // idle | connecting | ready | error
    const [printers, setPrinters]       = useState([])
    const [selectedPrinter, setSelectedPrinter] = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker, setShowPrinterPicker] = useState(false)
    const [printing, setPrinting]       = useState(false)

    // ── Try to connect to QZ Tray on mount ──
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
            // Auto-select saved printer if available
            const saved = getSavedPrinter()
            if (saved && list.includes(saved)) setSelectedPrinter(saved)
            else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
        } catch { /* ignore */ }
    }

    if (!invoice) return null

    // ── QZ Tray print ──
    const handleQZPrint = async () => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return }
        setPrinting(true)
        try {
            await printInvoice(invoice, change, { storeName, taxEnabled, taxRate }, selectedPrinter)
            toast.success('تمت الطباعة بنجاح')
        } catch (err) {
            toast.error('فشل الطباعة: ' + (err.message ?? ''))
        } finally {
            setPrinting(false)
        }
    }

    // ── Browser print fallback ──
    const handleBrowserPrint = () => window.print()

    const handlePrinterSelect = (name) => {
        savePrinter(name)
        setSelectedPrinter(name)
        setShowPrinterPicker(false)
        toast.success(`تم اختيار الطابعة: ${name}`)
    }

    const isCash   = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change

    return (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="modal" style={{ maxWidth: '400px' }}>

                {/* Header */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>فاتورة #{formatNumber(invoice.id)}</h3>
                    <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
                </div>

                {/* ── Receipt content (printed via browser print too) ── */}
                <div
                    id="receipt-print"
                    className="invoice-container"
                    style={{ fontFamily: 'monospace', fontSize: '0.85rem', lineHeight: 1.8 }}
                >
                    {/* Store header */}
                    <div style={{ textAlign: 'center', marginBottom: '0.75rem' }}>
                            <div style={{ fontWeight: 900, fontSize: '1.05rem' }}>🛒 {storeName || 'سوبر ماركت'}</div>
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{formatDate(invoice.created_at)}</div>
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>الكاشير: {invoice.cashier_name}</div>
                    </div>

                    {/* Items */}
                    <div style={{ borderTop: '1px dashed var(--border)', borderBottom: '1px dashed var(--border)', padding: '0.5rem 0', marginBottom: '0.5rem' }}>
                        {(invoice.items ?? []).map((item) => (
                            <div key={item.id} style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span>{item.product_name ?? item.name} × {formatNumber(item.quantity)}</span>
                                <span>{formatCurrency(item.price * item.quantity)}</span>
                            </div>
                        ))}
                    </div>

                    {/* Totals */}
                    <TotalRow label="المجموع الجزئي" value={formatCurrency(invoice.subtotal)} />
                    {parseFloat(invoice.discount) > 0 && (
                        <TotalRow label="الخصم" value={`- ${formatCurrency(invoice.discount)}`} color="var(--danger)" />
                    )}
                    {taxEnabled && parseFloat(invoice.tax) > 0 && (
                        <TotalRow label={`ضريبة ${formatPercent(taxRate)}`} value={formatCurrency(invoice.tax)} color="var(--text-muted)" />
                    )}
                    <div style={{ borderTop: '1px solid var(--border)', marginTop: '0.25rem', paddingTop: '0.25rem' }}>
                        <TotalRow label="الإجمالي" value={formatCurrency(invoice.total)} bold green />
                    </div>

                    <TotalRow label="طريقة الدفع" value={METHOD_LABELS[invoice.payment_method] ?? invoice.payment_method} />
                    {isCash && (
                        <>
                            <TotalRow label="المدفوع" value={formatCurrency(invoice.amount_paid)} />
                            <TotalRow label="الباقي" value={formatCurrency(changeAmt)} color="var(--secondary)" bold />
                        </>
                    )}

                    <div style={{ textAlign: 'center', marginTop: '0.75rem', color: 'var(--text-muted)', fontSize: '0.75rem' }}>
                        شكراً لزيارتكم ✨
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
                    {/* QZ Tray print — shown when QZ is available */}
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
                        /* Browser print fallback */
                        <button
                            className="btn btn-primary"
                            style={{ flex: 1, justifyContent: 'center' }}
                            onClick={handleBrowserPrint}
                        >
                            <Printer size={16} /> طباعة
                        </button>
                    )}
                    <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={onClose}>
                        إغلاق
                    </button>
                </div>

                {/* Always offer browser print as secondary option when QZ is available */}
                {qzStatus === 'ready' && (
                    <button
                        className="btn btn-ghost btn-sm"
                        style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', color: 'var(--text-muted)' }}
                        onClick={handleBrowserPrint}
                    >
                        طباعة عبر المتصفح (بديل)
                    </button>
                )}
            </div>

            {/* Printer picker modal */}
            {showPrinterPicker && (
                <div className="modal-overlay" style={{ zIndex: 1100 }} onClick={(e) => e.target === e.currentTarget && setShowPrinterPicker(false)}>
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
                                        style={{
                                            padding: '0.65rem 1rem', textAlign: 'right',
                                            border: `2px solid ${p === selectedPrinter ? 'var(--primary)' : 'var(--border)'}`,
                                            borderRadius: 'var(--radius)',
                                            background: p === selectedPrinter ? '#f0fdf4' : 'var(--surface)',
                                            cursor: 'pointer', fontFamily: 'inherit', fontSize: '0.9rem',
                                            fontWeight: p === selectedPrinter ? 600 : 400,
                                        }}
                                    >
                                        🖨 {p}
                                    </button>
                                ))}
                            </div>
                        )}

                        <button
                            className="btn btn-ghost"
                            style={{ width: '100%', justifyContent: 'center', marginTop: '0.75rem' }}
                            onClick={() => setShowPrinterPicker(false)}
                        >
                            إغلاق
                        </button>
                    </div>
                </div>
            )}

            {/* Browser print styles */}
            <style>{`
                @media print {
                    body > * { display: none !important; }
                    #receipt-print { display: block !important; }
                }
            `}</style>
        </div>
    )
}

function TotalRow({ label, value, bold, green, color }) {
    return (
        <div style={{
            display: 'flex', justifyContent: 'space-between',
            fontWeight: bold ? 700 : 400,
            fontSize: bold ? '1rem' : '0.88rem',
            color: color ?? (green ? 'var(--primary)' : 'var(--text)'),
        }}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    )
}

function QZStatusBar({ status, printer, onPickPrinter }) {
    const colors = {
        idle:        { bg: '#f3f4f6', text: '#6b7280' },
        connecting:  { bg: '#fef9c3', text: '#854d0e' },
        ready:       { bg: '#dcfce7', text: '#166534' },
        error:       { bg: '#fee2e2', text: '#991b1b' },
        unavailable: { bg: '#f3f4f6', text: '#6b7280' },
    }
    const labels = {
        idle:        'QZ Tray: جاري التحميل…',
        connecting:  'QZ Tray: جاري الاتصال…',
        ready:       printer ? `QZ Tray: متصل ✓  —  ${printer.length > 25 ? printer.slice(0,25)+'…' : printer}` : 'QZ Tray: متصل — لم تُختر طابعة بعد',
        error:       'QZ Tray: فشل الاتصال — سيُستخدم طباعة المتصفح',
        unavailable: 'QZ Tray: غير مثبت — سيُستخدم طباعة المتصفح',
    }
    const { bg, text } = colors[status] ?? colors.idle
    const label = labels[status] ?? ''

    return (
        <div
            onClick={status === 'ready' ? onPickPrinter : undefined}
            style={{
                marginTop: '0.75rem', padding: '0.45rem 0.75rem',
                background: bg, color: text, borderRadius: 'var(--radius)',
                fontSize: '0.78rem', fontWeight: 600,
                cursor: status === 'ready' ? 'pointer' : 'default',
                display: 'flex', alignItems: 'center', gap: '0.4rem',
            }}
            title={status === 'ready' ? 'انقر لتغيير الطابعة' : undefined}
        >
            {status === 'ready' && <Settings size={12} />}
            {label}
        </div>
    )
}
