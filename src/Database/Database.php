<?php
/**
 * Gaming Calls Platform - Database Connection
 * Provides a singleton PDO connection
 */

namespace GamingCalls\Database;

use PDO;
use PDOException;
use GamingCalls\Config\Config;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    private function __construct() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    public static function setConfig(array $config): void
    {
        self::$config = $config;
        // Reset instance to force reconnection with new config
        self::$instance = null;
    }

    private static function createConnection(): PDO
    {
        $config = self::$config ?: Config::get('database');
        
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options'] ?? []);
            return $pdo;
        } catch (PDOException $e) {
            if (Config::get('app.debug')) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
            throw new \RuntimeException('Database connection failed', 0, $e);
        }
    }

    public static function close(): void
    {
        self::$instance = null;
    }
}