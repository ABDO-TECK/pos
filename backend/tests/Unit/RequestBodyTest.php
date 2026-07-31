<?php

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\RequestBody;
use App\Helpers\ErrorCodes;
use PHPUnit\Framework\TestCase;

class RequestBodyTest extends TestCase
{
    public function testRejectsDeclaredOversizeBodyWithoutReadingStream(): void
    {
        $stream = $this->stream('{"name":"value"}');

        try {
            RequestBody::readJson($stream, 1_001, 1_000);
            $this->fail('Expected an oversized payload exception.');
        } catch (HttpException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
            $this->assertSame(ErrorCodes::PAYLOAD_TOO_LARGE, $exception->getErrorCode());
            $this->assertSame(0, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testStreamingCapRejectsDishonestContentLengthBeforeFullRead(): void
    {
        $payload = '{"value":"' . str_repeat('x', 10_000) . '"}';
        $stream = $this->stream($payload);

        try {
            RequestBody::readJson($stream, 10, 128);
            $this->fail('Expected an oversized payload exception.');
        } catch (HttpException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
            $this->assertSame(ErrorCodes::PAYLOAD_TOO_LARGE, $exception->getErrorCode());
            $this->assertLessThanOrEqual(129, ftell($stream));
            $this->assertLessThan(strlen($payload), ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testRejectsMalformedJsonWithStructuredBadRequestMetadata(): void
    {
        $stream = $this->stream('{"missing":');

        try {
            RequestBody::readJson($stream, null, 1_000);
            $this->fail('Expected a malformed JSON exception.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame(ErrorCodes::MALFORMED_JSON, $exception->getErrorCode());
        } finally {
            fclose($stream);
        }
    }

    public function testReadsValidJsonObject(): void
    {
        $stream = $this->stream('{"items":[1,2]}');

        try {
            $this->assertSame(['items' => [1, 2]], RequestBody::readJson($stream, null, 1_000));
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }
}
