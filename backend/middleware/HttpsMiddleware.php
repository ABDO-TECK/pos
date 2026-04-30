<?php

namespace App\Middleware;

/**
 * HttpsMiddleware — يعيد التوجيه إلى HTTPS في بيئة الإنتاج.
 *
 * في وضع التطوير (APP_ENV !== 'production') يسمح بـ HTTP.
 * في الإنتاج: إذا كان الطلب HTTP → يرد بـ 301 redirect إلى HTTPS.
 */
class HttpsMiddleware
{
    public function handle(callable $next): mixed
    {
        // تخطي في وضع التطوير
        if (!defined('APP_ENV') || APP_ENV !== 'production') {
            return $next();
        }

        // فحص هل الاتصال HTTPS
        $isHttps = false;

        // 1. فحص مباشر (Apache/IIS)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $isHttps = true;
        }
        // 2. فحص عبر Proxy/Load Balancer
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $isHttps = true;
        }
        // 3. فحص المنفذ
        elseif (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            $isHttps = true;
        }

        if ($isHttps) {
            return $next();
        }

        // إعادة التوجيه إلى HTTPS (301 Permanent Redirect)
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $url  = 'https://' . $host . $uri;

        header('Location: ' . $url, true, 301);
        exit;
    }
}
