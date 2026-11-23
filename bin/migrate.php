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
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$applied = $database->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);

$appliedCount = 0;
foreach ($files as $file) {
    $migration = pathinfo($file, PATHINFO_FILENAME);
    if (in_array($migration, $applied, true)) {
        echo "SKIP  {$migration}\n";
        continue;
    }

    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    $statements = array_values(array_filter(array_map(
        'trim',
        preg_split('/;\s*(?:\R|$)/', (string) $sql) ?: []
    )));
    foreach ($statements as $statement) {
        $database->exec($statement);
    }
    $record = $database->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (:migration)');
    $record->execute(['migration' => $migration]);
    $appliedCount++;
    echo "APPLY {$migration}\n";
}

echo $appliedCount === 0 ? "Database is current.\n" : "{$appliedCount} migration(s) applied.\n";

