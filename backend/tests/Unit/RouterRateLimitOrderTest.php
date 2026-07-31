<?php

namespace Tests\Unit;

use App\Core\Container;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CompressionMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\DeleteRateLimiter;
use App\Middleware\HttpsMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\ReadRateLimiter;
use App\Middleware\TimingMiddleware;
use App\Middleware\WriteRateLimiter;
use App\Services\AuthService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RouterRateLimitOrderTest extends TestCase
{
    public function testAuthPopulatesUserBeforeMethodRateLimiterRuns(): void
    {
        $container = new Container();
        $authService = new AuthService();
        $container->singleton(AuthService::class, $authService);
        $observedUserId = null;

        foreach ([
            HttpsMiddleware::class,
            CompressionMiddleware::class,
            TimingMiddleware::class,
            CsrfMiddleware::class,
            WriteRateLimiter::class,
            DeleteRateLimiter::class,
        ] as $middlewareClass) {
            $middleware = $this->createMock($middlewareClass);
            $middleware->method('handle')
                ->willReturnCallback(static fn(callable $next): mixed => $next());
            $container->singleton($middlewareClass, $middleware);
        }

        $authMiddleware = $this->createMock(AuthMiddleware::class);
        $authMiddleware->method('handle')
            ->willReturnCallback(
                static function (callable $next) use ($authService): mixed {
                    $authService->setUser([
                        'id' => 42,
                        'role' => 'admin',
                    ]);
                    return $next();
                }
            );
        $container->singleton(AuthMiddleware::class, $authMiddleware);

        $readLimiter = $this->createMock(ReadRateLimiter::class);
        $readLimiter->method('handle')
            ->willReturnCallback(
                static function (callable $next) use (
                    $authService,
                    &$observedUserId
                ): mixed {
                    $observedUserId = $authService->id();
                    return $next();
                }
            );
        $container->singleton(ReadRateLimiter::class, $readLimiter);

        $router = new Router($container);
        $prepare = new ReflectionMethod($router, 'prepareMiddlewares');
        $run = new ReflectionMethod($router, 'runMiddlewares');
        $middlewares = $prepare->invoke($router, [
            AuthMiddleware::class,
            PermissionMiddleware::require('test.permission'),
        ]);
        $finalCalled = false;

        $run->invoke(
            $router,
            $middlewares,
            static function () use (&$finalCalled): void {
                $finalCalled = true;
            }
        );

        $this->assertSame(42, $observedUserId);
        $this->assertTrue($finalCalled);
    }
}
