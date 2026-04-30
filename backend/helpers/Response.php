<?php

namespace App\Helpers;


class Response {

    public static function json(mixed $data, int $status = 200): array {
        return [
            'status_code' => $status,
            'body'        => $data
        ];
    }

    public static function success(mixed $data = null, ?string $message = 'success', int $status = 200, array $extra = []): array {
        $body = ['status' => 'success', 'message' => $message ?? 'success'];
        if ($data !== null) {
            $body['data'] = $data;
        }
        foreach ($extra as $key => $value) {
            $body[$key] = $value;
        }
        return self::json($body, $status);
    }

    public static function cacheable(mixed $data = null, int $ttl = 60, ?string $etag = null, array $extra = []): array {
        if ($etag === null) {
            $etag = md5(json_encode($data));
        }

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ifNoneMatch = trim($ifNoneMatch, '"W/ ');

        $headers = [
            'Cache-Control' => "public, max-age={$ttl}",
            'ETag'          => 'W/"' . $etag . '"'
        ];

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            $response = self::json(null, 304);
            $response['headers'] = $headers;
            return $response;
        }

        $response = self::success($data, 'success', 200, $extra);
        $response['headers'] = $headers;
        return $response;
    }

    public static function error(string $message, int $status = 400, mixed $errors = null, ?string $errorCode = null): array {
        $body = ['status' => 'error', 'message' => $message];
        
        if ($errorCode !== null) {
            $body['error_code'] = $errorCode;
        }

        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        return self::json($body, $status);
    }

    public static function notFound(string $message = 'Resource not found', ?string $errorCode = ErrorCodes::NOT_FOUND): array {
        return self::error($message, 404, null, $errorCode);
    }

    public static function unauthorized(string $message = 'Unauthorized', ?string $errorCode = ErrorCodes::UNAUTHORIZED): array {
        return self::error($message, 401, null, $errorCode);
    }

    public static function forbidden(string $message = 'Forbidden', ?string $errorCode = ErrorCodes::FORBIDDEN): array {
        return self::error($message, 403, null, $errorCode);
    }

    public static function serverError(string $message = 'Internal server error', ?string $errorCode = ErrorCodes::SERVER_ERROR): array {
        return self::error($message, 500, null, $errorCode);
    }
}
