/**
 * QZPrinterUI — Reusable UI components for QZ Tray integration.
 * Includes: QZStatusBar, QZPrinterPicker, and QZPrintButton.
 */
import { Printer, Settings, X } from 'lucide-react'

/** Status indicator bar */
export function QZStatusBar({ status, printer, onPickPrinter }) {
    const cfg = {
        idle:        { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray: جاري التحميل…' },
        connecting:  { bg: '#fef9c3', text: '#854d0e', label: 'QZ Tray: جاري الاتصال…' },
        ready:       { bg: '#dcfce7', text: '#166534',
                       label: printer
                           ? `QZ ✓ — ${printer.length > 28 ? printer.slice(0,28)+'…' : printer}`
                           : 'QZ Tray: متصل — انقر لاختيار الطابعة' },
        error:       { bg: '#fee2e2', text: '#991b1b', label: 'QZ Tray: فشل الاتصال' },
        unavailable: { bg: '#f3f4f6', text: '#6b7280', label: 'QZ Tray غير مثبت' },
    }
    const { bg, text, label } = cfg[status] ?? cfg.idle
    return (
        <div onClick={status === 'ready' ? onPickPrinter : undefined}
            title={status === 'ready' ? 'انقر لتغيير الطابعة' : undefined}
            style={{
                padding: '0.35rem 0.6rem',
                background: bg, color: text, borderRadius: 'var(--radius)',
                fontSize: '0.72rem', fontWeight: 600,
                cursor: status === 'ready' ? 'pointer' : 'default',
                display: 'flex', alignItems: 'center', gap: '0.3rem',
            }}>
            {status === 'ready' && <Settings size={11} />}
            {label}
        </div>
    )
}

/** Printer picker modal */
export function QZPrinterPicker({ printers, selectedPrinter, onSelect, onClose }) {
    return (
        <div className="modal-overlay" style={{ zIndex: 1100 }}
            onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="modal" style={{ maxWidth: '380px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                    <h3 style={{ fontWeight: 700 }}>اختر الطابعة</h3>
                    <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18}/></button>
                </div>
                {printers.length === 0 ? (
                    <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '1rem' }}>لا توجد طابعات متاحة</p>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                        {printers.map(p => (
                            <button
                                key={p}
                                onClick={() => onSelect(p)}
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
                    onClick={onClose}>
                    إغلاق
                </button>
            </div>
        </div>
    )
}

/**
 * QZPrintButton — A smart print button.
 * When QZ Tray is ready, it prints via QZ; otherwise falls back to browser print.
 */
export function QZPrintButton({
    qzReady,
    printing,
    onQZPrint,
    onBrowserPrint,
    onPickPrinter,
    label = 'طباعة',
    size = 'sm',
    style = {},
}) {
    if (qzReady) {
        return (
            <div style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center', ...style }}>
                <button
                    className={`btn btn-ghost btn-${size}`}
                    onClick={onQZPrint}
                    disabled={printing}
                    title="طباعة عبر QZ Tray"
                >
                    {printing ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Printer size={15} />}
                    {printing ? 'جاري…' : label}
                </button>
                {onPickPrinter && (
                    <button
                        className={`btn btn-ghost btn-icon`}
                        onClick={onPickPrinter}
                        title="اختيار الطابعة"
                    >
                        <Settings size={15} />
                    </button>
                )}
            </div>
        )
    }

    return (
        <button
            className={`btn btn-ghost btn-${size}`}
            onClick={onBrowserPrint}
            title="طباعة عبر المتصفح"
            style={style}
        >
            <Printer size={15} /> {label}
        </button>
    )
}
