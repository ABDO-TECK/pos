const { spawn, execSync } = require('child_process');
const path = require('path');
const net = require('net');
const { getMysqlPaths } = require('../utils/paths');

let mysqlProcess = null;
let mysqlPort = null;

function startMySQL(port) {
  mysqlPort = port;
  return new Promise((resolve, reject) => {
    const { mysqldPath, dataDir, baseDir } = getMysqlPaths();
    const fs = require('fs');

    // تهيئة قاعدة البيانات إذا لم تكن موجودة في مسار الـ userData
    if (!fs.existsSync(dataDir) || fs.readdirSync(dataDir).length === 0) {
      console.log('[MySQL] Initializing new database directory at:', dataDir);
      try {
        if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });
        const installDbPath = path.join(baseDir, 'bin', 'mysql_install_db.exe');
        execSync(`"${installDbPath}" --datadir="${dataDir}"`, { windowsHide: true });
      } catch (err) {
        console.error('[MySQL Init Error]', err.message);
      }
    }

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
  const { getBackendDir, getDatabaseDir } = require('../utils/paths');
  const schemaFile = path.join(getDatabaseDir(), 'pos_schema.sql');
  try {
    // إنشاء قاعدة البيانات إذا لم تكن موجودة مع دعم اللغة العربية
    execSync(`"${mysqlPath}" -u root --port=${port} --default-character-set=utf8mb4 -e "CREATE DATABASE IF NOT EXISTS pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`,
      { windowsHide: true });
    // فحص إذا الجداول موجودة
    const result = execSync(
      `"${mysqlPath}" -u root --port=${port} pos_db -e "SHOW TABLES" 2>&1`,
      { encoding: 'utf-8', windowsHide: true }
    );
    if (!result.includes('users')) {
      // أول تشغيل — تحميل الـ schema مع فرض ترميز utf8mb4
      execSync(`"${mysqlPath}" -u root --port=${port} --default-character-set=utf8mb4 pos_db < "${schemaFile}"`,
        { windowsHide: true, shell: true });
    } else {
      // إصلاح الجداول التالفة ("doesn't exist in engine")
      repairCorruptedTables(mysqlPath, port);
    }
  } catch (e) {
    console.error('[MySQL Init]', e.message);
  }
}

function repairCorruptedTables(mysqlPath, port) {
  try {
    // نتحقق من جدول واحد رئيسي (users) بدلاً من 14 جدول لتسريع التشغيل
    execSync(
      `"${mysqlPath}" -u root --port=${port} pos_db -e "SELECT id FROM users LIMIT 1" 2>&1`,
      { encoding: 'utf-8', windowsHide: true }
    );
  } catch (err) {
    if (err.message && err.message.includes("doesn't exist in engine")) {
      console.log(`[MySQL Repair] Database corruption detected. Repairing schema...`);
      // إجبار إعادة تحميل الهيكل لتنظيف الجداول التالفة
      const { getDatabaseDir } = require('../utils/paths');
      const schemaFile = path.join(getDatabaseDir(), 'pos_schema.sql');
      try {
        // Drop database and recreate to start fresh, since InnoDB corruption is hard to fix per-table
        execSync(`"${mysqlPath}" -u root --port=${port} -e "DROP DATABASE IF EXISTS pos_db; CREATE DATABASE pos_db;"`, { windowsHide: true });
        execSync(`"${mysqlPath}" -u root --port=${port} pos_db < "${schemaFile}"`, { windowsHide: true, shell: true });
        console.log('[MySQL Repair] Schema reloaded successfully');
      } catch (e) {
        console.error('[MySQL Repair] Schema reload failed:', e.message);
      }
    }
  }
}

function stopMySQL() {
  return new Promise((resolve) => {
    if (!mysqlProcess) {
      resolve();
      return;
    }
    const { baseDir } = getMysqlPaths();
    const mysqladmin = path.join(baseDir, 'bin', 'mysqladmin.exe');
    if (require('fs').existsSync(mysqladmin) && mysqlPort) {
      console.log(`[MySQL] Requesting clean shutdown via mysqladmin on port ${mysqlPort}...`);
      const { exec } = require('child_process');
      exec(`"${mysqladmin}" -u root --port=${mysqlPort} shutdown`, { windowsHide: true }, (err) => {
        if (err) {
          console.warn('[MySQL] mysqladmin shutdown failed, falling back to process kill:', err.message);
          if (mysqlProcess) {
            mysqlProcess.kill();
          }
        }
        mysqlProcess = null;
        resolve();
      });
    } else {
      console.log('[MySQL] Killing process directly...');
      mysqlProcess.kill();
      mysqlProcess = null;
      setTimeout(resolve, 1000);
    }
  });
}

module.exports = { startMySQL, stopMySQL };
