<?php
/**
 * Router for PHP built-in server (replacement for Apache .htaccess)
 * يقدّم ملفات Frontend (SPA) + يوجّه طلبات API للـ Backend.
 * Usage: php -S 127.0.0.1:8080 -t backend router.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// ── 0. Rewrite /pos/backend/ to / (for compatibility with XAMPP paths) ──
if (str_starts_with($uri, '/pos/backend/')) {
    $uri = substr($uri, 12); // Keep the starting slash: '/sign-message.php'
}

// ── 1. API requests → forward to backend index.php ──────────
if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require __DIR__ . '/index.php';
    return true;
}

// ── 2. Backend static files (sign-message.php, etc.) ────────
if ($uri !== '/' && file_exists(__DIR__ . $uri) && is_file(__DIR__ . $uri)) {
    if (strtolower(pathinfo(__DIR__ . $uri, PATHINFO_EXTENSION)) === 'php') {
        require __DIR__ . $uri;
        return true;
    }
    return false; // PHP built-in server يقدّم الملف العادي
}

// ── 3. Frontend static files (JS, CSS, images) ─────────────
$frontendDist = __DIR__ . '/../frontend/dist';
$filePath = $frontendDist . $uri;

if ($uri !== '/' && file_exists($filePath) && is_file($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp',
        'txt'  => 'text/plain; charset=UTF-8',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime");
    readfile($filePath);
    return true;
}

// ── 3.5 Fallback to public directory for certificates etc. ──
$publicPath = __DIR__ . '/../frontend/public' . $uri;
if ($uri !== '/' && file_exists($publicPath) && is_file($publicPath)) {
    $ext = strtolower(pathinfo($publicPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'txt'  => 'text/plain; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime");
    readfile($publicPath);
    return true;
}

// ── 4. SPA fallback: أي مسار آخر → index.html ──────────────
$indexHtml = $frontendDist . '/index.html';
if (file_exists($indexHtml)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexHtml);
    return true;
}

http_response_code(404);
echo 'Not Found';
