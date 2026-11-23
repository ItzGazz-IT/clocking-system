<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) Config::get('session.lifetime', 120) * 60;
        session_name((string) Config::get('session.name', 'attendance_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        $lastRegeneration = (int) ($_SESSION['_regenerated_at'] ?? 0);
        if (time() - $lastRegeneration >= (int) Config::get('session.regenerate_interval', 900)) {
            session_regenerate_id(true);
            $_SESSION['_regenerated_at'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    public static function flash(string $type, string $message): void
    {
        self::put('_flash', ['type' => $type, 'message' => $message]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();
    }

    public static function invalidate(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parameters['path'], '', $parameters['secure'], true);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        $key = (string) Config::get('security.csrf.session_key', '_csrf_token');
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[$key];
    }
}
