const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');

let proxyServer = null;

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
    console.log('[HTTPS] Certificate generated successfully');
  } catch (err) {
    console.error('[HTTPS] Failed to generate certificate:', err.message);
  }
}

function startHttpsProxy(phpPort, httpsPort) {
  return new Promise((resolve) => {
    const sslDir = path.join(__dirname, '..', 'assets', 'ssl');
    const keyPath = path.join(sslDir, 'server.key');
    const certPath = path.join(sslDir, 'server.crt');
    
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
        const proxyReq = http.request({
          hostname: '127.0.0.1',
          port: phpPort,
          path: req.url,
          method: req.method,
          headers: req.headers
        }, (proxyRes) => {
          res.writeHead(proxyRes.statusCode, proxyRes.headers);
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
