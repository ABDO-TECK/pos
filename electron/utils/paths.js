const path = require('path');
const fs = require('fs');
const { app } = require('electron');

function isPackaged() {
  return app.isPackaged;
}

function getPortableDir() {
  if (isPackaged()) {
    // بعد البناء: extraResources تنسخ إلى resources/portable
    return path.join(process.resourcesPath, 'portable');
  }
  return path.join(__dirname, '..', '..', 'portable');
}

function getBackendDir() {
  if (isPackaged()) {
    return path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  }
  return path.join(__dirname, '..', '..', 'backend');
}

function getDatabaseDir() {
  if (isPackaged()) {
    return path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'database');
  }
  return path.join(__dirname, '..', '..', 'database');
}

function getPhpPath() {
  const portablePhp = path.join(getPortableDir(), 'php', 'php.exe');
  if (fs.existsSync(portablePhp)) return portablePhp;
  // fallback: XAMPP
  const xamppPhp = 'C:\\xampp\\php\\php.exe';
  if (fs.existsSync(xamppPhp)) return xamppPhp;
  throw new Error('PHP not found. Install portable PHP in portable/php/');
}

function getMysqlPaths() {
  const portableDir = getPortableDir();
  const portableMysqld = path.join(portableDir, 'mysql', 'bin', 'mysqld.exe');

  if (fs.existsSync(portableMysqld)) {
    const dbDataPath = getMysqlDataDir();

    return {
      mysqldPath: portableMysqld,
      mysqlPath: path.join(portableDir, 'mysql', 'bin', 'mysql.exe'),
      dataDir: dbDataPath,
      baseDir: path.join(portableDir, 'mysql'),
    };
  }

  // fallback: XAMPP
  return {
    mysqldPath: 'C:\\xampp\\mysql\\bin\\mysqld.exe',
    mysqlPath: 'C:\\xampp\\mysql\\bin\\mysql.exe',
    dataDir: 'C:\\xampp\\mysql\\data',
    baseDir: 'C:\\xampp\\mysql',
  };
}

function getJavaPath() {
  // 1. المسار المدمج (portable/java)
  const portableJava = path.join(getPortableDir(), 'java', 'bin', 'java.exe');
  if (fs.existsSync(portableJava)) return portableJava;

  // 2. Fallback: Java مثبت على النظام
  const systemJava = 'java';
  try {
    require('child_process').execSync(`"${systemJava}" -version`, { windowsHide: true, stdio: 'pipe' });
    return systemJava;
  } catch { /* not found */ }

  throw new Error('Java not found. Install JRE in portable/java/');
}

function getQZTrayPath() {
  // 1. المسار المدمج (portable/qz-tray)
  const portableQZ = path.join(getPortableDir(), 'qz-tray', 'qz-tray.jar');
  if (fs.existsSync(portableQZ)) return portableQZ;

  // 2. Fallback: مجلد tray في التطوير
  const devQZ = path.join(__dirname, '..', '..', 'tray', 'out', 'dist', 'qz-tray.jar');
  if (fs.existsSync(devQZ)) return devQZ;

  throw new Error('qz-tray.jar not found. Build it with: cd tray && ant distribute');
}

let resolvedDataDir = null;

function getConfigDir() {
  const appDataPath = process.env.APPDATA || app.getPath('appData');
  return path.join(appDataPath, 'POS System');
}

function getPrimaryDataDir() {
  const programDataPath = process.env.PROGRAMDATA || 'C:\\ProgramData';
  return path.join(programDataPath, 'POS System');
}

function getFallbackDataDir() {
  const localAppDataPath = process.env.LOCALAPPDATA || path.join(app.getPath('appData'), '..', 'Local');
  return path.join(localAppDataPath, 'POS System', 'Data');
}

function getDataDir() {
  if (resolvedDataDir) return resolvedDataDir;

  const primary = getPrimaryDataDir();
  const fallback = getFallbackDataDir();

  try {
    if (!fs.existsSync(primary)) {
      fs.mkdirSync(primary, { recursive: true });
    }

    const testFile = path.join(primary, `.write-test-${Math.random().toString(36).substring(7)}`);
    fs.writeFileSync(testFile, 'test');
    fs.unlinkSync(testFile);

    resolvedDataDir = primary;
  } catch (err) {
    console.warn(`[Paths] Primary directory ${primary} is not writable. Falling back to LocalAppData. Error: ${err.message}`);
    try {
      if (!fs.existsSync(fallback)) {
        fs.mkdirSync(fallback, { recursive: true });
      }
      resolvedDataDir = fallback;
    } catch (fallbackErr) {
      console.error(`[Paths] Fallback directory ${fallback} is also not writable:`, fallbackErr);
      resolvedDataDir = app.getPath('userData');
    }
  }

  return resolvedDataDir;
}

function getLogsDir() {
  return path.join(getDataDir(), 'logs');
}

function getTempDir() {
  return path.join(getDataDir(), 'temp');
}

function getBackupsDir() {
  return path.join(getDataDir(), 'backups');
}

function getMysqlDataDir() {
  return path.join(getDataDir(), 'mysql_data');
}

function getRuntimeMetadataPath() {
  return path.join(getConfigDir(), 'runtime_metadata.json');
}

function getRuntimePortsPath() {
  return path.join(getConfigDir(), 'runtime_ports.json');
}

function getEnvPath() {
  return path.join(getConfigDir(), '.env');
}

function getRecoveryAuthPath() {
  return path.join(getConfigDir(), 'recovery_auth.json');
}

function ensureRuntimeDirs() {
  const config = getConfigDir();
  const data = getDataDir();
  const mysql = getMysqlDataDir();
  const backups = getBackupsDir();
  const updates = path.join(data, 'updates');
  const temp = getTempDir();
  const logs = getLogsDir();

  const dirs = [config, data, mysql, backups, updates, temp, logs];
  dirs.forEach(dir => {
    try {
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }
    } catch (err) {
      console.error(`[Paths] Failed to create directory: ${dir}`, err);
    }
  });

  const isPrimary = (data === getPrimaryDataDir());
  console.log(`[Paths] Config Directory: ${config}`);
  console.log(`[Paths] Data Directory: ${data} (${isPrimary ? 'ProgramData' : 'LocalAppData Fallback'})`);
  console.log(`[Paths] All subfolders verified/created.`);
}

module.exports = {
  getPhpPath,
  getMysqlPaths,
  isPackaged,
  getPortableDir,
  getBackendDir,
  getDatabaseDir,
  getJavaPath,
  getQZTrayPath,
  getConfigDir,
  getPrimaryDataDir,
  getFallbackDataDir,
  getDataDir,
  getLogsDir,
  getTempDir,
  getBackupsDir,
  getMysqlDataDir,
  getRuntimeMetadataPath,
  getRuntimePortsPath,
  getEnvPath,
  getRecoveryAuthPath,
  ensureRuntimeDirs
};
