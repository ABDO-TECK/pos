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
    return path.join(app.getAppPath(), 'backend');
  }
  return path.join(__dirname, '..', '..', 'backend');
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
    return {
      mysqldPath: portableMysqld,
      mysqlPath: path.join(portableDir, 'mysql', 'bin', 'mysql.exe'),
      dataDir: path.join(portableDir, 'mysql', 'data'),
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

module.exports = { getPhpPath, getMysqlPaths, isPackaged, getPortableDir, getBackendDir };
