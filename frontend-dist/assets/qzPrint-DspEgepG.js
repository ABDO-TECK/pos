var e={cash:`نقدي`,card:`بطاقة ائتمان`,vodafone_cash:`فودافون كاش`,instapay:`انستاباي`,other_wallet:`محفظة إلكترونية`,credit:`آجل`},t=`ar-EG-u-nu-latn`;function n(e){return new Intl.NumberFormat(t,{style:`currency`,currency:`EGP`}).format(e??0)}function r(e){return new Intl.NumberFormat(t).format(e??0)}function i(e){return new Intl.NumberFormat(t,{minimumFractionDigits:2,maximumFractionDigits:2}).format(e??0)}function a(e){return`${new Intl.NumberFormat(t).format(e??0)}%`}function o(e){return e?new Intl.DateTimeFormat(t,{year:`numeric`,month:`2-digit`,day:`2-digit`}).format(new Date(e)):``}function s(e){return e?new Intl.DateTimeFormat(t,{hour:`2-digit`,minute:`2-digit`}).format(new Date(e)):``}var c=`
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
`;function l(t,l=0,u={}){let d=u.storeName??`سوبر ماركت`,f=u.taxEnabled!==!1,p=u.taxRate??15,m=t.payment_method===`cash`,h=t.change_due??l,g=e[t.payment_method]??t.payment_method,_=(t.items??[]).map((e,t)=>`
        <tr>
            <td>${r(t+1)}</td>
            <td class="name">${e.product_name??e.name??``}</td>
            <td>${r(e.quantity)}</td>
            <td>${i(e.price)}</td>
            <td>${i(parseFloat(e.price)*parseFloat(e.quantity))}</td>
        </tr>`).join(``),v=parseFloat(t.discount)>0?`<div class="total-row discount"><span>الخصم</span><span>- ${n(t.discount)}</span></div>`:``,y=f&&parseFloat(t.tax)>0?`<div class="total-row"><span>ضريبة القيمة المضافة (${a(p)})</span><span>${n(t.tax)}</span></div>`:``,b=m?`
        <div class="total-row"><span>المبلغ المدفوع</span><span>${n(t.amount_paid)}</span></div>
        <div class="total-row"><span>المبلغ المسترد</span><span>${n(h)}</span></div>`:``,x=t.payment_method===`credit`,S=parseFloat(t.amount_due??t.total-t.amount_paid),C=x?`
        ${parseFloat(t.amount_paid)>0?`<div class="total-row"><span>عربون مدفوع</span><span>${n(t.amount_paid)}</span></div>`:``}
        <div class="total-row grand"><span>متبقي آجلاً</span><span>${n(S)}</span></div>`:``;return`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #${r(t.id)}</title>
    <style>${c}</style>
</head>
<body>
<div class="invoice-container">

    <!-- Header -->
    <div class="invoice-header">
        <h2> ${d}</h2>
        <div class="invoice-title">فاتورة رقم: #${r(t.id)}</div>
    </div>

    <!-- Details -->
    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${o(t.created_at)}</span>
            <span><span class="lbl">طريقة الدفع:</span> ${g}</span>
        </div>
        <div class="info-row">
            <span><span class="lbl">الوقت:</span> ${s(t.created_at)}</span>
            <span><span class="lbl">الكاشير:</span> ${t.cashier_name??``}</span>
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
        <tbody>${_}</tbody>
    </table>

    <!-- Totals -->
    <div class="total-section">
        <div class="total-row"><span>المجموع الجزئي</span><span>${n(t.subtotal)}</span></div>
        ${v}${y}
        <div class="total-row grand"><span>الإجمالي</span><span>${n(t.total)}</span></div>
        ${b}
        ${C}
    </div>

    <!-- Footer -->
    <div class="invoice-footer">
        <p>شكراً لزيارتكم — نتمنى لكم تجربة ممتعة</p>
    </div>

</div>
</body>
</html>`}function u(e,t,n){let r=l(e,t,n),i=window.open(``,`_blank`,`width=420,height=700,scrollbars=yes`);if(!i){alert(`يرجى السماح بالنوافذ المنبثقة لهذا الموقع`);return}i.document.open(),i.document.write(r),i.document.close(),i.addEventListener(`load`,()=>{i.focus(),i.print()})}function d(e,t={}){let a=t.storeName??`سوبر ماركت`,l=(e.items??[]).map((e,t)=>`
        <tr>
            <td>${r(t+1)}</td>
            <td class="name">${e.product_name??e.name??``}</td>
            <td>${r(e.quantity)}</td>
            <td>${i(e.cost)}</td>
            <td>${i(parseFloat(e.cost)*parseFloat(e.quantity))}</td>
        </tr>`).join(``);return`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة مشتريات #${r(e.id)}</title>
    <style>${c}</style>
</head>
<body>
<div class="invoice-container">
    <div class="invoice-header">
        <h2>${a}</h2>
        <div class="invoice-title">فاتورة مشتريات: #${r(e.id)}</div>
    </div>

    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${o(e.created_at)}</span>
            <span><span class="lbl">الوقت:</span> ${s(e.created_at)}</span>
        </div>
        <div class="info-row" style="justify-content: center; margin-top: 2mm">
            <span><span class="lbl">المورد:</span> ${e.supplier_name??``}</span>
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
        <tbody>${l}</tbody>
    </table>

    <div class="total-section">
        <div class="total-row grand"><span>الإجمالي</span><span>${n(e.total)}</span></div>
        <div class="total-row"><span>عدد الأصناف</span><span>${r(e.items_count)}</span></div>
    </div>
</div>
</body>
</html>`}function f(e,t){let n=d(e,t),r=window.open(``,`_blank`,`width=420,height=700,scrollbars=yes`);if(!r){alert(`يرجى السماح بالنوافذ المنبثقة لهذا الموقع`);return}r.document.open(),r.document.write(n),r.document.close(),r.addEventListener(`load`,()=>{r.focus(),r.print()})}function p(){if(typeof window>`u`||window.qz===void 0)throw Error(`مكتبة QZ Tray غير محملة. تأكد من تشغيل QZ Tray على جهازك.`);return window.qz}function m(){let e=window.QZ_CONFIG??{host:`localhost`,signUrl:`/pos/backend/sign-message.php`,certUrl:`/digital-certificate.txt`},t=typeof window<`u`?window.location.hostname:`localhost`,n=t!==`localhost`&&t!==`127.0.0.1`;return{...e,host:n?t:e.host||`localhost`,_isRemote:n}}var h=!1;function g(){if(h)return;let e=p(),t=m();e.security.setCertificatePromise((e,n)=>{fetch(t.certUrl,{cache:`no-store`,headers:{"Content-Type":`text/plain`}}).then(t=>{t.ok?t.text().then(e):(console.warn(`[QZ] digital-certificate.txt not found — using unsigned mode`),e())}).catch(()=>{console.warn(`[QZ] Could not fetch certificate — using unsigned mode`),e()})}),e.security.setSignatureAlgorithm(`SHA512`),e.security.setSignaturePromise(e=>(n,r)=>{fetch(`${t.signUrl}?request=${encodeURIComponent(e)}`,{cache:`no-store`,credentials:`include`,headers:{"Content-Type":`text/plain`}}).then(e=>{e.ok?e.text().then(e=>{n(e||void 0)}):(console.warn(`[QZ] sign-message endpoint returned`,e.status,`— using unsigned mode`),n())}).catch(()=>{n()})}),h=!0}var _=null;function v(e){let t=`https://${e}:8181`;return{message:`لا يمكن الاتصال بـ QZ Tray عبر الشبكة.\n\nلتفعيل الطباعة من هذا الجهاز:\n1. افتح الرابط التالي في المتصفح:\n   ${t}\n2. اضغط \"متابعة\" أو \"Advanced → Proceed\" لقبول الشهادة\n3. ارجع لهذه الصفحة وأعد المحاولة`,certUrl:t}}async function y(){let e=p(),t=m();if(e.websocket.isActive())return!0;if(_)return _;g();let n={host:t.host,retries:t.retries??2,delay:t.delay??0};return t._isRemote&&console.info(`[QZ] Remote mode → connecting to ${t.host} via secure WebSocket (wss://)`),_=e.websocket.connect(n).then(()=>(_=null,!0)).catch(e=>{if(_=null,t._isRemote){let e=v(t.host);console.error(`[QZ] ${e.message}`);let n=Error(e.message);throw n.certUrl=e.certUrl,n.isRemoteQZ=!0,n}throw e}),_}function b(){return typeof window<`u`&&window.qz!==void 0}function x(){try{return p().websocket.isActive()}catch{return!1}}var S=`pos_qz_printer`;function C(){try{return localStorage.getItem(S)||null}catch{return null}}function w(e){try{localStorage.setItem(S,e)}catch{}}async function T(){return await y(),p().printers.find()}async function E(e,t=null){await y();let n=p(),r=t??C();if(!r)throw Error(`لم يتم اختيار طابعة`);let i=n.configs.create(r,{orientation:`portrait`,margins:0});await n.print(i,[{type:`html`,format:`plain`,data:e}])}async function D(e,t=0,n={},r=null){await E(l(e,t,n),r)}export{T as a,w as c,d,l as f,x as i,u as l,C as n,E as o,b as r,D as s,y as t,f as u};