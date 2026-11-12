<?php
/**
 * Gaming Calls Platform - Configuration Manager
 */

namespace GamingCalls\Config;

class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(string $configPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        // Load from config.php
        if ($configPath === null) {
            $configPath = __DIR__ . '/../../config/config.php';
        }

        if (file_exists($configPath)) {
            self::$config = require $configPath;
        }

        // Override with config/local.php if exists (not versioned)
        $localPath = dirname($configPath) . '/local.php';
        if ($localPath !== $configPath && file_exists($localPath)) {
            self::$config = array_replace_recursive(self::$config, require $localPath);
        }

        // Override with .env if exists
        $envPath = dirname($configPath) . '/../.env';
        if (file_exists($envPath)) {
            self::loadEnv($envPath);
        }

        // Override with environment variables
        self::loadFromEnv();

        self::$loaded = true;
    }

    private static function loadEnv(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');
            self::setNested(self::envKeyToConfigKey($key), $value);
        }
    }

    /**
     * APP_BASE_URL -> app.base_url, DB_HOST -> database.host, WS_PORT -> websocket.port
     * (só o primeiro "_" separa a seção da chave).
     */
    private static function envKeyToConfigKey(string $key): string
    {
        $aliases = ['db' => 'database', 'ws' => 'websocket'];
        $parts = explode('_', strtolower($key), 2);
        if (count($parts) < 2) {
            return $parts[0];
        }
        $section = $aliases[$parts[0]] ?? $parts[0];
        return $section . '.' . $parts[1];
    }

    private static function loadFromEnv(): void
    {
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || 
                str_starts_with($key, 'SESSION_') || str_starts_with($key, 'WS_') ||
                str_starts_with($key, 'WEBRTC_') || str_starts_with($key, 'ROOM_') ||
                str_starts_with($key, 'SECURITY_') || str_starts_with($key, 'LOG_')) {
                self::setNested(self::envKeyToConfigKey($key), (string) $value);
            }
        }
    }

    private static function setNested(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $ref = &self::$config;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = self::parseValue($value);
    }

    private static function parseValue(string $value): mixed
    {
        if ($value === '') {
            return '';
        }
        // Try JSON first
        if (($value[0] === '[' || $value[0] === '{') && ($decoded = json_decode($value, true)) !== null) {
            return $decoded;
        }
        // Booleans
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        // Numbers
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }
        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        $parts = explode('.', $key);
        $value = self::$config;
        
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        if (!self::$loaded) {
            self::load();
        }
        self::setNested($key, $value);
    }

    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config;
    }
}