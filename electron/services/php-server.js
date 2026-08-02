const { spawn } = require('child_process');
const path = require('path');
const http = require('http');
const net = require('net');
const fs = require('fs');
const { getQZPrivateKeyPath } = require('./qz-certs');
const { getPhpRuntimeArgs, resolveSystemTimeZone } = require('../utils/php-runtime');

let phpProcess = null;
let serverInfo = null;
let lastHealthResponse = null;

function findAvailablePort(preferredPort) {
  return new Promise((resolve) => {
    const server = net.createServer();
    server.once('error', (err) => {
      resolve(findAvailablePort(preferredPort + 1));
    });
    server.once('listening', () => {
      server.close(() => {
        resolve(preferredPort);
      });
    });
    server.listen(preferredPort, '127.0.0.1');
  });
}

function resolveBackendPharPath() {
  const { getBackendDir } = require('../utils/paths');
  return path.join(getBackendDir(), 'backend.phar');
}

function resolveBackendEntryPath() {
  const { getBackendDir, isPackaged } = require('../utils/paths');
  return isPackaged()
    ? resolveBackendPharPath()
    : path.join(getBackendDir(), 'router.php');
}

function getPhpServerInfo() {
  return serverInfo;
}

function stopPhpServer() {
  if (phpProcess) {
    phpProcess.kill();
    phpProcess = null;
  }
  serverInfo = null;
}

// Wrapper for existing code
function stopPHP() {
  stopPhpServer();
}

function createBackendEnv({ mysqlPort, dbCredentials, apiPort }) {
  const {
    getBackupsDir,
    getDataDir,
    getEnvPath,
    getLogsDir,
    getRuntimeMetadataPath,
    getRuntimePortsPath,
  } = require('../utils/paths');

  const isLanDeployment = process.env.POS_LAN_ENABLED === 'true';

  return {
    ...process.env,
    DB_HOST: '127.0.0.1',
    DB_PORT: String(mysqlPort),
    DB_NAME: 'pos_db',
    DB_USER: dbCredentials.user,
    DB_PASS: dbCredentials.password,
    // Backend self-updates remain disabled by default. Packaged desktop
    // releases use electron-updater; source-mode updates require an explicit
    // ENABLE_AUTO_UPDATE=true in the operator environment.
    ENABLE_AUTO_UPDATE: process.env.ENABLE_AUTO_UPDATE || 'false',
    // The bundled desktop runtime is loopback-only, even when a stale
    // production APP_ENV is present in the persisted .env file. Keep LAN
    // deployments on their configured environment so production checks still
    // apply there.
    APP_ENV: isLanDeployment ? (process.env.APP_ENV || 'production') : 'development',
    DEPLOYMENT_MODE: isLanDeployment ? 'lan' : 'desktop',
    APP_TIMEZONE: resolveSystemTimeZone(),
    ENV_PATH: getEnvPath(),
    APP_STORAGE_DIR: getDataDir(),
    QZ_PRIVATE_KEY_PATH: getQZPrivateKeyPath(),
    DB_BACKUP_DIR: getBackupsDir(),
    LOGS_PATH: getLogsDir(),
    API_PORT: String(apiPort),
    PORT: String(apiPort),
    RUNTIME_METADATA_PATH: getRuntimeMetadataPath(),
    RUNTIME_PORTS_PATH: getRuntimePortsPath(),
  };
}

function runDatabaseMigrations({ mysqlPort, dbCredentials, apiPort }) {
  const {
    getBackendDir,
    getLogsDir,
    getTempDir,
    getPhpPath,
    isPackaged,
  } = require('../utils/paths');
  const backendDir = getBackendDir();
  const phpBin = getPhpPath();
  const phpRuntimeArgs = getPhpRuntimeArgs(phpBin, getTempDir());
  const migrationArgs = isPackaged()
    ? [...phpRuntimeArgs, path.join(backendDir, 'backend.phar'), 'migrate']
    : [...phpRuntimeArgs, path.join(backendDir, 'cli', 'migrate.php')];
  const env = createBackendEnv({ mysqlPort, dbCredentials, apiPort });

  return new Promise((resolve, reject) => {
    console.log('[Migration] Applying pending database migrations before starting PHP workers...');
    const logStream = fs.createWriteStream(
      path.join(getLogsDir(), 'database-migrations.log'),
      { flags: 'a' },
    );
    const child = spawn(phpBin, migrationArgs, {
      env,
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let settled = false;
    let stderr = '';

    child.stdout.on('data', (data) => {
      logStream.write(data);
      console.log('[Migration]', data.toString().trim());
    });
    child.stderr.on('data', (data) => {
      logStream.write(data);
      stderr = (stderr + data.toString()).slice(-8_192);
    });
    child.once('error', (error) => {
      if (settled) return;
      settled = true;
      logStream.end();
      reject(new Error(`Failed to start database migration: ${error.message}`));
    });
    child.once('close', (code, signal) => {
      if (settled) return;
      settled = true;
      logStream.end();

      if (code !== 0) {
        const detail = stderr.trim() || `exit code ${code}${signal ? `, signal ${signal}` : ''}`;
        reject(new Error(`Database migration failed: ${detail}`));
        return;
      }

      console.log('[Migration] Database schema is ready.');
      resolve();
    });
  });
}

function startPhpServer(options = {}) {
  return new Promise(async (resolve, reject) => {
    try {
      const { getPhpPath, getLogsDir, getTempDir, getRuntimePortsPath } = require('../utils/paths');
      const phpBin = getPhpPath();
      const backendEntryPath = resolveBackendEntryPath();

      const preferredPort = options.preferredPort || 8080;
      const mysqlPort = options.mysqlPort || 3307;
      const dbCredentials = options.dbCredentials;
      if (!dbCredentials?.user || !dbCredentials?.password) {
        throw new Error('Dedicated database credentials are required');
      }

      console.log(`[PHP] Finding available port starting from preferred port ${preferredPort}...`);
      const selectedPort = await findAvailablePort(preferredPort);
      const isFallback = (selectedPort !== preferredPort);
      console.log(`[PHP] Requested port: ${preferredPort}, Selected port: ${selectedPort}, Fallback occurred: ${isFallback ? 'Yes' : 'No'}`);

      // Write runtime_ports.json
      const portsPath = getRuntimePortsPath();
      const portsData = {
        apiPort: selectedPort,
        apiBaseUrl: `http://localhost:${selectedPort}`,
        updatedAt: new Date().toISOString()
      };
      
      try {
        fs.writeFileSync(portsPath, JSON.stringify(portsData, null, 2));
        console.log(`[PHP] Wrote runtime_ports.json to ${portsPath}`);
      } catch (err) {
        console.error(`[PHP] Failed to write runtime_ports.json:`, err);
      }

      const env = {
        ...createBackendEnv({
          mysqlPort,
          dbCredentials,
          apiPort: selectedPort,
        }),
        PHP_CLI_SERVER_WORKERS: '4',
      };

      await runDatabaseMigrations({
        mysqlPort,
        dbCredentials,
        apiPort: selectedPort,
      });

      console.log(`[PHP] Spawning PHP Server pointing to: ${backendEntryPath}`);
      const sysTempDir = getTempDir();
      const args = [
        ...getPhpRuntimeArgs(phpBin, sysTempDir),
        '-d', `sys_temp_dir=${sysTempDir}`,
        '-S', `127.0.0.1:${selectedPort}`,
        backendEntryPath
      ];

      phpProcess = spawn(phpBin, args, { env, windowsHide: true });

      // Pipe output to log file
      const logFile = path.join(getLogsDir(), 'php-server.log');
      const logStream = fs.createWriteStream(logFile, { flags: 'a' });
      phpProcess.stdout.pipe(logStream);
      phpProcess.stderr.pipe(logStream);

      // Handle stderr logging to console for development visibility
      phpProcess.stderr.on('data', (data) => {
        console.log('[PHP]', data.toString().trim());
      });

      serverInfo = {
        pid: phpProcess.pid,
        port: selectedPort,
        baseUrl: `http://localhost:${selectedPort}`,
        backendEntryPath,
        pharPath: backendEntryPath
      };

      // wait for PHP readiness (simple HTTP ping)
      let attempts = 0;
      const check = setInterval(() => {
        attempts++;
        http.get(`http://127.0.0.1:${selectedPort}/`, (res) => {
          clearInterval(check);
          resolve(serverInfo);
        }).on('error', () => {
          if (attempts > 50) {
            clearInterval(check);
            reject(new Error(`PHP server failed to start on port ${selectedPort}`));
          }
        });
      }, 200);

    } catch (err) {
      reject(err);
    }
  });
}

// Wrapper for existing code
async function startPHP(port, mysqlPort, dbCredentials) {
  const info = await startPhpServer({ preferredPort: port, mysqlPort, dbCredentials });
  return info;
}

function waitForHealth(baseUrl, options = {}) {
  const maxTime = options.maxTime || 60000;
  const startTime = Date.now();
  let delay = options.initialDelay || 500;

  return new Promise((resolve, reject) => {
    let attempt = 0;

    function nextCheck() {
      attempt++;
      const elapsed = Date.now() - startTime;
      if (elapsed >= maxTime) {
        return reject(new Error(`Timeout: Backend health check failed after ${elapsed}ms (${attempt} attempts)`));
      }

      console.log(`[Health] Attempt #${attempt} connecting to ${baseUrl}/api/health (Elapsed: ${elapsed}ms)...`);
      
      const req = http.get(`${baseUrl}/api/health`, (res) => {
        let rawData = '';
        res.on('data', (chunk) => { rawData += chunk; });
        res.on('end', () => {
          if (res.statusCode === 200 || res.statusCode === 503) {
            try {
              const body = JSON.parse(rawData);
              lastHealthResponse = body;
              if (body.critical_failed === false) {
                if (body.status === 'degraded') {
                  console.warn(`[Health] Backend is ready but degraded! Checks:`, body.checks);
                } else {
                  console.log(`[Health] Backend is healthy and ready! Status: ${body.status}`);
                }
                resolve(body);
              } else {
                console.error(`[Health] Backend reported critical_failed=true! Checks:`, body.checks);
                setTimeout(nextCheck, delay);
                delay = Math.min(delay * 1.5, 5000);
              }
            } catch (err) {
              console.error(`[Health] Parse error for health check response:`, err.message);
              lastHealthResponse = {
                status: 'failed',
                critical_failed: true,
                error: `Parse error: ${err.message}. Raw: ${rawData.substring(0, 200)}`,
                checks: null
              };
              setTimeout(nextCheck, delay);
              delay = Math.min(delay * 1.5, 5000);
            }
          } else {
            console.warn(`[Health] Received non-200 status code: ${res.statusCode}`);
            lastHealthResponse = {
              status: 'failed',
              critical_failed: true,
              error: `HTTP status code ${res.statusCode}`,
              checks: null
            };
            setTimeout(nextCheck, delay);
            delay = Math.min(delay * 1.5, 5000);
          }
        });
      });

      req.on('error', (err) => {
        console.warn(`[Health] Connection error: ${err.message}`);
        lastHealthResponse = {
          status: 'failed',
          critical_failed: true,
          error: `Connection error: ${err.message}`,
          checks: null
        };
        setTimeout(nextCheck, delay);
        delay = Math.min(delay * 1.5, 5000);
      });
    }

    nextCheck();
  });
}

function getLastHealthResponse() {
  return lastHealthResponse;
}

module.exports = {
  findAvailablePort,
  resolveBackendPharPath,
  resolveBackendEntryPath,
  runDatabaseMigrations,
  startPhpServer,
  stopPhpServer,
  getPhpServerInfo,
  startPHP,
  stopPHP,
  waitForHealth,
  getLastHealthResponse
};
