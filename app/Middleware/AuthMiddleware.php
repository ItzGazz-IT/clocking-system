<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Contracts\Middleware;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                throw new HttpException(401, 'Authentication is required.');
            }
            return Response::redirect((string) config('app.url') . '/login');
        }
        $user = Auth::user();
        if ($user?->mustChangePassword
            && !in_array($request->path(), ['/account/password', '/logout'], true)) {
            if ($request->expectsJson()) {
                throw new HttpException(403, 'A password change is required before continuing.');
            }
            return Response::redirect((string) config('app.url') . '/account/password');
        }
        return $next($request);
    }
}
