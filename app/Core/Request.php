<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        private array $attributes = []
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = $_POST;

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            $method,
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $_GET,
            $body,
            $_SERVER
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $basePath = parse_url((string) Config::get('app.url', ''), PHP_URL_PATH) ?: '';
        $path = '/' . ltrim($this->uri, '/');
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        if ($path === '/index.php') {
            $path = '/';
        } elseif (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        return '/' . trim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? 'unknown');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function expectsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
