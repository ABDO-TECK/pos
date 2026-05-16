<?php

namespace App\Middleware;

use App\Helpers\EnvLoader;

/**
 * CorsMiddleware — يعالج طلبات Cross-Origin Resource Sharing.
 * يسمح بالأصول المعروفة + أي أصل من الشبكة المحلية (LAN).
 */
class CorsMiddleware
{
    public function handle(callable $next): mixed
    {
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'https://localhost:5173',
            'https://127.0.0.1:5173',
            'file://',
            'app://.',
            FRONTEND_URL,
        ];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

        // السماح بأصل من IP الشبكة المحلية (LAN)
        if (!$originAllowed && $origin !== '') {
            $lanPattern = '#^https?://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d+)?$#';
            if (preg_match($lanPattern, $origin) === 1) {
                $originAllowed = true;
            }
        }

        // السماح بطلبات بدون Origin (Electron file:// protocol)
        if ($origin === '' && php_sapi_name() === 'cli-server') {
            $originAllowed = true;
        }

        if ($originAllowed && $origin !== '') {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-XSRF-TOKEN');
        header('Access-Control-Allow-Credentials: true');

        // Preflight
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        return $next();
    }
}
