/**
 * qzPrint.js — QZ Tray integration for the POS React app
 *
 * How it works:
 *  1. qz-tray.js is loaded as a plain <script> in index.html → puts `qz` on window
 *  2. qz-config.js is loaded next → puts `QZ_CONFIG` on window
 *  3. This module is imported by React components; it reads window.qz at call-time
 *     (not at import-time), so there's no timing issue.
 *
 * Digital signature:
 *  • Tries to load /digital-certificate.txt from the public folder.
 *  • If the file is absent, falls back to "unsigned / anonymous" mode — fine for
 *    development and internal networks.
 *  • sign-message.php lives in backend/ and is accessible at
 *    /pos/backend/sign-message.php (Apache serves it directly because it is a
 *    real file — the .htaccess rewrite condition is !-f).
 */

import { buildReceiptHTML } from './receiptBuilder'

// ── Helpers ────────────────────────────────────────────────────────────────

function getQZ() {
    if (typeof window === 'undefined' || typeof window.qz === 'undefined') {
        throw new Error('مكتبة QZ Tray غير محملة. تأكد من تشغيل QZ Tray على جهازك.')
    }
    return window.qz
}

function getCfg() {
    return window.QZ_CONFIG ?? { host: 'localhost', signUrl: '/pos/backend/sign-message.php', certUrl: '/digital-certificate.txt' }
}

// ── Security setup (runs once) ─────────────────────────────────────────────

let _securitySet = false

function ensureSecurity() {
    if (_securitySet) return
    const qz  = getQZ()
    const cfg = getCfg()

    // Certificate: try to load file; fall back to unsigned (anonymous) mode
    qz.security.setCertificatePromise((resolve, reject) => {
        fetch(cfg.certUrl, { cache: 'no-store', headers: { 'Content-Type': 'text/plain' } })
            .then(r => {
                if (r.ok) {
                    r.text().then(resolve)
                } else {
                    console.warn('[QZ] digital-certificate.txt not found — using unsigned mode')
                    resolve() // anonymous / unsigned
                }
            })
            .catch(() => {
                console.warn('[QZ] Could not fetch certificate — using unsigned mode')
                resolve() // anonymous / unsigned
            })
    })

    qz.security.setSignatureAlgorithm('SHA512')

    qz.security.setSignaturePromise(toSign => (resolve, reject) => {
        fetch(`${cfg.signUrl}?request=${encodeURIComponent(toSign)}`, {
            cache: 'no-store',
            headers: { 'Content-Type': 'text/plain' },
        })
            .then(r => {
                if (r.ok) {
                    r.text().then(t => {
                        // If server returned an empty body (anonymous mode), resolve without arg
                        resolve(t || undefined)
                    })
                } else {
                    // Non-OK (404, 500, etc.) → fall back to unsigned mode
                    console.warn('[QZ] sign-message endpoint returned', r.status, '— using unsigned mode')
                    resolve()
                }
            })
            .catch(() => {
                // Network error (CORS, offline, etc.) → unsigned fallback
                resolve()
            })
    })

    _securitySet = true
}

// ── Connection management ──────────────────────────────────────────────────

let _connecting = null  // in-flight promise guard

export async function connectQZ() {
    const qz  = getQZ()
    const cfg = getCfg()

    if (qz.websocket.isActive()) return true

    if (_connecting) return _connecting

    ensureSecurity()

    _connecting = qz.websocket
        .connect({ host: cfg.host, retries: cfg.retries ?? 2, delay: cfg.delay ?? 0 })
        .then(() => { _connecting = null; return true })
        .catch(err => { _connecting = null; throw err })

    return _connecting
}

export function disconnectQZ() {
    try {
        const qz = getQZ()
        if (qz.websocket.isActive()) qz.websocket.disconnect()
    } catch { /* ignore */ }
}

export function isQZAvailable() {
    return typeof window !== 'undefined' && typeof window.qz !== 'undefined'
}

export function isQZConnected() {
    try { return getQZ().websocket.isActive() } catch { return false }
}

// ── Printer management ─────────────────────────────────────────────────────

const STORAGE_KEY = 'pos_qz_printer'

export function getSavedPrinter() {
    try { return localStorage.getItem(STORAGE_KEY) || null } catch { return null }
}

export function savePrinter(name) {
    try { localStorage.setItem(STORAGE_KEY, name) } catch { /* ignore */ }
}

export async function listPrinters() {
    await connectQZ()
    return getQZ().printers.find()
}

// ── Core print function ────────────────────────────────────────────────────

/**
 * Print raw HTML to the chosen printer via QZ Tray.
 * @param {string} html  - Complete HTML string to print
 * @param {string} [printerName] - Override printer (otherwise uses saved or prompts)
 */
export async function printHTML(html, printerName = null) {
    await connectQZ()
    const qz = getQZ()

    const printer = printerName ?? getSavedPrinter()
    if (!printer) throw new Error('لم يتم اختيار طابعة')

    const config = qz.configs.create(printer)
    await qz.print(config, [{ type: 'html', format: 'plain', data: html }])
}

// ── High-level helper: print an invoice object ─────────────────────────────

/**
 * Build and print a POS invoice via QZ Tray.
 * Uses buildReceiptHTML so QZ Tray output is identical to browser-print output.
 *
 * @param {object} invoice      - Invoice object from the API
 * @param {number} change       - Change due (computed client-side)
 * @param {object} settings     - { storeName, taxEnabled, taxRate }
 * @param {string} [printerName]
 */
export async function printInvoice(invoice, change = 0, settings = {}, printerName = null) {
    const html = buildReceiptHTML(invoice, change, settings)
    await printHTML(html, printerName)
}
