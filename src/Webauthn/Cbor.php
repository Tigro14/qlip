<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Decodeur CBOR minimal (RFC 8949), juste assez pour lire un objet
 * d'attestation WebAuthn et une cle COSE.
 *
 * Types supportes : entier, chaine d'octets, chaine de texte, tableau, objet,
 * booleen, balise (ignoree). Les longueurs indefinies ne sont pas acceptees
 * (les authenticateurs n'en produisent pas).
 */
final class Cbor
{
    /** @return mixed */
    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value = self::read($data, $offset);

        if ($offset !== strlen($data)) {
            throw new \InvalidArgumentException('Donnees CBOR trop longues.');
        }

        return $value;
    }

    /** @param int $offset */
    private static function read(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new \InvalidArgumentException('CBOR tronquee.');
        }

        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $low = $initial & 31;

        if ($major === 7) {
            if ($low === 20) {
                return false;
            }

            if ($low === 21) {
                return true;
            }

            throw new \InvalidArgumentException('Valeur CBOR non supportee.');
        }

        $length = self::readLength($data, $offset, $low);

        switch ($major) {
            case 0:
                return $length;

            case 1:
                return -1 - $length;

            case 2:
                return self::take($data, $offset, $length);

            case 3:
                return self::take($data, $offset, $length);

            case 4:
                $items = [];

                for ($i = 0; $i < $length; $i++) {
                    $items[] = self::read($data, $offset);
                }

                return $items;

            case 5:
                $map = [];

                for ($i = 0; $i < $length; $i++) {
                    $key = self::read($data, $offset);
                    $map[(string) $key] = self::read($data, $offset);
                }

                return $map;

            case 6:
                // Balise : on lit la valeur suivante, la balise n'a pas d'effet
                // pour les donnees WebAuthn.
                return self::read($data, $offset);

            default:
                throw new \InvalidArgumentException('Type CBOR non supporte.');
        }
    }

    private static function readLength(string $data, int &$offset, int $low): int
    {
        if ($low < 24) {
            return $low;
        }

        if ($low === 31) {
            throw new \InvalidArgumentException('Longueur CBOR indefinie.');
        }

        if ($low > 27) {
            throw new \InvalidArgumentException('Longueur CBOR reservee.');
        }

        // 24 : 1 octet, 25 : 2, 26 : 4, 27 : 8.
        $bytes = self::take($data, $offset, 1 << ($low - 24));
        $value = 0;

        for ($i = 0; $i < strlen($bytes); $i++) {
            $value = $value * 256 + ord($bytes[$i]);
        }

        return (int) $value;
    }

    private static function take(string $data, int &$offset, int $length): string
    {
        if ($offset + $length > strlen($data)) {
            throw new \InvalidArgumentException('CBOR tronquee.');
        }

        $chunk = substr($data, $offset, $length);
        $offset += $length;

        return $chunk;
    }
}
