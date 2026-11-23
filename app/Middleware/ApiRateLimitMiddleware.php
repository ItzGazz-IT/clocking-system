<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Auth;
use App\Contracts\Middleware;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class ApiRateLimitMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, mixed ...$parameters): Response
    {
        $identity = $request->attribute('device_id')
            ? 'device:' . $request->attribute('device_id')
            : (Auth::user() ? 'user:' . Auth::user()->id : 'ip:' . $request->ip());
        $rateKey = hash('sha256', $identity . '|' . $request->path());
        $window = max(10, (int) Config::get('security.api_rate_limit.window_seconds', 60));
        $limit = max(10, (int) Config::get('security.api_rate_limit.attempts', 120));

        $statement = Database::connection()->prepare(
            'INSERT INTO api_rate_limits (rate_key, attempts, window_started_at, expires_at)
             VALUES (:rate_key, 1, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :window SECOND))
             ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at <= CURRENT_TIMESTAMP, 1, attempts + 1),
                window_started_at = IF(expires_at <= CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, window_started_at),
                expires_at = IF(expires_at <= CURRENT_TIMESTAMP,
                    DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :window_reset SECOND), expires_at)'
        );
        $statement->bindValue('rate_key', $rateKey);
        $statement->bindValue('window', $window, \PDO::PARAM_INT);
        $statement->bindValue('window_reset', $window, \PDO::PARAM_INT);
        $statement->execute();

        $check = Database::connection()->prepare(
            'SELECT attempts FROM api_rate_limits WHERE rate_key = :rate_key'
        );
        $check->execute(['rate_key' => $rateKey]);
        if ((int) $check->fetchColumn() > $limit) {
            throw new HttpException(429, 'Too many API requests. Try again shortly.');
        }
        if (random_int(1, 100) === 1) {
            Database::connection()->exec('DELETE FROM api_rate_limits WHERE expires_at < CURRENT_TIMESTAMP');
        }
        return $next($request);
    }
}
