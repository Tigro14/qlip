<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Signature : conversion d'une signature brute (r || s, comme la renvoie le
 * navigateur) en DER attendu par openssl_verify.
 */
final class Signature
{
    /** @return array{key: string, alg: string, type: string} */
    public static function verify(string $message, string $signature, array $key): bool
    {
        // Une signature ECDSA arrive brute (r || s) depuis le navigateur ; RSA
        // arrive deja en DER.
        if (str_starts_with($key['alg'], 'ES')) {
            $signature = self::toDer($signature, intdiv(strlen($signature), 2));
        }

        $result = openssl_verify($message, $signature, $key['key'], self::algorithm($key['alg']));

        return $result === 1;
    }

    /** Signature brute (r || s) -> DER SEQUENCE { INTEGER r, INTEGER s }. */
    public static function toDer(string $raw, int $size): string
    {
        if ($size <= 0 || strlen($raw) !== 2 * $size) {
            throw new \InvalidArgumentException('Signature brute de taille inattendue.');
        }

        $r = substr($raw, 0, $size);
        $s = substr($raw, $size);

        // Les entiers DER sont signes : un zero de tete est ajoute si le premier
        // bit est a 1, et les zeros superflus sont retires. La longueur est donc
        // calculee apres cette normalisation.
        $r = self::integer($r);
        $s = self::integer($s);

        return "\x30" . self::length(strlen($r) + strlen($s) + 4)
            . "\x02" . self::length(strlen($r)) . $r
            . "\x02" . self::length(strlen($s)) . $s;
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            return "\x00";
        }

        return ord($value[0]) >= 0x80 ? "\x00" . $value : $value;
    }

    private static function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length % 256) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 + strlen($bytes)) . $bytes;
    }

    private static function algorithm(string $alg): int
    {
        return match ($alg) {
            'ES256', 'RS256' => OPENSSL_ALGO_SHA256,
            'ES384', 'RS384' => OPENSSL_ALGO_SHA384,
            'ES512', 'RS512' => OPENSSL_ALGO_SHA512,
            default => OPENSSL_ALGO_SHA256,
        };
    }
}
