<?php
/**
 * Gaming Calls Platform - URL helpers
 *
 * Gera URLs de assets e do WebSocket a partir da própria requisição, em vez de
 * depender de um `app.base_url` fixo. Assim o CSS/JS carrega em qualquer host,
 * porta ou subpasta (php -S, Apache/XAMPP, Nginx, Docker).
 */

namespace GamingCalls\Support;

class Url
{
    /**
     * Caminho base da aplicação (ex.: "" na raiz, "/gaming-calls" em subpasta).
     */
    public static function basePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        // Páginas ficam em public/ (ou public/api/rooms/); sobe até a raiz pública.
        $dir = str_replace('\\', '/', dirname($script));
        $dir = preg_replace('#/api(/[^/]+)*$#', '', $dir);
        return rtrim($dir === '.' ? '' : $dir, '/');
    }

    /**
     * URL pública de um asset, com versão (mtime) para evitar CSS/JS em cache antigo.
     */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname(__DIR__, 2) . '/public/' . $path;
        $version = is_file($file) ? '?v=' . filemtime($file) : '';
        return self::basePath() . '/' . $path . $version;
    }

    /**
     * Origem completa da requisição + caminho base (ex.: https://site.com/gaming-calls).
     */
    public static function absolute(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . self::basePath();
    }

    /**
     * Base do link de convite: http://<IP do Radmin>:8080 quando o host o informa
     * (variável de ambiente RADMIN_IP, definida pelo start-host.bat); senão, a origem da requisição.
     */
    public static function inviteBase(): string
    {
        $ip = trim((string) getenv('RADMIN_IP'));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $port = (int) ($_SERVER['SERVER_PORT'] ?? 8080) ?: 8080;
            return 'http://' . $ip . ':' . $port . self::basePath();
        }
        return self::absolute();
    }

    /**
     * URL do servidor WebSocket (ws:// ou wss:// conforme o protocolo da página).
     */
    public static function websocket(int $port = 8081): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Atrás de proxy HTTPS (Caddy/Nginx): o WebSocket passa pelo mesmo host em /ws
        if ($https) {
            return 'wss://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ws';
        }
        $host = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
        return 'ws://' . $host . ':' . $port;
    }
}