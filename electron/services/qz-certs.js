const forge = require('node-forge');
const fs = require('fs');
const path = require('path');
const { app } = require('electron');
const crypto = require('crypto');

const CERTS_DIR_NAME = 'qz-certs';

/**
 * يُرجع مسار مجلد الشهادات في userData.
 * مثال: C:\Users\abdmo\AppData\Roaming\pos-desktop\qz-certs\
 */
function getCertsDir() {
  const dir = path.join(app.getPath('userData'), CERTS_DIR_NAME);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  return dir;
}

/**
 * يتحقق من وجود الشهادات. إذا غير موجودة، يولّدها.
 * يُستدعى مرة واحدة من main.js قبل startQZTray.
 *
 * @param {string} qzTrayDir - مسار مجلد qz-tray (portable/qz-tray أو المبني)
 */
function ensureQZCerts(qzTrayDir) {
  const certsDir = getCertsDir();
  const certPath = path.join(certsDir, 'digital-certificate.pem');
  const keyPath  = path.join(certsDir, 'private-key.pem');

  if (fs.existsSync(certPath) && fs.existsSync(keyPath)) {
    console.log('[QZ Certs] Certificates already exist');
    // تأكد أن override.crt محدّث
    _copyOverrideCert(certPath, qzTrayDir);
    return;
  }

  console.log('[QZ Certs] Generating new certificate pair...');

  // 1. توليد مفتاح RSA 2048-bit
  const keys = forge.pki.rsa.generateKeyPair(2048);

  // 2. إنشاء شهادة X.509 self-signed
  const cert = forge.pki.createCertificate();
  cert.publicKey = keys.publicKey;
  cert.serialNumber = '01' + crypto.randomBytes(8).toString('hex');

  // صالحة لمدة 20 سنة
  cert.validity.notBefore = new Date();
  cert.validity.notAfter = new Date();
  cert.validity.notAfter.setFullYear(cert.validity.notAfter.getFullYear() + 20);

  const attrs = [
    { name: 'commonName',    value: 'POS System' },
    { name: 'countryName',   value: 'US' },
    { shortName: 'ST',       value: 'NY' },
    { name: 'localityName',  value: 'Canastota' },
    { name: 'organizationName', value: 'QZ Industries, LLC' },
    { shortName: 'OU',       value: 'QZ Industries, LLC' },
    { name: 'emailAddress',  value: 'support@qz.io' },
  ];
  cert.setSubject(attrs);
  cert.setIssuer(attrs);

  // إضافة extensions (مطلوبة حتى يقبلها QZ Tray كـ CA)
  cert.setExtensions([
    { name: 'basicConstraints', cA: true, critical: true, pathLenConstraint: 1 },
    { name: 'keyUsage', keyCertSign: true, critical: true },
    { name: 'subjectKeyIdentifier' },
  ]);

  // 3. توقيع الشهادة بمفتاحها الخاص (self-signed)
  cert.sign(keys.privateKey, forge.md.sha256.create());

  // 4. حفظ الملفات
  const certPem = forge.pki.certificateToPem(cert);
  const keyPem  = forge.pki.privateKeyToPem(keys.privateKey);

  fs.writeFileSync(certPath, certPem, 'utf-8');
  fs.writeFileSync(keyPath, keyPem, 'utf-8');
  console.log('[QZ Certs] Certificate saved to:', certPath);
  console.log('[QZ Certs] Private key saved to:', keyPath);

  // 5. نسخ الشهادة كـ override.crt
  _copyOverrideCert(certPath, qzTrayDir);
}

/**
 * ينسخ الشهادة كـ override.crt بجانب qz-tray.jar
 * حتى يثق بها QZ Tray تلقائياً.
 */
function _copyOverrideCert(certPath, qzTrayDir) {
  try {
    const overridePath = path.join(qzTrayDir, 'override.crt');
    fs.copyFileSync(certPath, overridePath);
    console.log('[QZ Certs] override.crt copied to:', overridePath);
  } catch (err) {
    console.warn('[QZ Certs] Could not copy override.crt:', err.message);
  }
}

/**
 * يُرجع محتوى الشهادة كـ string (PEM).
 * يُستدعى عبر IPC من الـ Frontend.
 */
function getQZCertificate() {
  const certPath = path.join(getCertsDir(), 'digital-certificate.pem');
  if (fs.existsSync(certPath)) {
    return fs.readFileSync(certPath, 'utf-8');
  }
  return null;
}

/**
 * يوقّع رسالة QZ Tray بالمفتاح الخاص.
 * يُستدعى عبر IPC من الـ Frontend.
 *
 * @param {string} toSign - النص المراد توقيعه
 * @returns {string|null} - التوقيع بصيغة Base64، أو null عند الفشل
 */
function signQZMessage(toSign) {
  const keyPath = path.join(getCertsDir(), 'private-key.pem');
  if (!fs.existsSync(keyPath)) return null;

  try {
    const keyPem = fs.readFileSync(keyPath, 'utf-8');
    
    // استخدام مكتبة crypto المدمجة للتوقيع بشكل مطابق لـ openssl_sign في PHP
    const sign = crypto.createSign('SHA512');
    sign.update(toSign, 'utf8');
    sign.end();
    
    return sign.sign(keyPem, 'base64');
  } catch (err) {
    console.error('[QZ Certs] Signing failed:', err.message);
    return null;
  }
}

module.exports = { ensureQZCerts, getQZCertificate, signQZMessage };
