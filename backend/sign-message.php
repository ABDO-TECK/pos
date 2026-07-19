<?php
/**
 * QZ Tray — Sign-message endpoint
 *
 * Signs the QZ Tray request string with the private RSA key and returns the
 * base64-encoded SHA-512 signature.
 *
 * Key file location is configured via QZ_PRIVATE_KEY_PATH in .env:
 *   QZ_PRIVATE_KEY_PATH="C:/private/private-key.pem"
 *
 * If the variable is not set, the following fallback paths are tried:
 *   - C:/private/private-key.pem
 *   - (backend)/storage/private-key.pem
 *
 * To use unsigned / anonymous mode (development only), set:
 *   QZ_PRIVATE_KEY_PATH=""
 */

// Resolve base directory correctly inside or outside phar
$pharRunning = Phar::running(false);
$baseDir = $pharRunning ? dirname($pharRunning) : __DIR__;

// Load .env so QZ_PRIVATE_KEY_PATH is available
require_once __DIR__ . '/Helpers/EnvLoader.php';
use App\Helpers\EnvLoader;
$envPath = getenv('ENV_PATH') ?: $baseDir . '/.env';
EnvLoader::load($envPath);

// Allow CORS — restricted to known origins only (security fix)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedSignOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'https://localhost:5173',
    'https://127.0.0.1:5173',
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'https://localhost:8080',
    'https://127.0.0.1:8080',
    'app://pos-app',
    'app://.',
];
// Also allow LAN IPs for thermal printing from tablets on local network
$signOriginAllowed = in_array($origin, $allowedSignOrigins, true);
if (!$signOriginAllowed && $origin !== '') {
    $lanPattern = '#^https?://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})(:\d+)?$#';
    $signOriginAllowed = preg_match($lanPattern, $origin) === 1;
}
if ($signOriginAllowed && $origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Security: restrict to localhost only ───────────────────────────────────
// This endpoint signs arbitrary data with the private key and must not be
// accessible from remote clients. Only local processes (QZ Tray) should call it.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo 'Access denied: localhost only';
    exit(1);
}

// ── Key file path ──────────────────────────────────────────────────────────
// Read from .env first; fall back to known locations.
$envKeyPath = EnvLoader::get('QZ_PRIVATE_KEY_PATH', '');

$KEY = null;
if ($envKeyPath !== '' && file_exists($envKeyPath)) {
    $KEY = $envKeyPath;
} else {
    // Fallback paths (only used when .env key is not set)
    $possibleKeys = [
        'C:/private/private-key.pem',
        $baseDir . '/storage/private-key.pem',
    ];
    foreach ($possibleKeys as $path) {
        if (file_exists($path)) { $KEY = $path; break; }
    }
}

// ── Anonymous / unsigned fallback (if no key file exists) ─────────────────
if ($KEY === null) {
    // In unsigned mode QZ Tray's security.setSignaturePromise should call
    // resolve() with no argument. We just return an empty body so the JS
    // resolve() fallback in qzPrint.js takes over.
    header("Content-type: text/plain");
    echo '';
    exit(0);
}

// ── Sign the request ───────────────────────────────────────────────────────
$req = $_GET['request'] ?? '';

if ($req === '' || strlen($req) > 2048) {
    http_response_code(400);
    echo 'Invalid request parameter';
    exit(1);
}

$privateKey = openssl_get_privatekey(file_get_contents($KEY));

if (!$privateKey) {
    http_response_code(500);
    echo 'Error loading private key';
    exit(1);
}

$signature = null;
openssl_sign($req, $signature, $privateKey, "sha512");

if ($signature) {
    header("Content-type: text/plain");
    echo base64_encode($signature);
    exit(0);
}

http_response_code(500);
echo 'Error signing message';
exit(1);
?>
