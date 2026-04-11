/**
 * QZ Tray Configuration — POS System
 * يحدد عنوان السيرفر تلقائياً ويُعِدّ الشهادات الرقمية
 */

function getQZTrayHost() {
    return 'localhost';
}

window.QZ_CONFIG = {
    host:      getQZTrayHost(),
    port:      { secure: [8181, 8282, 8383, 8484], insecure: [8182, 8283, 8384, 8485] },
    keepAlive: 60,
    retries:   2,
    delay:     0,
    /** URL of sign-message.php relative to origin — e.g. /pos/backend/sign-message.php */
    signUrl:   '/pos/backend/sign-message.php',
    /** URL of digital-certificate.txt in the public folder */
    certUrl:   '/digital-certificate.txt',
};

window.getQZTrayHost = getQZTrayHost;

console.log('[QZ Config] host =', window.QZ_CONFIG.host);
