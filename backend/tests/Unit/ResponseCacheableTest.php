<?php
use PHPUnit\Framework\TestCase;
use App\Helpers\Response;

class ResponseCacheableTest extends TestCase
{
    public function testCacheableReturnsETag()
    {
        $data = ['key' => 'value'];
        $response = Response::cacheable($data, 60);
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('headers', $response);
        $this->assertArrayHasKey('ETag', $response['headers']);
    }

    public function testCacheableReturns304WhenETagMatches()
    {
        $data = ['key' => 'value'];
        // ETag is now computed from the full response body, not just $data
        $fullBody = ['status' => 'success', 'message' => 'success', 'data' => $data];
        $etag = md5(json_encode($fullBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"' . $etag . '"';
        $response = Response::cacheable($data, 60);
        $this->assertEquals(304, $response['status_code']);
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }
}
