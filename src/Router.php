<?php

declare(strict_types=1);

namespace Elonn\Time;

final class Router
{
    /** @var array<string, array<int, array{pattern: string, handler: callable(array<string, string>): void}>> */
    private array $routes = [];

    /**
     * @param callable(array<string, string>): void $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(array<string, string>): void $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * @param callable(array<string, string>): void $handler
     */
    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    /**
     * @param callable(array<string, string>): void $handler
     */
    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);

        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                $route['handler']($params);
                return;
            }
        }

        Response::json(['error' => 'Not Found'], 404);
    }

    /**
     * @param callable(array<string, string>): void $handler
     */
    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][] = [
            'pattern' => $this->normalizePath($path),
            'handler' => $handler,
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '//' ? '/' : $normalized;
    }

    /**
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if ($pattern === '/') {
            return $path === '/' ? [] : null;
        }

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $index => $part) {
            $pathPart = $pathParts[$index];
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $params[substr($part, 1, -1)] = $pathPart;
                continue;
            }

            if ($part !== $pathPart) {
                return null;
            }
        }

        return $params;
    }
}
