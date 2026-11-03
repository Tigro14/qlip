<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Signature EC2 ou RSA.
 *
 * Une cle EC2 a deux formes, et les deux arrivent reellement :
 *   - r || s, la forme que la spec WebAuthn definit pour une assertion recue du navigateur ;
 *   - le DER SEQUENCE { INTEGER r, INTEGER s }, la forme que la couche de compatibilite U2F
 *     (CTAP1, une cle U2F seule comme la YubiKey NEO) transmet telle quelle, y compris pour
 *     une assertion.
 * openssl_verify ne prend que le DER pour une cle EC2, donc la forme brute est convertie a la
 * main. Une cle RSA n'a qu'une forme : le block PKCS1 v1.5, la taille du modulo.
 *
 * Les deux formes EC2 sont acceptees parce que ce qui est controle est la base de signature et
 * la cle, pas l'encodage. Une forme qui n'est ni l'une ni l'autre est refusee : accepter le DER
 * ne veut pas dire accepter n'importe quels octets.
 */
final class Signature
{
    /**
     * @param array{key: string, alg: string, type: string} $key
     */
    public static function verify(string $message, string $signature, array $key): bool
    {
        $result = openssl_verify($message, self::form($signature, $key['alg']), $key['key'], self::algorithm($key['alg']));

        return $result === 1;
    }

    /**
     * Signature -> ce que openssl_verify attend. La forme est normalisee, pas acceptee telle
     * quelle : elle doit etre lisible.
     *
     * @throws \InvalidArgumentException la forme n'est ni r || s ni un DER lisible
     */
    public static function form(string $signature, string $alg): string
    {
        if (str_starts_with($alg, 'RS')) {
            // Une signature RSA n'a pas de forme DER : c'est le block PKCS1 v1.5, exactement la
            // taille du modulo. Une forme tronquee est refusee par openssl.
            if ($signature === '') {
                throw new \InvalidArgumentException('Signature RSA vide.');
            }

            return $signature;
        }

        $size = self::size($alg);

        // La forme brute est testee d'abord : une forme brute de la taille de la courbe n'a pas
        // a etre reparse, et une forme DER de 64 octets dont le premier octet serait 0x30 n'est
        // pas une forme brute.
        if ($size > 0 && strlen($signature) === 2 * $size) {
            return self::toDer($signature, $size);
        }

        return self::readDer($signature, $alg);
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

    /**
     * DER SEQUENCE { INTEGER r, INTEGER s }, controle structurel : la longueur annoncee doit
     * couvrir exactement deux INTEGER, un INTEGER vide est refuse, et rien ne doit suivre la
     * sequence. Un entier plus long que la courbe est une forme impossible, donc refusee.
     *
     * Sans ce controle, accepter la forme DER reviendrait a verifier une signature faite sur des
     * octets qui ne forment pas un couple r, s.
     */
    private static function readDer(string $der, string $alg): string
    {
        $size = self::size($alg);

        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            throw new \InvalidArgumentException('Signature de forme inattendue (' . strlen($der) . ' octets) : r || s ou DER.');
        }

        [$total, $position] = self::readLength($der, 1);
        $end = $position + $total;

        if ($end !== strlen($der)) {
            throw new \InvalidArgumentException('Signature DER : longueur de sequence incoherente.');
        }

        for ($i = 0; $i < 2; $i++) {
            if ($position >= $end || ord($der[$position]) !== 0x02) {
                throw new \InvalidArgumentException('Signature DER : deux INTEGER attendus.');
            }

            [$length, $value] = self::readLength($der, $position + 1);

            if ($length === 0 || $value + $length > $end) {
                throw new \InvalidArgumentException('Signature DER : INTEGER de longueur invalide.');
            }

            if ($size > 0 && strlen(ltrim(substr($der, $value, $length), "\x00")) > $size) {
                throw new \InvalidArgumentException('Signature DER : entier plus long que la courbe (' . $size . ' octets).');
            }

            $position = $value + $length;
        }

        return $der;
    }

    /** @return array{int, int} longueur annoncee et position du contenu */
    private static function readLength(string $der, int $position): array
    {
        if ($position >= strlen($der)) {
            throw new \InvalidArgumentException('Signature DER : en-tete tronquee.');
        }

        $first = ord($der[$position]);

        if ($first < 128) {
            return [$first, $position + 1];
        }

        $count = $first - 128;

        if ($count === 0 || $position + 1 + $count > strlen($der)) {
            throw new \InvalidArgumentException('Signature DER : longueur announcee illisible.');
        }

        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) + ord($der[$position + 1 + $i]);
        }

        return [$length, $position + 1 + $count];
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

    /** Taille d un entier pour la courbe de l algorithme. Une cle RSA n en a pas. */
    private static function size(string $alg): int
    {
        return match ($alg) {
            'ES256' => 32,
            'ES384' => 48,
            'ES512' => 66,
            default => 0,
        };
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
