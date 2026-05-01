const { spawn, execSync } = require('child_process');
const path = require('path');
const net = require('net');
const { getMysqlPaths } = require('../utils/paths');

let mysqlProcess = null;

function startMySQL(port) {
  return new Promise((resolve, reject) => {
    const { mysqldPath, dataDir, baseDir } = getMysqlPaths();

    mysqlProcess = spawn(mysqldPath, [
      `--port=${port}`,
      `--datadir=${dataDir}`,
      `--basedir=${baseDir}`,
      '--standalone',
      '--console',
      '--skip-networking=0',
      '--bind-address=127.0.0.1',
    ], { windowsHide: true });

    // انتظار جاهزية MySQL
    let attempts = 0;
    const check = setInterval(() => {
      attempts++;
      const sock = new net.Socket();
      sock.setTimeout(500);
      sock.connect(port, '127.0.0.1', () => {
        sock.destroy();
        clearInterval(check);
        // تأكد من وجود قاعدة البيانات
        initDatabase(port).then(resolve).catch(reject);
      });
      sock.on('error', () => { sock.destroy(); });
      sock.on('timeout', () => { sock.destroy(); });
      if (attempts > 30) {
        clearInterval(check);
        reject(new Error('MySQL failed to start'));
      }
    }, 500);
  });
}

async function initDatabase(port) {
  const { mysqlPath } = getMysqlPaths();
  const schemaFile = path.join(__dirname, '..', '..', 'database', 'pos_schema.sql');
  try {
    // إنشاء قاعدة البيانات إذا لم تكن موجودة
    execSync(`"${mysqlPath}" -u root --port=${port} -e "CREATE DATABASE IF NOT EXISTS pos_db"`,
      { windowsHide: true });
    // فحص إذا الجداول موجودة
    const result = execSync(
      `"${mysqlPath}" -u root --port=${port} pos_db -e "SHOW TABLES" 2>&1`,
      { encoding: 'utf-8', windowsHide: true }
    );
    if (!result.includes('users')) {
      // أول تشغيل — تحميل الـ schema
      execSync(`"${mysqlPath}" -u root --port=${port} pos_db < "${schemaFile}"`,
        { windowsHide: true, shell: true });
    }
  } catch (e) {
    console.error('[MySQL Init]', e.message);
  }
}

function stopMySQL() {
  return new Promise((resolve) => {
    if (mysqlProcess) {
      mysqlProcess.kill();
      mysqlProcess = null;
    }
    setTimeout(resolve, 1000);
  });
}

module.exports = { startMySQL, stopMySQL };
