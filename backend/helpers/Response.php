<?php

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

    public static function error(string $message, int $status = 400, mixed $errors = null): array {
        $body = ['status' => 'error', 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        return self::json($body, $status);
    }

    public static function notFound(string $message = 'Resource not found'): array {
        return self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): array {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): array {
        return self::error($message, 403);
    }

    public static function serverError(string $message = 'Internal server error'): array {
        return self::error($message, 500);
    }
}
