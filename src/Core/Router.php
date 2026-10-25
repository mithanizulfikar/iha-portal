<?php
declare(strict_types=1);

namespace Iha\Core;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void  { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, callable $handler): void { $this->routes['POST'][$path] = $handler; }

    public function dispatch(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        if ($method === 'POST' && !Csrf::valid($_POST['_csrf'] ?? null)) {
            Response::error(419, 'Session expired', 'Your form session has expired. Please go back, refresh the page and try again.');
            return;
        }
        $handler();
    }
}
