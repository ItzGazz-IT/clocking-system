<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Contracts\Middleware;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class PermissionMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        $permission = (string) ($parameters[0] ?? '');
        if (!Auth::user()?->can($permission)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
        return $next($request);
    }
}

