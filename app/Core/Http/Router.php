<?php

/**
 * Lightweight, dependency-free HTTP router mapping paths to controller actions.
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Http;

/**
 * Lightweight, dependency-free router. Patterns use {param} placeholders
 * matched against a single path segment, or {param*} matched greedily
 * across slashes. Register greedy patterns last — dispatch() returns on
 * the first match in registration order, so an earlier greedy pattern
 * would shadow every fixed route after it.
 */
final class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, handler: callable, name: string|null}>
     */
    private array $routes = [];

    private ?\Closure $notFoundHandler = null;

    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = $handler(...);
    }

    public function get(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $name);
    }

    public function post(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $name);
    }

    public function put(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('PUT', $pattern, $handler, $name);
    }

    public function patch(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('PATCH', $pattern, $handler, $name);
    }

    public function delete(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('DELETE', $pattern, $handler, $name);
    }

    public function add(string $method, string $pattern, callable $handler, ?string $name = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'name' => $name,
        ];
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $path = $this->normalizePath((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));
        $method = strtoupper($method);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);

            if ($params === null) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];

                continue;
            }

            return ($route['handler'])($params);
        }

        if ($allowedMethods !== []) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));

            return null;
        }

        http_response_code(404);

        if ($this->notFoundHandler !== null) {
            return ($this->notFoundHandler)();
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $decoded = rawurldecode($path);

        // Always starts with '/', so it can never be empty — even for the
        // root path, trim('/') on '/' yields '', and '/' . '' is '/'.
        return '/' . trim($decoded, '/');
    }

    /**
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . (($m[2] ?? '') === '*' ? '.+' : '[^/]+') . ')',
            $pattern,
        );

        if ($regex === null) {
            return null;
        }

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
