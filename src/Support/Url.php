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
     * IPv4 que outros computadores podem usar para chegar a este host.
     *
     * A ordem importa: GRAPE_HOST_IP vem do start-host.bat e é o endereço
     * compartilhado (Radmin VPN, LAN ou IP público encaminhado). RADMIN_IP é o
     * fallback/bind local. O Host da requisição é o fallback quando a aplicação
     * foi aberta diretamente pelo endereço de rede. Loopback e link-local nunca
     * são compartilhados entre usuários.
     */
    public static function hostAddress(): ?string
    {
        foreach (['GRAPE_HOST_IP', 'RADMIN_IP'] as $name) {
            $candidate = trim((string) getenv($name));
            if (RoomCode::isShareableIpv4($candidate)) {
                return $candidate;
            }
        }

        $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^\[([^\]]+)\]/', $requestHost, $m)) {
            $requestHost = $m[1];
        } else {
            $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: '';
        }

        return RoomCode::isShareableIpv4($requestHost) ? $requestHost : null;
    }

    /**
     * Base do link de convite: a origem pública (https://<IP>:8443)
     * quando existe uma interface de rede compartilhável.
     */
    public static function inviteBase(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Atrás do Caddy, SERVER_PORT é a porta interna do PHP (8181). O host
        // público chega em HTTP_HOST, com a porta 8443.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = 0;
        if (preg_match('/:(\d+)$/', $host, $m)) {
            $port = (int) $m[1];
            $host = substr($host, 0, -strlen($m[0]));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (int) $_SERVER['HTTP_X_FORWARDED_PORT'];
        } elseif (!empty($_SERVER['SERVER_PORT'])) {
            $port = (int) $_SERVER['SERVER_PORT'];
        }

        $scheme = $https ? 'https' : 'http';
        $defaultPort = $https ? 443 : 80;
        if ($port <= 0) {
            $port = $defaultPort;
        }

        $sharedHost = self::hostAddress();
        if ($sharedHost !== null) {
            $host = $sharedHost;
        }

        $suffix = $port !== $defaultPort ? ':' . $port : '';
        return $scheme . '://' . $host . $suffix . self::basePath();
    }

    /**
     * URL do servidor WebSocket (ws:// ou wss:// conforme o protocolo da página).
     */
    public static function websocket(int $port = 8182): string
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