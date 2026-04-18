/**
 * useQZPrinter — Reusable React hook for QZ Tray printer management.
 * Provides connection status, printer selection, and a print helper.
 */
import { useState, useEffect, useCallback } from 'react'
import {
    isQZAvailable,
    isQZConnected,
    connectQZ,
    listPrinters,
    getSavedPrinter,
    savePrinter,
    printHTML,
    printPDFBase64,
} from '../utils/qzPrint'

export default function useQZPrinter() {
    const [qzStatus,          setQzStatus]          = useState('idle')
    const [printers,          setPrinters]           = useState([])
    const [selectedPrinter,   setSelectedPrinter]    = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker, setShowPrinterPicker]  = useState(false)
    const [printing,          setPrinting]           = useState(false)
    const [remoteError,       setRemoteError]        = useState(null) // { message, certUrl }

    const loadPrinters = useCallback(async () => {
        try {
            const list = await listPrinters()
            setPrinters(list)
            const saved = getSavedPrinter()
            if (saved && list.includes(saved)) setSelectedPrinter(saved)
            else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
        } catch { /* ignore */ }
    }, [])

    useEffect(() => {
        if (!isQZAvailable()) { setQzStatus('unavailable'); return }
        if (isQZConnected())  { setQzStatus('ready'); loadPrinters(); return }
        setQzStatus('connecting')
        connectQZ()
            .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
            .catch((err) => {
                setQzStatus('error')
                if (err?.isQZError || err?.certUrl) {
                    setRemoteError({ message: err.message, certUrl: err.certUrl })
                }
            })
    }, [loadPrinters])

    const handlePrinterSelect = useCallback((name) => {
        savePrinter(name)
        setSelectedPrinter(name)
        setShowPrinterPicker(false)
    }, [])

    /** محاولة إعادة الاتصال (بعد قبول الشهادة) */
    const retryConnect = useCallback(() => {
        setQzStatus('connecting')
        setRemoteError(null)
        connectQZ()
            .then(() => { setQzStatus('ready'); loadPrinters() })
            .catch((err) => {
                setQzStatus('error')
                if (err?.isQZError || err?.certUrl) {
                    setRemoteError({ message: err.message, certUrl: err.certUrl })
                }
            })
    }, [loadPrinters])

    /** Print raw HTML via QZ Tray. Returns { ok, error }. */
    const qzPrint = useCallback(async (html) => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return { ok: false, error: 'لم يتم اختيار طابعة' } }
        setPrinting(true)
        try {
            await printHTML(html, selectedPrinter)
            return { ok: true }
        } catch (err) {
            return { ok: false, error: err.message ?? 'فشل الطباعة' }
        } finally {
            setPrinting(false)
        }
    }, [selectedPrinter])

    /** Print PDF (base64) via QZ Tray. Returns { ok, error }. */
    const qzPrintPDF = useCallback(async (base64) => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return { ok: false, error: 'لم يتم اختيار طابعة' } }
        setPrinting(true)
        try {
            await printPDFBase64(base64, selectedPrinter)
            return { ok: true }
        } catch (err) {
            return { ok: false, error: err.message ?? 'فشل الطباعة' }
        } finally {
            setPrinting(false)
        }
    }, [selectedPrinter])

    return {
        qzStatus,
        qzReady: qzStatus === 'ready',
        printers,
        selectedPrinter,
        showPrinterPicker,
        setShowPrinterPicker,
        handlePrinterSelect,
        printing,
        qzPrint,
        qzPrintPDF,
        remoteError,
        retryConnect,
    }
}
