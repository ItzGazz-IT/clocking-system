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
$hasBaseSchema = (int) $database->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'companies'"
)->fetchColumn() > 0;

if ($hasBaseSchema) {
    echo "Base schema already exists.\n";
    exit(0);
}

$sql = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = array_values(array_filter(array_map(
    'trim',
    preg_split('/;\s*(?:\R|$)/', $sql) ?: []
)));

foreach ($statements as $statement) {
    $database->exec($statement);
}

echo count($statements) . " base schema statement(s) applied.\n";
