<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        return self::$connection = self::open();
    }

    public static function open(array $overrides = []): PDO
    {
        $configuration = array_replace((array) Config::get('database'), $overrides);
        $options = (array) $configuration['options'];
        if (!empty($configuration['ssl_ca']) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = (string) $configuration['ssl_ca'];
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) $configuration['ssl_verify'];
            }
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $configuration['host'],
            $configuration['port'],
            $configuration['database'],
            $configuration['charset']
        );

        try {
            $connection = new PDO(
                $dsn,
                (string) $configuration['username'],
                (string) $configuration['password'],
                $options
            );
            $connection->exec("SET time_zone = '+00:00'");
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database.', 0, $exception);
        }

        return $connection;
    }

    private function __construct()
    {
    }
}
