<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Environment;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Environment::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');

$password = rtrim((string) stream_get_contents(STDIN), "\r\n");
$statement = Database::connection()->query(
    "SELECT u.password_hash, u.is_active, c.name company_name, r.slug role_slug,
            COUNT(DISTINCT rp.permission_id) permission_count
     FROM users u
     JOIN companies c ON c.id = u.company_id
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     LEFT JOIN role_permissions rp ON rp.role_id = r.id
     WHERE u.username = 'admin' AND u.deleted_at IS NULL
     GROUP BY u.id, u.password_hash, u.is_active, c.name, r.slug"
);
$admin = $statement->fetch();

if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
    throw new RuntimeException('Admin verification failed.');
}

echo sprintf(
    "Verified: company=%s, active=%s, role=%s, permissions=%d, password=valid\n",
    $admin['company_name'],
    (int) $admin['is_active'] === 1 ? 'yes' : 'no',
    $admin['role_slug'],
    (int) $admin['permission_count']
);
