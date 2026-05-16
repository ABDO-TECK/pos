<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Helpers\Response;

class ResponseTest extends TestCase
{
    public function testSuccessReturns200(): void
    {
        $r = Response::success(['id' => 1], 'ok');
        $this->assertEquals(200, $r['status_code']);
        $this->assertEquals('success', $r['body']['status']);
        $this->assertEquals('ok', $r['body']['message']);
        $this->assertEquals(['id' => 1], $r['body']['data']);
    }

    public function testSuccessWithExtra(): void
    {
        $r = Response::success([], 'ok', 200, ['pagination' => ['page' => 1]]);
        $this->assertArrayHasKey('pagination', $r['body']);
    }

    public function testErrorReturns400(): void
    {
        $r = Response::error('bad', 400);
        $this->assertEquals(400, $r['status_code']);
        $this->assertEquals('error', $r['body']['status']);
    }

    public function testNotFoundReturns404(): void
    {
        $r = Response::notFound('missing');
        $this->assertEquals(404, $r['status_code']);
    }

    public function testUnauthorizedReturns401(): void
    {
        $r = Response::unauthorized();
        $this->assertEquals(401, $r['status_code']);
    }

    public function testServerErrorReturns500(): void
    {
        $r = Response::serverError();
        $this->assertEquals(500, $r['status_code']);
    }

    public function testSuccessWithNullData(): void
    {
        $r = Response::success(null, 'deleted');
        $this->assertArrayNotHasKey('data', $r['body']);
    }
}
