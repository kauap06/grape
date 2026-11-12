<?php
namespace GamingCalls\Session;

use GamingCalls\Config\Config;
use GamingCalls\Database\Database;

/**
 * Gaming Calls Platform
 * Session Manager
 */

class SessionManager
{
    private static bool $started = false;

    private static ?string $sessionId = null;

    /**
     * Inicializa a sessão.
     */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        $config = Config::get('session', []);

        // Verificar se outra parte da aplicação já iniciou a sessão.
        if (session_status() !== PHP_SESSION_ACTIVE) {

            // Configurar nome da sessão.
            if (!empty($config['name'])) {
                session_name($config['name']);
            }

            // Tempo de vida da sessão.
            $lifetime = (int) ($config['lifetime'] ?? 7200);

            // Configurações de segurança.
            $secure = (bool) (
                $config['secure']
                ?? Config::get('session.secure', false)
            );

            $httpOnly = true;

            $sameSite = $config['samesite'] ?? 'Lax';

            // Configurar armazenamento e duração.
            ini_set('session.gc_maxlifetime', (string) $lifetime);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $secure ? '1' : '0');

            // Configuração dos cookies.
            // PHP 8 utiliza array associativo.
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => $config['path'] ?? '/',
                'domain' => $config['domain'] ?? '',
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $sameSite
            ]);

            // Iniciar sessão.
            if (!session_start()) {
                throw new \RuntimeException(
                    'Não foi possível iniciar a sessão.'
                );
            }
        }

        self::$started = true;
        self::$sessionId = session_id();

        // Inicialização e proteção da sessão.
        if (!isset($_SESSION['initiated'])) {

            session_regenerate_id(true);

            self::$sessionId = session_id();

            $_SESSION['initiated'] = true;
            $_SESSION['created_at'] = time();
            $_SESSION['last_activity'] = time();

        } else {

            $_SESSION['last_activity'] = time();
        }

        // Persistir sessão no banco de dados.
        self::persistSession();
    }

    /**
     * Salva os dados da sessão no banco.
     */
    public static function persistSession(): void
    {
        if (
            !self::$started ||
            !self::$sessionId ||
            session_status() !== PHP_SESSION_ACTIVE
        ) {
            return;
        }

        try {

            $db = Database::getInstance();

            $stmt = $db->prepare(
                "INSERT INTO `sessions`
                    (`id`, `data`, `last_activity`, `created_at`)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `data` = VALUES(`data`),
                    `last_activity` = VALUES(`last_activity`)"
            );

            $stmt->execute([
                self::$sessionId,
                serialize($_SESSION),
                time(),
                $_SESSION['created_at'] ?? time()
            ]);

        } catch (\Throwable $e) {

            error_log(
                'Session persist error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Retorna o identificador da sessão.
     */
    public static function getId(): ?string
    {
        return self::$sessionId;
    }

    /**
     * Recupera um valor da sessão.
     */
    public static function get(
        string $key,
        mixed $default = null
    ): mixed {

        self::start();

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Define um valor na sessão.
     */
    public static function set(
        string $key,
        mixed $value
    ): void {

        self::start();

        $_SESSION[$key] = $value;

        self::persistSession();
    }

    /**
     * Verifica se uma chave existe.
     */
    public static function has(string $key): bool
    {
        self::start();

        return array_key_exists($key, $_SESSION);
    }

    /**
     * Remove uma chave da sessão.
     */
    public static function remove(string $key): void
    {
        self::start();

        unset($_SESSION[$key]);

        self::persistSession();
    }

    /**
     * Limpa os dados da sessão.
     */
    public static function clear(): void
    {
        self::start();

        $_SESSION = [];

        self::$sessionId = session_id();

        self::persistSession();
    }

    /**
     * Destrói a sessão atual.
     */
    public static function destroy(): void
    {
        self::start();

        $sessionId = self::$sessionId;

        $cookieParams = session_get_cookie_params();
        $cookieName = session_name();

        // Remover os dados da sessão.
        $_SESSION = [];

        session_unset();
        session_destroy();

        // Expirar o cookie no navegador.
        if (!headers_sent()) {

            setcookie($cookieName, '', [
                'expires' => time() - 42000,
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $cookieParams['secure'],
                'httponly' => $cookieParams['httponly'],
                'samesite' => $cookieParams['samesite'] ?? 'Lax'
            ]);
        }

        self::$started = false;
        self::$sessionId = null;

        // Remover registro do banco.
        if ($sessionId) {

            try {

                $db = Database::getInstance();

                $stmt = $db->prepare(
                    "DELETE FROM `sessions` WHERE `id` = ?"
                );

                $stmt->execute([$sessionId]);

            } catch (\Throwable $e) {

                error_log(
                    'Session destroy error: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Regenera o identificador da sessão.
     */
    public static function regenerate(): void
    {
        self::start();

        $oldSessionId = self::$sessionId;

        if (!session_regenerate_id(true)) {
            throw new \RuntimeException(
                'Não foi possível regenerar o identificador da sessão.'
            );
        }

        self::$sessionId = session_id();

        // Remover registro antigo do banco.
        if ($oldSessionId && $oldSessionId !== self::$sessionId) {

            try {

                $db = Database::getInstance();

                $stmt = $db->prepare(
                    "DELETE FROM `sessions` WHERE `id` = ?"
                );

                $stmt->execute([$oldSessionId]);

            } catch (\Throwable $e) {

                error_log(
                    'Session regeneration error: ' . $e->getMessage()
                );
            }
        }

        self::persistSession();
    }

    /**
     * Gera ou recupera o token CSRF.
     */
    public static function getCsrfToken(): string
    {
        if (!self::has('csrf_token')) {

            $length = (int) Config::get(
                'security.csrf_token_length',
                32
            );

            $length = max(16, min($length, 128));

            self::set(
                'csrf_token',
                bin2hex(random_bytes($length))
            );
        }

        return self::get('csrf_token');
    }

    /**
     * Valida o token CSRF.
     */
    public static function validateCsrfToken(
        string $token
    ): bool {

        $storedToken = self::getCsrfToken();

        return hash_equals($storedToken, $token);
    }

    /**
     * Verifica se existe usuário autenticado.
     */
    public static function isAuthenticated(): bool
    {
        return self::has('user_id');
    }

    /**
     * Recupera o ID do usuário.
     */
    public static function getUserId(): ?string
    {
        return self::get('user_id');
    }

    /**
     * Define o ID do usuário.
     */
    public static function setUserId(string $userId): void
    {
        self::set('user_id', $userId);
    }

    /**
     * Recupera o nome de exibição.
     */
    public static function getDisplayName(): ?string
    {
        return self::get('display_name');
    }

    /**
     * Define o nome de exibição.
     */
    public static function setDisplayName(string $name): void
    {
        self::set('display_name', $name);
    }

    /**
     * Recupera o avatar.
     */
    public static function getAvatar(): ?string
    {
        return self::get('avatar');
    }

    /**
     * Define o avatar.
     */
    public static function setAvatar(string $avatar): void
    {
        self::set('avatar', $avatar);
    }

    /**
     * Recupera o ID da sala atual.
     */
    public static function getCurrentRoomId(): ?int
    {
        return self::get('room_id');
    }

    /**
     * Define a sala atual.
     */
    public static function setCurrentRoomId(int $roomId): void
    {
        self::set('room_id', $roomId);
    }

    /**
     * Remove a sala atual.
     */
    public static function clearCurrentRoom(): void
    {
        self::remove('room_id');
    }
}
