<?php
/**
 * Grape - Situacao da CA do HTTPS local
 *
 * O Caddyfile usa "tls internal", entao a CA que o navegador precisa confiar e
 * a raiz do proprio Caddy. Esta classe descobre onde o arquivo .crt esta neste
 * PC e se ele ja foi instalado na loja de raizes confiaveis do Windows.
 */

namespace GamingCalls\Support;

final class CertificateAuthority
{
    /** Prefixo das chaves de registro onde o Windows guarda as raizes. */
    private const STORE_KEY = 'Software\\Microsoft\\SystemCertificates\\Root\\Certificates\\';

    /**
     * Arquivos candidatos, do mais provavel ao fallback:
     * 1. CA do Caddy deste PC (quem hospeda a sala);
     * 2. copia distribuida para os convidados;
     * 3. CA propria gerada por deploy\ca\generate-root-ca.bat.
     *
     * @return string[]
     */
    public static function candidates(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = [];

        $appData = getenv('APPDATA');
        if (is_string($appData) && $appData !== '') {
            $paths[] = rtrim($appData, '/\\') . '/Caddy/pki/authorities/local/root.crt';
        }

        $paths[] = $root . '/deploy/ca/dist/caddy-root.crt';
        $paths[] = $root . '/deploy/ca/dist/grape-root.crt';

        return $paths;
    }

    /** Primeiro arquivo de CA existente neste PC. */
    public static function find(): ?string
    {
        foreach (self::candidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** Caminho do instalador, quando existir. */
    public static function installer(): ?string
    {
        $file = dirname(__DIR__, 2) . '/deploy/ca/install-ca.bat';
        return is_file($file) ? $file : null;
    }

    /**
     * Dados do certificado (impressao digital, emissor, validade).
     *
     * @return array{thumbprint: ?string, name: ?string, subject: ?string, expiresAt: ?string, expired: ?bool}
     */
    public static function inspect(string $file): array
    {
        $dados = ['thumbprint' => null, 'name' => null, 'subject' => null, 'expiresAt' => null, 'expired' => null];

        $pem = @file_get_contents($file);
        if (!is_string($pem) || $pem === '') {
            return $dados;
        }

        // Impressao digital = SHA-1 do DER (mesmo valor que o Windows mostra).
        $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $der = $base64 !== null ? base64_decode($base64, true) : false;
        if (is_string($der) && $der !== '') {
            $dados['thumbprint'] = strtoupper(sha1($der));
        }

        $info = @openssl_x509_parse($pem);
        if (is_array($info)) {
            $dados['subject'] = isset($info['name']) ? (string) $info['name'] : null;
            if (isset($info['subject']['CN'])) {
                $dados['name'] = (string) $info['subject']['CN'];
            }
            $expira = $info['validTo_time_t'] ?? null;
            if (is_int($expira) || is_float($expira)) {
                $dados['expiresAt'] = gmdate('c', (int) $expira);
                $dados['expired'] = (int) $expira < time();
            }
        }

        return $dados;
    }

    /**
     * Lojas do Windows onde a raiz esta instalada.
     * Retorna null quando nao da para verificar (outro sistema ou exec off).
     *
     * @return string[]|null
     */
    public static function installedStores(?string $thumbprint): ?array
    {
        if ($thumbprint === null || PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            return null;
        }

        $lojas = [];
        $hives = ['HKCU' => 'usuário atual', 'HKLM' => 'computador'];

        foreach ($hives as $hive => $rotulo) {
            $chave = $hive . '\\' . self::STORE_KEY . $thumbprint;
            $saida = [];
            $codigo = 1;
            @exec('reg query ' . escapeshellarg($chave) . ' 2>&1', $saida, $codigo);
            if ($codigo === 0) {
                $lojas[] = $rotulo;
            }
        }

        return $lojas;
    }

    /**
     * Tudo o que a interface precisa saber sobre a CA deste PC.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $arquivo = self::find();
        $instalador = self::installer();
        $info = $arquivo !== null ? self::inspect($arquivo) : null;
        $lojas = self::installedStores($info['thumbprint'] ?? null);

        return [
            'platform' => PHP_OS_FAMILY,
            'certPath' => $arquivo !== null ? self::windowsPath($arquivo) : null,
            'installerPath' => $instalador !== null ? self::windowsPath($instalador) : null,
            'thumbprint' => $info['thumbprint'] ?? null,
            'name' => $info['name'] ?? null,
            'subject' => $info['subject'] ?? null,
            'expiresAt' => $info['expiresAt'] ?? null,
            'expired' => $info['expired'] ?? null,
            'installed' => $lojas !== null ? $lojas !== [] : null,
            'stores' => $lojas ?? [],
            'checkAvailable' => $lojas !== null,
        ];
    }

    /** Windows usa barra invertida nos caminhos mostrados ao usuario. */
    public static function windowsPath(string $path): string
    {
        return str_replace('/', '\\', $path);
    }
}
