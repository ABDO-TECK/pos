// @ts-nocheck
/**
 * receiptBuilder.js
 * Builds a complete, print-ready HTML invoice string.
 * Used by both browser-print (new window) and QZ Tray.
 * Layout and CSS are taken directly from qz_tray/print-invoice.css.
 */

const PAYMENT_LABELS = {
    cash:          'نقدي',
    card:          'بطاقة ائتمان',
    vodafone_cash: 'فودافون كاش',
    instapay:      'انستاباي',
    other_wallet:  'محفظة إلكترونية',
    credit:        'آجل',
}

const AR = 'ar-EG-u-nu-latn'

function fc(n) {
    return new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(n ?? 0)
}
function fn(n) {
    return new Intl.NumberFormat(AR).format(n ?? 0)
}
// number with 2 decimal places, no currency symbol (for table cells)
function fd2(n) {
    return new Intl.NumberFormat(AR, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0)
}
function fp(n) {
    return `${new Intl.NumberFormat(AR).format(n ?? 0)}%`
}
function fd(d) {
    if (!d) return ''
    return new Intl.DateTimeFormat(AR, {
        year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date(d))
}
function ft(d) {
    if (!d) return ''
    return new Intl.DateTimeFormat(AR, {
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(d))
}

// ── CSS ──────────────────────────────────────────────────────────────────────
export const PRINT_CSS = `
* { box-sizing: border-box; }
body {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.3;
    margin: 0; padding: 0;
    direction: rtl;
    unicode-bidi: embed;
    color: #000;
    background: #fff;
    width: 100%;
    margin: 0 auto;
    text-align: center;
}
.invoice-container {
    max-width: 80mm;
    width: 100%;
    margin: 0 auto;
    padding: 1.5mm;
    text-align: right;
    display: inline-block;
}
.invoice-header {
    text-align: center;
    margin-bottom: 3mm;
    padding-bottom: 2mm;
    border-bottom: 1.5pt solid #000;
}
.invoice-header h2 {
    font-size: 4.8mm;
    margin: 1mm 0;
    font-weight: 900;
    color: #000;
}
.invoice-title {
    font-weight: 900;
    font-size: 3.2mm;
    margin: 1.5mm 0 0;
    text-align: center;
}
.invoice-details {
    margin: 2mm 0;
    padding-bottom: 1mm;
}
.info-row {
    display: flex;
    justify-content: space-between;
    margin: 1mm 0;
    font-size: 2.8mm;
    line-height: 1.3;
}
.info-row .lbl { font-weight: 900; white-space: nowrap; }
.info-row .val { text-align: left; }
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 2mm 0;
}
.table th, .table td {
    padding: 1.2mm 1mm;
    font-size: 2.8mm;
    border: 1pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
    line-height: 1.3;
}
.table th { font-weight: 900; font-size: 2.8mm; }
.table .name { text-align: right; max-width: 25mm; word-break: break-word; }
.total-section { margin-top: 1.5mm; }
.total-row {
    display: flex;
    justify-content: space-between;
    margin: 1.2mm 0;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.3;
}
.total-row.grand {
    font-size: 3.8mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1.2mm 0;
    margin-top: 1.5mm;
    margin-bottom: 1mm;
}
.invoice-footer {
    text-align: center;
    margin-top: 3.5mm;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.4;
}
.invoice-footer p { margin: 0.5mm 0; }
.no-print { display: none !important; }
@media print {
    @page { size: 80mm auto; margin: 0; }
    body {
        width: 100%;
        text-align: center;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .invoice-container { max-width: 80mm; width: 100%; margin: 0 auto; padding: 1.5mm; display: inline-block; text-align: right; }
    .no-print { display: none !important; }
}
`

export const SCOPED_PRINT_CSS = `
.thermal-preview * { box-sizing: border-box; }
.thermal-preview {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.3;
    direction: rtl;
    unicode-bidi: embed;
    color: #000;
    background: #fff;
    width: 100%;
    text-align: center;
}
.thermal-preview .invoice-container {
    max-width: 80mm;
    width: 100%;
    margin: 0 auto;
    padding: 1.5mm;
    text-align: right;
    display: inline-block;
}
.thermal-preview .invoice-header {
    text-align: center;
    margin-bottom: 3mm;
    padding-bottom: 2mm;
    border-bottom: 1.5pt solid #000;
}
.thermal-preview .invoice-header h2 {
    font-size: 4.8mm;
    margin: 1mm 0;
    font-weight: 900;
    color: #000;
}
.thermal-preview .invoice-title {
    font-weight: 900;
    font-size: 3.2mm;
    margin: 1.5mm 0 0;
    text-align: center;
}
.thermal-preview .invoice-details {
    margin: 2mm 0;
    padding-bottom: 1mm;
}
.thermal-preview .info-row {
    display: flex;
    justify-content: space-between;
    margin: 1mm 0;
    font-size: 2.8mm;
    line-height: 1.3;
}
.thermal-preview .info-row .lbl { font-weight: 900; white-space: nowrap; }
.thermal-preview .info-row .val { text-align: left; }
.thermal-preview .table {
    width: 100%;
    border-collapse: collapse;
    margin: 2mm 0;
}
.thermal-preview .table th, .thermal-preview .table td {
    padding: 1.2mm 1mm;
    font-size: 2.8mm;
    border: 1pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
    line-height: 1.3;
}
.thermal-preview .table th { font-weight: 900; font-size: 2.8mm; }
.thermal-preview .table .name { text-align: right; max-width: 25mm; word-break: break-word; }
.thermal-preview .total-section { margin-top: 1.5mm; }
.thermal-preview .total-row {
    display: flex;
    justify-content: space-between;
    margin: 1.2mm 0;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.3;
}
.thermal-preview .total-row.grand {
    font-size: 3.8mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1.2mm 0;
    margin-top: 1.5mm;
    margin-bottom: 1mm;
}
.thermal-preview .invoice-footer {
    text-align: center;
    margin-top: 3.5mm;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.4;
}
.thermal-preview .invoice-footer p { margin: 0.5mm 0; }
`

function getA4OverrideCss(paperSize: string): string {
    if (paperSize !== 'A4') return ''
    return `
    @media print { @page { size: A4 portrait; margin: 10mm; } }
    .invoice-container { max-width: 190mm !important; font-size: 14px; padding: 10mm; }
    .invoice-header h2 { font-size: 8mm !important; }
    .invoice-title { font-size: 5mm !important; }
    .info-row { font-size: 4.5mm !important; margin: 2mm 0 !important; }
    .table th, .table td { font-size: 4mm !important; padding: 2mm !important; }
    .table .name { max-width: none !important; }
    .total-row { font-size: 4.5mm !important; margin: 1.5mm 0 !important; }
    .total-row.grand { font-size: 6mm !important; padding: 2mm 0 !important; }
    .invoice-footer { font-size: 4.5mm !important; margin-top: 5mm !important; }
    `
}

// ── HTML builder ────────────────────────────────────────────────────────────
interface ReceiptSettings {
  storeName?: string;
  storeLogo?: string | null;
  taxEnabled?: boolean;
  taxRate?: number;
}

export function buildReceiptInnerHTML(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change = 0, 
    settings: ReceiptSettings = {}, 
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'
    const storeLogo  = settings.storeLogo  ?? null
    const taxEnabled = settings.taxEnabled !== false
    const taxRate    = settings.taxRate    ?? 15

    const isCash    = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change
    const payLabel  = PAYMENT_LABELS[invoice.payment_method] ?? invoice.payment_method

    const showQty = !options.hideQuantities
    const showPrice = !options.hidePrices

    // ── Items rows — no currency symbol in table ──
    const itemRows = (invoice.items ?? []).map((item, i) => {
        const qty = parseFloat(item.quantity)
        const unitType = item.unit_type ?? (parseInt(item.sell_by_weight) === 1 ? 'weight' : 'piece')
        const isByWeight = unitType === 'weight'
        const isByLiter = unitType === 'liter'
        const qtyStr = isByWeight ? `${qty.toFixed(3)} كجم` : (isByLiter ? `${qty.toFixed(2)} لتر` : fn(item.quantity))
        const nameStr = (item.product_name ?? item.name ?? '') + (item.size_name ? ` (${item.size_name})` : '')
        return `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${nameStr}</td>
            ${showQty ? `<td>${qtyStr}</td>` : ''}
            ${showPrice ? `<td>${fd2(item.price)}</td><td>${fd2(parseFloat(item.price) * qty)}</td>` : ''}
        </tr>`
    }).join('')

    // ── Totals ──
    const discountRow = parseFloat(invoice.discount) > 0
        ? `<div class="total-row discount"><span>الخصم</span><span>- ${fc(invoice.discount)}</span></div>` : ''

    const taxRow = taxEnabled && parseFloat(invoice.tax) > 0
        ? `<div class="total-row"><span>ضريبة القيمة المضافة (${fp(taxRate)})</span><span>${fc(invoice.tax)}</span></div>` : ''

    const cashRows = isCash ? `
        <div class="total-row"><span>المبلغ المدفوع</span><span>${fc(invoice.amount_paid)}</span></div>
        <div class="total-row"><span>المبلغ المسترد</span><span>${fc(changeAmt)}</span></div>` : ''

    const isCredit = invoice.payment_method === 'credit'
    const amountDue = parseFloat(invoice.amount_due ?? (invoice.total - invoice.amount_paid))
    const creditRows = isCredit ? `
        ${parseFloat(invoice.amount_paid) > 0 ? `<div class="total-row"><span>عربون مدفوع</span><span>${fc(invoice.amount_paid)}</span></div>` : ''}
        <div class="total-row grand" style="border-top: none; margin-top: 0;"><span>متبقي آجلاً</span><span>${fc(amountDue)}</span></div>` : ''

    const logoHtml = storeLogo ? `
    <div style="text-align: center; margin-bottom: 2.5mm;">
        <img src="${storeLogo}" style="max-height: 20mm; max-width: 40mm; object-fit: contain;" />
    </div>` : '';

    return `
<div class="invoice-container">
    ${logoHtml}

    <!-- Header -->
    <div class="invoice-header">
        <h2> ${storeName}</h2>
        <div class="invoice-title">فاتورة رقم: #${fn(invoice.id)}</div>
    </div>

    <!-- Details -->
    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${fd(invoice.created_at)}</span>
            <span><span class="lbl">طريقة الدفع:</span> ${payLabel}</span>
        </div>
        <div class="info-row">
            <span><span class="lbl">الوقت:</span> ${ft(invoice.created_at)}</span>
            <span><span class="lbl">الكاشير:</span> ${invoice.cashier_name ?? ''}</span>
        </div>
    </div>

    <!-- Items -->
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>المنتج</th>
                ${showQty ? '<th>الكمية</th>' : ''}
                ${showPrice ? '<th>السعر</th><th>الإجمالي</th>' : ''}
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    <!-- Totals -->
    ${showPrice ? `
    <div class="total-section">
        <div class="total-row"><span>المجموع الجزئي</span><span>${fc(invoice.subtotal)}</span></div>
        ${discountRow}${taxRow}
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total)}</span></div>
        ${cashRows}
        ${creditRows}
    </div>` : ''}

    <!-- Footer -->
    <div class="invoice-footer">
        <p>شكراً لزيارتكم — نتمنى لكم تجربة ممتعة</p>
    </div>
</div>`
}

export function buildReceiptHTML(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change = 0, 
    settings: ReceiptSettings = {}, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const inner = buildReceiptInnerHTML(invoice, change, settings, options)
    const a4Css = getA4OverrideCss(paperSize)

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #${fn(invoice.id)}</title>
    <style>${PRINT_CSS} ${a4Css}</style>
</head>
<body>
${inner}
</body>
</html>`
}

/**
 * Open a new browser print window and print.
 * Works correctly from inside modals because the content is in a separate window.
 */
export function browserPrint(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change: number, 
    settings: ReceiptSettings, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const html    = buildReceiptHTML(invoice, change, settings, paperSize, options)
    const win     = window.open('', '_blank', 'width=800,height=800,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}


// ── Purchase Invoice printing ─────────────────────────────────────────────
export function buildPurchaseReceiptInnerHTML(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings = {}, 
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'
    const storeLogo  = settings.storeLogo  ?? null

    const showQty = !options.hideQuantities
    const showPrice = !options.hidePrices

    const itemRows = (invoice.items ?? []).map((item, i) => {
        const qty = parseFloat(item.quantity)
        const unitType = item.unit_type ?? (parseInt(item.sell_by_weight) === 1 ? 'weight' : 'piece')
        const isByWeight = unitType === 'weight'
        const isByLiter = unitType === 'liter'
        const qtyStr = isByWeight ? `${qty.toFixed(3)} كجم` : (isByLiter ? `${qty.toFixed(2)} لتر` : fn(item.quantity))
        const nameStr = (item.product_name ?? item.name ?? '') + (item.size_name ? ` (${item.size_name})` : '')
        return `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${nameStr}</td>
            ${showQty ? `<td>${qtyStr}</td>` : ''}
            ${showPrice ? `<td>${fd2(item.cost)}</td><td>${fd2(parseFloat(item.cost) * qty)}</td>` : ''}
        </tr>`
    }).join('')

    const logoHtml = storeLogo ? `
    <div style="text-align: center; margin-bottom: 2.5mm;">
        <img src="${storeLogo}" style="max-height: 20mm; max-width: 40mm; object-fit: contain;" />
    </div>` : '';

    return `
<div class="invoice-container">
    ${logoHtml}

    <div class="invoice-header">
        <h2>${storeName}</h2>
        <div class="invoice-title">فاتورة مشتريات: #${fn(invoice.id)}</div>
    </div>

    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${fd(invoice.created_at)}</span>
            <span><span class="lbl">الوقت:</span> ${ft(invoice.created_at)}</span>
        </div>
        <div class="info-row" style="justify-content: center; margin-top: 2mm">
            <span><span class="lbl">المورد:</span> ${invoice.supplier_name ?? ''}</span>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>المنتج</th>
                ${showQty ? '<th>الكمية</th>' : ''}
                ${showPrice ? '<th>التكلفة</th><th>الإجمالي</th>' : ''}
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    ${showPrice ? `
    <div class="total-section">
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total)}</span></div>
        <div class="total-row"><span>عدد الأصناف</span><span>${fn(invoice.items_count)}</span></div>
    </div>` : ''}
</div>`
}

export function buildPurchaseReceiptHTML(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings = {}, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const inner = buildPurchaseReceiptInnerHTML(invoice, settings, options)
    const a4Css = getA4OverrideCss(paperSize)

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة مشتريات #${fn(invoice.id)}</title>
    <style>${PRINT_CSS} ${a4Css}</style>
</head>
<body>
${inner}
</body>
</html>`
}

export function browserPrintPurchase(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const html    = buildPurchaseReceiptHTML(invoice, settings, paperSize, options)
    const win     = window.open('', '_blank', 'width=800,height=800,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}
