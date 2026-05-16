const { spawn } = require('child_process');
const path = require('path');

let wsProcess = null;

function startWebSocketServer() {
  const { getPhpPath, getBackendDir } = require('../utils/paths');
  const phpBin = getPhpPath();
  const wsPath = path.join(getBackendDir(), 'cli', 'websocket-server.php');

  wsProcess = spawn(phpBin, [wsPath], { windowsHide: true });

  wsProcess.stderr.on('data', (data) => {
    console.log('[WebSocket]', data.toString());
  });
  wsProcess.stdout.on('data', (data) => {
    console.log('[WebSocket]', data.toString());
  });
}

function stopWebSocketServer() {
  if (wsProcess) {
    wsProcess.kill();
    wsProcess = null;
  }
}

module.exports = { startWebSocketServer, stopWebSocketServer };
