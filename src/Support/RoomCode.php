<?php
/**
 * Codigo de sala autoexplicativo para descoberta entre computadores.
 *
 * Antes, o codigo era apenas um identificador local: ele so existia no banco
 * de quem criou a sala. Como cada instalacao tem o proprio banco, quem entrava
 * com o codigo em outro computador consultava o banco errado e recebia
 * "Sala nao encontrada".
 *
 * O novo codigo carrega o IPv4 alcancavel do anfitriao, um nonce e um
 * checksum. Assim o convidado resolve o endereco sem depender de um servidor
 * central de salas. O endereco so funciona se o anfitriao estiver alcancavel
 * (Radmin VPN, LAN ou porta publica encaminhada).
 */

namespace GamingCalls\Support;

class RoomCode
{
    /** Prefixo que diferencia os codigos novos dos codigos locais antigos. */
    public const PREFIX = 'G';

    /** 1 prefixo + 11 caracteres base32. */
    public const LENGTH = 12;

    /** Porta publica do Caddy. O projeto expoe a aplicacao por HTTPS nela. */
    public const PORT = 8443;

    public const SCHEME = 'https';

    /** Alfabeto sem 0/1/I/O para evitar confusao ao ditar o codigo. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PAYLOAD_CHARS = 11;
    private const NONCE_BITS = 16;
    private const CHECKSUM_BITS = 5;
    private const RESERVED_BITS = 2;

    public static function generate(string $host, ?int $nonce = null): string
    {
        $ip = self::ipv4ToInt($host);
        if ($ip === null) {
            throw new \InvalidArgumentException('Endereco IPv4 invalido para o codigo da sala');
        }

        $nonce ??= random_int(0, (1 << self::NONCE_BITS) - 1);
        $nonce &= (1 << self::NONCE_BITS) - 1;
        $checksum = self::checksum($ip, $nonce);

        $value = ($ip << (self::NONCE_BITS + self::CHECKSUM_BITS + self::RESERVED_BITS))
            | ($nonce << (self::CHECKSUM_BITS + self::RESERVED_BITS))
            | ($checksum << self::RESERVED_BITS);

        return self::PREFIX . self::base32Encode($value, self::PAYLOAD_CHARS);
    }

    /**
     * @return array{host:string, port:int, scheme:string, code:string, url:string}|null
     */
    public static function decode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (strlen($code) !== self::LENGTH || $code[0] !== self::PREFIX) {
            return null;
        }

        $payload = substr($code, 1);
        $value = self::base32Decode($payload);
        if ($value === null) {
            return null;
        }

        if (($value & ((1 << self::RESERVED_BITS) - 1)) !== 0) {
            return null;
        }

        $checksum = ($value >> self::RESERVED_BITS) & ((1 << self::CHECKSUM_BITS) - 1);
        $nonceShift = self::CHECKSUM_BITS + self::RESERVED_BITS;
        $nonce = ($value >> $nonceShift) & ((1 << self::NONCE_BITS) - 1);
        $ip = ($value >> ($nonceShift + self::NONCE_BITS)) & 0xFFFFFFFF;

        if ($checksum !== self::checksum($ip, $nonce)) {
            return null;
        }

        $host = self::intToIpv4($ip);
        if (!self::isShareableIpv4($host)) {
            return null;
        }

        return [
            'host' => $host,
            'port' => self::PORT,
            'scheme' => self::SCHEME,
            'code' => $code,
            'url' => self::SCHEME . '://' . $host . ':' . self::PORT . '/room.php?code=' . rawurlencode($code),
        ];
    }

    public static function isEncoded(string $code): bool
    {
        return strlen(trim($code)) === self::LENGTH
            && strtoupper(trim($code)[0] ?? '') === self::PREFIX;
    }

    public static function isShareableIpv4(string $ip): bool
    {
        $long = self::ipv4ToInt($ip);
        if ($long === null) {
            return false;
        }

        $first = ($long >> 24) & 0xFF;
        $second = ($long >> 16) & 0xFF;
        if ($first === 0 || $first === 127 || $first >= 224) {
            return false;
        }
        if ($first === 169 && $second === 254) {
            return false;
        }

        return true;
    }

    private static function checksum(int $ip, int $nonce): int
    {
        $bytes = [
            ($ip >> 24) & 0xFF,
            ($ip >> 16) & 0xFF,
            ($ip >> 8) & 0xFF,
            $ip & 0xFF,
            ($nonce >> 8) & 0xFF,
            $nonce & 0xFF,
        ];

        $check = 0;
        foreach ($bytes as $index => $byte) {
            $check = (($check * 33) + $byte + $index) & 0x1F;
        }
        return $check;
    }

    private static function base32Encode(int $value, int $length): string
    {
        $out = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $out .= self::ALPHABET[($value >> ($i * 5)) & 0x1F];
        }
        return $out;
    }

    private static function base32Decode(string $payload): ?int
    {
        if (strlen($payload) !== self::PAYLOAD_CHARS) {
            return null;
        }

        $value = 0;
        for ($i = 0; $i < self::PAYLOAD_CHARS; $i++) {
            $index = strpos(self::ALPHABET, $payload[$i]);
            if ($index === false) {
                return null;
            }
            $value = ($value << 5) | $index;
        }
        return $value;
    }

    private static function ipv4ToInt(string $ip): ?int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $long = ip2long($ip);
        return $long === false ? null : ($long & 0xFFFFFFFF);
    }

    private static function intToIpv4(int $ip): string
    {
        return long2ip($ip & 0xFFFFFFFF);
    }
}
