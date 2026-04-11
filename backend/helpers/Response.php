<?php

class Response {

    public static function json(mixed $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, ?string $message = 'success', int $status = 200, array $extra = []): void {
        $body = ['status' => 'success', 'message' => $message ?? 'success'];
        if ($data !== null) {
            $body['data'] = $data;
        }
        // دمج أي metadata إضافية (مثل pagination)
        foreach ($extra as $key => $value) {
            $body[$key] = $value;
        }
        self::json($body, $status);
    }

    public static function error(string $message, int $status = 400, mixed $errors = null): void {
        $body = ['status' => 'error', 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        self::json($body, $status);
    }

    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void {
        self::error($message, 403);
    }

    public static function serverError(string $message = 'Internal server error'): void {
        self::error($message, 500);
    }
}
