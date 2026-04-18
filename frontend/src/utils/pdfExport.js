/**
 * pdfExport.js
 * Generates Excel-styled PDF exports via the browser's native print dialog.
 * Uses a new window with print-optimized CSS for clean PDF output.
 * 
 * IMPORTANT: This uses window.open() + window.print() (Chrome's native renderer)
 * which handles Arabic/English mixed text (BiDi) perfectly.
 * DO NOT use html2canvas/html2pdf.js — they break Arabic text rendering.
 */

const AR = 'ar-EG-u-nu-latn'

// Unicode BiDi Isolate characters — forces correct text ordering in ANY renderer
const LRI = '\u2066' // LEFT-TO-RIGHT ISOLATE — start an LTR-isolated segment
const PDI = '\u2069' // POP DIRECTIONAL ISOLATE — end the isolated segment

function fc(n) {
  return LRI + new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(n ?? 0) + PDI
}
function fn(n) {
  return LRI + new Intl.NumberFormat(AR).format(n ?? 0) + PDI
}
function fdate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  if (Number.isNaN(dt.getTime())) return '—'
  return LRI + new Intl.DateTimeFormat('en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(dt) + PDI
}
function fshort(d) {
  if (!d) return '—'
  const dt = new Date(d)
  if (Number.isNaN(dt.getTime())) return '—'
  return LRI + new Intl.DateTimeFormat('en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(dt) + PDI
}

/**
 * Opens a new browser window with the HTML content and triggers print.
 * Chrome's native print → "Save as PDF" handles RTL/BiDi text perfectly.
 */
function openPrintWindow(html) {
  const win = window.open('', '_blank', 'width=1100,height=750,scrollbars=yes')
  if (!win) { alert('يرجى السماح بالنوافذ المنبثقة (Popups) في المتصفح'); return }
  win.document.open()
  win.document.write(html)
  win.document.close()
  setTimeout(() => { win.focus(); win.print() }, 600)
}

// ── Shared PDF CSS (Excel-like look) ─────────────────────────────────────────
const PDF_CSS = `
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Tahoma, sans-serif;
  font-size: 11px;
  color: #1e293b;
  background: #fff;
  direction: rtl;
  unicode-bidi: embed;
  padding: 12mm;
  text-align: center;
  width: 100%;
}
.ledger-container {
  max-width: 1000px;
  width: 100%;
  margin: 0 auto;
  text-align: right;
  display: inline-block;
}
@page {
  size: A4 portrait;
  margin: 10mm;
}
@media print {
  body { padding: 0; }
  .no-print { display: none !important; }
}

/* ── Header ── */
.report-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 3px solid #1e40af;
}
.report-header .title-block h1 {
  font-size: 20px;
  font-weight: 800;
  color: #1e3a5f;
  margin-bottom: 4px;
}
.report-header .title-block .subtitle {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
}
.report-header .meta-block {
  text-align: left;
  font-size: 11px;
  color: #475569;
  line-height: 1.6;
}
.report-header .meta-block strong { color: #1e3a5f; }

/* ── Summary cards ── */
.summary-row {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.summary-card {
  flex: 1;
  min-width: 120px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  padding: 8px 12px;
  text-align: center;
}
.summary-card .label {
  font-size: 9px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  margin-bottom: 2px;
}
.summary-card .value {
  font-size: 14px;
  font-weight: 800;
  color: #1e40af;
}
.summary-card.danger .value { color: #dc2626; }
.summary-card.danger { background: #fef2f2; border-color: #fecaca; }
.summary-card.success .value { color: #16a34a; }
.summary-card.success { background: #f0fdf4; border-color: #bbf7d0; }

/* ── Excel-style table ── */
table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 10px;
  font-size: 10.5px;
}
thead th {
  background: #1e40af;
  color: #ffffff;
  font-weight: 700;
  padding: 8px 10px;
  text-align: right;
  border: 1px solid #1d4ed8;
  white-space: nowrap;
  font-size: 10.5px;
}
thead th.num { text-align: left; }
tbody td {
  padding: 6px 10px;
  border: 1px solid #cbd5e1;
  vertical-align: middle;
}
tbody tr:nth-child(even) { background: #eff6ff; }
tbody tr:nth-child(odd) { background: #ffffff; }
tbody tr:hover { background: #dbeafe; }

/* colored cells */
.debit  { color: #dc2626; font-weight: 700; text-align: left; }
.credit { color: #16a34a; font-weight: 700; text-align: left; }
.num    { text-align: left; }
.muted  { color: #94a3b8; }
.bold   { font-weight: 700; }

/* footer totals */
tfoot td {
  background: #1e3a5f;
  color: #ffffff;
  font-weight: 800;
  padding: 8px 10px;
  border: 1px solid #1e3a5f;
  font-size: 11px;
}
tfoot .debit  { color: #fca5a5; }
tfoot .credit { color: #86efac; }
tfoot .balance { color: #fbbf24; }

/* ── Footer ── */
.report-footer {
  margin-top: 14px;
  padding-top: 8px;
  border-top: 2px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  font-size: 9px;
  color: #94a3b8;
}

/* ── Print button ── */
.print-btn {
  position: fixed;
  top: 15px;
  left: 15px;
  padding: 10px 24px;
  background: #1e40af;
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  z-index: 9999;
  font-family: inherit;
}
.print-btn:hover { background: #1d4ed8; }
`

// ══════════════════════════════════════════════════════════════════════════════
// Customer Account Statement (كشف حساب العميل)
// ══════════════════════════════════════════════════════════════════════════════
export function buildCustomerLedgerHTML(ledgerData, storeName = 'سوبر ماركت') {
  if (!ledgerData?.entries?.length) return null

  const { customer, entries, balance } = ledgerData
  const totalDebit  = entries.reduce((s, r) => s + (r.debit  || 0), 0)
  const totalCredit = entries.reduce((s, r) => s + (r.credit || 0), 0)
  const now = new Date()

  const rows = entries.map((row, i) => {
    const isDebit  = row.debit  > 0
    const isCredit = row.credit > 0
    const balClass = row.balance > 0 ? 'debit' : row.balance < 0 ? 'credit' : ''
    return `
      <tr>
        <td class="muted num">${fn(i + 1)}</td>
        <td style="white-space:nowrap">${fdate(row.date)}</td>
        <td>${row.description || '—'}${row.type === 'initial' ? ' <small style="color:#3b82f6">(رصيد مبدئي)</small>' : ''}</td>
        <td class="${isDebit ? 'debit' : 'muted num'}"><span dir="ltr">${isDebit ? fc(row.debit) : '—'}</span></td>
        <td class="${isCredit ? 'credit' : 'muted num'}"><span dir="ltr">${isCredit ? fc(row.credit) : '—'}</span></td>
        <td class="${balClass} num bold"><span dir="ltr">${fc(Math.abs(row.balance))}</span> ${row.balance > 0 ? 'مدين' : row.balance < 0 ? 'دائن' : ''}</td>
      </tr>`
  }).join('')

  return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>كشف حساب — ${customer.name}</title>
  <style>${PDF_CSS}</style>
</head>
<body>
<div class="ledger-container">
<div class="report-header">
  <div class="title-block">
    <h1>كشف حساب العميل</h1>
    <div class="subtitle">${storeName}</div>
  </div>
  <div class="meta-block">
    <div><strong>العميل:</strong> ${customer.name}</div>
    ${customer.phone ? `<div><strong>الهاتف:</strong> ${customer.phone}</div>` : ''}
    ${customer.address ? `<div><strong>العنوان:</strong> ${customer.address}</div>` : ''}
    <div><strong>تاريخ الطباعة:</strong> ${fdate(now)}</div>
  </div>
</div>

<div class="summary-row">
  <div class="summary-card">
    <div class="label">عدد الحركات</div>
    <div class="value"><span dir="ltr">${fn(entries.length)}</span></div>
  </div>
  <div class="summary-card danger">
    <div class="label">إجمالي المدين</div>
    <div class="value"><span dir="ltr">${fc(totalDebit)}</span></div>
  </div>
  <div class="summary-card success">
    <div class="label">إجمالي الدائن</div>
    <div class="value"><span dir="ltr">${fc(totalCredit)}</span></div>
  </div>
  <div class="summary-card ${balance > 0 ? 'danger' : 'success'}">
    <div class="label">الرصيد الحالي</div>
    <div class="value"><span dir="ltr">${fc(Math.abs(balance))}</span> ${balance > 0 ? '(مدين)' : '(دائن)'}</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th class="num">#</th>
      <th>التاريخ</th>
      <th>البيان</th>
      <th class="num">مدين</th>
      <th class="num">دائن</th>
      <th class="num">الرصيد</th>
    </tr>
  </thead>
  <tbody>${rows}</tbody>
  <tfoot>
    <tr>
      <td colspan="3">الإجمالي</td>
      <td class="debit num"><span dir="ltr">${fc(totalDebit)}</span></td>
      <td class="credit num"><span dir="ltr">${fc(totalCredit)}</span></td>
      <td class="balance num"><span dir="ltr">${fc(Math.abs(balance))}</span> ${balance > 0 ? 'مدين' : 'دائن'}</td>
    </tr>
  </tfoot>
</table>

<div class="report-footer">
  <span>تم إنشاء هذا التقرير بواسطة نظام نقاط البيع — ${storeName}</span>
  <span>${fdate(now)}</span>
</div>
</div>
</body>
</html>`
}

export function exportCustomerLedgerPDF(ledgerData, storeName = 'سوبر ماركت') {
  const html = buildCustomerLedgerHTML(ledgerData, storeName)
  if (!html) {
    alert('لا توجد حركات لعرضها')
    return
  }
  openPrintWindow(html)
}

// ══════════════════════════════════════════════════════════════════════════════
// Supplier Account Statement (كشف حساب المورد)
// ══════════════════════════════════════════════════════════════════════════════
export function buildSupplierLedgerHTML(ledgerData, storeName = 'سوبر ماركت') {
  if (!ledgerData?.entries?.length) return null

  const { supplier, entries, balance } = ledgerData
  const totalDebit  = entries.reduce((s, r) => s + (r.debit  || 0), 0)
  const totalCredit = entries.reduce((s, r) => s + (r.credit || 0), 0)
  const now = new Date()

  const rows = entries.map((row, i) => {
    const isDebit  = row.debit  > 0
    const isCredit = row.credit > 0
    const balClass = row.balance > 0 ? 'debit' : row.balance < 0 ? 'credit' : ''
    return `
      <tr>
        <td class="muted num">${fn(i + 1)}</td>
        <td style="white-space:nowrap">${fdate(row.date)}</td>
        <td>${row.description || '—'}${row.type === 'initial' ? ' <small style="color:#3b82f6">(رصيد مبدئي)</small>' : ''}</td>
        <td class="${isDebit ? 'debit' : 'muted num'}"><span dir="ltr">${isDebit ? fc(row.debit) : '—'}</span></td>
        <td class="${isCredit ? 'credit' : 'muted num'}"><span dir="ltr">${isCredit ? fc(row.credit) : '—'}</span></td>
        <td class="${balClass} num bold"><span dir="ltr">${fc(Math.abs(row.balance))}</span> ${row.balance > 0 ? 'مدين' : row.balance < 0 ? 'دائن' : ''}</td>
      </tr>`
  }).join('')

  return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>كشف حساب — ${supplier.name}</title>
  <style>${PDF_CSS}</style>
</head>
<body>
<div class="ledger-container">
<div class="report-header">
  <div class="title-block">
    <h1>كشف حساب المورد</h1>
    <div class="subtitle">${storeName}</div>
  </div>
  <div class="meta-block">
    <div><strong>المورد:</strong> ${supplier.name}</div>
    ${supplier.phone ? `<div><strong>الهاتف:</strong> ${supplier.phone}</div>` : ''}
    ${supplier.address ? `<div><strong>العنوان:</strong> ${supplier.address}</div>` : ''}
    ${supplier.email ? `<div><strong>البريد:</strong> ${supplier.email}</div>` : ''}
    <div><strong>تاريخ الطباعة:</strong> ${fdate(now)}</div>
  </div>
</div>

<div class="summary-row">
  <div class="summary-card">
    <div class="label">عدد الحركات</div>
    <div class="value"><span dir="ltr">${fn(entries.length)}</span></div>
  </div>
  <div class="summary-card danger">
    <div class="label">إجمالي المدين</div>
    <div class="value"><span dir="ltr">${fc(totalDebit)}</span></div>
  </div>
  <div class="summary-card success">
    <div class="label">إجمالي الدائن</div>
    <div class="value"><span dir="ltr">${fc(totalCredit)}</span></div>
  </div>
  <div class="summary-card ${balance > 0 ? 'danger' : 'success'}">
    <div class="label">الرصيد الحالي</div>
    <div class="value"><span dir="ltr">${fc(Math.abs(balance))}</span> ${balance > 0 ? '(مستحق)' : '(مُسدَّد)'}</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th class="num">#</th>
      <th>التاريخ</th>
      <th>البيان</th>
      <th class="num">مدين</th>
      <th class="num">دائن</th>
      <th class="num">الرصيد</th>
    </tr>
  </thead>
  <tbody>${rows}</tbody>
  <tfoot>
    <tr>
      <td colspan="3">الإجمالي</td>
      <td class="debit num"><span dir="ltr">${fc(totalDebit)}</span></td>
      <td class="credit num"><span dir="ltr">${fc(totalCredit)}</span></td>
      <td class="balance num"><span dir="ltr">${fc(Math.abs(balance))}</span> ${balance > 0 ? 'مستحق' : 'مُسدَّد'}</td>
    </tr>
  </tfoot>
</table>

<div class="report-footer">
  <span>تم إنشاء هذا التقرير بواسطة نظام نقاط البيع — ${storeName}</span>
  <span>${fdate(now)}</span>
</div>
</div>
</body>
</html>`
}

export function exportSupplierLedgerPDF(ledgerData, storeName = 'سوبر ماركت') {
  const html = buildSupplierLedgerHTML(ledgerData, storeName)
  if (!html) {
    alert('لا توجد حركات لعرضها')
    return
  }
  openPrintWindow(html)
}
