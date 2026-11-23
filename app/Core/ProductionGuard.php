<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ProductionGuard
{
    public static function validate(): void
    {
        if (Config::get('app.environment') !== 'production') {
            return;
        }

        $failures = [];
        if (Config::get('app.debug', false)) {
            $failures[] = 'APP_DEBUG must be false';
        }
        if (!str_starts_with((string) Config::get('app.url'), 'https://')) {
            $failures[] = 'APP_URL must use HTTPS';
        }
        if (!Config::get('session.secure', false)) {
            $failures[] = 'SESSION_SECURE must be true';
        }
        if (strlen((string) Config::get('app.key', '')) < 64) {
            $failures[] = 'APP_KEY must contain at least 64 characters';
        }
        if (Config::get('database.ssl_verify') && !Config::get('database.ssl_ca')) {
            $failures[] = 'DB_SSL_CA must point to the database CA certificate when DB_SSL_VERIFY=true';
        }
        if ($failures !== []) {
            throw new RuntimeException('Unsafe production configuration: ' . implode('; ', $failures));
        }
    }
}
