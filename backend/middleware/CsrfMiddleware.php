<?php

namespace App\Middleware;

use App\Helpers\Response;


class CsrfMiddleware {
    
    /** Routes that don't require CSRF verification (pre-auth) */
    private array $exempt = [
        '/api/login',
        '/api/csrf-cookie',
    ];
    
    public function handle(callable $next): mixed {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Skip safe methods
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next();
        }
        
        // Skip exempt routes (pre-authentication)
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $uri = preg_replace('#^/pos/backend#', '', $uri);
        $uri = preg_replace('#^/api/v\d+/#', '/api/', $uri);
        foreach ($this->exempt as $path) {
            if ($uri === $path) {
                return $next();
            }
        }

        // Skip CSRF check for desktop runtime (Electron)
        // Only trust the Origin header (app:// or file://) — NOT User-Agent,
        // because User-Agent is trivially spoofable by any HTTP client.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // Only trust the exact Electron app origin — not any app:// or file:// origin
        if ($origin === 'app://pos-app') {
            return $next();
        }
        
        $cookieNonce = $_COOKIE['XSRF-TOKEN'] ?? '';
        $headerToken = $this->getHeaderToken();
        
        if (empty($cookieNonce) || empty($headerToken)) {
            return Response::forbidden('CSRF token missing');
        }

        // HMAC-signed double-submit: verify that the header token is HMAC(nonce, secret)
        // This prevents XSS from bypassing CSRF — even if JS reads the cookie nonce,
        // it cannot forge the HMAC without the server-side secret.
        $expectedSignature = hash_hmac('sha256', $cookieNonce, self::getCsrfSecret());

        if (!hash_equals($expectedSignature, $headerToken)) {
            return Response::forbidden('CSRF token mismatch');
        }
        
        return $next();
    }
    
    /**
     * Retrieve the CSRF HMAC secret.
     * Uses CSRF_SECRET from .env if set, otherwise derives one from DB credentials.
     */
    public static function getCsrfSecret(): string
    {
        $secret = \App\Helpers\EnvLoader::get('CSRF_SECRET', '');
        if ($secret === '') {
            // Derive a stable, deployment-unique secret from DB config
            $secret = hash('sha256', DB_HOST . DB_NAME . DB_USER . DB_PASS . '__csrf_salt__');
        }
        return $secret;
    }

    private function getHeaderToken(): string {
        $header = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
        if (empty($header) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['X-XSRF-TOKEN'] ?? $headers['x-xsrf-token'] ?? '';
        }
        return $header;
    }
}
