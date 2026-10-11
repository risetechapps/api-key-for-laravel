<?php

namespace RiseTechApps\ApiKey\Support;

/**
 * Códigos de verificação em duas etapas (TOTP, RFC 6238).
 *
 * Compatível com Google Authenticator, Authy, 1Password e afins: segredo em
 * base32, HMAC-SHA1, passo de 30 segundos, 6 dígitos.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** URI que os aplicativos leem do QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function code(string $secret, ?int $timestep = null): string
    {
        $timestep ??= self::timestep();

        $hash = hash_hmac('sha1', pack('J', $timestep), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Passo de tempo em que o código confere, ou null.
     *
     * Aceita um passo antes e um depois, para relógio de celular adiantado ou
     * atrasado. Quem chama usa o passo devolvido para impedir que o mesmo
     * código seja usado duas vezes.
     */
    public static function verify(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', (string) $code)) {
            return null;
        }

        $now = self::timestep();

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    public static function timestep(): int
    {
        return intdiv(time(), self::PERIOD);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim($data, '='));
        $bits = '';

        foreach (str_split($data) as $char) {
            $pos = strpos(self::ALPHABET, $char);

            if ($pos === false) {
                continue;
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
