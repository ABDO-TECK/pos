<?php

namespace Tests\Unit;

use App\Core\Router;
use App\Helpers\ErrorCodes;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class RouterVersionTest extends TestCase
{
    public function testUnsupportedApiVersionIsRejectedWithoutV1Rewrite(): void
    {
        $originalServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v2/health';
        http_response_code(200);
        $outputLevel = ob_get_level();

        try {
            ob_start();
            (new Router())->dispatch();
            $body = json_decode((string) ob_get_clean(), true);

            $this->assertSame(404, http_response_code());
            $this->assertSame('error', $body['status']);
            $this->assertSame(ErrorCodes::UNSUPPORTED_API_VERSION, $body['error_code']);
            $this->assertSame('v2', $body['errors']['requested_version']);
            $this->assertSame(['v1'], $body['errors']['supported_versions']);
        } finally {
            while (ob_get_level() > $outputLevel) {
                ob_end_clean();
            }
            $_SERVER = $originalServer;
            http_response_code(200);
        }
    }

    public function testUnsupportedRequestDoesNotClaimRequestedVersionWasServed(): void
    {
        $originalServer = $_SERVER;
        $router = new Router();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v99';

        try {
            ob_start();
            $router->dispatch();
            ob_end_clean();

            $property = new ReflectionProperty(Router::class, 'apiVersion');
            $this->assertSame('v1', $property->getValue($router));
        } finally {
            $_SERVER = $originalServer;
            http_response_code(200);
        }
    }
}
