<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class DatabaseSetup
{
    public const BASELINE = 'web_install_baseline';

    public static function provisioned(): bool
    {
        return is_file(self::path());
    }

    public static function path(): string
    {
        return BASE_PATH . '/config/database.local.json';
    }

    public static function connect(array $input): void
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        if (!preg_match('/\A[A-Za-z0-9.-]+\z/', $host) || $port === false
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $database) || $username === '') {
            throw new RuntimeException('Enter valid database connection details.');
        }

        $config = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => (string) ($input['database_password'] ?? ''),
        ];
        $lock = fopen(BASE_PATH . '/config/database-setup.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('The installer could not lock the local configuration.');
        }

        $temporaryPath = null;
        try {
            if (!flock($lock, LOCK_EX) || self::provisioned() || (string) env('DB_DATABASE', '') !== '') {
                throw new RuntimeException('The database connection has already been configured or is being configured.');
            }
            $pdo = Database::open($config);
            if ($pdo->query('SHOW FULL TABLES')->fetchColumn() !== false) {
                throw new RuntimeException('Choose an empty database for a new installation.');
            }

            $temporaryPath = tempnam(BASE_PATH . '/config', '.database-');
            $contents = json_encode($config, JSON_THROW_ON_ERROR);
            if ($temporaryPath === false || !chmod($temporaryPath, 0600)
                || file_put_contents($temporaryPath, $contents) !== strlen($contents)
                || !rename($temporaryPath, self::path())) {
                throw new RuntimeException('The installer could not save the local configuration.');
            }
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            fclose($lock);
        }
    }

    public static function initialized(PDO $pdo): bool
    {
        $check = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'");
        if ((int) $check->fetchColumn() === 0) {
            return false;
        }
        $statement = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = ?');
        $statement->execute([self::BASELINE]);
        return (bool) $statement->fetchColumn();
    }

    public static function lock(PDO $pdo): void
    {
        if ((int) $pdo->query("SELECT GET_LOCK(SHA2(CONCAT('clocking-setup:', DATABASE()), 256), 5)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another setup request is in progress.');
        }
    }

    public static function unlock(PDO $pdo): void
    {
        $pdo->query("SELECT RELEASE_LOCK(SHA2(CONCAT('clocking-setup:', DATABASE()), 256))")->closeCursor();
    }

    public static function initialize(): void
    {
        if (!self::provisioned()) {
            throw new RuntimeException('Connect a database through the installer first.');
        }
        $pdo = Database::connection();
        self::lock($pdo);
        try {
            if (self::initialized($pdo)) {
                return;
            }

            $schema = self::readSql('schema.sql') . "\n" . self::readSql('migrations/20260706_005_anomaly_detection.sql');
            preg_match_all('/^CREATE TABLE ([a-z_]+) \(/m', $schema, $matches);
            $existing = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($existing, $matches[1]) !== [] || in_array('users', $existing, true)
                && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                throw new RuntimeException('Choose a dedicated, empty database for installation.');
            }

            self::executeSql($pdo, str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $schema));
            $pdo->beginTransaction();
            self::executeSql($pdo, self::readSql('seed.sql'));
            $record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
            // The fresh schema includes migrations through this release.
            foreach (glob(BASE_PATH . '/database/migrations/*.sql') ?: [] as $file) {
                if (basename($file) > '20260915_001_autonomous_flexible_default.sql') {
                    continue;
                }
                $record->execute([pathinfo($file, PATHINFO_FILENAME)]);
            }
            $record->execute([self::BASELINE]);
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::unlock($pdo);
        }
    }

    private static function readSql(string $file): string
    {
        $sql = file_get_contents(BASE_PATH . '/database/' . $file);
        if ($sql === false) {
            throw new RuntimeException('An installation SQL file is missing.');
        }
        return (string) preg_replace('/^\h*--[^\r\n]*/m', '', $sql);
    }

    private static function executeSql(PDO $pdo, string $sql): void
    {
        foreach (preg_split('/;\h*(?:\R|$)/', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
