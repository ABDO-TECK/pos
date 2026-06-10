const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const net = require('net');

let proxyServer = null;

/**
 * Get the SSL directory path. In production (packaged), we use the
 * writable userData directory because app.asar is read-only.
 * In development, we use the local electron/assets/ssl directory.
 */
function getSslDir() {
  try {
    const { app } = require('electron');
    if (app.isPackaged) {
      return path.join(app.getPath('userData'), 'ssl');
    }
  } catch (e) {
    // electron not available (unlikely but safe fallback)
  }
  return path.join(__dirname, '..', 'assets', 'ssl');
}

function generateCertificate(certPath, keyPath) {
  try {
    const forge = require('node-forge');
    console.log('[HTTPS] Generating new self-signed certificate...');
    
    const keys = forge.pki.rsa.generateKeyPair(2048);
    const cert = forge.pki.createCertificate();
    
    cert.publicKey = keys.publicKey;
    cert.serialNumber = '01';
    cert.validity.notBefore = new Date();
    cert.validity.notAfter = new Date();
    cert.validity.notAfter.setFullYear(cert.validity.notBefore.getFullYear() + 10);
    
    const attrs = [{ name: 'commonName', value: 'POS Local Server' }];
    cert.setSubject(attrs);
    cert.setIssuer(attrs);
    
    cert.sign(keys.privateKey);
    
    const pemCert = forge.pki.certificateToPem(cert);
    const pemKey = forge.pki.privateKeyToPem(keys.privateKey);
    
    // Ensure directory exists
    const dir = path.dirname(certPath);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    
    fs.writeFileSync(certPath, pemCert);
    fs.writeFileSync(keyPath, pemKey);
    console.log('[HTTPS] Certificate generated successfully at:', dir);
  } catch (err) {
    console.error('[HTTPS] Failed to generate certificate:', err.message);
  }
}

function startHttpsProxy(phpPort, httpsPort) {
  return new Promise((resolve) => {
    const sslDir = getSslDir();
    const keyPath = path.join(sslDir, 'server.key');
    const certPath = path.join(sslDir, 'server.crt');
    
    console.log('[HTTPS] SSL directory:', sslDir);

    // Generate cert if it doesn't exist
    if (!fs.existsSync(keyPath) || !fs.existsSync(certPath)) {
      generateCertificate(certPath, keyPath);
    }

    try {
      const options = {
        key: fs.readFileSync(keyPath),
        cert: fs.readFileSync(certPath)
      };

      proxyServer = https.createServer(options, (req, res) => {
        // Fix: Preserve the original Host header from the client,
        // and forward the request to the PHP backend with the correct host.
        // This prevents cookie domain mismatches that cause refresh loops.
        const headers = { ...req.headers };
        // Tell PHP the real origin so cookies/CORS work correctly
        headers['x-forwarded-proto'] = 'https';
        headers['x-forwarded-port'] = String(httpsPort);
        // Keep original Host header so PHP sees the correct domain

        const proxyReq = http.request({
          hostname: '127.0.0.1',
          port: phpPort,
          path: req.url,
          method: req.method,
          headers: headers
        }, (proxyRes) => {
          // Fix Set-Cookie headers: remove Secure flag (PHP may add it
          // because it sees HTTPS, but the self-signed cert isn't trusted)
          // and ensure SameSite=None for cross-port cookies
          const responseHeaders = { ...proxyRes.headers };
          if (responseHeaders['set-cookie']) {
            responseHeaders['set-cookie'] = (Array.isArray(responseHeaders['set-cookie'])
              ? responseHeaders['set-cookie']
              : [responseHeaders['set-cookie']]
            ).map(cookie => {
              // Remove Secure flag that PHP might add since we're behind HTTPS proxy
              // but the cert is self-signed / not trusted by mobile browsers
              let c = cookie;
              // Ensure SameSite=None so cookies work cross-port
              if (!/samesite/i.test(c)) {
                c = c + '; SameSite=None';
              }
              // Ensure Secure flag is present when SameSite=None (required by browsers)
              if (/samesite=none/i.test(c) && !/;\s*secure/i.test(c)) {
                c = c + '; Secure';
              }
              return c;
            });
          }

          res.writeHead(proxyRes.statusCode, responseHeaders);
          proxyRes.pipe(res, { end: true });
        });

        req.pipe(proxyReq, { end: true });
        
        proxyReq.on('error', (e) => {
          if (!res.headersSent) {
            res.writeHead(502);
          }
          res.end('Proxy error: ' + e.message);
        });
      });

      // WebSocket upgrade support — required for Service Worker & HMR
      proxyServer.on('upgrade', (req, socket, head) => {
        const proxySocket = net.connect(phpPort, '127.0.0.1', () => {
          // Reconstruct the HTTP upgrade request
          const reqLine = `${req.method} ${req.url} HTTP/1.1\r\n`;
          const headers = Object.entries(req.headers)
            .map(([k, v]) => `${k}: ${v}`)
            .join('\r\n');
          proxySocket.write(reqLine + headers + '\r\n\r\n');
          if (head && head.length) proxySocket.write(head);
          proxySocket.pipe(socket);
          socket.pipe(proxySocket);
        });
        proxySocket.on('error', () => socket.end());
        socket.on('error', () => proxySocket.end());
      });

      proxyServer.listen(httpsPort, '0.0.0.0', () => {
        console.log(`[HTTPS] Proxy running on 0.0.0.0:${httpsPort}`);
        resolve();
      });
    } catch (err) {
      console.error('[HTTPS] Could not start proxy:', err.message);
      resolve(); // resolve anyway so app continues
    }
  });
}

function stopHttpsProxy() {
  if (proxyServer) {
    proxyServer.close();
    proxyServer = null;
  }
}

module.exports = { startHttpsProxy, stopHttpsProxy };
