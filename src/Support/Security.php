<?php
/**
 * Grape - Security headers
 *
 * Centraliza os cabecalhos de seguranca das paginas HTML. A Content-Security-
 * Policy usa nonce (nao 'unsafe-inline' para scripts), porque as paginas
 * embutem um bloco <script> com window.APP_CONFIG.
 *
 * Uso na pagina:
 *
 *     $nonce = Security::nonce();
 *     Security::sendHeaders();
 *     ...
 *     <script nonce="<?= $nonce ?>"> ... </script>
 */

namespace GamingCalls\Support;

class Security
{
    private static ?string $nonce = null;
    private static bool $sent = false;

    /**
     * Nonce do CSP desta request. Gerado uma unica vez.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }
        return self::$nonce;
    }

    /**
     * Origem do WebSocket liberada no connect-src (mesmo host da pagina).
     */
    private static function websocketOrigins(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return '';
        }
        // Remove userinfo/porta antes e reaproveita a porta do WS, se veio por proxy.
        $hostOnly = parse_url('//' . $host, PHP_URL_HOST);
        if (!$hostOnly) {
            return '';
        }
        $wsPort = (int) (\GamingCalls\Config\Config::get('websocket.port', 8081));
        return ' ws://' . $hostOnly . ':' . $wsPort . ' wss://' . $hostOnly;
    }

    /**
     * Envia os cabecalhos de seguranca. Idempotente.
     */
    public static function sendHeaders(): void
    {
        if (self::$sent || headers_sent()) {
            return;
        }
        self::$sent = true;

        $nonce = self::nonce();

        $csp = implode('; ', [
            "default-src 'self'",
            // Sem 'unsafe-inline': apenas os <script nonce=...> e arquivos locais.
            "script-src 'self' 'nonce-{$nonce}'",
            // 'unsafe-inline' e necessario para atributos style="" usados pelo JS.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "media-src 'self' data: blob:",
            // WebRTC/WebSocket: conexoes para o mesmo host (ws/wss)
            "connect-src 'self'" . self::websocketOrigins() . ' blob:',
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);

        header('Content-Security-Policy: ' . $csp);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
