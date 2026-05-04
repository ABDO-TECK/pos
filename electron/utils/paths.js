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
    const userDataPath = app.getPath('userData');
    const dbDataPath = path.join(userDataPath, 'mysql_data');

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

module.exports = { getPhpPath, getMysqlPaths, isPackaged, getPortableDir, getBackendDir, getDatabaseDir, getJavaPath, getQZTrayPath };
