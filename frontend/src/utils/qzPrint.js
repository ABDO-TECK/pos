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

// ── Invoice HTML builder ───────────────────────────────────────────────────

/**
 * Wrap receipt DOM content in a complete print-ready HTML document.
 * @param {string} bodyContent  - innerHTML of the invoice container
 * @param {string} storeName
 */
export function buildInvoiceHTML(bodyContent, storeName = 'سوبر ماركت') {
    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>فاتورة — ${storeName}</title>
  <style>
    @page { size: 80mm auto; margin: 0; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
      font-size: 10px; font-weight: 700; line-height: 1.4;
      color: #000; background: #fff;
      width: 80mm; direction: rtl;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .invoice-container { width: 100%; padding: 3mm; }
    .inv-header { text-align: center; margin-bottom: 3mm; padding-bottom: 3mm; border-bottom: 1px dashed #000; }
    .inv-header h2 { font-size: 5mm; font-weight: 900; margin-bottom: 1mm; }
    .inv-header p  { font-size: 3.5mm; }
    .inv-divider   { border-top: 1px dashed #000; margin: 2mm 0; }
    .inv-row       { display: flex; justify-content: space-between; margin: 1mm 0; font-size: 3.5mm; }
    .inv-row-bold  { display: flex; justify-content: space-between; margin: 1.5mm 0; font-size: 4.5mm; font-weight: 900; }
    .inv-items     { margin: 2mm 0; }
    .inv-item      { display: flex; justify-content: space-between; margin: 1mm 0; font-size: 3.5mm; }
    .inv-item-name { flex: 1; text-align: right; }
    .inv-item-qty  { width: 10mm; text-align: center; }
    .inv-item-price{ width: 18mm; text-align: left; }
    .inv-footer    { text-align: center; margin-top: 3mm; font-size: 3.5mm; }
  </style>
</head>
<body>
  ${bodyContent}
</body>
</html>`
}

// ── High-level helper: print an invoice object ─────────────────────────────

const METHOD_LABELS = {
    cash: 'نقدي', card: 'بطاقة',
    vodafone_cash: 'فودافون كاش', instapay: 'انستاباي', other_wallet: 'محفظة أخرى',
}

const AR = 'ar-EG-u-nu-latn'

function fmt(num) {
    return new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(num ?? 0)
}

function fmtNum(num) {
    return new Intl.NumberFormat(AR).format(num ?? 0)
}

function fmtPct(num) {
    return `${new Intl.NumberFormat(AR).format(num ?? 0)}%`
}

function fmtDate(d) {
    if (!d) return ''
    return new Intl.DateTimeFormat(AR, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(d))
}

/**
 * Build and print a POS invoice.
 * @param {object} invoice  - Invoice object from the API
 * @param {number} change   - Change due (computed client-side)
 * @param {object} settings - { storeName, taxEnabled, taxRate }
 * @param {string} [printerName]
 */
export async function printInvoice(invoice, change = 0, settings = {}, printerName = null) {
    const storeName = settings.storeName ?? 'سوبر ماركت'
    const taxEnabled = settings.taxEnabled !== false
    const taxRate    = settings.taxRate ?? 15

    const itemsHTML = (invoice.items ?? []).map(item => `
        <div class="inv-item">
            <span class="inv-item-name">${item.product_name ?? item.name}</span>
            <span class="inv-item-qty">× ${fmtNum(item.quantity)}</span>
            <span class="inv-item-price">${fmt(item.price * item.quantity)}</span>
        </div>`).join('')

    const discountHTML = parseFloat(invoice.discount) > 0
        ? `<div class="inv-row"><span>الخصم</span><span>- ${fmt(invoice.discount)}</span></div>` : ''

    const taxHTML = taxEnabled && parseFloat(invoice.tax) > 0
        ? `<div class="inv-row"><span>ضريبة ${fmtPct(taxRate)}</span><span>${fmt(invoice.tax)}</span></div>` : ''

    const payMethod = METHOD_LABELS[invoice.payment_method] ?? invoice.payment_method
    const isCash    = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change

    const body = `
<div class="invoice-container">
  <div class="inv-header">
    <h2>${storeName}</h2>
    <p>فاتورة #${fmtNum(invoice.id)}</p>
    <p>${fmtDate(invoice.created_at)}</p>
    <p>الكاشير: ${invoice.cashier_name ?? ''}</p>
  </div>

  <div class="inv-items">${itemsHTML}</div>

  <div class="inv-divider"></div>
  <div class="inv-row"><span>المجموع الجزئي</span><span>${fmt(invoice.subtotal)}</span></div>
  ${discountHTML}${taxHTML}
  <div class="inv-divider"></div>
  <div class="inv-row-bold"><span>الإجمالي</span><span>${fmt(invoice.total)}</span></div>

  <div class="inv-row"><span>طريقة الدفع</span><span>${payMethod}</span></div>
  ${isCash ? `<div class="inv-row"><span>المدفوع</span><span>${fmt(invoice.amount_paid)}</span></div>
  <div class="inv-row"><span>الباقي</span><span>${fmt(changeAmt)}</span></div>` : ''}

  <div class="inv-divider"></div>
  <div class="inv-footer">شكراً لزيارتكم 🛒</div>
</div>`

    const html = buildInvoiceHTML(body, storeName)
    await printHTML(html, printerName)
}
