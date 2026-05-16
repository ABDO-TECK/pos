<?php
use PHPUnit\Framework\TestCase;
use App\Middleware\RateLimiter;

class RateLimiterTest extends TestCase
{
    public function testCheckDoesNotBlockUnderLimit()
    {
        $limiter = new RateLimiter(5, 60);
        // لا يجب أن يُوقف التطبيق عند أول طلب
        // ملاحظة: هذا الاختبار يعتمد على SQLite fallback
        ob_start();
        $limiter->check('test_rl', 99999);
        $output = ob_get_clean();
        $this->assertEmpty($output, 'First request should not be rate limited');
    }
}
