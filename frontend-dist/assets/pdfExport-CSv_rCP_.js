var e=`ar-EG-u-nu-latn`;function t(t){return new Intl.NumberFormat(e,{style:`currency`,currency:`EGP`}).format(t??0)}function n(t){return new Intl.NumberFormat(e).format(t??0)}function r(e){if(!e)return`—`;let t=new Date(e);return Number.isNaN(t.getTime())?`—`:new Intl.DateTimeFormat(`en-GB`,{year:`numeric`,month:`short`,day:`numeric`,hour:`2-digit`,minute:`2-digit`,hour12:!1}).format(t)}var i=`
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
  font-size: 11px;
  color: #1e293b;
  background: #fff;
  direction: rtl;
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
`;function a(e,a=`سوبر ماركت`){if(!e?.entries?.length)return null;let{customer:o,entries:s,balance:c}=e,l=s.reduce((e,t)=>e+(t.debit||0),0),u=s.reduce((e,t)=>e+(t.credit||0),0),d=new Date,f=s.map((e,i)=>{let a=e.debit>0,o=e.credit>0,s=e.balance>0?`debit`:e.balance<0?`credit`:``;return`
      <tr>
        <td class="muted num">${n(i+1)}</td>
        <td style="white-space:nowrap">${r(e.date)}</td>
        <td>${e.description||`—`}${e.type===`initial`?` <small style="color:#3b82f6">(رصيد مبدئي)</small>`:``}</td>
        <td class="${a?`debit`:`muted num`}">${a?t(e.debit):`—`}</td>
        <td class="${o?`credit`:`muted num`}">${o?t(e.credit):`—`}</td>
        <td class="${s} num bold">${t(Math.abs(e.balance))} ${e.balance>0?`مدين`:e.balance<0?`دائن`:``}</td>
      </tr>`}).join(``);return`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>كشف حساب — ${o.name}</title>
  <style>${i}</style>
</head>
<body>
<div class="ledger-container">
<div class="report-header">
  <div class="title-block">
    <h1>كشف حساب العميل</h1>
    <div class="subtitle">${a}</div>
  </div>
  <div class="meta-block">
    <div><strong>العميل:</strong> ${o.name}</div>
    ${o.phone?`<div><strong>الهاتف:</strong> ${o.phone}</div>`:``}
    ${o.address?`<div><strong>العنوان:</strong> ${o.address}</div>`:``}
    <div><strong>تاريخ الطباعة:</strong> ${r(d)}</div>
  </div>
</div>

<div class="summary-row">
  <div class="summary-card">
    <div class="label">عدد الحركات</div>
    <div class="value">${n(s.length)}</div>
  </div>
  <div class="summary-card danger">
    <div class="label">إجمالي المدين</div>
    <div class="value">${t(l)}</div>
  </div>
  <div class="summary-card success">
    <div class="label">إجمالي الدائن</div>
    <div class="value">${t(u)}</div>
  </div>
  <div class="summary-card ${c>0?`danger`:`success`}">
    <div class="label">الرصيد الحالي</div>
    <div class="value">${t(Math.abs(c))} ${c>0?`(مدين)`:`(دائن)`}</div>
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
  <tbody>${f}</tbody>
  <tfoot>
    <tr>
      <td colspan="3">الإجمالي</td>
      <td class="debit num">${t(l)}</td>
      <td class="credit num">${t(u)}</td>
      <td class="balance num">${t(Math.abs(c))} ${c>0?`مدين`:`دائن`}</td>
    </tr>
  </tfoot>
</table>

<div class="report-footer">
  <span>تم إنشاء هذا التقرير بواسطة نظام نقاط البيع — ${a}</span>
  <span>${r(d)}</span>
</div>
</div>
</body>
</html>`}function o(e,t=`سوبر ماركت`){let n=a(e,t);if(!n){alert(`لا توجد حركات لعرضها`);return}let r=window.open(``,`_blank`,`width=1100,height=750,scrollbars=yes`);if(!r){alert(`يرجى السماح بالنوافذ المنبثقة`);return}r.document.open(),r.document.write(n),r.document.close(),setTimeout(()=>{r.focus(),r.print()},500)}function s(e,a=`سوبر ماركت`){if(!e?.entries?.length)return null;let{supplier:o,entries:s,balance:c}=e,l=s.reduce((e,t)=>e+(t.debit||0),0),u=s.reduce((e,t)=>e+(t.credit||0),0),d=new Date,f=s.map((e,i)=>{let a=e.debit>0,o=e.credit>0,s=e.balance>0?`debit`:e.balance<0?`credit`:``;return`
      <tr>
        <td class="muted num">${n(i+1)}</td>
        <td style="white-space:nowrap">${r(e.date)}</td>
        <td>${e.description||`—`}${e.type===`initial`?` <small style="color:#3b82f6">(رصيد مبدئي)</small>`:``}</td>
        <td class="${a?`debit`:`muted num`}">${a?t(e.debit):`—`}</td>
        <td class="${o?`credit`:`muted num`}">${o?t(e.credit):`—`}</td>
        <td class="${s} num bold">${t(Math.abs(e.balance))} ${e.balance>0?`مدين`:e.balance<0?`دائن`:``}</td>
      </tr>`}).join(``);return`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <title>كشف حساب — ${o.name}</title>
  <style>${i}</style>
</head>
<body>
<div class="ledger-container">
<div class="report-header">
  <div class="title-block">
    <h1>كشف حساب المورد</h1>
    <div class="subtitle">${a}</div>
  </div>
  <div class="meta-block">
    <div><strong>المورد:</strong> ${o.name}</div>
    ${o.phone?`<div><strong>الهاتف:</strong> ${o.phone}</div>`:``}
    ${o.address?`<div><strong>العنوان:</strong> ${o.address}</div>`:``}
    ${o.email?`<div><strong>البريد:</strong> ${o.email}</div>`:``}
    <div><strong>تاريخ الطباعة:</strong> ${r(d)}</div>
  </div>
</div>

<div class="summary-row">
  <div class="summary-card">
    <div class="label">عدد الحركات</div>
    <div class="value">${n(s.length)}</div>
  </div>
  <div class="summary-card danger">
    <div class="label">إجمالي المدين</div>
    <div class="value">${t(l)}</div>
  </div>
  <div class="summary-card success">
    <div class="label">إجمالي الدائن</div>
    <div class="value">${t(u)}</div>
  </div>
  <div class="summary-card ${c>0?`danger`:`success`}">
    <div class="label">الرصيد الحالي</div>
    <div class="value">${t(Math.abs(c))} ${c>0?`(مستحق)`:`(مُسدَّد)`}</div>
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
  <tbody>${f}</tbody>
  <tfoot>
    <tr>
      <td colspan="3">الإجمالي</td>
      <td class="debit num">${t(l)}</td>
      <td class="credit num">${t(u)}</td>
      <td class="balance num">${t(Math.abs(c))} ${c>0?`مستحق`:`مُسدَّد`}</td>
    </tr>
  </tfoot>
</table>

<div class="report-footer">
  <span>تم إنشاء هذا التقرير بواسطة نظام نقاط البيع — ${a}</span>
  <span>${r(d)}</span>
</div>
</div>
</body>
</html>`}function c(e,t=`سوبر ماركت`){let n=s(e,t);if(!n){alert(`لا توجد حركات لعرضها`);return}let r=window.open(``,`_blank`,`width=1100,height=750,scrollbars=yes`);if(!r){alert(`يرجى السماح بالنوافذ المنبثقة`);return}r.document.open(),r.document.write(n),r.document.close(),setTimeout(()=>{r.focus(),r.print()},500)}export{c as i,s as n,o as r,a as t};