const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { app } = require('electron');
const { getRuntimeMetadataPath, getDataDir, getConfigDir, getTempDir, getLogsDir, getBackupsDir, getMysqlDataDir } = require('./paths');

// Configurable limit: default 2GB
const MYSQL_PREFLIGHT_BACKUP_MAX_BYTES = 2 * 1024 * 1024 * 1024; // 2GB

/**
 * Resolves the current application version from version.json or app.getVersion() fallback.
 */
function readAppVersion() {
  try {
    const versionPath = path.join(app.getAppPath(), 'version.json');
    if (fs.existsSync(versionPath)) {
      const data = fs.readFileSync(versionPath, 'utf8');
      const json = JSON.parse(data);
      if (json && json.version) {
        return json.version;
      }
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to read version.json:', err.message);
  }
  return app.getVersion();
}

/**
 * Writes the runtime metadata registry.
 */
function writeRuntimeMetadata(metadata) {
  try {
    const metadataPath = getRuntimeMetadataPath();
    fs.mkdirSync(path.dirname(metadataPath), { recursive: true });
    fs.writeFileSync(metadataPath, JSON.stringify(metadata, null, 2), 'utf8');
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to write metadata file:', err.message);
    throw err;
  }
}

/**
 * Initializes the default runtime metadata structure.
 */
function ensureMetadataInitialized(currentVersion) {
  const metadataPath = getRuntimeMetadataPath();
  
  if (fs.existsSync(metadataPath)) {
    try {
      const data = fs.readFileSync(metadataPath, 'utf8');
      return JSON.parse(data);
    } catch (e) {
      // If corrupt, readRuntimeMetadata will handle backup and reinit
    }
  }

  const initialMetadata = {
    appVersion: currentVersion,
    lastSuccessfulVersion: currentVersion,
    migrationState: 'idle',
    dataDir: getDataDir(),
    configDir: getConfigDir(),
    archivedPaths: [],
    foundationSafeModeOnly: true,
    realDataMigrationPerformed: false,
    safeFileMigrationPerformed: false,
    mysqlDataMigrationPerformed: false,
    fileMigrations: {
      env: { status: 'skipped', from: null, to: null, timestamp: null },
      logs: { status: 'skipped', filesCopied: 0, timestamp: null },
      backups: { status: 'skipped', filesCopied: 0, timestamp: null },
      cache: { status: 'skipped', filesCopied: 0, timestamp: null }
    },
    events: [
      {
        timestamp: new Date().toISOString(),
        phase: 'init',
        message: 'Initial deployment completed successfully.',
        details: { mode: 'foundation-only' }
      }
    ]
  };

  try {
    writeRuntimeMetadata(initialMetadata);
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to write initial metadata:', err.message);
  }

  return initialMetadata;
}

/**
 * Helper to record event on a metadata object direct.
 */
function recordMigrationEventDirect(metadata, event) {
  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });
}

/**
 * Reads the runtime metadata file. Handles corruption recovery by making a backup.
 */
function readRuntimeMetadata() {
  const metadataPath = getRuntimeMetadataPath();
  if (!fs.existsSync(metadataPath)) {
    return null;
  }
  try {
    const data = fs.readFileSync(metadataPath, 'utf8');
    return JSON.parse(data);
  } catch (err) {
    console.error('[RuntimeMigrator] Metadata file is corrupt:', err.message);
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const corruptPath = path.join(getConfigDir(), `runtime_metadata.corrupt.${timestamp}.json`);
    try {
      fs.copyFileSync(metadataPath, corruptPath);
      fs.unlinkSync(metadataPath);
      console.log(`[RuntimeMigrator] Backed up corrupt metadata to: ${corruptPath}`);
    } catch (copyErr) {
      console.error('[RuntimeMigrator] Failed to backup corrupt metadata:', copyErr.message);
    }
    
    // Reinitialize metadata
    const currentVersion = readAppVersion();
    const cleanMetadata = ensureMetadataInitialized(currentVersion);
    
    recordMigrationEventDirect(cleanMetadata, {
      phase: 'failed',
      message: `Recovered from corrupt metadata. Backup saved as ${path.basename(corruptPath)}`,
      details: { error: err.message }
    });
    
    writeRuntimeMetadata(cleanMetadata);
    return cleanMetadata;
  }
}

/**
 * Records migration events in the metadata registry.
 */
function recordMigrationEvent(event) {
  const metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  
  // Idempotency: Do not duplicate consecutive identical events
  const lastEvent = metadata.events[metadata.events.length - 1];
  if (lastEvent && lastEvent.phase === event.phase && lastEvent.message === event.message) {
    return;
  }

  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });

  writeRuntimeMetadata(metadata);
}

/**
 * Checks if a migration is required.
 */
function needsMigration(currentVersion, metadata) {
  if (!metadata) return true;
  return metadata.lastSuccessfulVersion !== currentVersion || metadata.appVersion !== currentVersion;
}

/**
 * Returns migration state status for APIs.
 */
function getMigrationStatus() {
  const metadata = readRuntimeMetadata();
  if (!metadata) {
    return {
      status: 'pending',
      state: 'pending',
      needsMigration: true,
      lastSuccessfulVersion: null,
      appVersion: readAppVersion()
    };
  }
  const currentVersion = readAppVersion();
  return {
    status: metadata.migrationState === 'committed' || metadata.migrationState === 'idle' ? 'ok' : 'pending',
    state: metadata.migrationState,
    needsMigration: needsMigration(currentVersion, metadata),
    lastSuccessfulVersion: metadata.lastSuccessfulVersion,
    appVersion: metadata.appVersion
  };
}

/**
 * Recursive idempotent copy directory helper.
 */
function copyDirectorySafe(src, dest) {
  if (!fs.existsSync(src)) {
    throw new Error(`Source path does not exist: ${src}`);
  }
  
  const stats = fs.statSync(src);
  if (stats.isDirectory()) {
    if (!fs.existsSync(dest)) {
      fs.mkdirSync(dest, { recursive: true });
    }
    const entries = fs.readdirSync(src);
    for (const entry of entries) {
      copyDirectorySafe(path.join(src, entry), path.join(dest, entry));
    }
  } else {
    if (fs.existsSync(dest)) {
      const srcStats = fs.statSync(src);
      const destStats = fs.statSync(dest);
      if (srcStats.size === destStats.size) {
        return; // Already copied
      }
    }
    fs.copyFileSync(src, dest);
  }
}

/**
 * Compares structure and file sizes of two directories.
 */
function verifyDirectoryCopy(src, dest) {
  if (!fs.existsSync(src) || !fs.existsSync(dest)) {
    return false;
  }
  
  const srcStats = fs.statSync(src);
  const destStats = fs.statSync(dest);
  
  if (srcStats.isDirectory() !== destStats.isDirectory()) {
    return false;
  }
  
  if (!srcStats.isDirectory()) {
    return srcStats.size === destStats.size;
  }
  
  const srcEntries = fs.readdirSync(src);
  const destEntries = fs.readdirSync(dest);
  
  for (const entry of srcEntries) {
    if (!destEntries.includes(entry)) {
      return false;
    }
    const entryVerified = verifyDirectoryCopy(
      path.join(src, entry),
      path.join(dest, entry)
    );
    if (!entryVerified) {
      return false;
    }
  }
  
  return true;
}

/**
 * Moves file/directory to a timestamped archive path.
 */
function archiveOldPath(src) {
  if (!fs.existsSync(src)) return null;
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const archivePath = `${src}.archive.${timestamp}`;
  try {
    fs.renameSync(src, archivePath);
    
    const metadata = readRuntimeMetadata();
    if (metadata) {
      if (!metadata.archivedPaths) {
        metadata.archivedPaths = [];
      }
      metadata.archivedPaths.push(archivePath);
      writeRuntimeMetadata(metadata);
    }
    return archivePath;
  } catch (err) {
    console.error(`[RuntimeMigrator] Failed to archive path: ${src}`, err.message);
    throw err;
  }
}

/**
 * Checks if path is locked by trying to open files.
 */
function isPathLocked(pathStr) {
  if (!fs.existsSync(pathStr)) return false;
  
  try {
    const stats = fs.statSync(pathStr);
    if (stats.isDirectory()) {
      const entries = fs.readdirSync(pathStr);
      for (const entry of entries) {
        if (isPathLocked(path.join(pathStr, entry))) {
          return true;
        }
      }
      return false;
    } else {
      const fd = fs.openSync(pathStr, 'r+');
      fs.closeSync(fd);
      return false;
    }
  } catch (err) {
    if (err.code === 'EBUSY' || err.code === 'EPERM' || err.code === 'EACCES') {
      return true;
    }
    return false;
  }
}

/**
 * Handler 1: Migrate env file from legacy paths.
 */
function migrateEnvFile(metadata) {
  const destDir = getConfigDir();
  const destPath = path.join(destDir, '.env');
  
  let sourcePath = null;
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const envSources = [
    path.join(legacyAppDataDir, '.env'),
    path.join(unpackedBackendDir, '.env'),
    path.join(devBackendDir, '.env')
  ];

  for (const src of envSources) {
    if (fs.existsSync(src)) {
      sourcePath = src;
      break;
    }
  }

  if (!sourcePath) {
    metadata.fileMigrations.env = {
      status: 'skipped',
      from: null,
      to: destPath,
      timestamp: new Date().toISOString()
    };
    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: 'env_skipped: No legacy .env file found to migrate.',
      details: {}
    });
    return;
  }

  try {
    if (!fs.existsSync(destPath)) {
      fs.mkdirSync(destDir, { recursive: true });
      fs.copyFileSync(sourcePath, destPath);
      
      const srcStats = fs.statSync(sourcePath);
      const destStats = fs.statSync(destPath);
      if (srcStats.size !== destStats.size) {
        throw new Error('Verification failed: Size mismatch');
      }

      metadata.fileMigrations.env = {
        status: 'migrated',
        from: sourcePath,
        to: destPath,
        timestamp: new Date().toISOString()
      };
      
      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: 'env_migrated: Successfully copied .env file to config folder.',
        details: { from: sourcePath, to: destPath }
      });
    } else {
      const srcStats = fs.statSync(sourcePath);
      const destStats = fs.statSync(destPath);
      if (srcStats.size === destStats.size) {
        metadata.fileMigrations.env = {
          status: 'skipped',
          from: sourcePath,
          to: destPath,
          timestamp: new Date().toISOString()
        };
        recordMigrationEventDirect(metadata, {
          phase: 'check',
          message: 'env_skipped: Destination .env already exists and matches legacy file size.',
          details: { from: sourcePath, to: destPath }
        });
        return;
      }
      
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
      const conflictPath = path.join(destDir, `.env.legacy.${timestamp}`);
      fs.copyFileSync(sourcePath, conflictPath);
      
      metadata.fileMigrations.env = {
        status: 'migrated_with_conflict_copy',
        from: sourcePath,
        to: conflictPath,
        timestamp: new Date().toISOString()
      };

      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: `env_conflict_copy_created: Active .env already exists. Created legacy backup copy at ${path.basename(conflictPath)}.`,
        details: { from: sourcePath, to: conflictPath }
      });
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to migrate .env:', err.message);
    metadata.fileMigrations.env = {
      status: 'failed',
      from: sourcePath,
      to: destPath,
      timestamp: new Date().toISOString()
    };
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `env_failed: Failed to migrate env file: ${err.message}`,
      details: { from: sourcePath, to: destPath }
    });
  }
}

/**
 * Handler 2: Migrate logs from legacy paths.
 */
function migrateLogs(metadata) {
  const destDir = getLogsDir();
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const logSources = [
    path.join(legacyAppDataDir, 'logs'),
    path.join(unpackedBackendDir, 'logs'),
    path.join(devBackendDir, 'logs')
  ];

  let filesCopied = 0;
  let hasConflicts = false;
  let anyFound = false;

  for (const srcDir of logSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      const files = fs.readdirSync(srcDir);
      for (const file of files) {
        const srcPath = path.join(srcDir, file);
        if (fs.statSync(srcPath).isFile()) {
          let destPath = path.join(destDir, file);
          try {
            if (fs.existsSync(destPath)) {
              const srcSize = fs.statSync(srcPath).size;
              const destSize = fs.statSync(destPath).size;
              if (srcSize === destSize) {
                continue;
              }
              hasConflicts = true;
              const ext = path.extname(file);
              const base = path.basename(file, ext);
              const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
              destPath = path.join(destDir, `${base}.legacy.${timestamp}${ext}`);
            }

            fs.copyFileSync(srcPath, destPath);
            filesCopied++;
          } catch (err) {
            console.error(`[RuntimeMigrator] Failed to copy log ${file}:`, err.message);
          }
        }
      }
    }
  }

  metadata.fileMigrations.logs = {
    status: !anyFound ? 'skipped' : (hasConflicts ? 'conflict' : 'migrated'),
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `logs_migrated: Successfully copied ${filesCopied} log files.` : 'logs_skipped: No log files required migration.',
    details: { filesCopied, hasConflicts }
  });
}

/**
 * Handler 3: Migrate backups from legacy paths.
 */
function migrateBackups(metadata) {
  const destDir = getBackupsDir();
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const backupSources = [
    path.join(legacyAppDataDir, 'backups'),
    path.join(unpackedBackendDir, 'backups'),
    path.join(devBackendDir, 'backups')
  ];

  let filesCopied = 0;
  let hasConflicts = false;
  let anyFound = false;

  for (const srcDir of backupSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      const files = fs.readdirSync(srcDir);
      for (const file of files) {
        const srcPath = path.join(srcDir, file);
        if (fs.statSync(srcPath).isFile()) {
          let destPath = path.join(destDir, file);
          try {
            if (fs.existsSync(destPath)) {
              const srcSize = fs.statSync(srcPath).size;
              const destSize = fs.statSync(destPath).size;
              if (srcSize === destSize) {
                continue;
              }
              hasConflicts = true;
              const ext = path.extname(file);
              const base = path.basename(file, ext);
              const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
              destPath = path.join(destDir, `${base}.legacy.${timestamp}${ext}`);
            }

            fs.copyFileSync(srcPath, destPath);
            filesCopied++;
          } catch (err) {
            console.error(`[RuntimeMigrator] Failed to copy backup ${file}:`, err.message);
          }
        }
      }
    }
  }

  metadata.fileMigrations.backups = {
    status: !anyFound ? 'skipped' : (hasConflicts ? 'conflict' : 'migrated'),
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `backups_migrated: Successfully copied ${filesCopied} database backup files.` : 'backups_skipped: No database backups found to migrate.',
    details: { filesCopied, hasConflicts }
  });
}

/**
 * Handler 4: Migrate sqlite/cache files from legacy paths, excluding mysql.
 */
function migrateCacheRuntimeFiles(metadata) {
  const destDir = path.join(getTempDir(), 'runtime-cache');
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const cacheSources = [
    path.join(legacyAppDataDir, 'storage'),
    path.join(unpackedBackendDir, 'storage'),
    path.join(devBackendDir, 'storage')
  ];

  let filesCopied = 0;
  let anyFound = false;

  for (const srcDir of cacheSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      
      function copyCacheFilesRecursive(currentSrc, currentDest) {
        const entries = fs.readdirSync(currentSrc, { withFileTypes: true });
        for (const entry of entries) {
          const entryPath = path.join(currentSrc, entry.name);
          
          if (entry.isDirectory()) {
            const nameLower = entry.name.toLowerCase();
            if (
              nameLower.includes('mysql') || 
              nameLower.includes('database') || 
              nameLower.includes('db_data') || 
              nameLower.includes('data')
            ) {
              continue;
            }
            const nextDest = path.join(currentDest, entry.name);
            copyCacheFilesRecursive(entryPath, nextDest);
          } else {
            const extLower = path.extname(entry.name).toLowerCase();
            if (['.sqlite', '.sqlite3', '.db', '.cache'].includes(extLower) || entry.name.includes('cache') || entry.name.includes('limit')) {
              if (!fs.existsSync(currentDest)) {
                fs.mkdirSync(currentDest, { recursive: true });
              }
              const destFilePath = path.join(currentDest, entry.name);
              
              if (fs.existsSync(destFilePath)) {
                const srcSize = fs.statSync(entryPath).size;
                const destSize = fs.statSync(destFilePath).size;
                if (srcSize === destSize) {
                  continue;
                }
              }
              
              fs.copyFileSync(entryPath, destFilePath);
              filesCopied++;
            }
          }
        }
      }
      
      try {
        copyCacheFilesRecursive(srcDir, destDir);
      } catch (err) {
        console.error(`[RuntimeMigrator] Error traversing cache folder ${srcDir}:`, err.message);
      }
    }
  }

  metadata.fileMigrations.cache = {
    status: !anyFound ? 'skipped' : 'migrated',
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `cache_migrated: Successfully copied ${filesCopied} cache/sqlite runtime files.` : 'cache_skipped: No cache/sqlite files found to migrate.',
    details: { filesCopied }
  });
}

/**
 * Safe runtime file migration orchestration.
 */
function runSafeFileMigrations(metadata) {
  if (!metadata.fileMigrations) {
    metadata.fileMigrations = {};
  }
  
  recordMigrationEventDirect(metadata, {
    phase: 'init',
    message: 'file_migration_started: Safe file migration sequence started.',
    details: {}
  });

  try {
    migrateEnvFile(metadata);
    migrateLogs(metadata);
    migrateBackups(metadata);
    migrateCacheRuntimeFiles(metadata);

    metadata.safeFileMigrationPerformed = true;
    metadata.mysqlDataMigrationPerformed = false;
    metadata.realDataMigrationPerformed = false;

    recordMigrationEventDirect(metadata, {
      phase: 'commit',
      message: 'file_migration_completed: Safe file migration sequence completed successfully.',
      details: {
        fileMigrations: {
          env: metadata.fileMigrations.env?.status,
          logs: metadata.fileMigrations.logs?.status,
          backups: metadata.fileMigrations.backups?.status,
          cache: metadata.fileMigrations.cache?.status
        }
      }
    });
    
    writeRuntimeMetadata(metadata);
    console.log('[RuntimeMigrator] Safe file migrations completed successfully.');
  } catch (err) {
    console.error('[RuntimeMigrator] Safe file migration failed:', err.message);
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `file_migration_failed: Safe file migration sequence failed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 10: MySQL Preflight & Backup Foundation Utilities
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Checks list of potential legacy mysql_data directories.
 */
function detectLegacyMysqlDataDirs() {
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const potentialPaths = [
    path.join(legacyAppDataDir, 'mysql_data'),
    path.join(legacyAppDataDir, 'mysql'),
    path.join(unpackedBackendDir, 'mysql_data'),
    path.join(devBackendDir, 'mysql_data')
  ];

  const activeMysqlDir = getMysqlDataDir();
  const normalizedActive = activeMysqlDir ? path.normalize(activeMysqlDir).toLowerCase() : '';

  const found = [];
  for (const p of potentialPaths) {
    if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
      const normalizedP = path.normalize(p).toLowerCase();
      if (normalizedP === normalizedActive) {
        continue;
      }
      found.push(p);
    }
  }
  return found;
}

/**
 * Validates if a folder looks like a MySQL data directory.
 */
function validateMysqlDataDir(dirPath) {
  if (!fs.existsSync(dirPath) || !fs.statSync(dirPath).isDirectory()) {
    return false;
  }
  
  try {
    const files = fs.readdirSync(dirPath);
    
    const hasIbdata = files.includes('ibdata1');
    const hasAutoCnf = files.includes('auto.cnf');
    const hasMysqlSubdir = files.some(f => f.toLowerCase() === 'mysql' && fs.statSync(path.join(dirPath, f)).isDirectory());
    const hasPerfSchema = files.some(f => f.toLowerCase() === 'performance_schema' && fs.statSync(path.join(dirPath, f)).isDirectory());
    const hasIbLogfile = files.some(f => f.startsWith('ib_logfile'));
    
    let score = 0;
    if (hasIbdata) score++;
    if (hasAutoCnf) score++;
    if (hasMysqlSubdir) score++;
    if (hasPerfSchema) score++;
    if (hasIbLogfile) score++;

    return score >= 2;
  } catch (err) {
    return false;
  }
}

function detectRunningMysqldProcesses() {
  if (process.platform !== 'win32') return [];
  
  const found = [];
  
  // 1. Try PowerShell Get-CimInstance to retrieve full cmdLine
  try {
    const cmd = `powershell -Command "Get-CimInstance Win32_Process -Filter \\"name='mysqld.exe' or name='mariadbd.exe'\\" | Select-Object Name, CommandLine | ConvertTo-Json"`;
    const output = execSync(cmd, { encoding: 'utf8', windowsHide: true, timeout: 3000 }).trim();
    if (output) {
      const parsed = JSON.parse(output);
      const list = Array.isArray(parsed) ? parsed : [parsed];
      for (const item of list) {
        if (item && item.Name) {
          const match = item.CommandLine ? item.CommandLine.match(/--datadir=(?:"([^"]+)"|([^\s]+))/) : null;
          found.push({
            name: item.Name,
            commandLine: item.CommandLine || null,
            dataDir: match ? (match[1] || match[2]) : null
          });
        }
      }
      return found;
    }
  } catch (err) {
    // Ignore and fallback to wmic
  }
 
  // 2. Try wmic fallback
  try {
    const output = execSync('wmic process where "name=\'mysqld.exe\' or name=\'mariadbd.exe\'" get name,commandline /format:list', { encoding: 'utf8', windowsHide: true, timeout: 3000 }).trim();
    if (output) {
      const blocks = output.split(/\r?\n\r?\n/);
      for (const block of blocks) {
        const lines = block.split(/\r?\n/);
        let name = null;
        let commandLine = null;
        for (const line of lines) {
          if (line.startsWith('CommandLine=')) commandLine = line.substring(12);
          if (line.startsWith('Name=')) name = line.substring(5);
        }
        if (name) {
          const match = commandLine ? commandLine.match(/--datadir=(?:"([^"]+)"|([^\s]+))/) : null;
          found.push({
            name,
            commandLine,
            dataDir: match ? (match[1] || match[2]) : null
          });
        }
      }
      if (found.length > 0) return found;
    }
  } catch (err) {
    // Ignore and fallback to tasklist
  }

  // 3. Fallback to tasklist
  try {
    const output = execSync('tasklist', { encoding: 'utf8', windowsHide: true });
    if (output.toLowerCase().includes('mysqld.exe')) {
      found.push({ name: 'mysqld.exe', commandLine: null, dataDir: null });
    }
    if (output.toLowerCase().includes('mariadbd.exe')) {
      found.push({ name: 'mariadbd.exe', commandLine: null, dataDir: null });
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Fallback tasklist failed:', err.message);
  }

  return found;
}

/**
 * Checks safe read locks on critical MySQL files without locking or truncating.
 */
function detectMysqlLockState(dirPath) {
  if (!fs.existsSync(dirPath)) return 'none';

  try {
    const filesToCheck = ['ibdata1', 'auto.cnf', 'ib_logfile0', 'ib_logfile1'];
    let isLocked = false;
    let isAccessDenied = false;
    let isUnknown = false;

    for (const f of filesToCheck) {
      const filePath = path.join(dirPath, f);
      if (fs.existsSync(filePath)) {
        try {
          const fd = fs.openSync(filePath, 'r');
          fs.closeSync(fd);
        } catch (err) {
          if (err.code === 'EBUSY') {
            isLocked = true;
          } else if (err.code === 'EACCES' || err.code === 'EPERM') {
            isAccessDenied = true;
          } else {
            isUnknown = true;
          }
        }
      }
    }

    const runningProcesses = detectRunningMysqldProcesses();
    if (runningProcesses.length > 0) {
      let datadirMatched = false;
      let undeterminedDatadir = false;
      
      const normalizedDir = path.normalize(dirPath).toLowerCase();
      for (const proc of runningProcesses) {
        if (proc.dataDir) {
          const normalizedProcDir = path.normalize(proc.dataDir).toLowerCase();
          if (normalizedProcDir === normalizedDir) {
            datadirMatched = true;
          }
        } else {
          undeterminedDatadir = true;
        }
      }
      
      if (datadirMatched || undeterminedDatadir) {
        return 'process_detected';
      }
    }

    if (isLocked) return 'locked';
    if (isAccessDenied) return 'access_denied';
    if (isUnknown) return 'unknown_lock_state';

    return 'none';
  } catch (err) {
    return 'unknown_lock_state';
  }
}

/**
 * Recursively sums directory size in bytes.
 */
function getDirSizeBytes(dirPath) {
  let total = 0;
  const files = fs.readdirSync(dirPath);
  for (const file of files) {
    const filePath = path.join(dirPath, file);
    const stat = fs.statSync(filePath);
    if (stat.isDirectory()) {
      total += getDirSizeBytes(filePath);
    } else {
      total += stat.size;
    }
  }
  return total;
}

/**
 * Checks if a legacy folder is valid and safe to replicate.
 */
function isMysqlDataSafeToCopy(dirPath, options = {}) {
  if (!validateMysqlDataDir(dirPath)) {
    return { safe: false, reason: 'invalid_mysql_structure' };
  }

  const lockState = detectMysqlLockState(dirPath);
  if (lockState !== 'none') {
    const reasonMap = {
      'locked': 'locked',
      'access_denied': 'access_denied',
      'process_detected': 'process_detected_copy_skipped',
      'unknown_lock_state': 'unknown_lock_state'
    };
    return { safe: false, reason: reasonMap[lockState] || 'unknown_lock_state' };
  }

  const maxBytes = options.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES !== undefined 
    ? options.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES 
    : (process.env.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES 
        ? parseInt(process.env.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES, 10) 
        : MYSQL_PREFLIGHT_BACKUP_MAX_BYTES);

  try {
    const sizeBytes = getDirSizeBytes(dirPath);
    if (sizeBytes > maxBytes) {
      return { safe: false, reason: 'backup_skipped_size_limit', sizeBytes };
    }
    return { safe: true, reason: 'none', sizeBytes };
  } catch (err) {
    return { safe: false, reason: 'failed', message: err.message };
  }
}

/**
 * Copies MySQL database folders to preflight backup location.
 */
function createMysqlPreMigrationBackup(srcPath, backupRoot) {
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const destPath = path.join(backupRoot, 'mysql-preflight', timestamp);

  try {
    fs.mkdirSync(destPath, { recursive: true });
    copyDirectorySafe(srcPath, destPath);
    return destPath;
  } catch (err) {
    console.error(`[RuntimeMigrator] Preflight backup copy failed:`, err.message);
    throw err;
  }
}

/**
 * Verifies copied files count and size.
 */
function verifyMysqlBackupCopy(srcPath, backupPath) {
  if (!fs.existsSync(srcPath) || !fs.existsSync(backupPath)) {
    return false;
  }

  function getFileInfoRecursive(dir) {
    let count = 0;
    let size = 0;
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        const info = getFileInfoRecursive(entryPath);
        count += info.count;
        size += info.size;
      } else {
        count++;
        size += fs.statSync(entryPath).size;
      }
    }
    return { count, size };
  }

  const srcInfo = getFileInfoRecursive(srcPath);
  const destInfo = getFileInfoRecursive(backupPath);

  if (srcInfo.count !== destInfo.count || srcInfo.size !== destInfo.size) {
    return false;
  }

  const criticalFiles = ['ibdata1', 'auto.cnf'];
  for (const cf of criticalFiles) {
    const srcCf = path.join(srcPath, cf);
    const destCf = path.join(backupPath, cf);
    if (fs.existsSync(srcCf)) {
      if (!fs.existsSync(destCf)) return false;
      if (fs.statSync(srcCf).size !== fs.statSync(destCf).size) return false;
    }
  }

  return true;
}

/**
 * Safe Preflight check orchestration.
 */
function runMysqlMigrationPreflight(metadata, options = {}) {
  if (!metadata.mysqlMigration) {
    metadata.mysqlMigration = {
      phase: 'preflight',
      status: 'not_started',
      activeMigrationPerformed: false,
      mysqlDataMigrationPerformed: false,
      activeMysqlPathChanged: false,
      candidates: [],
      backup: {
        path: null,
        status: 'skipped',
        timestamp: null,
        sizeBytes: 0
      },
      lastError: null
    };
  }

  const status = metadata.mysqlMigration.status;
  const force = !!options.forceMysqlPreflight;
  
  if ((status === 'backup_verified' || status === 'skipped') && !force) {
    console.log('[RuntimeMigrator] MySQL preflight already verified or skipped. Skipping.');
    return;
  }

  recordMigrationEventDirect(metadata, {
    phase: 'check',
    message: 'mysql_preflight_started: MySQL migration preflight checklist started.',
    details: {}
  });

  try {
    const foundCandidates = detectLegacyMysqlDataDirs();
    metadata.mysqlMigration.candidates = [];

    if (foundCandidates.length === 0) {
      metadata.mysqlMigration.status = 'skipped';
      metadata.mysqlMigration.backup.status = 'skipped';
      metadata.mysqlMigration.backup.timestamp = new Date().toISOString();
      
      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: 'mysql_candidate_skipped: No legacy MySQL directories detected.',
        details: {}
      });
      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: 'mysql_preflight_completed: Preflight complete. Skipping backup.',
        details: {}
      });
      writeRuntimeMetadata(metadata);
      return;
    }

    let chosenCandidate = null;
    let chosenSafeResult = null;

    for (const c of foundCandidates) {
      const isValid = validateMysqlDataDir(c);
      const lockState = detectMysqlLockState(c);
      const isLocked = (lockState !== 'none');
      
      let sizeBytes = 0;
      let reason = 'none';
      if (isValid) {
        try { sizeBytes = getDirSizeBytes(c); } catch (e) {}
      } else {
        reason = 'invalid_mysql_structure';
      }

      const candidateObj = {
        path: c,
        valid: isValid,
        locked: isLocked,
        lockState: lockState,
        sizeBytes: sizeBytes,
        reason: reason
      };

      metadata.mysqlMigration.candidates.push(candidateObj);

      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: isValid ? `mysql_candidate_detected: Found valid candidate at ${path.basename(c)}.` : `mysql_candidate_skipped: Invalid MySQL candidate structure at ${path.basename(c)}.`,
        details: { path: c, lockState, sizeBytes }
      });

      if (isValid && !chosenCandidate) {
        const safeResult = isMysqlDataSafeToCopy(c, options);
        if (safeResult.safe) {
          chosenCandidate = c;
          chosenSafeResult = safeResult;
        } else {
          metadata.mysqlMigration.status = safeResult.reason;
          metadata.mysqlMigration.candidates[metadata.mysqlMigration.candidates.length - 1].reason = safeResult.reason;
          
          if (safeResult.reason === 'backup_skipped_size_limit') {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: `mysql_backup_skipped_size_limit: Candidate exceeds size limit. Candidate: ${safeResult.sizeBytes} bytes.`,
              details: { path: c, sizeBytes: safeResult.sizeBytes }
            });
          } else if (safeResult.reason === 'process_detected_copy_skipped') {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: 'mysql_process_detected: Active database process running. Preflight skipped.',
              details: { path: c }
            });
          } else {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: `mysql_lock_detected: Database files locked or access denied. State: ${safeResult.reason}.`,
              details: { path: c, lockState: safeResult.reason }
            });
          }
        }
      }
    }

    if (chosenCandidate && chosenSafeResult) {
      metadata.mysqlMigration.status = 'candidate_found';
      writeRuntimeMetadata(metadata);

      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: `mysql_backup_started: Copying valid candidate database files for preflight verification.`,
        details: { path: chosenCandidate }
      });

      const backupPath = createMysqlPreMigrationBackup(chosenCandidate, getBackupsDir());
      
      metadata.mysqlMigration.status = 'backup_created';
      metadata.mysqlMigration.backup.path = backupPath;
      metadata.mysqlMigration.backup.status = 'created';
      metadata.mysqlMigration.backup.timestamp = new Date().toISOString();
      metadata.mysqlMigration.backup.sizeBytes = chosenSafeResult.sizeBytes;
      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: 'mysql_backup_created: Preflight backup copy created successfully.',
        details: { backupPath, sizeBytes: chosenSafeResult.sizeBytes }
      });
      writeRuntimeMetadata(metadata);

      const isVerified = verifyMysqlBackupCopy(chosenCandidate, backupPath);
      if (isVerified) {
        metadata.mysqlMigration.status = 'backup_verified';
        metadata.mysqlMigration.backup.status = 'verified';
        recordMigrationEventDirect(metadata, {
          phase: 'verify',
          message: `mysql_backup_verified: Preflight backup verified successfully. File counts and sizes match exactly.`,
          details: { backupPath }
        });
      } else {
        metadata.mysqlMigration.status = 'failed';
        metadata.mysqlMigration.backup.status = 'failed';
        metadata.mysqlMigration.lastError = 'Backup verification size or count mismatch';
        recordMigrationEventDirect(metadata, {
          phase: 'failed',
          message: `mysql_preflight_failed: Verification mismatch between source and preflight backup.`,
          details: { backupPath }
        });
      }
    } else {
      if (metadata.mysqlMigration.status === 'not_started') {
        metadata.mysqlMigration.status = 'skipped';
      }
    }

    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: `mysql_preflight_completed: Preflight sequence finished. Status: ${metadata.mysqlMigration.status}.`,
      details: { status: metadata.mysqlMigration.status }
    });
    
    writeRuntimeMetadata(metadata);
  } catch (err) {
    console.error('[RuntimeMigrator] MySQL preflight execution failed:', err.message);
    metadata.mysqlMigration.status = 'failed';
    metadata.mysqlMigration.lastError = err.message;
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_preflight_failed: Preflight crashed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
  }
}

/**
 * Core runtime migration function. Handles checks and safe dummy testing.
 */
function runRuntimeMigration(options = {}) {
  const safeMode = options.safeMode !== false;
  const dryRun = !!options.dryRun;
  const verbose = !!options.verbose;

  const currentVersion = readAppVersion();
  let metadata = readRuntimeMetadata();

  if (!metadata) {
    console.log('[RuntimeMigrator] First run detected. Initializing metadata.');
    metadata = ensureMetadataInitialized(currentVersion);
    runSafeFileMigrations(metadata);
    
    // Reload and run preflight on first boot as well
    metadata = readRuntimeMetadata();
    runMysqlMigrationPreflight(metadata, { ...options, backupOnly: true });
    return { success: true, message: 'Initialized metadata, performed file and mysql preflight checks' };
  }

  const needsMigrate = needsMigration(currentVersion, metadata);
  const safeFileNotRun = !metadata.safeFileMigrationPerformed;
  
  const forcePreflight = !!options.forceMysqlPreflight;
  const noMysqlStatus = !metadata.mysqlMigration || !metadata.mysqlMigration.status;
  const runPreflight = needsMigrate || noMysqlStatus || forcePreflight;

  if (!needsMigrate && !safeFileNotRun && !runPreflight) {
    if (verbose) {
      console.log(`[RuntimeMigrator] No migration needed. App version is ${currentVersion}.`);
      recordMigrationEvent({
        phase: 'check',
        message: `Version check passed. No migration required. App version: ${currentVersion}`,
        details: { verbose: true }
      });
    }
    return { success: true, message: 'Same version, no action taken' };
  }

  console.log(`[RuntimeMigrator] Migration needed. Registry version: ${metadata.appVersion}, Current version: ${currentVersion}, safeFileNotRun: ${safeFileNotRun}, runPreflight: ${runPreflight}`);
  
  if (dryRun) {
    console.log('[RuntimeMigrator] Dry-run enabled. Skipping migrations.');
    recordMigrationEvent({
      phase: 'check',
      message: `Dry run check: Migration needed.`,
      details: { dryRun: true }
    });
    return { success: true, message: 'Dry run completed' };
  }

  // Set state to pending
  metadata.migrationState = 'pending';
  metadata.appVersion = currentVersion;
  writeRuntimeMetadata(metadata);
  
  recordMigrationEvent({
    phase: 'check',
    message: `Migration initiated to version ${currentVersion}. State: pending.`,
    details: { currentVersion, previousVersion: metadata.lastSuccessfulVersion }
  });

  try {
    // Run safe file migrations first
    runSafeFileMigrations(metadata);

    // Reload metadata
    metadata = readRuntimeMetadata();

    // Run MySQL preflight foundation
    if (runPreflight) {
      runMysqlMigrationPreflight(metadata, { ...options, backupOnly: true });
      metadata = readRuntimeMetadata();
    }

    // Run controlled MySQL active migration if enabled (opt-in)
    const enableActive = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
    if (enableActive) {
      runControlledMysqlMigration(metadata, options);
      metadata = readRuntimeMetadata();
    }

    if (safeMode) {
      console.log('[RuntimeMigrator] Running safeMode dummy migration...');
      
      const tempDir = getTempDir();
      const dummySrc = path.join(tempDir, 'dummy_migrate_src');
      const dummyDest = path.join(tempDir, 'dummy_migrate_dest');

      // Clear any pre-existing dummy test data
      if (fs.existsSync(dummySrc)) {
        try { fs.rmSync(dummySrc, { recursive: true, force: true }); } catch (e) {}
      }
      if (fs.existsSync(dummyDest)) {
        try { fs.rmSync(dummyDest, { recursive: true, force: true }); } catch (e) {}
      }

      // Create test dummy structure
      fs.mkdirSync(dummySrc, { recursive: true });
      fs.writeFileSync(path.join(dummySrc, 'test1.txt'), 'content1', 'utf8');
      fs.mkdirSync(path.join(dummySrc, 'subdir'), { recursive: true });
      fs.writeFileSync(path.join(dummySrc, 'subdir', 'test2.txt'), 'content2', 'utf8');

      // Transition state: copying
      metadata.migrationState = 'copying';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'copy',
        message: 'Copying files to destination (dummy check)...',
        details: { dummySrc, dummyDest }
      });

      // Execute copy
      copyDirectorySafe(dummySrc, dummyDest);

      // Transition state: verified
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'verified';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'verify',
        message: 'Verifying copied files (dummy check)...',
        details: { dummySrc, dummyDest }
      });

      // Execute verification
      const isCopyOk = verifyDirectoryCopy(dummySrc, dummyDest);
      if (!isCopyOk) {
        throw new Error('Dummy directory verification failed. File mismatch.');
      }

      // Check path lock check on dummy
      const isLocked = isPathLocked(dummyDest);
      console.log(`[RuntimeMigrator] Dummy destination locked check: ${isLocked}`);

      // Archive dummy source
      const archivedSrc = archiveOldPath(dummySrc);
      console.log(`[RuntimeMigrator] Dummy source archived to: ${archivedSrc}`);

      // Clean up dummyDest
      try { fs.rmSync(dummyDest, { recursive: true, force: true }); } catch (e) {}

      // Transition state: committed
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'committed';
      metadata.lastSuccessfulVersion = currentVersion;
      metadata.foundationSafeModeOnly = true;
      metadata.realDataMigrationPerformed = false;
      metadata.safeFileMigrationPerformed = true;
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'commit',
        message: `Migration to version ${currentVersion} committed successfully (safe foundation dummy check). No production data was moved or deleted.`,
        details: {
          foundationSafeModeOnly: true,
          realDataMigrationPerformed: false,
          safeFileMigrationPerformed: true
        }
      });

      console.log(`[RuntimeMigrator] Safe migration dummy check completed successfully for version ${currentVersion}.`);
      return { success: true, message: 'Safe dummy migration committed' };
    } else {
      console.warn('[RuntimeMigrator] Real data migration requested but not supported in Phase 10.');
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'failed';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'failed',
        message: 'Real data migration skipped: not supported in Phase 10.',
        details: { safeMode }
      });
      return { success: false, message: 'Real data migration not supported in Phase 10' };
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Migration process failed:', err.message);
    metadata = readRuntimeMetadata();
    metadata.migrationState = 'failed';
    writeRuntimeMetadata(metadata);
    
    recordMigrationEvent({
      phase: 'failed',
      message: `Migration failed: ${err.message}`,
      details: { error: err.stack }
    });
    return { success: false, error: err.message };
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 11: Controlled MySQL Active Migration Execution
// ─────────────────────────────────────────────────────────────────────────────

function canRunMysqlActiveMigration(metadata, options = {}) {
  if (!metadata || !metadata.mysqlMigration) return false;
  
  const migrationEnabled = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
  if (!migrationEnabled) return false;

  const preflightStatus = metadata.mysqlMigration.status;
  const backupStatus = metadata.mysqlMigration.backup ? metadata.mysqlMigration.backup.status : 'skipped';
  
  if (preflightStatus !== 'backup_verified' || backupStatus !== 'verified') {
    return false;
  }

  if (metadata.mysqlMigration.mysqlDataMigrationPerformed || metadata.mysqlDataMigrationPerformed) {
    return false;
  }

  const validCandidate = metadata.mysqlMigration.candidates.find(c => c.valid === true);
  if (!validCandidate) return false;

  const lockState = module.exports.detectMysqlLockState(validCandidate.path);
  if (lockState !== 'none') return false;

  const runningProcesses = module.exports.detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) return false;

  return true;
}

function stopAppManagedMysqlIfRunning(options = {}) {
  // In Phase 11, runRuntimeMigration is called during app initialization (app.whenReady)
  // BEFORE startMySQL is called. Therefore, there is no app-managed MySQL process started yet.
  // We do not stop any external database or run taskkill/net stop commands.
  return true;
}

function copyMysqlDataToRuntime(src, dest, options = {}) {
  if (!fs.existsSync(src)) {
    throw new Error(`Source path does not exist: ${src}`);
  }
  
  const stats = fs.statSync(src);
  if (stats.isDirectory()) {
    if (!fs.existsSync(dest)) {
      fs.mkdirSync(dest, { recursive: true });
    }
    const entries = fs.readdirSync(src);
    for (const entry of entries) {
      const entryLower = entry.toLowerCase();
      if (entryLower.endsWith('.pid') || entryLower.endsWith('.err') || entryLower === 'ibtmp1') {
        continue;
      }
      copyMysqlDataToRuntime(path.join(src, entry), path.join(dest, entry), options);
    }
  } else {
    fs.copyFileSync(src, dest);
  }
}

function verifyRuntimeMysqlDataCopy(srcPath, destPath) {
  if (!fs.existsSync(srcPath) || !fs.existsSync(destPath)) {
    return false;
  }

  function getFileInfo(dir, skipTemp = true) {
    let count = 0;
    let size = 0;
    const filesList = [];
    
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const nameLower = entry.name.toLowerCase();
      if (skipTemp && (nameLower.endsWith('.pid') || nameLower.endsWith('.err') || nameLower === 'ibtmp1')) {
        continue;
      }
      
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        const info = getFileInfo(entryPath, skipTemp);
        count += info.count;
        size += info.size;
        filesList.push(...info.filesList);
      } else {
        count++;
        const s = fs.statSync(entryPath).size;
        size += s;
        filesList.push({ name: entry.name, size: s, path: entryPath });
      }
    }
    return { count, size, filesList };
  }

  const srcInfo = getFileInfo(srcPath, true);
  const destInfo = getFileInfo(destPath, false);

  if (srcInfo.count !== destInfo.count || srcInfo.size !== destInfo.size) {
    console.error(`[Verify] Mismatch count/size. Src: ${srcInfo.count}/${srcInfo.size}, Dest: ${destInfo.count}/${destInfo.size}`);
    return false;
  }

  const hasIbdata = fs.existsSync(path.join(destPath, 'ibdata1'));
  const hasAutoCnf = fs.existsSync(path.join(destPath, 'auto.cnf'));
  const hasMysqlDir = fs.existsSync(path.join(destPath, 'mysql')) && fs.statSync(path.join(destPath, 'mysql')).isDirectory();

  if (!hasIbdata || !hasAutoCnf || !hasMysqlDir) {
    console.error('[Verify] Critical files missing in destination');
    return false;
  }

  try {
    const crypto = require('crypto');
    const autoCnfSrc = path.join(srcPath, 'auto.cnf');
    const autoCnfDest = path.join(destPath, 'auto.cnf');
    if (fs.existsSync(autoCnfSrc) && fs.existsSync(autoCnfDest)) {
      const srcHash = crypto.createHash('sha256').update(fs.readFileSync(autoCnfSrc)).digest('hex');
      const destHash = crypto.createHash('sha256').update(fs.readFileSync(autoCnfDest)).digest('hex');
      if (srcHash !== destHash) {
        console.error('[Verify] Hash mismatch for auto.cnf');
        return false;
      }
    }
  } catch (hashErr) {
    console.warn('[Verify] Hash verification skipped due to error:', hashErr.message);
  }

  try {
    fs.accessSync(destPath, fs.constants.R_OK);
  } catch (accessErr) {
    console.error('[Verify] Destination is not readable:', accessErr.message);
    return false;
  }

  return true;
}

function writeMysqlMigrationCommit(metadata, result) {
  metadata.mysqlMigration = {
    phase: 'controlled_migration',
    status: 'migration_committed',
    activeMigrationPerformed: true,
    mysqlDataMigrationPerformed: true,
    activeMysqlPathChanged: true,
    sourcePath: result.sourcePath,
    activePath: result.activePath,
    preflightBackupPath: result.preflightBackupPath,
    rollbackAvailable: true,
    legacySourcePreserved: true,
    committedAt: new Date().toISOString(),
    lastError: null
  };
  
  metadata.mysqlDataMigrationPerformed = true;
  metadata.realDataMigrationPerformed = true;
  
  writeRuntimeMetadata(metadata);
}

function markMysqlMigrationRollbackAvailable(metadata, reason) {
  if (metadata.mysqlMigration) {
    metadata.mysqlMigration.rollbackAvailable = true;
    metadata.mysqlMigration.lastError = reason;
    writeRuntimeMetadata(metadata);
  }
}

function runControlledMysqlMigration(metadata, options = {}) {
  if (!metadata.mysqlMigration) {
    metadata.mysqlMigration = {
      phase: 'controlled_migration',
      status: 'not_started',
      activeMigrationPerformed: false,
      mysqlDataMigrationPerformed: false,
      activeMysqlPathChanged: false,
      candidates: [],
      backup: { path: null, status: 'skipped', timestamp: null, sizeBytes: 0 },
      lastError: null
    };
  }

  const migrationEnabled = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
  if (!migrationEnabled) {
    metadata.mysqlMigration.phase = 'controlled_migration';
    metadata.mysqlMigration.status = 'active_migration_not_enabled';
    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: 'mysql_active_migration_skipped: Active migration is not enabled via config or env.',
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: true, message: 'Active MySQL migration is not enabled' };
  }

  const preflightStatus = metadata.mysqlMigration.status;
  const backupStatus = metadata.mysqlMigration.backup ? metadata.mysqlMigration.backup.status : 'skipped';
  
  if (preflightStatus !== 'backup_verified' || backupStatus !== 'verified') {
    console.log('[RuntimeMigrator] Preflight check not verified. Skipping controlled migration.');
    return { success: false, reason: 'preflight_not_verified' };
  }

  if (metadata.mysqlMigration.mysqlDataMigrationPerformed || metadata.mysqlDataMigrationPerformed) {
    console.log('[RuntimeMigrator] MySQL migration already performed.');
    return { success: true, message: 'Already migrated' };
  }

  const validCandidate = metadata.mysqlMigration.candidates.find(c => c.valid === true);
  if (!validCandidate) {
    console.log('[RuntimeMigrator] No valid MySQL candidates found.');
    return { success: false, reason: 'no_valid_candidate' };
  }

  const lockState = module.exports.detectMysqlLockState(validCandidate.path);
  if (lockState !== 'none') {
    metadata.mysqlMigration.status = (lockState === 'process_detected' ? 'external_mysql_process_detected' : lockState);
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_active_migration_failed: Candidate is locked or external mysql process detected. lockState: ${lockState}`,
      details: { path: validCandidate.path, lockState }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: lockState === 'process_detected' ? 'external_mysql_process_detected' : lockState };
  }

  const runningProcesses = module.exports.detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    metadata.mysqlMigration.status = 'external_mysql_process_detected';
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: 'mysql_active_migration_failed: Running external MySQL process detected.',
      details: { runningProcesses }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'external_mysql_process_detected' };
  }

  const destDir = getMysqlDataDir();
  if (!fs.existsSync(destDir)) {
    fs.mkdirSync(destDir, { recursive: true });
  } else {
    try {
      const files = fs.readdirSync(destDir);
      if (files.length > 0) {
        metadata.mysqlMigration.status = 'destination_not_empty';
        recordMigrationEventDirect(metadata, {
          phase: 'failed',
          message: 'mysql_active_migration_failed: Destination directory is not empty. Overwrite prevented.',
          details: { destDir, files }
        });
        writeRuntimeMetadata(metadata);
        return { success: false, reason: 'destination_not_empty' };
      }
    } catch (err) {
      console.error('[RuntimeMigrator] Failed to read destination directory:', err.message);
      metadata.mysqlMigration.status = 'failed';
      metadata.mysqlMigration.lastError = `Destination read error: ${err.message}`;
      writeRuntimeMetadata(metadata);
      return { success: false, reason: 'failed' };
    }
  }

  recordMigrationEventDirect(metadata, {
    phase: 'controlled_migration',
    message: 'mysql_active_migration_started: Controlled MySQL active migration execution started.',
    details: { from: validCandidate.path, to: destDir }
  });

  try {
    module.exports.stopAppManagedMysqlIfRunning(options);

    module.exports.copyMysqlDataToRuntime(validCandidate.path, destDir, options);

    const isCopyOk = module.exports.verifyRuntimeMysqlDataCopy(validCandidate.path, destDir);
    if (!isCopyOk) {
      metadata.mysqlMigration.status = 'verify_failed';
      recordMigrationEventDirect(metadata, {
        phase: 'failed',
        message: 'mysql_active_migration_failed: Copy verification failed. Size or count mismatch.',
        details: { from: validCandidate.path, to: destDir }
      });
      writeRuntimeMetadata(metadata);
      return { success: false, reason: 'verify_failed' };
    }

    const commitResult = {
      sourcePath: validCandidate.path,
      activePath: destDir,
      preflightBackupPath: metadata.mysqlMigration.backup.path
    };

    module.exports.writeMysqlMigrationCommit(metadata, commitResult);

    recordMigrationEventDirect(metadata, {
      phase: 'controlled_migration',
      message: 'mysql_active_migration_completed: Controlled MySQL active migration completed successfully.',
      details: commitResult
    });

    console.log('[RuntimeMigrator] Controlled MySQL active migration completed successfully.');
    return { success: true };

  } catch (err) {
    console.error('[RuntimeMigrator] Controlled MySQL active migration crashed:', err.message);
    metadata.mysqlMigration.status = 'failed';
    metadata.mysqlMigration.lastError = err.message;
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_active_migration_failed: Migration crashed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, error: err.message };
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 12: Recovery Rollback Foundation
// ─────────────────────────────────────────────────────────────────────────────

function getRollbackReadiness(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return { available: false, reason: 'No mysqlMigration metadata found' };
  }

  const mm = metadata.mysqlMigration;
  if (mm.rollbackAvailable !== true) {
    return { available: false, reason: 'Rollback is not marked as available in metadata' };
  }

  if (!mm.sourcePath) {
    return { available: false, reason: 'sourcePath is missing from metadata' };
  }

  if (!mm.activePath) {
    return { available: false, reason: 'activePath is missing from metadata' };
  }

  if (!mm.preflightBackupPath) {
    return { available: false, reason: 'preflightBackupPath is missing from metadata' };
  }

  if (mm.legacySourcePreserved !== true) {
    return { available: false, reason: 'legacySourcePreserved is not marked as true in metadata' };
  }

  if (!fs.existsSync(mm.sourcePath)) {
    return { available: false, reason: `sourcePath directory does not exist physically: ${mm.sourcePath}` };
  }

  if (!fs.existsSync(mm.activePath)) {
    return { available: false, reason: `activePath directory does not exist physically: ${mm.activePath}` };
  }

  return {
    available: true,
    reason: 'rollback metadata available',
    sourcePath: mm.sourcePath,
    activePath: mm.activePath,
    preflightBackupPath: mm.preflightBackupPath,
    legacySourcePreserved: true,
    activeMysqlPathChanged: mm.activeMysqlPathChanged === true,
    mysqlDataMigrationPerformed: mm.mysqlDataMigrationPerformed === true
  };
}

function buildMysqlRollbackPlan(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return null;
  }
  const mm = metadata.mysqlMigration;
  const snapshotPathPlaceholder = path.join(getBackupsDir(), 'mysql-rollback-snapshot', 'YYYY-MM-DD-HH-MM-SS');
  return {
    currentActivePath: mm.activePath || '',
    rollbackSourceCandidate: mm.sourcePath || '',
    preflightBackupPath: mm.preflightBackupPath || '',
    snapshotPath: snapshotPathPlaceholder,
    actions: [
      'verify rollback source',
      'verify active path',
      'create pre-rollback snapshot',
      'copy rollback source to active path or restore strategy',
      'verify restored active path',
      'update metadata'
    ]
  };
}

function validateMysqlRollbackSources(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return { valid: false, reason: 'No mysqlMigration metadata found' };
  }
  const mm = metadata.mysqlMigration;
  const src = mm.sourcePath;
  const active = mm.activePath;

  if (!src || !fs.existsSync(src)) {
    return { valid: false, reason: `sourcePath is invalid or does not exist: ${src}` };
  }
  if (!active || !fs.existsSync(active)) {
    return { valid: false, reason: `activePath is invalid or does not exist: ${active}` };
  }

  // Validate directory structure
  if (!validateMysqlDataDir(src)) {
    return { valid: false, reason: `sourcePath does not look like a valid MySQL directory: ${src}` };
  }
  if (!validateMysqlDataDir(active)) {
    return { valid: false, reason: `activePath does not look like a valid MySQL directory: ${active}` };
  }

  return { valid: true };
}

function runMysqlRollbackDryRun(metadata) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      lastError: null
    };
  }

  const readiness = getRollbackReadiness(metadata);
  metadata.mysqlRollback.available = readiness.available;

  if (!readiness.available) {
    metadata.mysqlRollback.dryRun = {
      status: 'blocked',
      timestamp: new Date().toISOString(),
      plan: buildMysqlRollbackPlan(metadata),
      reason: readiness.reason
    };
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = readiness.reason;
    writeRuntimeMetadata(metadata);
    return metadata.mysqlRollback;
  }

  const plan = buildMysqlRollbackPlan(metadata);
  const validation = validateMysqlRollbackSources(metadata);

  if (!validation.valid) {
    metadata.mysqlRollback.dryRun = {
      status: 'blocked',
      timestamp: new Date().toISOString(),
      plan: plan,
      reason: validation.reason
    };
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = validation.reason;
    writeRuntimeMetadata(metadata);
    return metadata.mysqlRollback;
  }

  metadata.mysqlRollback.dryRun = {
    status: 'ready',
    timestamp: new Date().toISOString(),
    plan: plan,
    reason: null
  };
  metadata.mysqlRollback.status = 'dry_run_ready';
  metadata.mysqlRollback.lastError = null;

  writeRuntimeMetadata(metadata);
  return metadata.mysqlRollback;
}

function createPreRollbackSnapshot(activePath, backupsDir) {
  if (!fs.existsSync(activePath)) {
    throw new Error(`Active path does not exist for snapshot: ${activePath}`);
  }

  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const destDir = path.join(backupsDir, 'mysql-rollback-snapshot', timestamp);

  try {
    fs.mkdirSync(destDir, { recursive: true });
    copyMysqlDataToRuntime(activePath, destDir);

    const isCopyOk = verifyRuntimeMysqlDataCopy(activePath, destDir);
    if (!isCopyOk) {
      throw new Error('Verification failed: Size or file count mismatch in snapshot');
    }

    return { success: true, path: destDir, timestamp };
  } catch (err) {
    console.error('[RuntimeMigrator] Pre-rollback snapshot failed:', err.message);
    return { success: false, status: 'rollback_snapshot_failed', error: err.message };
  }
}

function executeMysqlRollback(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      lastError: null
    };
  }

  const enableRollback = options.enableRollback === true;
  const confirmationToken = options.confirmationToken === 'CONFIRM_MYSQL_ROLLBACK';
  const dryRun = options.dryRun !== false;

  if (!enableRollback || !confirmationToken || dryRun) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastError = 'Unauthorized rollback request or missing confirmation token';
    writeRuntimeMetadata(metadata);
    throw new Error('Unauthorized rollback execution: confirmation token is missing or invalid.');
  }

  const readiness = getRollbackReadiness(metadata);
  if (!readiness.available) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = `Readiness check failed: ${readiness.reason}`;
    writeRuntimeMetadata(metadata);
    return { success: false, reason: readiness.reason };
  }

  const runningProcesses = module.exports.detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = 'external_mysql_process_detected';
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: 'mysql_rollback_failed: Running external MySQL process detected.',
      details: { runningProcesses }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'external_mysql_process_detected' };
  }

  const mm = metadata.mysqlMigration;
  const activePath = mm.activePath;
  const sourcePath = mm.sourcePath;

  if (!fs.existsSync(activePath) || !fs.existsSync(sourcePath)) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = 'Active or legacy path does not exist physically';
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'Active or legacy path does not exist physically' };
  }

  recordRollbackEvent(metadata, {
    phase: 'rollback_snapshot_started',
    message: 'mysql_rollback_snapshot_started: Creating safety snapshot of active database.',
    details: { activePath }
  });

  const snapshotResult = createPreRollbackSnapshot(activePath, getBackupsDir());
  
  if (!snapshotResult.success) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastSnapshot = {
      path: null,
      status: 'failed',
      timestamp: new Date().toISOString()
    };
    metadata.mysqlRollback.lastError = `Snapshot creation failed: ${snapshotResult.error}`;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_snapshot_failed: Snapshot creation failed: ${snapshotResult.error}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'rollback_snapshot_failed', error: snapshotResult.error };
  }

  metadata.mysqlRollback.lastSnapshot = {
    path: snapshotResult.path,
    status: 'verified',
    timestamp: new Date().toISOString()
  };
  metadata.mysqlRollback.status = 'rollback_ready_for_restore';
  metadata.mysqlRollback.lastError = 'rollback_restore_requires_next_phase';

  recordRollbackEvent(metadata, {
    phase: 'rollback_snapshot_created',
    message: 'mysql_rollback_snapshot_created: Safety snapshot verified successfully.',
    details: { path: snapshotResult.path }
  });

  recordRollbackEvent(metadata, {
    phase: 'rollback_ready',
    message: 'mysql_rollback_ready: System is ready for legacy restore. actual restore requires next phase.',
    details: { reason: 'rollback_restore_requires_next_phase' }
  });

  writeRuntimeMetadata(metadata);
  console.log('[RuntimeMigrator] Pre-rollback snapshot verified. Staged rollback status is rollback_ready_for_restore.');
  return { success: true, status: 'rollback_ready_for_restore', snapshotPath: snapshotResult.path };
}

function recordRollbackEvent(metadata, event) {
  if (!metadata.events) {
    metadata.events = [];
  }
  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 13: Authorized MySQL Rollback Restore Execution — Staging Restore Only
// ─────────────────────────────────────────────────────────────────────────────

function canExecuteMysqlRollbackRestore(metadata, options = {}) {
  if (!options.enableRollbackRestore) {
    return { canExecute: false, reason: 'enableRollbackRestore_not_enabled' };
  }
  if (options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK_RESTORE') {
    return { canExecute: false, reason: 'invalid_confirmation_token' };
  }
  if (options.dryRun === true) {
    return { canExecute: false, reason: 'dry_run_not_supported_for_restore' };
  }
  if (!metadata) {
    return { canExecute: false, reason: 'no_metadata' };
  }
  
  const mm = metadata.mysqlMigration;
  const mr = metadata.mysqlRollback;
  if (!mm) {
    return { canExecute: false, reason: 'no_mysqlMigration_metadata' };
  }
  if (!mr) {
    return { canExecute: false, reason: 'no_mysqlRollback_metadata' };
  }
  if (mm.status !== 'migration_committed') {
    return { canExecute: false, reason: 'migration_not_committed' };
  }
  if (mr.status !== 'rollback_ready_for_restore') {
    return { canExecute: false, reason: 'rollback_not_ready_for_restore' };
  }
  if (!mr.lastSnapshot || mr.lastSnapshot.status !== 'verified') {
    return { canExecute: false, reason: 'last_snapshot_not_verified' };
  }
  if (mm.rollbackAvailable !== true) {
    return { canExecute: false, reason: 'rollback_not_available' };
  }
  if (mm.legacySourcePreserved !== true) {
    return { canExecute: false, reason: 'legacy_source_not_preserved' };
  }
  if (!mm.activePath || !fs.existsSync(mm.activePath)) {
    return { canExecute: false, reason: 'active_path_missing_or_invalid' };
  }

  // Check running MySQL processes
  const runningProcesses = module.exports.detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    return { canExecute: false, reason: 'external_mysql_process_detected' };
  }

  return { canExecute: true };
}

function createRollbackRestoreStaging(restoreSourcePath) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const stagingPath = path.join(getBackupsDir(), 'mysql-rollback-restore-staging', timestamp);
    
    fs.mkdirSync(stagingPath, { recursive: true });
    copyMysqlDataToRuntime(restoreSourcePath, stagingPath);

    return { success: true, path: stagingPath };
  } catch (err) {
    console.error('[RuntimeMigrator] Create rollback restore staging failed:', err.message);
    return { success: false, error: err.message };
  }
}

function verifyRollbackRestoreStaging(metadata, stagingPath) {
  const mm = metadata.mysqlMigration;
  let restoreSourcePath = null;
  if (mm.sourcePath && fs.existsSync(mm.sourcePath) && validateMysqlDataDir(mm.sourcePath)) {
    restoreSourcePath = mm.sourcePath;
  } else if (mm.preflightBackupPath && fs.existsSync(mm.preflightBackupPath) && validateMysqlDataDir(mm.preflightBackupPath)) {
    restoreSourcePath = mm.preflightBackupPath;
  }

  if (!restoreSourcePath) {
    return false;
  }

  return module.exports.verifyRuntimeMysqlDataCopy(restoreSourcePath, stagingPath);
}

function runAuthorizedMysqlRollbackRestore(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      finalSwitchPerformed: false,
      activePathSwitched: false,
      activePathOverwritten: false,
      lastError: null
    };
  } else {
    if (metadata.mysqlRollback.finalSwitchPerformed === undefined) {
      metadata.mysqlRollback.finalSwitchPerformed = false;
    }
    if (metadata.mysqlRollback.activePathSwitched === undefined) {
      metadata.mysqlRollback.activePathSwitched = false;
    }
    if (metadata.mysqlRollback.activePathOverwritten === undefined) {
      metadata.mysqlRollback.activePathOverwritten = false;
    }
  }

  const checkResult = module.exports.canExecuteMysqlRollbackRestore(metadata, options);
  if (!checkResult.canExecute) {
    const reason = checkResult.reason;
    if (reason === 'invalid_confirmation_token' || reason === 'enableRollbackRestore_not_enabled') {
      metadata.mysqlRollback.status = 'failed';
      metadata.mysqlRollback.lastError = `Unauthorized restore: ${reason}`;
      writeRuntimeMetadata(metadata);
      throw new Error(`Unauthorized restore: ${reason}`);
    } else {
      metadata.mysqlRollback.status = 'rollback_restore_blocked';
      metadata.mysqlRollback.lastError = reason;
      recordRollbackEvent(metadata, {
        phase: 'failed',
        message: `mysql_rollback_restore_blocked: ${reason}`,
        details: {}
      });
      writeRuntimeMetadata(metadata);
      return { success: false, reason };
    }
  }

  const mm = metadata.mysqlMigration;
  let restoreSourcePath = null;
  let restoreSourceName = null;

  if (mm.sourcePath && fs.existsSync(mm.sourcePath) && module.exports.validateMysqlDataDir(mm.sourcePath)) {
    restoreSourcePath = mm.sourcePath;
    restoreSourceName = 'legacy_source';
  } else if (mm.preflightBackupPath && fs.existsSync(mm.preflightBackupPath) && module.exports.validateMysqlDataDir(mm.preflightBackupPath)) {
    restoreSourcePath = mm.preflightBackupPath;
    restoreSourceName = 'preflight_backup';
  }

  if (!restoreSourcePath) {
    const reason = 'no_valid_restore_source_directory';
    metadata.mysqlRollback.status = 'rollback_restore_blocked';
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_blocked: ${reason}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason };
  }

  recordRollbackEvent(metadata, {
    phase: 'rollback_restore_started',
    message: `mysql_rollback_restore_started: Staging database restore from ${restoreSourceName}.`,
    details: { restoreSourcePath }
  });

  const stagingResult = module.exports.createRollbackRestoreStaging(restoreSourcePath);
  if (!stagingResult.success) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastError = `Staging creation failed: ${stagingResult.error}`;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_failed: Staging creation failed: ${stagingResult.error}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'staging_failed', error: stagingResult.error };
  }

  const stagingPath = stagingResult.path;

  const verifyOk = module.exports.verifyRollbackRestoreStaging(metadata, stagingPath);
  if (!verifyOk) {
    const reason = 'mismatch_count_or_size_in_staging';
    metadata.mysqlRollback.status = 'rollback_restore_verify_failed';
    metadata.mysqlRollback.stagingPath = stagingPath;
    metadata.mysqlRollback.restoreSource = restoreSourceName;
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_failed: Staging verification failed. Path: ${stagingPath}`,
      details: { stagingPath }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'rollback_restore_verify_failed', stagingPath };
  }

  metadata.mysqlRollback.status = 'rollback_restore_staged_verified';
  metadata.mysqlRollback.restoreSource = restoreSourceName;
  metadata.mysqlRollback.stagingPath = stagingPath;
  metadata.mysqlRollback.restoreReadyForFinalSwitch = true;
  metadata.mysqlRollback.finalSwitchPerformed = false;
  metadata.mysqlRollback.activePathOverwritten = false;
  metadata.mysqlRollback.legacySourcePreserved = true;
  metadata.mysqlRollback.lastError = null;

  recordRollbackEvent(metadata, {
    phase: 'rollback_restore_staged_verified',
    message: 'mysql_rollback_restore_staged_verified: Restore staging verified. final switch not performed.',
    details: { stagingPath, restoreSource: restoreSourceName }
  });

  writeRuntimeMetadata(metadata);
  return { 
    success: true, 
    status: 'rollback_restore_staged_verified', 
    stagingPath, 
    restoreSource: restoreSourceName 
  };
}

// ─────────────────────────────────────────────────────────────────────────────
// Phase 14: Final MySQL Rollback Switch
// ─────────────────────────────────────────────────────────────────────────────

function createStagingManifest(stagingPath) {
  const crypto = require('crypto');
  let fileCount = 0;
  let totalSize = 0;
  const criticalFiles = {};
  const sampleHashes = {};

  const filesToCheck = ['ibdata1', 'auto.cnf'];
  for (const file of filesToCheck) {
    const filePath = path.join(stagingPath, file);
    if (fs.existsSync(filePath)) {
      criticalFiles[file] = true;
      try {
        const content = fs.readFileSync(filePath);
        const hash = crypto.createHash('sha256').update(content).digest('hex');
        sampleHashes[file] = hash;
      } catch (err) {
        console.warn(`[Manifest] Could not calculate hash for ${file}:`, err.message);
      }
    } else {
      criticalFiles[file] = false;
    }
  }

  criticalFiles['mysql'] = fs.existsSync(path.join(stagingPath, 'mysql')) && fs.statSync(path.join(stagingPath, 'mysql')).isDirectory();

  function walk(dir) {
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(entryPath);
      } else {
        fileCount++;
        totalSize += fs.statSync(entryPath).size;
      }
    }
  }
  walk(stagingPath);

  return {
    fileCount,
    totalSize,
    criticalFiles,
    sampleHashes
  };
}

function canExecuteFinalRollbackSwitch(metadata, options = {}) {
  if (!options.enableFinalRollbackSwitch) {
    return { canExecute: false, reason: 'enableFinalRollbackSwitch_not_enabled' };
  }
  if (options.confirmationToken !== 'CONFIRM_FINAL_MYSQL_ROLLBACK_SWITCH') {
    return { canExecute: false, reason: 'invalid_confirmation_token' };
  }
  if (options.dryRun === true) {
    return { canExecute: false, reason: 'dry_run_not_supported_for_final_switch' };
  }
  if (!metadata) {
    return { canExecute: false, reason: 'no_metadata' };
  }

  const mm = metadata.mysqlMigration;
  const mr = metadata.mysqlRollback;

  if (!mm || !mr) {
    return { canExecute: false, reason: 'missing_migration_or_rollback_metadata' };
  }

  if (mr.status !== 'rollback_restore_staged_verified') {
    return { canExecute: false, reason: 'rollback_not_staged_verified' };
  }

  if (mr.restoreReadyForFinalSwitch !== true) {
    return { canExecute: false, reason: 'restore_not_ready_for_final_switch' };
  }

  if (mr.finalSwitchPerformed === true) {
    return { canExecute: false, reason: 'final_switch_already_performed' };
  }

  if (!mr.stagingPath || !fs.existsSync(mr.stagingPath) || !module.exports.validateMysqlDataDir(mr.stagingPath)) {
    return { canExecute: false, reason: 'staging_path_missing_or_invalid' };
  }

  if (!mm.activePath || !fs.existsSync(mm.activePath)) {
    return { canExecute: false, reason: 'active_path_missing_or_invalid' };
  }

  if (!mr.lastSnapshot || mr.lastSnapshot.status !== 'verified') {
    return { canExecute: false, reason: 'last_snapshot_not_verified' };
  }

  // Check running MySQL processes
  const runningProcesses = module.exports.detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    return { canExecute: false, reason: 'external_mysql_process_detected' };
  }

  return { canExecute: true };
}

function createFinalSwitchSafetySnapshot(activePath, backupsDir) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const destDir = path.join(backupsDir, 'mysql-final-switch-snapshot', timestamp);
    
    fs.mkdirSync(destDir, { recursive: true });
    module.exports.copyMysqlDataToRuntime(activePath, destDir);

    const isCopyOk = module.exports.verifyRuntimeMysqlDataCopy(activePath, destDir);
    if (!isCopyOk) {
      throw new Error('Verification failed: Size or file count mismatch in final safety snapshot');
    }

    return { success: true, path: destDir, timestamp };
  } catch (err) {
    console.error('[RuntimeMigrator] Final switch safety snapshot failed:', err.message);
    return { success: false, error: err.message };
  }
}

function prepareActivePathForSwitch(activePath, options = {}) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const backupPath = `${activePath}.pre-final-rollback.${timestamp}`;
    
    fs.renameSync(activePath, backupPath);
    return { success: true, backupPath };
  } catch (err) {
    console.error('[RuntimeMigrator] Prepare active path rename failed:', err.message);
    return { success: false, error: err.message };
  }
}

function switchStagingToActivePath(stagingPath, activePath, options = {}) {
  try {
    fs.renameSync(stagingPath, activePath);
    return { success: true };
  } catch (err) {
    console.error('[RuntimeMigrator] Switch staging rename to active failed:', err.message);
    return { success: false, error: err.message };
  }
}

function verifyFinalActivePath(metadata, activePath, manifest) {
  if (!fs.existsSync(activePath)) {
    return false;
  }

  if (!module.exports.validateMysqlDataDir(activePath)) {
    return false;
  }

  // Compare against manifest
  const newManifest = createStagingManifest(activePath);
  if (newManifest.fileCount !== manifest.fileCount || newManifest.totalSize !== manifest.totalSize) {
    console.error(`[VerifyFinal] Mismatch count/size. Expected: ${manifest.fileCount}/${manifest.totalSize}, Got: ${newManifest.fileCount}/${newManifest.totalSize}`);
    return false;
  }

  // Check critical files
  for (const file in manifest.criticalFiles) {
    if (manifest.criticalFiles[file] !== newManifest.criticalFiles[file]) {
      console.error(`[VerifyFinal] Critical file existence mismatch: ${file}`);
      return false;
    }
  }

  // Check sample hashes
  for (const file in manifest.sampleHashes) {
    if (manifest.sampleHashes[file] !== newManifest.sampleHashes[file]) {
      console.error(`[VerifyFinal] Sample hash mismatch: ${file}`);
      return false;
    }
  }

  try {
    fs.accessSync(activePath, fs.constants.R_OK);
  } catch (err) {
    console.error('[VerifyFinal] Active path is not readable:', err.message);
    return false;
  }

  return true;
}

function commitFinalRollbackSwitch(metadata, result) {
  metadata.mysqlRollback.status = 'rollback_completed';
  metadata.mysqlRollback.finalSwitchPerformed = true;
  metadata.mysqlRollback.activePathSwitched = true;
  metadata.mysqlRollback.activePathOverwritten = false;
  metadata.mysqlRollback.restoreReadyForFinalSwitch = false;
  metadata.mysqlRollback.completedAt = new Date().toISOString();
  metadata.mysqlRollback.finalActivePath = result.finalActivePath;
  metadata.mysqlRollback.previousActivePathBackup = result.previousActivePathBackup;
  metadata.mysqlRollback.finalSwitchSnapshotPath = result.finalSwitchSnapshotPath;
  metadata.mysqlRollback.stagingPathUsed = result.stagingPathUsed;
  metadata.mysqlRollback.lastError = null;

  writeRuntimeMetadata(metadata);
}

function abortFinalRollbackSwitch(metadata, status, reason) {
  metadata.mysqlRollback.status = status;
  metadata.mysqlRollback.lastError = reason;
  recordRollbackEvent(metadata, {
    phase: 'failed',
    message: `mysql_final_switch_failed: ${reason}`,
    details: {}
  });
  writeRuntimeMetadata(metadata);
}

function runFinalMysqlRollbackSwitch(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (metadata.mysqlRollback) {
    if (metadata.mysqlRollback.finalSwitchPerformed === undefined) {
      metadata.mysqlRollback.finalSwitchPerformed = false;
    }
    if (metadata.mysqlRollback.activePathSwitched === undefined) {
      metadata.mysqlRollback.activePathSwitched = false;
    }
    if (metadata.mysqlRollback.activePathOverwritten === undefined) {
      metadata.mysqlRollback.activePathOverwritten = false;
    }
  }

  const checkResult = module.exports.canExecuteFinalRollbackSwitch(metadata, options);
  if (!checkResult.canExecute) {
    const reason = checkResult.reason;
    if (reason === 'invalid_confirmation_token' || reason === 'enableFinalRollbackSwitch_not_enabled') {
      module.exports.abortFinalRollbackSwitch(metadata, 'failed', `Unauthorized final switch: ${reason}`);
      throw new Error(`Unauthorized final switch: ${reason}`);
    } else {
      module.exports.abortFinalRollbackSwitch(metadata, 'final_switch_blocked', reason);
      return { success: false, reason };
    }
  }

  const mr = metadata.mysqlRollback;
  const mm = metadata.mysqlMigration;
  const activePath = mm.activePath;
  const stagingPath = mr.stagingPath;

  recordRollbackEvent(metadata, {
    phase: 'final_switch_started',
    message: 'mysql_final_switch_started: Creating safety snapshot of active database before switch.',
    details: { activePath }
  });

  // 1. Create final safety snapshot
  const snapshotResult = module.exports.createFinalSwitchSafetySnapshot(activePath, getBackupsDir());
  if (!snapshotResult.success) {
    const reason = `Final switch safety snapshot failed: ${snapshotResult.error}`;
    module.exports.abortFinalRollbackSwitch(metadata, 'final_switch_snapshot_failed', reason);
    return { success: false, reason: 'final_switch_snapshot_failed', error: snapshotResult.error };
  }

  const snapshotPath = snapshotResult.path;

  recordRollbackEvent(metadata, {
    phase: 'final_switch_snapshot_created',
    message: 'mysql_final_switch_snapshot_created: Safety snapshot verified successfully.',
    details: { snapshotPath }
  });

  // Create staging manifest before renaming stagingPath
  const stagingManifest = createStagingManifest(stagingPath);

  // 2. Rename activePath to pre-final-rollback
  recordRollbackEvent(metadata, {
    phase: 'final_switch_rename_started',
    message: 'mysql_final_switch_rename_started: Renaming active database to previous backup.',
    details: { activePath }
  });

  const prepareResult = module.exports.prepareActivePathForSwitch(activePath, options);
  if (!prepareResult.success) {
    const reason = `atomic_rename_unavailable: ${prepareResult.error}`;
    module.exports.abortFinalRollbackSwitch(metadata, 'final_switch_rename_failed', reason);
    return { success: false, reason: 'final_switch_rename_failed', error: prepareResult.error };
  }

  const backupPath = prepareResult.backupPath;

  // 3. Rename stagingPath to activePath
  const switchResult = module.exports.switchStagingToActivePath(stagingPath, activePath, options);
  if (!switchResult.success) {
    // Attempt best-effort restore rename of pre-final-rollback back to activePath
    try {
      fs.renameSync(backupPath, activePath);
      console.log('[RuntimeMigrator] Successfully restored activePath backup on staging rename failure.');
    } catch (restoreErr) {
      console.error('[RuntimeMigrator] Failed to restore activePath backup on staging rename failure:', restoreErr.message);
    }

    const reason = `atomic_rename_unavailable: ${switchResult.error}`;
    module.exports.abortFinalRollbackSwitch(metadata, 'final_switch_rename_failed', reason);
    return { success: false, reason: 'final_switch_rename_failed', error: switchResult.error };
  }

  // 4. Verify new activePath against staging manifest
  const verifyOk = module.exports.verifyFinalActivePath(metadata, activePath, stagingManifest);
  if (!verifyOk) {
    const reason = 'mismatch_count_or_size_in_final_active_path';
    metadata.mysqlRollback.status = 'final_switch_verify_failed';
    metadata.mysqlRollback.stagingPathUsed = stagingPath;
    metadata.mysqlRollback.previousActivePathBackup = backupPath;
    metadata.mysqlRollback.finalSwitchSnapshotPath = snapshotPath;
    metadata.mysqlRollback.finalActivePath = activePath;
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_final_switch_failed: Staging manifest verification failed. Path: ${activePath}`,
      details: { activePath }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'final_switch_verify_failed', activePath };
  }

  // 5. Commit metadata on success
  const commitResult = {
    finalActivePath: activePath,
    previousActivePathBackup: backupPath,
    finalSwitchSnapshotPath: snapshotPath,
    stagingPathUsed: stagingPath
  };

  module.exports.commitFinalRollbackSwitch(metadata, commitResult);

  recordRollbackEvent(metadata, {
    phase: 'rollback_completed',
    message: 'mysql_rollback_completed: Final database switch verified and committed successfully.',
    details: commitResult
  });

  return {
    success: true,
    status: 'rollback_completed',
    finalActivePath: activePath,
    previousActivePathBackup: backupPath,
    finalSwitchSnapshotPath: snapshotPath,
    stagingPathUsed: stagingPath
  };
}

module.exports = {
  readAppVersion,
  readRuntimeMetadata,
  writeRuntimeMetadata,
  ensureMetadataInitialized,
  needsMigration,
  getMigrationStatus,
  recordMigrationEvent,
  copyDirectorySafe,
  verifyDirectoryCopy,
  archiveOldPath,
  isPathLocked,
  runRuntimeMigration,
  
  // Phase 10 exports
  detectLegacyMysqlDataDirs,
  validateMysqlDataDir,
  detectMysqlLockState,
  detectRunningMysqldProcesses,
  isMysqlDataSafeToCopy,
  createMysqlPreMigrationBackup,
  verifyMysqlBackupCopy,
  runMysqlMigrationPreflight,

  // Phase 11 exports
  canRunMysqlActiveMigration,
  stopAppManagedMysqlIfRunning,
  copyMysqlDataToRuntime,
  verifyRuntimeMysqlDataCopy,
  writeMysqlMigrationCommit,
  markMysqlMigrationRollbackAvailable,
  runControlledMysqlMigration,

  // Phase 12 exports
  getRollbackReadiness,
  buildMysqlRollbackPlan,
  validateMysqlRollbackSources,
  runMysqlRollbackDryRun,
  createPreRollbackSnapshot,
  executeMysqlRollback,
  recordRollbackEvent,

  // Phase 13 exports
  canExecuteMysqlRollbackRestore,
  createRollbackRestoreStaging,
  verifyRollbackRestoreStaging,
  runAuthorizedMysqlRollbackRestore,

  // Phase 14 exports
  canExecuteFinalRollbackSwitch,
  createFinalSwitchSafetySnapshot,
  prepareActivePathForSwitch,
  switchStagingToActivePath,
  verifyFinalActivePath,
  commitFinalRollbackSwitch,
  abortFinalRollbackSwitch,
  runFinalMysqlRollbackSwitch
};
