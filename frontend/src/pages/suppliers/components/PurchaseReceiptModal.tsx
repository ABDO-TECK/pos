// @ts-nocheck
import { useState, useEffect } from 'react'
import { Printer, X, EyeOff, Eye } from 'lucide-react'
import toast from 'react-hot-toast'
import { formatCurrency, formatNumber, formatDate } from '../../../utils/formatters'
import { browserPrintPurchase, buildPurchaseReceiptHTML } from '../../../utils/receiptBuilder'
import useSettingsStore from '../../../store/settingsStore'
import {
  isQZAvailable,
  isQZConnected,
  connectQZ,
  listPrinters,
  getSavedPrinter,
  savePrinter,
  printHTML,
} from '../../../utils/qzPrint'
import QZStatusBar from '../../../components/pos/receipt/QZStatusBar'
import PrinterPickerModal from '../../../components/pos/receipt/PrinterPickerModal'

interface PurchaseReceiptModalProps {
  invoice: any
  onClose: () => void
}

export default function PurchaseReceiptModal({ invoice, onClose }: PurchaseReceiptModalProps) {
  const { storeName } = useSettingsStore()
  const settings = { storeName }

  // Print options state
  const [hidePrices, setHidePrices] = useState(false)
  const [hideQuantities, setHideQuantities] = useState(false)

  // QZ Tray printer states
  const [qzStatus, setQzStatus] = useState('idle')
  const [printers, setPrinters] = useState<any[]>([])
  const [selectedPrinter, setSelectedPrinter] = useState(getSavedPrinter() ?? '')
  const [showPrinterPicker, setShowPrinterPicker] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [remoteError, setRemoteError] = useState<any>(null)

  useEffect(() => {
    if (!isQZAvailable()) { setQzStatus('unavailable'); return }
    if (isQZConnected()) { setQzStatus('ready'); loadPrinters(); return }
    setQzStatus('connecting')
    connectQZ()
      .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
      .catch((err: any) => {
        setQzStatus('error')
        if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
      })
  }, [])

  const retryQZ = () => {
    setQzStatus('connecting')
    setRemoteError(null)
    connectQZ()
      .then(() => { setQzStatus('ready'); loadPrinters() })
      .catch((err: any) => {
        setQzStatus('error')
        if (err?.isRemoteQZ) setRemoteError({ message: err.message, certUrl: err.certUrl })
      })
  }

  const loadPrinters = async () => {
    try {
      const list = await listPrinters()
      setPrinters(Array.isArray(list) ? list : [list] as any)
      const saved = getSavedPrinter()
      if (saved && list.includes(saved)) setSelectedPrinter(saved)
      else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
    } catch (err) { /* ignore */ }
  }

  if (!invoice) return null

  const printOptions = { hidePrices, hideQuantities }

  // ── QZ Tray print ──
  const handleQZPrint = async (paperSize = '80mm') => {
    if (!selectedPrinter) { setShowPrinterPicker(true); return }
    setPrinting(true)
    try {
      const html = buildPurchaseReceiptHTML(invoice, settings, paperSize, printOptions)
      await printHTML(html, selectedPrinter)
      toast.success(`تمت الطباعة بنجاح (${paperSize})`)
    } catch (err: any) {
      toast.error('فشل الطباعة: ' + (err.message ?? ''))
    } finally {
      setPrinting(false)
    }
  }

  // ── Browser prints ──
  const handleBrowserPrint = () => browserPrintPurchase(invoice, settings, '80mm', printOptions)
  const handleA4Print = () => browserPrintPurchase(invoice, settings, 'A4', printOptions)

  const handlePrinterSelect = (name: string) => {
    savePrinter(name)
    setSelectedPrinter(name)
    setShowPrinterPicker(false)
    toast.success(`تم اختيار الطابعة: ${name}`)
  }

  // Keyboard shortcut: Enter to close
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter' && !showPrinterPicker) {
        e.preventDefault()
        onClose()
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [onClose, showPrinterPicker])

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: '480px', width: '95%' }}>
        {/* Header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h3 style={{ fontWeight: 700 }}>فاتورة مشتريات #{formatNumber(invoice.id)}</h3>
          <button className="btn btn-ghost btn-icon" onClick={onClose}><X size={18} /></button>
        </div>

        {/* Print Toggles */}
        <div style={{
          display: 'flex',
          gap: '1rem',
          background: 'var(--bg)',
          borderRadius: 'var(--radius)',
          padding: '0.75rem',
          marginBottom: '1rem',
          border: '1px solid var(--border)'
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHidePrices(!hidePrices)}>
            {hidePrices ? <EyeOff size={16} className="text-danger" /> : <Eye size={16} className="text-primary" />}
            <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الأسعار والتكاليف</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', cursor: 'pointer' }} onClick={() => setHideQuantities(!hideQuantities)}>
            {hideQuantities ? <EyeOff size={16} className="text-danger" /> : <Eye size={16} className="text-primary" />}
            <span style={{ fontSize: '0.85rem', fontWeight: 600 }}>إخفاء الكميات</span>
          </div>
        </div>

        {/* Thermal Preview */}
        <div style={{
          background: '#e5e7eb', padding: '1rem',
          borderRadius: 'var(--radius)',
          maxHeight: '40vh', overflowY: 'auto',
          display: 'flex', justifyContent: 'center',
          marginBottom: '1rem'
        }}>
          <div style={{
            background: '#ffffff',
            width: '80mm',
            maxWidth: '100%',
            padding: '4mm',
            boxShadow: '0 4px 6px -1px rgba(0,0,0,0.1)',
            fontFamily: "Arial, Tahoma, 'DejaVu Sans', sans-serif",
            fontSize: '3mm', lineHeight: 1.2, color: '#000',
            direction: 'rtl', textShadow: 'none',
          }}>
            {/* Store details */}
            <div style={{ textAlign: 'center', marginBottom: '2mm', paddingBottom: '2mm', borderBottom: '1.5pt solid #000' }}>
              <h2 style={{ fontSize: '4.5mm', margin: '0.5mm 0', fontWeight: 900, color: '#000' }}>
                {storeName || 'سوبر ماركت'}
              </h2>
              <div style={{ fontWeight: 900, fontSize: '3.5mm', marginTop: '1mm' }}>
                فاتورة مشتريات رقم: #{formatNumber(invoice.id)}
              </div>
            </div>

            {/* Details */}
            <div style={{ margin: '1.5mm 0', paddingBottom: '1mm', fontSize: '2.8mm' }}>
              <div><strong>التاريخ:</strong> {formatDate(invoice.created_at)}</div>
              <div><strong>المورد:</strong> {invoice.supplier_name ?? ''}</div>
            </div>

            {/* Items Table */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: '2mm', fontSize: '2.8mm' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid #000', borderTop: '1px solid #000', fontWeight: 900 }}>
                  <th style={{ textAlign: 'right', padding: '1mm 0' }}>#</th>
                  <th style={{ textAlign: 'right', padding: '1mm 0' }}>الصنف</th>
                  {!hideQuantities && <th style={{ textAlign: 'center', padding: '1mm 0' }}>الكمية</th>}
                  {!hidePrices && <th style={{ textAlign: 'left', padding: '1mm 0' }}>التكلفة</th>}
                </tr>
              </thead>
              <tbody>
                {(invoice.items ?? []).map((item, idx) => (
                  <tr key={idx} style={{ borderBottom: '1px dashed #ccc' }}>
                    <td style={{ padding: '1mm 0' }}>{idx + 1}</td>
                    <td style={{ padding: '1mm 0' }}>
                      {item.product_name ?? item.name}
                      {item.size_name && ` (${item.size_name})`}
                    </td>
                    {!hideQuantities && <td style={{ textAlign: 'center', padding: '1mm 0' }}>{formatNumber(item.quantity)}</td>}
                    {!hidePrices && <td style={{ textAlign: 'left', padding: '1mm 0' }}>{formatCurrency(item.cost)}</td>}
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Summary */}
            {!hidePrices && (
              <div style={{ borderTop: '1.5pt solid #000', marginTop: '2mm', paddingTop: '1.5mm', fontSize: '3mm' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 900 }}>
                  <span>الإجمالي:</span>
                  <span>{formatCurrency(invoice.total)}</span>
                </div>
              </div>
            )}

            {/* Delivery Info Preview in Receipt */}
            {(invoice.driver_name || invoice.vehicle_number || invoice.delivery_date || invoice.delivery_notes) && (
              <div style={{ borderTop: '1px dashed #000', marginTop: '2mm', paddingTop: '2mm', fontSize: '2.8mm' }}>
                <div style={{ textAlign: 'center', fontWeight: 'bold', marginBottom: '1mm' }}>🚚 بيانات التسليم</div>
                {invoice.driver_name && <div><strong>السائق:</strong> {invoice.driver_name}</div>}
                {invoice.vehicle_number && <div><strong>السيارة:</strong> {invoice.vehicle_number}</div>}
                {invoice.delivery_date && <div><strong>التاريخ:</strong> {formatDate(invoice.delivery_date)}</div>}
                {invoice.delivery_notes && <div style={{ whiteSpace: 'pre-wrap' }}><strong>ملاحظات:</strong> {invoice.delivery_notes}</div>}
              </div>
            )}
          </div>
        </div>

        {/* QZ Status */}
        <QZStatusBar
          status={qzStatus}
          printer={selectedPrinter}
          onPickPrinter={() => setShowPrinterPicker(true)}
          remoteError={remoteError}
          onRetry={retryQZ}
        />

        {/* Actions */}
        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem' }}>
          {qzStatus === 'ready' ? (
            <div style={{ display: 'flex', gap: '0.5rem', flex: 2 }}>
              <button
                className="btn btn-primary"
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={() => handleQZPrint('80mm')}
                disabled={printing}
              >
                <Printer size={16} /> QZ (80mm)
              </button>
              <button
                className="btn btn-secondary"
                style={{ flex: 1, justifyContent: 'center' }}
                onClick={() => handleQZPrint('A4')}
                disabled={printing}
              >
                <Printer size={16} /> QZ (A4)
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
          <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={onClose}>
            إغلاق <kbd style={{ fontSize: '0.7rem', background: 'var(--bg)', border: '1px solid var(--border)', padding: '0.1rem 0.3rem', borderRadius: '3px', marginRight: '0.4rem' }}>Enter</kbd>
          </button>
        </div>

        {/* Secondary options */}
        {qzStatus === 'ready' && (
          <button
            className="btn btn-ghost btn-sm"
            style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', color: 'var(--text-muted)', fontSize: '0.8rem' }}
            onClick={handleBrowserPrint}
          >
            <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة بون صغير (80mm)
          </button>
        )}
        <button
          className="btn btn-secondary btn-sm"
          style={{ width: '100%', justifyContent: 'center', marginTop: '0.4rem', fontSize: '0.8rem' }}
          onClick={handleA4Print}
        >
          <Printer size={13} style={{ marginLeft: '4px' }} /> طباعة ورقة كبيرة (A4)
        </button>
      </div>

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
