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
import { InfoRow } from './receipt/ReceiptHelpers'
import QZStatusBar from './receipt/QZStatusBar'
import ReceiptItemsTable from './receipt/ReceiptItemsTable'
import ReceiptTotals from './receipt/ReceiptTotals'
import PrinterPickerModal from './receipt/PrinterPickerModal'

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
    const [printers,         setPrinters]         = useState<any[]>([])
    const [selectedPrinter,  setSelectedPrinter]  = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker,setShowPrinterPicker] = useState(false)
    const [printing,         setPrinting]         = useState(false)
    const [remoteError,      setRemoteError]      = useState<any>(null)

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
    const handleQZPrint = async (paperSize = '80mm') => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return }
        setPrinting(true)
        try {
            await printInvoice(invoice, change, settings, selectedPrinter, paperSize)
            toast.success(`تمت الطباعة بنجاح (${paperSize})`)
        } catch (err: any) {
            toast.error('فشل الطباعة: ' + (err.message ?? ''))
        } finally {
            setPrinting(false)
        }
    }

    // ── Browser print — opens a new clean window (fixes blank-page issue) ──
    const handleBrowserPrint = () => browserPrint(invoice, changeAmt, settings, '80mm')
    const handleA4Print = () => browserPrint(invoice, changeAmt, settings, 'A4')

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
                        <ReceiptItemsTable items={invoice.items} />

                        {/* Totals */}
                        <ReceiptTotals 
                            invoice={invoice} 
                            taxEnabled={taxEnabled} 
                            taxRate={taxRate} 
                            isCash={isCash} 
                            changeAmt={changeAmt} 
                        />

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
                        <div style={{ display: 'flex', gap: '0.5rem', flex: 2 }}>
                            <button
                                className="btn btn-primary"
                                style={{ flex: 1, justifyContent: 'center', padding: '0.4rem' }}
                                onClick={() => handleQZPrint('80mm')}
                                disabled={printing}
                            >
                                {printing ? <span className="spinner" /> : <Printer size={16} />}
                                {printing ? '...' : 'QZ (80mm)'}
                            </button>
                            <button
                                className="btn btn-secondary"
                                style={{ flex: 1, justifyContent: 'center', padding: '0.4rem' }}
                                onClick={() => handleQZPrint('A4')}
                                disabled={printing}
                            >
                                {printing ? <span className="spinner" /> : <Printer size={16} />}
                                QZ (A4)
                            </button>
                        </div>
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
                        <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة بون صغير (80mm)
                    </button>
                )}

                {/* A4 Print option */}
                <button
                    className="btn btn-secondary btn-sm"
                    style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', fontSize: '0.8rem' }}
                    onClick={handleA4Print}
                >
                    <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة ورقة كبيرة (A4)
                </button>
            </div>

            {/* ── Printer picker modal ── */}
            <PrinterPickerModal
                showPrinterPicker={showPrinterPicker}
                setShowPrinterPicker={setShowPrinterPicker}
                printers={printers}
                selectedPrinter={selectedPrinter}
                handlePrinterSelect={handlePrinterSelect}
            />
        </div>
    )
}
