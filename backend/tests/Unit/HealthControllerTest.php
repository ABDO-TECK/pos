<?php

namespace Tests\Unit;

use App\Controllers\HealthController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Services\HealthService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class HealthControllerTest extends TestCase
{
    public function testPublicHealthContainsOnlySafeLivenessFields(): void
    {
        $service = new class extends HealthService {
            public function getLiveness(): array
            {
                return ['status' => 'ok', 'version' => '1.2.3+build'];
            }

            public function runHealthCheck(): array
            {
                throw new \RuntimeException('C:\\secret\\database path and raw connection error');
            }
        };

        $response = (new HealthController($service))->check();

        $this->assertSame(200, $response['status_code']);
        $this->assertSame(['status', 'version'], array_keys($response['body']));
        $serialized = json_encode($response['body']);
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString('connection error', $serialized);
        $this->assertStringNotContainsString('ws_port', $serialized);
        $this->assertStringNotContainsString('checks', $serialized);
    }

    public function testDeepDiagnosticsRouteRequiresAuthAndSettingsPermission(): void
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/system.php';

        $property = new ReflectionProperty(Router::class, 'routes');
        $routes = $property->getValue($router);
        $route = current(array_filter(
            $routes,
            static fn (array $candidate): bool => $candidate['path'] === '/api/health/diagnostics'
        ));

        $this->assertIsArray($route);
        $this->assertSame([
            AuthMiddleware::class,
            PermissionMiddleware::require('settings.update'),
        ], $route['handler'][2]);
    }
}
