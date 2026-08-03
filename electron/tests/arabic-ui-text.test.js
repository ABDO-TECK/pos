const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('desktop startup and recovery UI keep user-facing status text in Arabic', () => {
  const main = read('electron/main.js');
  const recovery = read('electron/assets/recovery.html');
  const splash = read('electron/assets/splash.html');

  for (const text of [main, recovery, splash]) {
    assert.equal(text.includes('\uFFFD'), false, 'UI source contains a replacement character');
  }

  assert.match(main, /جاري تشغيل قاعدة البيانات/);
  assert.match(main, /جاري تشغيل الخادم/);
  assert.match(main, /التحقق من جاهزية النظام/);
  assert.match(main, /جاري تشغيل خدمة الطباعة/);
  assert.match(recovery, /وضع استرداد نظام نقاط البيع/);
  assert.match(recovery, /مرحلة بدء التشغيل/);
  assert.match(recovery, /رمز الخطأ/);
  assert.doesNotMatch(main, /Starting the bundled database|Starting the backend server|Checking runtime readiness/);
  assert.doesNotMatch(recovery, /POS System Recovery Mode|Startup stage:|Error code:/);
});
