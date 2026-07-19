<?php

namespace App\Middleware\Traits;

/**
 * ClientIpTrait — Shared proxy-aware IP detection for rate limiters.
 * Extracts the real client IP considering trusted proxies.
 */
trait ClientIpTrait
{
    /**
     * Extract the real client IP, considering trusted proxy headers.
     */
    protected function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : ['127.0.0.1', '::1'];

        if (in_array($remoteAddr, $trustedProxies, true)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                return trim($ips[0]);
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                return trim($_SERVER['HTTP_X_REAL_IP']);
            }
        }

        return $remoteAddr;
    }
}
