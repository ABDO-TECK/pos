/**
 * QZ Tray Print Handler for Invoices
 * معالج طباعة QZ Tray للفواتير
 */

// متغيرات عامة
let selectedPrinter = null;
let isQzConnected = false;

// دالة للحصول على حجم اللوجو
function getLogoHeight() {
    return window.invoiceData?.logo_height || '15mm';
}

// دالة الاتصال بـ QZ Tray
function connectToQZ() {
    return new Promise((resolve, reject) => {
        // التحقق من وجود مكتبة QZ Tray
        if (typeof qz === 'undefined') {
            console.error('❌ مكتبة QZ Tray غير محملة');
            resolve(false);
            return;
        }

        if (isQzConnected) {
            resolve(true);
            return;
        }

        // تحديد عنوان QZ Tray (localhost أو IP السيرفر)
        const qzHost = (typeof getQZTrayHost === 'function') ? getQZTrayHost() : 'localhost';
        const connectOptions = { host: qzHost };

        console.log(`🔄 جاري الاتصال بـ QZ Tray على ${qzHost}...`);

        qz.websocket.connect(connectOptions).then(() => {
            console.log(`✅ تم الاتصال بـ QZ Tray على ${qzHost}`);
            isQzConnected = true;
            resolve(true);
        }).catch(err => {
            console.error(`❌ فشل الاتصال بـ QZ Tray على ${qzHost}:`, err);
            isQzConnected = false;
            resolve(false);
        });
    });
}

// دالة الحصول على قائمة الطابعات
async function getPrinters() {
    try {
        if (!isQzConnected) {
            await connectToQZ();
        }
        const printers = await qz.printers.find();
        return printers;
    } catch (error) {
        console.error('خطأ في الحصول على قائمة الطابعات:', error);
        return [];
    }
}

// دالة إنشاء قائمة منسدلة للطابعات
function createPrinterDropdown(printers) {
    // إنشاء عنصر القائمة المنسدلة
    const dropdown = document.createElement('select');
    dropdown.id = 'printer-dropdown';
    dropdown.className = 'form-select';
    dropdown.style.cssText = 'margin: 10px 0; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;';
    
    // إضافة خيار افتراضي
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'اختر الطابعة...';
    dropdown.appendChild(defaultOption);
    
    // إضافة الطابعات للقائمة
    printers.forEach((printer, index) => {
        const option = document.createElement('option');
        option.value = printer;
        option.textContent = printer;
        dropdown.appendChild(option);
    });
    
    return dropdown;
}

// دالة عرض قائمة الطابعات
async function showPrinterSelection() {
    const printers = await getPrinters();
    
    if (printers.length === 0) {
        showError('لم يتم العثور على طابعات متاحة');
        return null;
    }

    return new Promise((resolve) => {
        // إنشاء نافذة حوار مخصصة
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            direction: rtl;
        `;
        
        const dialog = document.createElement('div');
        dialog.style.cssText = `
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            min-width: 300px;
            max-width: 500px;
            text-align: center;
        `;
        
        const title = document.createElement('h3');
        title.textContent = 'اختيار الطابعة';
        title.style.cssText = 'margin-bottom: 15px; color: #333; font-size: 18px;';
        
        const dropdown = createPrinterDropdown(printers);
        
        const buttonContainer = document.createElement('div');
        buttonContainer.style.cssText = 'margin-top: 15px; display: flex; gap: 10px; justify-content: center;';
        
        const confirmBtn = document.createElement('button');
        confirmBtn.textContent = 'تأكيد';
        confirmBtn.className = 'btn btn-primary';
        confirmBtn.style.cssText = 'padding: 8px 20px; margin: 0 5px;';
        
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'إلغاء';
        cancelBtn.className = 'btn btn-secondary';
        cancelBtn.style.cssText = 'padding: 8px 20px; margin: 0 5px;';
        
        // تحميل الطابعة المحفوظة
        const savedPrinter = loadSavedPrinter();
        if (savedPrinter && printers.includes(savedPrinter)) {
            dropdown.value = savedPrinter;
        }
        
        // أحداث الأزرار
        confirmBtn.onclick = () => {
            const selectedPrinter = dropdown.value;
            if (selectedPrinter) {
                savePrinterChoice(selectedPrinter);
                document.body.removeChild(modal);
                resolve(selectedPrinter);
            } else {
                showError('يرجى اختيار طابعة');
            }
        };
        
        cancelBtn.onclick = () => {
            document.body.removeChild(modal);
            resolve(null);
        };
        
        // تجميع العناصر
        buttonContainer.appendChild(confirmBtn);
        buttonContainer.appendChild(cancelBtn);
        
        dialog.appendChild(title);
        dialog.appendChild(dropdown);
        dialog.appendChild(buttonContainer);
        
        modal.appendChild(dialog);
        document.body.appendChild(modal);
        
        // التركيز على القائمة المنسدلة
        dropdown.focus();
    });
}

// دالة حفظ اختيار الطابعة
function savePrinterChoice(printer) {
    // حفظ الطابعة مع معرف الجلسة
    const sessionData = {
        printer: printer,
        timestamp: Date.now(),
        sessionId: getSessionId()
    };
    localStorage.setItem('qz_selected_printer_invoice', JSON.stringify(sessionData));
}

// دالة تحميل اختيار الطابعة المحفوظ
function loadSavedPrinter() {
    try {
        const savedData = localStorage.getItem('qz_selected_printer_invoice');
        if (!savedData) return null;
        
        const data = JSON.parse(savedData);
        const currentSessionId = getSessionId();
        
        // التحقق من صحة الجلسة
        if (data.sessionId === currentSessionId) {
            return data.printer;
        } else {
            // حذف البيانات المحفوظة إذا كانت من جلسة مختلفة
            localStorage.removeItem('qz_selected_printer_invoice');
            return null;
        }
    } catch (error) {
        console.error('خطأ في تحميل الطابعة المحفوظة:', error);
        localStorage.removeItem('qz_selected_printer_invoice');
        return null;
    }
}

// دالة الحصول على معرف الجلسة
function getSessionId() {
    // استخدام معرف ثابت للمستخدم بدلاً من sessionStorage المؤقت
    // هذا يحل مشكلة فقدان الطابعة المحفوظة في PHPDesktop عند تسجيل الخروج
    let userId = localStorage.getItem('cafe_user_id');
    if (!userId) {
        userId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('cafe_user_id', userId);
    }
    return userId;
}

// دالة حذف بيانات الطابعة عند تسجيل الخروج
function clearPrinterData() {
    // لا نحذف بيانات الطابعة عند تسجيل الخروج في PHPDesktop
    // لأن المستخدم يريد الاحتفاظ بالطابعة المحفوظة
    // localStorage.removeItem('qz_selected_printer_invoice');
    // localStorage.removeItem('cafe_user_id');
    console.log('تم الاحتفاظ ببيانات الطابعة المحفوظة');
}

// دالة الطباعة الرئيسية
async function qzPrintInvoice(printer = null) {
    try {
        // محاولة الاتصال بـ QZ Tray
        const connected = await connectToQZ();
        if (!connected) {
            if (typeof qz === 'undefined') {
                showError('مكتبة QZ Tray غير محملة. يرجى إعادة تحميل الصفحة.');
            } else {
                showError('فشل الاتصال بـ QZ Tray. تأكد من تشغيل QZ Tray وإعادة المحاولة.');
            }
            return;
        }

        // الحصول على الطابعة المحفوظة أو اختيار جديدة
        let printerToUse = printer || loadSavedPrinter();
        if (!printerToUse) {
            printerToUse = await showPrinterSelection();
            if (!printerToUse) {
                throw new Error('لم يتم اختيار طابعة');
            }
            savePrinterChoice(printerToUse);
        }

        // الحصول على محتوى الفاتورة
        const invoiceContainer = document.querySelector('.invoice-container');
        if (!invoiceContainer) {
            throw new Error('لم يتم العثور على محتوى الفاتورة');
        }

        // إنشاء نسخة من المحتوى للطباعة
        const printContent = invoiceContainer.cloneNode(true);
        
        // إزالة العناصر غير المرغوب فيها
        const elementsToRemove = printContent.querySelectorAll('.no-print, .print-buttons');
        elementsToRemove.forEach(element => element.remove());

        // تحويل مسارات الصور النسبية إلى مسارات مطلقة
        const images = printContent.querySelectorAll('img');
        images.forEach(img => {
            const src = img.getAttribute('src');
            if (src && !src.startsWith('http') && !src.startsWith('data:')) {
                // تحويل المسار النسبي إلى مسار مطلق (تجاهل data URLs مثل QR codes)
                const baseUrl = window.location.protocol + '//' + window.location.host;
                let absolutePath;
                
                if (src.startsWith('../../')) {
                    // إزالة ../../ وإضافة /cafe/
                    absolutePath = baseUrl + '/cafe/' + src.replace('../../', '');
                } else if (src.startsWith('../')) {
                    // إزالة ../ وإضافة /cafe/views/
                    absolutePath = baseUrl + '/cafe/views/' + src.replace('../', '');
                } else if (src.startsWith('./')) {
                    // إزالة ./ وإضافة المسار الحالي
                    absolutePath = baseUrl + window.location.pathname.replace(/\/[^\/]*$/, '/') + src.replace('./', '');
                } else {
                    // مسار نسبي بدون بادئة
                    absolutePath = baseUrl + '/cafe/' + src;
                }
                
                img.setAttribute('src', absolutePath);
                console.log('تم تحويل مسار الصورة من:', src, 'إلى:', absolutePath);
            } else if (src && src.startsWith('data:')) {
                // QR Code أو صورة مشفرة - لا حاجة لتغيير المسار
                console.log('تم العثور على QR Code أو صورة مشفرة:', src.substring(0, 50) + '...');
            }
        });

        // التأكد من وجود نص footer في النهاية (يتم أخذه من ملف PHP)
        let footerText = printContent.querySelector('.footer-text');
        if (!footerText) {
            // إذا لم يكن موجود في المحتوى، نحاول الحصول عليه من الصفحة الأصلية
            const originalFooter = document.querySelector('.footer-text');
            if (originalFooter) {
                footerText = originalFooter.cloneNode(true);
                printContent.appendChild(footerText);
            }
        }

        // إنشاء HTML كامل للطباعة
        const printHtml = createPrintHTML(printContent.outerHTML);

        // إعداد الطباعة
        const config = qz.configs.create(printerToUse);
        const data = [{
            type: 'html',
            format: 'plain',
            data: printHtml
        }];

        await qz.print(config, data);
        console.log('✅ تم طباعة الفاتورة بنجاح');
        showSuccess('تم طباعة الفاتورة بنجاح');
        
        // إغلاق النافذة إذا كانت في وضع الطباعة التلقائية
        if (window.invoiceData?.auto_print) {
            setTimeout(() => {
                window.close();
            }, 1000);
        }

    } catch (error) {
        console.error('❌ خطأ في طباعة الفاتورة:', error);
        showError('فشل في طباعة الفاتورة: ' + error.message);
        
        // العودة للطباعة التقليدية في حالة الفشل
        if (window.invoiceData?.auto_print) {
            window.print();
            setTimeout(() => {
                window.close();
            }, 1000);
        }
    }
}

// دالة لإنشاء HTML للطباعة مع الأنماط
function createPrintHTML(content) {
    const printStyles = `
        <style>
        @page {
            size: 100% auto;
            margin: 0 !important;
            padding: 0 !important;
        }
        * {
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
        }
        body {
            font-family: Arial, 'Tahoma', 'DejaVu Sans', sans-serif !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            line-height: 1.3 !important;
            color: #000000 !important;
            background: white !important;
            width: 100% !important;
            direction: rtl !important;
            unicode-bidi: embed !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            padding-bottom: 15mm !important;
        }
        .invoice-container {
            width: 100% !important;
            padding: 1mm !important;
            background: white !important;
        }
        .invoice-header {
            text-align: center !important;
            margin-bottom: 4mm !important;
            padding-bottom: 3mm !important;
        }
        .invoice-header img {
            max-height: ${getLogoHeight()} !important;
            margin-bottom: 2mm !important;
        }
        .invoice-header h2 {
            font-size: 5mm !important;
            font-weight: 900 !important;
            margin: 2mm 0 !important;
            font-family: Arial, 'Tahoma', 'DejaVu Sans', sans-serif !important;
            direction: rtl !important;
            color: #000000 !important;
        }
        .invoice-title {
            font-size: 4.5mm !important;
            font-weight: 900 !important;
            margin: 2mm 0 !important;
            text-align: center !important;
            direction: rtl !important;
            font-family: Arial, 'Tahoma', 'DejaVu Sans', sans-serif !important;
            color: #000000 !important;
        }
        .invoice-details {
            margin: 2mm 0 !important;
            padding-bottom: 2mm !important;
        }
        .invoice-details .row {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 2mm !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .invoice-details .col-6 {
            margin: 0 !important;
            padding: 0 !important;
        }
        .info-row {
            display: flex !important;
            justify-content: flex-start !important;
            align-items: center !important;
            gap: 2mm !important;
            margin: 1mm 0 !important;
            font-size: 3.5mm !important;
            direction: rtl !important;
            text-align: right !important;
        }
        .info-row span:first-child {
            font-weight: 800 !important;
            color: #000000 !important;
        }
        .table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin: 2mm 0 !important;
        }
        .table th, .table td {
            padding: 1.5mm !important;
            font-size: 3.5mm !important;
            border: 1.5pt solid #000000 !important;
            text-align: center !important;
            vertical-align: middle !important;
            font-family: Arial, 'Tahoma', 'DejaVu Sans', sans-serif !important;
            font-weight: 700 !important;
            direction: rtl !important;
            color: #000000 !important;
            background-color: white !important;
        }
        .table th {
            background-color: white !important;
            font-weight: 900 !important;
            color: #000000 !important;
        }
        .invoice-footer {
            text-align: center !important;
            margin: 3mm 0 !important;
            padding: 2mm 0 !important;
            font-size: 3.5mm !important;
            font-weight: 700 !important;
            color: #000000 !important;
        }
        .branch-info {
            margin-bottom: 3mm !important;
            padding-bottom: 2mm !important;
        }
        .branch-info p {
            margin: 0.5mm 0 !important;
            padding: 0 !important;
        }
        .invoice-footer > p {
            margin: 2mm 0 !important;
            padding: 1mm 0 !important;
        }
        .qr-code {
            text-align: center !important;
            margin: 4mm 0 !important;
            padding: 2mm 0 !important;
        }
        .qr-code img {
            max-width: 20mm !important;
            max-height: 20mm !important;
            margin: 1mm 0 !important;
        }
        .total-section {
            margin-top: 2mm !important;
            padding-top: 2mm !important;
            border-top: 2px dashed #000000 !important;
        }
        .total-row {
            display: flex !important;
            justify-content: space-between !important;
            margin: 1mm 0 !important;
            font-size: 4mm !important;
            font-weight: 900 !important;
            direction: rtl !important;
            text-align: right !important;
            color: #000000 !important;
        }
        .footer-text {
            position: fixed !important;
            bottom: 3mm !important;
            left: 0 !important;
            width: 100% !important;
            text-align: center !important;
            font-size: 4mm !important;
            font-weight: 700 !important;
            color: #000000 !important;
            margin: 0 !important;
            padding: 3mm 0 !important;
            background: white !important;
            z-index: 1000 !important;
        }
        </style>`;

    return `<!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>طباعة الفاتورة</title>
        ${printStyles}
    </head>
    <body>
        ${content}
    </body>
    </html>`;
}

// دوال المساعدة لعرض الرسائل باستخدام نظام الإشعارات المخصص
function showSuccess(message) {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'success', 5000);
    } else if (typeof toastr !== 'undefined') {
        toastr.success(message);
    } else {
        alert(message);
    }
}

function showError(message) {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'danger', 5000);
    } else if (typeof toastr !== 'undefined') {
        toastr.error(message);
    } else {
        alert(message);
    }
}

function showWarning(message) {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'warning', 5000);
    } else if (typeof toastr !== 'undefined') {
        toastr.warning(message);
    } else {
        alert(message);
    }
}

function showInfo(message) {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'info', 5000);
    } else if (typeof toastr !== 'undefined') {
        toastr.info(message);
    } else {
        alert(message);
    }
}

// جعل دالة حذف بيانات الطابعة متاحة عالمياً
window.clearPrinterData = clearPrinterData;

// إعداد الشهادات الرقمية
qz.security.setCertificatePromise(function(resolve, reject) {
    //Preferred method - from server
    fetch("digital-certificate.txt", {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
      .then(function(data) { data.ok ? resolve(data.text()) : reject(data.text()); });

    //Alternate method 1 - anonymous
//        resolve();  // remove this line in live environment

    //Alternate method 2 - direct
    /*resolve("-----BEGIN CERTIFICATE-----\n" +
                "MIIE9TCCAt2gAwIBAgIQNzkyMDI0MTIyMDE5MDI0NDANBgkqhkiG9w0BAQsFADCB\n" +
                "mDELMAkGA1UEBhMCVVMxCzAJBgNVBAgMAk5ZMRswGQYDVQQKDBJRWiBJbmR1c3Ry\n" +
                "aWVzLCBMTEMxGzAZBgNVBAsMElFaIEluZHVzdHJpZXMsIExMQzEZMBcGA1UEAwwQ\n" +
                "cXppbmR1c3RyaWVzLmNvbTEnMCUGCSqGSIb3DQEJARYYc3VwcG9ydEBxemluZHVz\n" +
                "dHJpZXMuY29tMB4XDTI0MTIyMDE5MDI0NFoXDTI5MTIyMDE4NTMxOVowga4xFjAU\n" +
                "BgNVBAYMDVVuaXRlZCBTdGF0ZXMxCzAJBgNVBAgMAk5ZMRIwEAYDVQQHDAlDYW5h\n" +
                "c3RvdGExGzAZBgNVBAoMElFaIEluZHVzdHJpZXMsIExMQzEbMBkGA1UECwwSUVog\n" +
                "SW5kdXN0cmllcywgTExDMRswGQYDVQQDDBJRWiBJbmR1c3RyaWVzLCBMTEMxHDAa\n" +
                "BgkqhkiG9w0BCQEMDXN1cHBvcnRAcXouaW8wggEiMA0GCSqGSIb3DQEBAQUAA4IB\n" +
                "DwAwggEKAoIBAQC+j6ewVhtLHbY3uBNgqNB5DSz+QX9Pz5Dm46bI9vt/Q1Q6BL8I\n" +
                "dhaxT2PA1AY0fqQgkzlSrwqNCjWZcrNZRw/e54FGM8zf3azbHrQif6d7Wo1JK5oN\n" +
                "kI3jdB54YVwHIAt6i3BcLIvyOHsPnrKjlpROz72Kx1kK5g0gLDuH5RYVM9KFK+HR\n" +
                "fBc3JSfeg8nUkTqYJVzlT5AGRWPXeDWloqQqSyuB1t8DihNBReWyJHQ7a4yerLOI\n" +
                "J6N0jAlLDx9yt9UznAxnoO+7tKBfxCbNJerGfePMOwRKq0gx+r8M/FTrAoj+yc+T\n" +
                "SOYtuY/VZ79HCTP/vLgm1pGyrta1we24fVezAgMBAAGjIzAhMB8GA1UdIwQYMBaA\n" +
                "FJCmULeE1LnqX/IFhBN4ReipdVRcMA0GCSqGSIb3DQEBCwUAA4ICAQAMvfp931Zt\n" +
                "PgfqGXSrsM+GAVBxcRVm14MyldWfRr+MVaFZ6cH7c+fSs8hUt2qNPwHrnpK9eev5\n" +
                "MPUL27hjfiTPwv1ojLJ180aMO0ZAfPfnKeLO8uTzY7GiPQeGK7Qh39kX9XxEOidG\n" +
                "rMwfllZ6jJReS0ZGaX8LUXhh9RHGSYJhxgyUV7clB/dJch8Bbcd+DOxwc1POUHx1\n" +
                "wWExKkoWzHCCYNvqxLC9p1eO2Elz9J9ynDjXtCBl7lssnoSUKtahBCKgN5tYmZZK\n" +
                "NErKPQpbYk5yTEK1gybxhup8i2sGEJXZ9HRJLAl0UxB+eCu1ExWv7eGbcbIZJbeh\n" +
                "bwRf03fatsqzCQbGboLWtMQfcxHrEu+5MdZwOFx8i+c0c2WYad2MkkzGYHBVHPtY\n" +
                "o+PR61uIwJC2mNkPpX94CIFxSHyZumttyVKF4AhIPm9IMGTHaIr5M39zesQpVc7N\n" +
                "VIgxmMuePBrLyh6vKvuqD7W3S2HWA/8IUX703tdhoXhv5lNo1j0oywSrrUkCvUvJ\n" +
                "FjPS8+VUtVZNl7SVetQTexdcUwoADj6c1UwL9QWItskJ5Myesco3ZY0O+3QbgCuQ\n" +
                "SRqN5D0qdaLNMdEwh1YekUp4i1jm0jzPzia+WvJrW1k1ZafV6ep+YkMBkC1SFYFw\n" +
                "1Mdy+fYGyXlSn/Mvou//SSb0fUMIpXE9NA==\n" +
                "-----END CERTIFICATE-----\n" +
                "--START INTERMEDIATE CERT--\n" +
                "-----BEGIN CERTIFICATE-----\n" +
                "MIIFEjCCA/qgAwIBAgICEAAwDQYJKoZIhvcNAQELBQAwgawxCzAJBgNVBAYTAlVT\n" +
                "MQswCQYDVQQIDAJOWTESMBAGA1UEBwwJQ2FuYXN0b3RhMRswGQYDVQQKDBJRWiBJ\n" +
                "bmR1c3RyaWVzLCBMTEMxGzAZBgNVBAsMElFaIEluZHVzdHJpZXMsIExMQzEZMBcG\n" +
                "A1UEAwwQcXppbmR1c3RyaWVzLmNvbTEnMCUGCSqGSIb3DQEJARYYc3VwcG9ydEBx\n" +
                "emluZHVzdHJpZXMuY29tMB4XDTE1MDMwMjAwNTAxOFoXDTM1MDMwMjAwNTAxOFow\n" +
                "gZgxCzAJBgNVBAYTAlVTMQswCQYDVQQIDAJOWTEbMBkGA1UECgwSUVogSW5kdXN0\n" +
                "cmllcywgTExDMRswGQYDVQQLDBJRWiBJbmR1c3RyaWVzLCBMTEMxGTAXBgNVBAMM\n" +
                "EHF6aW5kdXN0cmllcy5jb20xJzAlBgkqhkiG9w0BCQEWGHN1cHBvcnRAcXppbmR1\n" +
                "c3RyaWVzLmNvbTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBANTDgNLU\n" +
                "iohl/rQoZ2bTMHVEk1mA020LYhgfWjO0+GsLlbg5SvWVFWkv4ZgffuVRXLHrwz1H\n" +
                "YpMyo+Zh8ksJF9ssJWCwQGO5ciM6dmoryyB0VZHGY1blewdMuxieXP7Kr6XD3GRM\n" +
                "GAhEwTxjUzI3ksuRunX4IcnRXKYkg5pjs4nLEhXtIZWDLiXPUsyUAEq1U1qdL1AH\n" +
                "EtdK/L3zLATnhPB6ZiM+HzNG4aAPynSA38fpeeZ4R0tINMpFThwNgGUsxYKsP9kh\n" +
                "0gxGl8YHL6ZzC7BC8FXIB/0Wteng0+XLAVto56Pyxt7BdxtNVuVNNXgkCi9tMqVX\n" +
                "xOk3oIvODDt0UoQUZ/umUuoMuOLekYUpZVk4utCqXXlB4mVfS5/zWB6nVxFX8Io1\n" +
                "9FOiDLTwZVtBmzmeikzb6o1QLp9F2TAvlf8+DIGDOo0DpPQUtOUyLPCh5hBaDGFE\n" +
                "ZhE56qPCBiQIc4T2klWX/80C5NZnd/tJNxjyUyk7bjdDzhzT10CGRAsqxAnsjvMD\n" +
                "2KcMf3oXN4PNgyfpbfq2ipxJ1u777Gpbzyf0xoKwH9FYigmqfRH2N2pEdiYawKrX\n" +
                "6pyXzGM4cvQ5X1Yxf2x/+xdTLdVaLnZgwrdqwFYmDejGAldXlYDl3jbBHVM1v+uY\n" +
                "5ItGTjk+3vLrxmvGy5XFVG+8fF/xaVfo5TW5AgMBAAGjUDBOMB0GA1UdDgQWBBSQ\n" +
                "plC3hNS56l/yBYQTeEXoqXVUXDAfBgNVHSMEGDAWgBQDRcZNwPqOqQvagw9BpW0S\n" +
                "BkOpXjAMBgNVHRMEBTADAQH/MA0GCSqGSIb3DQEBCwUAA4IBAQAJIO8SiNr9jpLQ\n" +
                "eUsFUmbueoxyI5L+P5eV92ceVOJ2tAlBA13vzF1NWlpSlrMmQcVUE/K4D01qtr0k\n" +
                "gDs6LUHvj2XXLpyEogitbBgipkQpwCTJVfC9bWYBwEotC7Y8mVjjEV7uXAT71GKT\n" +
                "x8XlB9maf+BTZGgyoulA5pTYJ++7s/xX9gzSWCa+eXGcjguBtYYXaAjjAqFGRAvu\n" +
                "pz1yrDWcA6H94HeErJKUXBakS0Jm/V33JDuVXY+aZ8EQi2kV82aZbNdXll/R6iGw\n" +
                "2ur4rDErnHsiphBgZB71C5FD4cdfSONTsYxmPmyUb5T+KLUouxZ9B0Wh28ucc1Lp\n" +
                "rbO7BnjW\n" +
                "-----END CERTIFICATE-----\n");*/
});

qz.security.setSignatureAlgorithm("SHA512"); // Since 2.1
qz.security.setSignaturePromise(function(toSign) {
    return function(resolve, reject) {
        //Preferred method - from server
        fetch("sign-message.php?request=" + toSign, {cache: 'no-store', headers: {'Content-Type': 'text/plain'}})
          .then(function(data) { data.ok ? resolve(data.text()) : reject(data.text()); });

        //Alternate method - unsigned
        //resolve(); // remove this line in live environment
    };
});

// تهيئة الصفحة عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    // إضافة أزرار QZ Tray إذا لم تكن موجودة
    addQZPrintButtons();
    
    // محاولة الاتصال بـ QZ Tray
    connectToQZ().then(connected => {
        if (connected) {
            console.log('QZ Tray متاح ومتصل');
            
            // إذا كانت الطباعة التلقائية مفعلة، استخدم QZ Tray
            if (window.invoiceData && window.invoiceData.auto_print) {
                setTimeout(() => {
                    qzPrintInvoice();
                }, 1000);
            }
        } else {
            console.log('QZ Tray غير متاح');
        }
    });
    
    // إضافة مستمع لحدث تسجيل الخروج
    window.addEventListener('beforeunload', function() {
        // يمكن إضافة منطق إضافي هنا إذا لزم الأمر
    });
});

// دالة للاستدعاء عند تسجيل الخروج (يجب استدعاؤها من صفحة تسجيل الخروج)
function onLogout() {
    clearPrinterData();
    console.log('تم حذف بيانات الطابعة المحفوظة');
}

// جعل دالة تسجيل الخروج متاحة عالمياً
window.onLogout = onLogout;

// دالة لإضافة أزرار QZ Tray
function addQZPrintButtons() {
    const printButtons = document.querySelector('.print-buttons');
    if (!printButtons) return;

    // إضافة زر اختيار الطابعة
    const printerSelectBtn = document.createElement('button');
    printerSelectBtn.className = 'btn btn-info me-2';
    printerSelectBtn.id = 'printer-select-btn';
    
    // تحديث نص الزر حسب الطابعة المحفوظة
    function updatePrinterButtonText() {
        const savedPrinter = loadSavedPrinter();
        if (savedPrinter) {
            printerSelectBtn.innerHTML = `<i class="fas fa-printer me-1"></i> الطابعة: ${savedPrinter.length > 20 ? savedPrinter.substring(0, 20) + '...' : savedPrinter}`;
            printerSelectBtn.title = `الطابعة الحالية: ${savedPrinter}`;
        } else {
            printerSelectBtn.innerHTML = '<i class="fas fa-cog me-1"></i> اختيار الطابعة';
            printerSelectBtn.title = 'اختيار طابعة للطباعة';
        }
    }
    
    updatePrinterButtonText();
    
    printerSelectBtn.onclick = async function() {
        const printer = await showPrinterSelection();
        if (printer) {
            updatePrinterButtonText();
            // عرض رسالة تأكيد مؤقتة
            const originalText = printerSelectBtn.innerHTML;
            printerSelectBtn.innerHTML = '<i class="fas fa-check me-1"></i> تم الحفظ';
            printerSelectBtn.className = 'btn btn-success me-2';
            setTimeout(() => {
                updatePrinterButtonText();
                printerSelectBtn.className = 'btn btn-info me-2';
            }, 2000);
        }
    };

    // إضافة زر طباعة QZ Tray
    const qzPrintButton = document.createElement('button');
    qzPrintButton.id = 'qz-print-btn';
    qzPrintButton.className = 'btn btn-success me-2';
    qzPrintButton.innerHTML = '<i class="fas fa-print me-1"></i> طباعة الفاتورة';
    qzPrintButton.onclick = function() {
        qzPrintInvoice();
    };

    // إدراج الأزرار
    const firstButton = printButtons.querySelector('button');
    if (firstButton) {
        printButtons.insertBefore(printerSelectBtn, firstButton);
        printButtons.insertBefore(qzPrintButton, firstButton);
    } else {
        printButtons.appendChild(printerSelectBtn);
        printButtons.appendChild(qzPrintButton);
    }
}