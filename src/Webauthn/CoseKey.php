<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Cle publique COSE (RFC 8392) -> PEM pour openssl_verify.
 *
 * Les labels sont des entiers negatives : kty = -1, alg = -2, courbe ou modulo
 * = -3, x = -4, y = -5 (courbes), n = -3, e = -4 (RSA).
 */
final class CoseKey
{
    private const KTYPES = ['EC2' => 2, 'RSA' => 1, 'OKP' => 1];

    /** @return array{key: string, alg: string, type: string} */
    public static function toPem(array $map): array
    {
        $kty = $map['-1'] ?? null;

        if ($kty === 2) {
            return self::ecPem($map);
        }

        if ($kty === 1) {
            return self::rsaPem($map);
        }

        throw new \InvalidArgumentException('Type de cle COSE non supporte.');
    }

    /** @return array{key: string, alg: string, type: string} */
    private static function ecPem(array $map): array
    {
        $x = (string) ($map['-4'] ?? '');
        $y = (string) ($map['-5'] ?? '');
        $curve = (string) ($map['-3'] ?? '');

        if ($x === '' || $y === '' || strlen($x) !== strlen($y)) {
            throw new \InvalidArgumentException('Cle COSE EC2 incomplete.');
        }

        $oid = match ($curve) {
            'P-256' => "\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'P-384' => "\x2b\x65\x04\x22\x00\x22",
            'P-521' => "\x2b\x65\x04\x22\x00\x23",
            default => null,
        };

        if ($oid === null) {
            throw new \InvalidArgumentException('Courbe COSE non supportee.');
        }

        // SubjectPublicKeyInfo : SEQUENCE { SEQUENCE { ecPublicKey, courbe }, BIT STRING (point) }
        // OID ecPublicKey = 1.2.840.10045.2.1 (le curve OID est distinct).
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . self::der(0x06, $oid));
        // Le premier octet du BIT STRING est le nombre de bits inutilises (0),
        // ajoute par derBitString : le point commence par 0x04 (point non compressé).
        $point = "\x04" . $x . $y;

        return ['key' => self::pem(0x30, $algorithm . self::derBitString($point)), 'alg' => self::algName($map), 'type' => 'EC2'];
    }

    /** @return array{key: string, alg: string, type: string} */
    private static function rsaPem(array $map): array
    {
        $modulus = (string) ($map['-3'] ?? '');
        $exponent = (string) ($map['-4'] ?? '');

        if ($modulus === '' || $exponent === '') {
            throw new \InvalidArgumentException('Cle COSE RSA incomplete.');
        }

        $publicKey = self::der(0x30, self::derInteger($modulus) . self::derInteger($exponent));
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . self::der(0x05, ''));

        return ['key' => self::pem(0x30, $algorithm . self::derBitString($publicKey)), 'alg' => self::algName($map), 'type' => 'RSA'];
    }

    private static function algName(array $map): string
    {
        return match ($map['-2'] ?? null) {
            -7 => 'ES256',
            -35 => 'ES384',
            -36 => 'ES512',
            -257 => 'RS256',
            default => 'inconnu',
        };
    }

    private static function der(int $tag, string $contents): string
    {
        return chr($tag) . self::derLength(strlen($contents)) . $contents;
    }

    private static function derBitString(string $contents): string
    {
        return self::der(0x03, "\x00" . $contents);
    }

    private static function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        if (ord($value[0]) >= 0x80) {
            $value = "\x00" . $value;
        }

        return self::der(0x02, $value);
    }

    private static function derLength(int $length): string
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

    private static function pem(int $tag, string $contents): string
    {
        $der = self::der($tag, $contents);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . '-----END PUBLIC KEY-----';
    }
}
