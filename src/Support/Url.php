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
     * URL do servidor WebSocket (ws:// ou wss:// conforme o protocolo da página).
     *
     * Em localhost, sempre usa a porta explícita (ex.: ws://localhost:8081),
     * porque o servidor WebSocket (Ratchet) escuta na porta 8081 e não é
     * proxyado pelo Apache. Em hosts remotos atrás de proxy HTTPS (Caddy/Nginx),
     * o WebSocket pode ser acessado pelo próprio host no caminho /ws.
     */
    public static function websocket(int $port = 8081): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Remove porta do host, se houver, para obter apenas o hostname
        $hostOnly = parse_url('//' . $host, PHP_URL_HOST) ?: 'localhost';

        $isLocalhost = $hostOnly === 'localhost' || $hostOnly === '127.0.0.1';

        // Em localhost o servidor WebSocket (Ratchet) só aceita ws:// (sem SSL).
        // Nunca use wss:// em localhost, mesmo que a página esteja carregada via HTTPS.
        if ($isLocalhost) {
            return 'ws://' . $hostOnly . ':' . $port;
        }

        // Atrás de proxy HTTPS (Caddy/Nginx): o WebSocket passa pelo mesmo host em /ws
        if ($https) {
            return 'wss://' . $hostOnly . '/ws';
        }

        return 'ws://' . $hostOnly . ':' . $port;
    }
}