<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Session;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => trim((string) $value, "\"'"),
        };
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    $name = e((string) config('security.csrf.token_name', '_token'));
    return '<input type="hidden" name="' . $name . '" value="' . e(csrf_token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return Session::get('_old_input.' . $key, $default);
}

function app_url(string $path = ''): string
{
    return (string) config('app.url') . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function active_path(string $path): string
{
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';
    if ($base !== '' && str_starts_with($current, $base)) {
        $current = substr($current, strlen($base)) ?: '/';
    }
    return ($path === '/dashboard' ? $current === $path : str_starts_with($current, $path)) ? 'active' : '';
}

function initials(?string $name): string
{
    $words = preg_split('/\s+/', trim((string) $name)) ?: [];
    return strtoupper(substr(implode('', array_map(static fn (string $word): string => $word[0] ?? '', array_slice($words, 0, 2))), 0, 2));
}

function local_datetime(?string $utcDateTime, string $format = 'd M Y, H:i'): string
{
    if (!$utcDateTime) {
        return '—';
    }
    return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')))
        ->format($format);
}

function format_minutes(mixed $minutes): string
{
    if ($minutes === null || $minutes === '') {
        return '—';
    }

    $total = max(0, (int) $minutes);
    $hours = intdiv($total, 60);
    $remaining = $total % 60;

    return $hours . 'h ' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) . 'm';
}
