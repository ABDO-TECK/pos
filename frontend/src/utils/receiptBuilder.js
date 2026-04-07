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
}

const AR = 'ar-EG-u-nu-latn'

function fc(n) {
    return new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(n ?? 0)
}
function fn(n) {
    return new Intl.NumberFormat(AR).format(n ?? 0)
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

// ── CSS (from print-invoice.css) ────────────────────────────────────────────
const PRINT_CSS = `
* { box-sizing: border-box; }
body {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 10px;
    font-weight: 900;
    line-height: 1.3;
    margin: 0; padding: 0;
    direction: rtl;
    unicode-bidi: embed;
    color: #000;
    background: #fff;
    width: 100%;
}
.invoice-container {
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
    background: #fff;
    padding: 5mm;
}
.invoice-header {
    text-align: center;
    margin-bottom: 3mm;
    padding-bottom: 3mm;
    border-bottom: 1.5pt solid #000;
}
.invoice-header h2 {
    font-size: 7mm;
    margin: 1mm 0;
    font-weight: 900;
    color: #000;
}
.invoice-header p {
    font-size: 4mm;
    margin: 0.5mm 0;
    font-weight: 700;
    color: #000;
}
.invoice-title {
    font-weight: 900;
    font-size: 4.5mm;
    margin: 2mm 0 1mm;
    text-align: center;
    padding: 0;
    display: inline-block;
}
.invoice-details {
    margin: 2mm 0;
    padding-bottom: 2mm;
}
.invoice-details .row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2mm;
}
.info-row {
    display: flex;
    gap: 2mm;
    margin: 1mm 0;
    font-size: 3.5mm;
    align-items: flex-start;
}
.info-row span:first-child {
    font-weight: 800;
    white-space: nowrap;
}
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 2mm 0;
}
.table th, .table td {
    padding: 1.5mm;
    font-size: 3.5mm;
    border: 1.5pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
}
.table th { font-weight: 900; }
.table td:nth-child(2) { text-align: right; }
.total-section {
    margin-top: 1mm;
    padding-top: 1mm;
}
.total-row {
    display: flex;
    justify-content: space-between;
    margin: 1mm 0;
    font-size: 3.8mm;
    font-weight: 700;
    color: #000;
}
.total-row.grand {
    font-size: 5mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1.5mm 0;
    margin-top: 1mm;
}
.total-row.discount { color: #000; }
.invoice-footer {
    text-align: center;
    margin-top: 3mm;
    padding-top: 0;
    font-size: 3.5mm;
    font-weight: 700;
    color: #000;
}
.invoice-footer p { margin: 1mm 0; padding: 0; }
.no-print { display: none !important; }
@media print {
    @page { size: 80mm auto; margin: 0; }
    * { margin: 0 !important; padding: 0 !important; }
    body {
        font-size: 10px !important;
        font-weight: 900 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        padding-bottom: 10mm !important;
    }
    .invoice-container { padding: 2mm !important; }
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

    // ── Items rows ──
    const itemRows = (invoice.items ?? []).map((item, i) => `
        <tr>
            <td>${fn(i + 1)}</td>
            <td style="text-align:right">${item.product_name ?? item.name ?? ''}</td>
            <td>${fn(item.quantity)}</td>
            <td>${fc(item.price)}</td>
            <td>${fc(parseFloat(item.price) * parseFloat(item.quantity))}</td>
        </tr>`).join('')

    // ── Totals ──
    const discountRow = parseFloat(invoice.discount) > 0
        ? `<div class="total-row discount"><span>الخصم</span><span>- ${fc(invoice.discount)}</span></div>` : ''

    const taxRow = taxEnabled && parseFloat(invoice.tax) > 0
        ? `<div class="total-row"><span>ضريبة القيمة المضافة (${fp(taxRate)})</span><span>${fc(invoice.tax)}</span></div>` : ''

    const cashRows = isCash ? `
        <div class="total-row"><span>المبلغ المدفوع</span><span>${fc(invoice.amount_paid)}</span></div>
        <div class="total-row"><span>المبلغ المسترد</span><span>${fc(changeAmt)}</span></div>` : ''

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
        <h2>🛒 ${storeName}</h2>
        <div class="invoice-title">فاتورة رقم: #${fn(invoice.id)}</div>
    </div>

    <!-- Details -->
    <div class="invoice-details">
        <div class="row">
            <div>
                <div class="info-row"><span>التاريخ:</span><span>${fd(invoice.created_at)}</span></div>
                <div class="info-row"><span>الوقت:</span><span>${ft(invoice.created_at)}</span></div>
                <div class="info-row"><span>الكاشير:</span><span>${invoice.cashier_name ?? ''}</span></div>
            </div>
            <div>
                <div class="info-row"><span>طريقة الدفع:</span><span>${payLabel}</span></div>
            </div>
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
    const html = buildReceiptHTML(invoice, change, settings)
    const win  = window.open('', '_blank', 'width=420,height=700,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print(); })
    // Fallback if load event doesn't fire
    setTimeout(() => { try { win.focus(); win.print() } catch { /* ignore */ } }, 800)
}
