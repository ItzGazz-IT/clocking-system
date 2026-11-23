<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    /** @param callable|array{class-string, string} $action @param list<string> $middleware */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $action,
        public readonly array $middleware = []
    ) {
    }
}

