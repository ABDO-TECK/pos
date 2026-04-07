/**
 * QZ Tray Configuration
 * إعدادات QZ Tray للطباعة عبر الشبكة
 * 
 * هذا الملف يحدد عنوان السيرفر الذي يعمل عليه QZ Tray
 * عند الوصول من أجهزة أخرى على الشبكة (مثل الموبايل)
 */

// تحديد عنوان QZ Tray تلقائيًا
// إذا كان المستخدم على نفس الجهاز (localhost)، يتصل مباشرة
// إذا كان على جهاز آخر (مثل موبايل)، يتصل عبر IP السيرفر
function getQZTrayHost() {
    const currentHost = window.location.hostname;
    
    // إذا كان الوصول من localhost أو 127.0.0.1، استخدم localhost
    if (currentHost === 'localhost' || currentHost === '127.0.0.1') {
        return 'localhost';
    }
    
    // إذا كان الوصول من IP آخر، استخدم نفس الـ IP (السيرفر)
    // لأن QZ Tray يعمل على السيرفر
    return currentHost;
}

// إعدادات الاتصال بـ QZ Tray
const QZ_CONFIG = {
    host: getQZTrayHost(),
    port: {
        secure: [8181, 8282, 8383, 8484],  // HTTPS/WSS ports
        insecure: [8182, 8283, 8384, 8485]  // HTTP/WS ports
    },
    keepAlive: 60,  // ثواني
    retries: 2,
    delay: 0
};

// دالة للاتصال بـ QZ Tray مع الإعدادات الصحيحة
function connectToQZWithConfig() {
    return new Promise((resolve, reject) => {
        if (typeof qz === 'undefined') {
            console.error('❌ مكتبة QZ Tray غير محملة');
            reject(new Error('QZ Tray library not loaded'));
            return;
        }

        // التحقق من الاتصال الحالي
        if (qz.websocket.isActive()) {
            console.log('✅ QZ Tray متصل بالفعل');
            resolve(true);
            return;
        }

        // إعداد خيارات الاتصال
        const connectOptions = {
            host: QZ_CONFIG.host,
            retries: QZ_CONFIG.retries,
            delay: QZ_CONFIG.delay
        };

        console.log(`🔄 جاري الاتصال بـ QZ Tray على ${QZ_CONFIG.host}...`);

        qz.websocket.connect(connectOptions)
            .then(() => {
                console.log(`✅ تم الاتصال بـ QZ Tray على ${QZ_CONFIG.host}`);
                resolve(true);
            })
            .catch(err => {
                console.error(`❌ فشل الاتصال بـ QZ Tray على ${QZ_CONFIG.host}:`, err);
                reject(err);
            });
    });
}

// تصدير الإعدادات والدوال
window.QZ_CONFIG = QZ_CONFIG;
window.getQZTrayHost = getQZTrayHost;
window.connectToQZWithConfig = connectToQZWithConfig;

console.log(`📡 QZ Tray Config: Host = ${QZ_CONFIG.host}`);
