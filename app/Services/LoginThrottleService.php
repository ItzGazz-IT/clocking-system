<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

final class LoginThrottleService
{
    public function tooManyAttempts(string $email, string $ipAddress): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip_address = :ip_address
               AND succeeded = 0
               AND attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :decay MINUTE)'
        );
        $statement->bindValue('email', $email);
        $statement->bindValue('ip_address', $ipAddress);
        $statement->bindValue('decay', (int) Config::get('security.login.decay_minutes', 15), \PDO::PARAM_INT);
        $statement->execute();
        return (int) $statement->fetchColumn() >= (int) Config::get('security.login.max_attempts', 5);
    }

    public function recordFailure(string $email, string $ipAddress): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (:email, :ip_address, 0)'
        );
        $statement->execute(['email' => $email, 'ip_address' => $ipAddress]);
    }

    public function clear(string $email, string $ipAddress): void
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip_address'
        );
        $statement->execute(['email' => $email, 'ip_address' => $ipAddress]);
    }
}

