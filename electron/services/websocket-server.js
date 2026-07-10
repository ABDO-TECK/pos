const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const net = require('net');

let wsProcess = null;
let serverInfo = null;

/**
 * Find an available TCP port starting from the preferred port.
 * Uses net.createServer to detect active binds.
 */
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

function getWebSocketServerInfo() {
  return serverInfo;
}

function startWebSocketServer(options = {}) {
  return new Promise(async (resolve, reject) => {
    try {
      const { getPhpPath, getBackendDir, getRuntimePortsPath, getLogsDir } = require('../utils/paths');
      const phpBin = getPhpPath();
      const backendDir = getBackendDir();
      const preferredPort = options.preferredPort || 8090;

      console.log(`[WebSocket] Finding available port starting from preferred port ${preferredPort}...`);
      const selectedPort = await findAvailablePort(preferredPort);
      const isFallback = (selectedPort !== preferredPort);
      console.log(`[WebSocket] Requested port: ${preferredPort}, Selected port: ${selectedPort}, Fallback occurred: ${isFallback ? 'Yes' : 'No'}`);

      // Read existing runtime_ports.json to merge
      const portsPath = getRuntimePortsPath();
      let portsData = {
        apiPort: null,
        apiBaseUrl: null,
        wsPort: selectedPort,
        wsBaseUrl: `ws://127.0.0.1:${selectedPort}`,
        updatedAt: new Date().toISOString()
      };

      try {
        if (fs.existsSync(portsPath)) {
          const existing = JSON.parse(fs.readFileSync(portsPath, 'utf8'));
          portsData = {
            ...existing,
            wsPort: selectedPort,
            wsBaseUrl: `ws://127.0.0.1:${selectedPort}`,
            updatedAt: new Date().toISOString()
          };
        }
      } catch (err) {
        console.warn('[WebSocket] Failed to parse existing runtime_ports.json, writing new one:', err.message);
      }

      try {
        fs.writeFileSync(portsPath, JSON.stringify(portsData, null, 2));
        console.log(`[WebSocket] Updated runtime_ports.json to include wsPort ${selectedPort}`);
      } catch (err) {
        console.error('[WebSocket] Failed to write runtime_ports.json:', err.message);
      }

      // Check if running from packaged app or source
      let wsPath;
      let args;
      
      const pharPath = path.join(backendDir, 'backend.phar');
      if (fs.existsSync(pharPath)) {
        wsPath = pharPath;
        args = ['backend.phar', 'websocket-server'];
      } else {
        wsPath = path.join(backendDir, 'cli', 'websocket-server.php');
        args = [wsPath];
      }

      console.log(`[WebSocket] Spawning WebSocket server using ${phpBin} and arguments:`, args);

      // Environment setup with WS_PORT passed to the process
      const env = {
        ...process.env,
        WS_PORT: String(selectedPort)
      };

      // Set up logs
      const logDir = getLogsDir();
      const logFile = path.join(logDir, 'websocket-server.log');
      const logStream = fs.createWriteStream(logFile, { flags: 'a' });

      logStream.write(`\n--- WebSocket Server Starting at ${new Date().toISOString()} on port ${selectedPort} (PID: pending) ---\n`);

      wsProcess = spawn(phpBin, args, {
        windowsHide: true,
        env,
        cwd: backendDir
      });

      const pid = wsProcess.pid;
      console.log(`[WebSocket] Process spawned successfully. PID: ${pid}`);
      logStream.write(`[WebSocket] Process spawned with PID: ${pid}\n`);

      serverInfo = {
        port: selectedPort,
        baseUrl: `ws://127.0.0.1:${selectedPort}`,
        pid: pid
      };

      wsProcess.stdout.on('data', (data) => {
        const str = data.toString();
        console.log('[WebSocket]', str.trim());
        logStream.write(`[STDOUT] ${str}`);
      });

      wsProcess.stderr.on('data', (data) => {
        const str = data.toString();
        console.error('[WebSocket ERROR]', str.trim());
        logStream.write(`[STDERR] ${str}`);
      });

      wsProcess.on('close', (code) => {
        console.log(`[WebSocket] Process exited with code ${code}`);
        logStream.write(`[WebSocket] Process exited with code ${code}\n`);
        logStream.end();
      });

      resolve(serverInfo);
    } catch (err) {
      console.error('[WebSocket] Setup failed:', err);
      reject(err);
    }
  });
}

function stopWebSocketServer() {
  if (wsProcess) {
    console.log(`[WebSocket] Killing WebSocket process with PID: ${wsProcess.pid}`);
    wsProcess.kill();
    wsProcess = null;
  }
  serverInfo = null;
}

module.exports = {
  startWebSocketServer,
  stopWebSocketServer,
  getWebSocketServerInfo
};
