<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Session;
use App\Models\User;

final class Auth
{
    private static ?UserIdentity $user = null;

    public static function check(): bool
    {
        return self::user() instanceof UserIdentity;
    }

    public static function user(): ?UserIdentity
    {
        if (self::$user instanceof UserIdentity) {
            return self::$user;
        }

        $userId = Session::get('auth_user_id');
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        self::$user = (new User())->identityById((int) $userId);
        if (self::$user
            && (int) Session::get('auth_version', 0) !== self::$user->authVersion) {
            self::$user = null;
            Session::forget('auth_user_id');
            Session::forget('auth_version');
        }
        if (!self::$user) {
            Session::forget('auth_user_id');
        }
        return self::$user;
    }

    public static function login(UserIdentity $user): void
    {
        Session::regenerate();
        Session::put('auth_user_id', $user->id);
        Session::put('auth_version', $user->authVersion);
        self::$user = $user;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::invalidate();
    }
}
