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
const PRINT_CSS = `
* { box-sizing: border-box; }
body {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.2;
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
    padding: 2mm;
    text-align: right;
    display: inline-block;
}
.invoice-header {
    text-align: center;
    margin-bottom: 2mm;
    padding-bottom: 2mm;
    border-bottom: 1.5pt solid #000;
}
.invoice-header h2 {
    font-size: 5mm;
    margin: 0.5mm 0;
    font-weight: 900;
    color: #000;
}
.invoice-title {
    font-weight: 900;
    font-size: 3.5mm;
    margin: 1mm 0 0;
    text-align: center;
}
.invoice-details {
    margin: 1.5mm 0;
    padding-bottom: 1mm;
}
.info-row {
    display: flex;
    justify-content: space-between;
    margin: 0.8mm 0;
    font-size: 3mm;
}
.info-row .lbl { font-weight: 900; white-space: nowrap; }
.info-row .val { text-align: left; }
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.5mm 0;
}
.table th, .table td {
    padding: 0.8mm 1mm;
    font-size: 2.8mm;
    border: 1pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
}
.table th { font-weight: 900; font-size: 2.8mm; }
.table .name { text-align: right; max-width: 25mm; word-break: break-word; }
.total-section { margin-top: 1mm; }
.total-row {
    display: flex;
    justify-content: space-between;
    margin: 0.8mm 0;
    font-size: 3mm;
    font-weight: 700;
    color: #000;
}
.total-row.grand {
    font-size: 4mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1mm 0;
    margin-top: 0.5mm;
}
.invoice-footer {
    text-align: center;
    margin-top: 2mm;
    font-size: 3mm;
    font-weight: 700;
    color: #000;
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
    .invoice-container { max-width: 80mm; width: 100%; margin: 0 auto; padding: 2mm; display: inline-block; text-align: right; }
    .no-print { display: none !important; }
}
`

// ── HTML builder ────────────────────────────────────────────────────────────
export function buildReceiptHTML(invoice, change = 0, settings = {}) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'
    const taxEnabled = settings.taxEnabled !== false
    const taxRate    = settings.taxRate    ?? 15

    const isCash    = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change
    const payLabel  = PAYMENT_LABELS[invoice.payment_method] ?? invoice.payment_method

    // ── Items rows — no currency symbol in table ──
    const itemRows = (invoice.items ?? []).map((item, i) => `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${item.product_name ?? item.name ?? ''}</td>
            <td>${fn(item.quantity)}</td>
            <td>${fd2(item.price)}</td>
            <td>${fd2(parseFloat(item.price) * parseFloat(item.quantity))}</td>
        </tr>`).join('')

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
        <div class="total-row grand"><span>متبقي آجلاً</span><span>${fc(amountDue)}</span></div>` : ''

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #${fn(invoice.id)}</title>
    <style>${PRINT_CSS}</style>
</head>
<body>
<div class="invoice-container">

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
                <th>الكمية</th>
                <th>السعر</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    <!-- Totals -->
    <div class="total-section">
        <div class="total-row"><span>المجموع الجزئي</span><span>${fc(invoice.subtotal)}</span></div>
        ${discountRow}${taxRow}
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total)}</span></div>
        ${cashRows}
        ${creditRows}
    </div>

    <!-- Footer -->
    <div class="invoice-footer">
        <p>شكراً لزيارتكم — نتمنى لكم تجربة ممتعة</p>
    </div>

</div>
</body>
</html>`
}

/**
 * Open a new browser print window and print.
 * Works correctly from inside modals because the content is in a separate window.
 */
export function browserPrint(invoice, change, settings) {
    const html    = buildReceiptHTML(invoice, change, settings)
    const win     = window.open('', '_blank', 'width=420,height=700,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}


// ── Purchase Invoice printing ─────────────────────────────────────────────
export function buildPurchaseReceiptHTML(invoice, settings = {}) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'

    const itemRows = (invoice.items ?? []).map((item, i) => `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${item.product_name ?? item.name ?? ''}</td>
            <td>${fn(item.quantity)}</td>
            <td>${fd2(item.cost)}</td>
            <td>${fd2(parseFloat(item.cost) * parseFloat(item.quantity))}</td>
        </tr>`).join('')

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة مشتريات #${fn(invoice.id)}</title>
    <style>${PRINT_CSS}</style>
</head>
<body>
<div class="invoice-container">
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
                <th>الكمية</th>
                <th>التكلفة</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    <div class="total-section">
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total)}</span></div>
        <div class="total-row"><span>عدد الأصناف</span><span>${fn(invoice.items_count)}</span></div>
    </div>
</div>
</body>
</html>`
}

export function browserPrintPurchase(invoice, settings) {
    const html    = buildPurchaseReceiptHTML(invoice, settings)
    const win     = window.open('', '_blank', 'width=420,height=700,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}
