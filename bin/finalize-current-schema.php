<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Environment;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

Environment::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');
$database = Database::connection();

$database->exec(
    "INSERT INTO windows_event_types (code, name) VALUES
     ('agent_exit', 'Agent Exited'),
     ('agent_uninstall', 'Agent Uninstall Started'),
     ('activity', 'Keyboard or Mouse Activity')
     ON DUPLICATE KEY UPDATE name = VALUES(name)"
);

$record = $database->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (:migration)');
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $record->execute(['migration' => pathinfo($file, PATHINFO_FILENAME)]);
}

echo count($files) . " migration version(s) recorded for the current base schema.\n";
