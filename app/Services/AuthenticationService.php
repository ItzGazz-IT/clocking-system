<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Config;
use App\Models\User;

final class AuthenticationService
{
    public function __construct(
        private readonly User $users = new User(),
        private readonly LoginThrottleService $throttle = new LoginThrottleService()
    ) {
    }

    public function attempt(string $username, string $password, string $ipAddress): bool
    {
        $username = strtolower(trim($username));
        if ($this->throttle->tooManyAttempts($username, $ipAddress)) {
            return false;
        }

        $user = $this->users->findActiveByUsername($username);
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $passwordIsValid = password_verify($password, (string) ($user['password_hash'] ?? $dummyHash));
        if (!$user || !$passwordIsValid) {
            $this->throttle->recordFailure($username, $ipAddress);
            return false;
        }

        $this->throttle->clear($username, $ipAddress);
        if (password_needs_rehash(
            (string) $user['password_hash'],
            Config::get('security.password.algorithm', PASSWORD_DEFAULT),
            (array) Config::get('security.password.options', [])
        )) {
            $this->users->updatePasswordHash(
                (int) $user['id'],
                password_hash(
                    $password,
                    Config::get('security.password.algorithm', PASSWORD_DEFAULT),
                    (array) Config::get('security.password.options', [])
                )
            );
        }

        $identity = $this->users->identityById((int) $user['id']);
        if (!$identity) {
            return false;
        }

        Auth::login($identity);
        $this->users->updateLastLogin($identity->id);
        return true;
    }
}
