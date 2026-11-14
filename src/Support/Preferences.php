<?php
/**
 * Grape - Preferencias de interface por navegador
 *
 * As preferencias (nome, avatar, dispositivos, layout, mudo) ficam no
 * localStorage para resposta imediata, e sao espelhadas no banco para
 * sobreviverem a limpeza de cache, troca de origem (localhost <-> IP) e
 * expiracao da sessao.
 *
 * A chave e um cookie aleatorio de longa duracao (grape_pref), sem relacao
 * com a sessao: identifica o navegador, nao o usuario. Nao e HttpOnly de
 * proposito? Sim, e: o JS nao precisa ler o cookie, ele acompanha as
 * requisicoes sozinho.
 */

namespace GamingCalls\Support;

use GamingCalls\Database\Database;

class Preferences
{
    public const COOKIE = 'grape_pref';
    private const LIFETIME = 31536000; // 1 ano
    private const MAX_BYTES = 65536;   // 64 KB

    /**
     * Chave do navegador atual. Cria o cookie na primeira visita.
     * Retorna null se nao houver chave valida (primeira chamada em GET).
     */
    public static function key(bool $create = true): ?string
    {
        $key = $_COOKIE[self::COOKIE] ?? '';
        if (preg_match('/^[a-f0-9]{32,64}$/', $key) === 1) {
            return $key;
        }

        if (!$create) {
            return null;
        }

        $key = bin2hex(random_bytes(24));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if (!headers_sent()) {
            setcookie(self::COOKIE, $key, [
                'expires' => time() + self::LIFETIME,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $key;

        return $key;
    }

    /**
     * Preferencias salvas para o navegador atual (array vazio se nao houver).
     */
    public static function load(?string $key = null): array
    {
        $key = $key ?? self::key(false);
        if ($key === null) {
            return [];
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT `data` FROM `user_preferences` WHERE `pref_key` = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $raw = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return []; // banco indisponivel: o localStorage continua valendo
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Grava (substitui) as preferencias do navegador atual.
     */
    public static function save(array $data, ?string $key = null): bool
    {
        $key = $key ?? self::key();
        if ($key === null) {
            return false;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return false;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `user_preferences` (`pref_key`, `data`)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)'
            );
            return $stmt->execute([$key, $json]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Descarta a chave atual (o proximo acesso gera outra).
     */
    public static function forget(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
        unset($_COOKIE[self::COOKIE]);
    }
}
