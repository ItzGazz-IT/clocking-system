<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\Middleware;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

final class CsrfMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        $name = (string) Config::get('security.csrf.token_name', '_token');
        if (!hash_equals(Session::csrfToken(), (string) $request->input($name, ''))) {
            throw new HttpException(419, 'The security token is invalid or expired.');
        }
        return $next($request);
    }
}

