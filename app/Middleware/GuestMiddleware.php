<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Contracts\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class GuestMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        return Auth::check()
            ? Response::redirect((string) config('app.url') . '/dashboard')
            : $next($request);
    }
}

