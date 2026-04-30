<?php

namespace App\Middleware;

class TimingMiddleware
{
    public function handle(callable $next): mixed
    {
        $start = hrtime(true);

        $response = $next();

        $elapsed = (hrtime(true) - $start) / 1e6; // ميلي ثانية

        if (is_array($response)) {
            $response['headers'] = $response['headers'] ?? [];
            $response['headers']['X-Response-Time'] = round($elapsed, 2) . 'ms';
            $response['headers']['Server-Timing'] = 'total;dur=' . round($elapsed, 2);
        }

        return $response;
    }
}
