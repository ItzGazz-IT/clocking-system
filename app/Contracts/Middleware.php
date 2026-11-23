<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Core\Request;
use App\Core\Response;
use Closure;

interface Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response;
}

