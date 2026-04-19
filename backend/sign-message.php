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
 *   - (project root)/private-key.pem
 *
 * To use unsigned / anonymous mode (development only), set:
 *   QZ_PRIVATE_KEY_PATH=""
 */

// Load .env so QZ_PRIVATE_KEY_PATH is available
require_once __DIR__ . '/helpers/EnvLoader.php';
EnvLoader::load(__DIR__ . '/.env');

// Allow CORS for credentials
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (empty($_COOKIE['pos_token'])) {
    http_response_code(401);
    exit('Unauthorized: session cookie missing');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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
        __DIR__ . '/../private-key.pem',
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
$req        = $_GET['request'] ?? '';
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
