const { spawn } = require('child_process');
const path = require('path');
const http = require('http');

let phpProcess = null;

function startPHP(port, mysqlPort) {
  return new Promise((resolve, reject) => {
    const { getPhpPath, getBackendDir } = require('../utils/paths');
    const phpBin = getPhpPath();
    const backendDir = getBackendDir();
    const routerFile = path.join(backendDir, 'router.php');

    // تمرير إعدادات الاتصال لـ PHP
    const env = {
      ...process.env,
      DB_HOST: '127.0.0.1',
      DB_PORT: String(mysqlPort),
      DB_NAME: 'pos_db',
      DB_USER: 'root',
      DB_PASS: '',
      ENABLE_AUTO_UPDATE: 'true',
    };

    phpProcess = spawn(phpBin, [
      '-S', `0.0.0.0:${port}`,
      '-t', backendDir,
      routerFile
    ], { env, windowsHide: true });

    phpProcess.stderr.on('data', (data) => {
      console.log('[PHP]', data.toString());
    });

    // انتظار جاهزية PHP (محاولة اتصال كل 200ms لمدة 10 ثوانٍ)
    let attempts = 0;
    const check = setInterval(() => {
      attempts++;
      http.get(`http://127.0.0.1:${port}/`, (res) => {
        if (res.statusCode === 200) {
          clearInterval(check);
          resolve();
        }
      }).on('error', () => {
        if (attempts > 50) {
          clearInterval(check);
          reject(new Error('PHP server failed to start'));
        }
      });
    }, 200);
  });
}

function stopPHP() {
  if (phpProcess) {
    phpProcess.kill();
    phpProcess = null;
  }
}

module.exports = { startPHP, stopPHP };
