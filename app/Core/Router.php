<?php

declare(strict_types=1);

namespace App\Core;

use App\Contracts\Middleware;
use Closure;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param callable|array{class-string, string} $action @param list<string> $middleware */
    public function get(string $path, mixed $action, array $middleware = []): void
    {
        $this->add('GET', $path, $action, $middleware);
    }

    /** @param callable|array{class-string, string} $action @param list<string> $middleware */
    public function post(string $path, mixed $action, array $middleware = []): void
    {
        $this->add('POST', $path, $action, $middleware);
    }

    /** @param callable|array{class-string, string} $action @param list<string> $middleware */
    private function add(string $method, string $path, mixed $action, array $middleware): void
    {
        $this->routes[] = new Route($method, '/' . trim($path, '/'), $action, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            $parameters = $this->match($route, $request);
            if ($parameters === null) {
                continue;
            }

            foreach ($parameters as $key => $value) {
                $request->setAttribute($key, $value);
            }

            $destination = fn (Request $request): Response => $this->invoke($route, $request, $parameters);
            $pipeline = array_reduce(
                array_reverse($route->middleware),
                fn (Closure $next, string $definition): Closure => $this->middleware($definition, $next),
                $destination
            );

            return $pipeline($request);
        }

        throw new HttpException(404, 'The requested resource was not found.');
    }

    /** @return array<string, string>|null */
    private function match(Route $route, Request $request): ?array
    {
        if ($route->method !== $request->method()) {
            return null;
        }

        $names = [];
        $pattern = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function (array $matches) use (&$names): string {
            $names[] = $matches[1];
            return '([^/]+)';
        }, $route->path);

        if (!preg_match('#^' . $pattern . '/?$#', $request->path(), $matches)) {
            return null;
        }

        array_shift($matches);
        return array_combine($names, array_map('urldecode', $matches)) ?: [];
    }

    /** @param array<string, string> $parameters */
    private function invoke(Route $route, Request $request, array $parameters): Response
    {
        if (is_callable($route->action)) {
            return ($route->action)($request, ...array_values($parameters));
        }

        [$controller, $method] = $route->action;
        return (new $controller())->{$method}($request, ...array_values($parameters));
    }

    private function middleware(string $definition, Closure $next): Closure
    {
        [$alias, $parameterList] = array_pad(explode(':', $definition, 2), 2, '');
        $middlewareClass = Application::middleware($alias);
        if (!is_a($middlewareClass, Middleware::class, true)) {
            throw new \LogicException("Invalid middleware: {$alias}");
        }
        $parameters = $parameterList === '' ? [] : explode(',', $parameterList);

        return fn (Request $request): Response => (new $middlewareClass())->handle($request, $next, ...$parameters);
    }
}

