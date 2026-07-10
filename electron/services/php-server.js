const { spawn } = require('child_process');
const path = require('path');
const http = require('http');
const net = require('net');
const fs = require('fs');

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

function startPhpServer(options = {}) {
  return new Promise(async (resolve, reject) => {
    try {
      const { getPhpPath, getConfigDir, getDataDir, getBackupsDir, getLogsDir, getTempDir, getEnvPath, getRuntimePortsPath, getRuntimeMetadataPath } = require('../utils/paths');
      const phpBin = getPhpPath();
      const pharPath = resolveBackendPharPath();

      const preferredPort = options.preferredPort || 8080;
      const mysqlPort = options.mysqlPort || 3307;

      console.log(`[PHP] Finding available port starting from preferred port ${preferredPort}...`);
      const selectedPort = await findAvailablePort(preferredPort);
      const isFallback = (selectedPort !== preferredPort);
      console.log(`[PHP] Requested port: ${preferredPort}, Selected port: ${selectedPort}, Fallback occurred: ${isFallback ? 'Yes' : 'No'}`);

      // Write runtime_ports.json
      const portsPath = getRuntimePortsPath();
      let portsData = {
        apiPort: selectedPort,
        apiBaseUrl: `http://127.0.0.1:${selectedPort}`,
        wsPort: null,
        wsBaseUrl: null,
        updatedAt: new Date().toISOString()
      };
      
      try {
        if (fs.existsSync(portsPath)) {
          const existing = JSON.parse(fs.readFileSync(portsPath, 'utf8'));
          portsData = {
            ...portsData,
            wsPort: existing.wsPort !== undefined ? existing.wsPort : null,
            wsBaseUrl: existing.wsBaseUrl !== undefined ? existing.wsBaseUrl : null
          };
        }
      } catch (err) {
        console.warn(`[PHP] Could not read existing ports for merging:`, err.message);
      }
      
      try {
        fs.writeFileSync(portsPath, JSON.stringify(portsData, null, 2));
        console.log(`[PHP] Wrote runtime_ports.json to ${portsPath}`);
      } catch (err) {
        console.error(`[PHP] Failed to write runtime_ports.json:`, err);
      }

      // Configure Env
      const env = {
        ...process.env,
        DB_HOST: '127.0.0.1',
        DB_PORT: String(mysqlPort),
        DB_NAME: 'pos_db',
        DB_USER: 'root',
        DB_PASS: '',
        ENABLE_AUTO_UPDATE: 'true',
        PHP_CLI_SERVER_WORKERS: '4',
        ENV_PATH: getEnvPath(),
        APP_STORAGE_DIR: getDataDir(),
        DB_BACKUP_DIR: getBackupsDir(),
        LOGS_PATH: getLogsDir(),
        API_PORT: String(selectedPort),
        PORT: String(selectedPort),
        RUNTIME_METADATA_PATH: getRuntimeMetadataPath()
      };

      console.log(`[PHP] Spawning PHP Server pointing to: ${pharPath}`);
      const sysTempDir = getTempDir();
      const args = [
        '-d', `sys_temp_dir=${sysTempDir}`,
        '-S', `127.0.0.1:${selectedPort}`,
        pharPath
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
        baseUrl: `http://127.0.0.1:${selectedPort}`,
        pharPath: pharPath
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
async function startPHP(port, mysqlPort) {
  const info = await startPhpServer({ preferredPort: port, mysqlPort });
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
  startPhpServer,
  stopPhpServer,
  getPhpServerInfo,
  startPHP,
  stopPHP,
  waitForHealth,
  getLastHealthResponse
};
