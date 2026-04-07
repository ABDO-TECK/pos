<?php

abstract class Controller {

    protected function getBody(): array {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function getParam(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    protected function validate(array $data, array $rules): array {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $ruleList = explode('|', $rule);
            foreach ($ruleList as $r) {
                if ($r === 'required' && (empty($data[$field]) && $data[$field] !== 0 && $data[$field] !== '0')) {
                    $errors[$field][] = "$field is required";
                } elseif (str_starts_with($r, 'min:')) {
                    $min = (int) substr($r, 4);
                    if (isset($data[$field]) && strlen((string)$data[$field]) < $min) {
                        $errors[$field][] = "$field must be at least $min characters";
                    }
                } elseif ($r === 'numeric' && isset($data[$field]) && !is_numeric($data[$field])) {
                    $errors[$field][] = "$field must be numeric";
                } elseif ($r === 'email' && isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "$field must be a valid email";
                }
            }
        }
        return $errors;
    }

    protected function sanitize(mixed $value): string {
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
    }
}
