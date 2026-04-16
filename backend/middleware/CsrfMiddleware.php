<?php

class CsrfMiddleware {
    public function handle(callable $next): mixed {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Skip safe methods
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next();
        }
        
        $cookieToken = $_COOKIE['XSRF-TOKEN'] ?? '';
        $headerToken = $this->getHeaderToken();
        
        if (empty($cookieToken) || empty($headerToken) || !hash_equals($cookieToken, $headerToken)) {
            return Response::forbidden('CSRF token mismatch');
        }
        
        return $next();
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
