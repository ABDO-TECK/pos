<?php

class Router {
    private array $routes = [];
    private Container $container;

    public function __construct(?Container $container = null) {
        $this->container = $container ?? new Container();
    }

    public function get(string $path, array $handler): void {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void {
        $this->routes[] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip /backend/api prefix if running under /pos/backend
        $base = '/pos/backend';
        if (str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        // ── API Versioning ────────────────────────────────────────
        // دعم /api/v1/... → /api/... (backward-compatible)
        // يسمح باستخدام /api/v1/products أو /api/products بنفس النتيجة
        $uri = preg_replace('#^/api/v\d+/#', '/api/', $uri);

        foreach ($this->routes as $route) {
            $params = $this->match($route['method'], $route['path'], $method, $uri);
            if ($params !== null) {
                [$controllerClass, $action, $middlewares] = $this->parseHandler($route['handler']);
                
                // Inject CsrfMiddleware globally as the first middleware
                array_unshift($middlewares, CsrfMiddleware::class);

                $response = $this->runMiddlewares($middlewares, function () use ($controllerClass, $action, $params) {
                    $controller = $this->container->get($controllerClass);
                    return $controller->$action(...array_values($params));
                });
                $this->sendResponse($response);
                return;
            }
        }

        $this->sendResponse(Response::notFound('Route not found'));
    }

    private function sendResponse(mixed $response): void {
        if (is_array($response) && isset($response['status_code'], $response['body'])) {
            http_response_code($response['status_code']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else if (is_string($response)) {
            echo $response;
        }
    }

    private function match(string $routeMethod, string $routePath, string $method, string $uri): ?array {
        if ($routeMethod !== $method) {
            return null;
        }

        // Convert route path to regex: /products/{id} -> /products/([^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $uri, $matches)) {
            return null;
        }

        // Extract param names
        preg_match_all('/\{([a-zA-Z_]+)\}/', $routePath, $paramNames);
        $params = [];
        foreach ($paramNames[1] as $index => $name) {
            $params[$name] = $matches[$index + 1];
        }

        return $params;
    }

    private function parseHandler(array $handler): array {
        $controllerClass = $handler[0];
        $action          = $handler[1];
        $middlewares     = $handler[2] ?? [];
        return [$controllerClass, $action, $middlewares];
    }

    private function runMiddlewares(array $middlewares, callable $final): mixed {
        $chain = array_reduce(
            array_reverse($middlewares),
            fn($next, $mw) => fn() => (new $mw())->handle($next),
            $final
        );
        return $chain();
    }
}
