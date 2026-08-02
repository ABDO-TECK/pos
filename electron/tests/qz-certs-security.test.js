const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repositoryRoot = path.resolve(__dirname, '..', '..');

function readRepositoryFile(relativePath) {
  return fs.readFileSync(path.join(repositoryRoot, relativePath), 'utf8');
}

test('QZ private key is not published beneath the backend web root', () => {
  const certificateService = readRepositoryFile('electron/services/qz-certs.js');
  const phpServer = readRepositoryFile('electron/services/php-server.js');
  const signingEndpoint = readRepositoryFile('backend/sign-message.php');

  assert.doesNotMatch(certificateService, /backend[\\/]storage[\\/]private-key\.pem/);
  assert.doesNotMatch(certificateService, /Private key published/);
  assert.match(certificateService, /function getQZPrivateKeyPath\(\)/);
  assert.match(phpServer, /QZ_PRIVATE_KEY_PATH:\s*getQZPrivateKeyPath\(\)/);
  assert.doesNotMatch(signingEndpoint, /\$baseDir\s*\.\s*['"]\/storage\/private-key\.pem/);
});

test('Apache denies runtime data and private-key file types', () => {
  const htaccess = readRepositoryFile('backend/.htaccess');
  const dockerfile = readRepositoryFile('Dockerfile');

  assert.match(htaccess, /RewriteRule \^\(\?:storage\|logs\)/);
  assert.match(htaccess, /pem\|key\|sqlite/);
  assert.match(htaccess, /Require all denied/);
  assert.match(dockerfile, /backend\/storage/);
  assert.match(dockerfile, /backend\/logs/);
  assert.match(dockerfile, /pem\|key\|sqlite/);
  assert.match(dockerfile, /curl/);
  assert.match(dockerfile, /Alias \/api \/var\/www\/html\/backend/);
});

test('Docker production profile requires an explicit network security posture', () => {
  const compose = readRepositoryFile('docker-compose.yml');

  assert.match(compose, /127\.0\.0\.1:8000:80/);
  assert.match(compose, /DEPLOYMENT_MODE=\$\{DEPLOYMENT_MODE:\?Set DEPLOYMENT_MODE=web or lan\}/);
  assert.match(compose, /FORCE_HTTPS=\$\{FORCE_HTTPS:\?Set FORCE_HTTPS=true\}/);
  assert.match(compose, /SECURE_COOKIES=\$\{SECURE_COOKIES:\?Set SECURE_COOKIES=true\}/);
  assert.match(compose, /FRONTEND_URL=\$\{FRONTEND_URL:\?Set FRONTEND_URL to the public HTTPS URL\}/);
});
