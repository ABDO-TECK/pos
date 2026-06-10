<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\HealthService;

class HealthServiceTest extends TestCase
{
    private HealthService $service;

    protected function setUp(): void
    {
        $this->service = new HealthService();
    }

    public function testRunHealthCheckReturnsExpectedStructure()
    {
        // هذا الاختبار سينجح فقط إذا كانت قاعدة البيانات متصلة
        // إذا لم تكن متصلة، سيظل يعمل لكن healthy=false
        $result = $this->service->runHealthCheck();

        $this->assertArrayHasKey('healthy', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertIsBool($result['healthy']);
        $this->assertIsArray($result['checks']);
        $this->assertIsArray($result['warnings']);
    }

    public function testRunHealthCheckContainsAllCheckKeys()
    {
        $result = $this->service->runHealthCheck();

        // يجب أن يحتوي على كل أقسام الفحص
        $this->assertArrayHasKey('database', $result['checks']);
        $this->assertArrayHasKey('disk', $result['checks']);
        $this->assertArrayHasKey('memory', $result['checks']);
        $this->assertArrayHasKey('php', $result['checks']);
    }

    public function testRunHealthCheckMemoryIsAlwaysOk()
    {
        $result = $this->service->runHealthCheck();

        // الذاكرة يجب أن تكون OK دائماً (لأننا لا نستهلك كثير في الاختبار)
        $this->assertEquals('ok', $result['checks']['memory']['status']);
        $this->assertGreaterThan(0, $result['checks']['memory']['usage_mb']);
    }

    public function testRunHealthCheckPhpVersion()
    {
        $result = $this->service->runHealthCheck();

        $this->assertEquals(PHP_VERSION, $result['checks']['php']['version']);
        $this->assertIsBool($result['checks']['php']['extensions']['pdo_mysql']);
    }
}
