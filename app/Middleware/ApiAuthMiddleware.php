<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Contracts\Middleware;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class ApiAuthMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        if (Auth::check()) {
            if (Auth::user()?->mustChangePassword) {
                throw new HttpException(403, 'A password change is required before continuing.');
            }
            return $next($request);
        }

        $authorization = $this->authorizationHeader();
        if (!preg_match('/^Bearer ([^.]+)\.(.+)$/', $authorization, $matches)) {
            throw new HttpException(401, 'A valid device token is required.');
        }

        $statement = Database::connection()->prepare(
            'SELECT id, company_id, employee_id, api_key_hash FROM devices
             WHERE api_key_identifier = :identifier AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['identifier' => $matches[1]]);
        $device = $statement->fetch();
        if (!$device || !password_verify($matches[2], (string) $device['api_key_hash'])) {
            throw new HttpException(401, 'A valid device token is required.');
        }

        $request->setAttribute('device_id', (int) $device['id']);
        $request->setAttribute('device_company_id', (int) $device['company_id']);
        $request->setAttribute('device_employee_id', $device['employee_id'] === null ? null : (int) $device['employee_id']);
        Database::connection()->prepare('UPDATE devices SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $device['id']]);
        return $next($request);
    }

    private function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }
}
