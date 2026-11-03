<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Cle publique COSE (RFC 9053) -> PEM pour openssl_verify.
 *
 * Les labels du map COSE ont deux numerotations, et les navigateurs n emettent pas la
 * meme :
 *   - la numerotation actuelle (RFC 9052 et RFC 9053) : kty = 1, alg = 3, puis les labels
 *     propres au type de cle (EC2 : courbe = -1, x = -2, y = -3 ; RSA : modulo = -1,
 *     exposant = -2). C est celle que Firefox ecrit ;
 *   - la numerotation heritee de RFC 8152, depassee mais encore emise (Chrome) :
 *     kty = -1, alg = -2, courbe = -3, x = -4, y = -5 (RSA : n = -3, e = -4).
 *
 * Les deux sont lues. Une cle n est retenue que si elle est coherent avec une numerotation :
 * type du registre, courbe du registre, taille des coordonnees, algorithme. Quand deux
 * numerotations donnent deux cles differentes, la cle est refusee : un map qui melange les
 * deux n est pas une cle COSE, et le lire quand meme rendrait la verification fausse.
 *
 * Valeurs du registre COSE : kty OKP = 1, EC2 = 2, RSA = 3. Courbes : P-256 = 1, P-384 = 2,
 * P-521 = 3 (le nom en chaine est accepte, le registre dit int / tstr ; la valeur heritee
 * -7 pour P-256 est encore emise par certains navigateurs).
 */
final class CoseKey
{
    /** @var array<string, array<string, list<string>>> */
    private const SHAPES = [
        'actuelle' => [
            'kty' => ['1'], 'alg' => ['3'],
            'crv' => ['-1'], 'x' => ['-2'], 'y' => ['-3'],
            'n' => ['-1'], 'e' => ['-2'],
        ],
        'jwk' => [
            'kty' => ['1'], 'alg' => ['3'],
            'crv' => ['4'], 'x' => ['5'], 'y' => ['6'],
            'n' => ['3'], 'e' => ['4'],
        ],
        'heritee' => [
            'kty' => ['-1'], 'alg' => ['-2'],
            'crv' => ['-3'], 'x' => ['-4'], 'y' => ['-5'],
            'n' => ['-3'], 'e' => ['-4'],
        ],
    ];

    /** @var array<string, array{name: string, values: list<int>, oid: string, size: int, alg: string}> */
    private const CURVES = [
        'P-256' => [
            'name' => 'P-256',
            'values' => [1, -7],
            'oid' => "\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'size' => 32,
            'alg' => 'ES256',
        ],
        'P-384' => [
            'name' => 'P-384',
            'values' => [2],
            'oid' => "\x2b\x65\x04\x22\x00\x22",
            'size' => 48,
            'alg' => 'ES384',
        ],
        'P-521' => [
            'name' => 'P-521',
            'values' => [3],
            'oid' => "\x2b\x65\x04\x22\x00\x23",
            'size' => 66,
            'alg' => 'ES512',
        ],
    ];

    /** @var array<string, int> */
    private const ALGS = [
        'ES256' => -7, 'ES384' => -35, 'ES512' => -36,
        'RS256' => -257, 'RS384' => -258, 'RS512' => -259,
    ];

    /** Types de cle du registre : OKP = 1, EC2 = 2, RSA = 3. */
    private const KTY_EC2 = 2;
    private const KTY_RSA = 3;

    /** @return array{key: string, alg: string, type: string} */
    public static function toPem(array $map): array
    {
        $data = self::read($map);

        if ($data['type'] === 'EC2') {
            $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . self::der(0x06, $data['oid']));

            return [
                'key' => self::pem(0x30, $algorithm . self::derBitString(self::point($data))),
                'alg' => $data['alg'],
                'type' => 'EC2',
            ];
        }

        if ($data['type'] === 'RSA') {
            $publicKey = self::der(0x30, self::derInteger($data['n']) . self::derInteger($data['e']));
            $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . self::der(0x05, ''));

            return [
                'key' => self::pem(0x30, $algorithm . self::derBitString($publicKey)),
                'alg' => $data['alg'],
                'type' => 'RSA',
            ];
        }

        throw new \InvalidArgumentException('Type de cle COSE non supporte.');
    }

    /**
     * Point EC2 en forme non compressee : 0x04 || x || y.
     *
     * C est la forme que l'attestation U2F signe, et la seule que le format
     * fido-u2f connait : une cle U2F signe en EC2/P-256, il n'y a pas de cle RSA
     * dans ce format.
     */
    public static function x962(array $map): string
    {
        $data = self::read($map);

        if ($data['type'] !== 'EC2') {
            throw new \InvalidArgumentException('Cle COSE non EC2 : le format fido-u2f exige une cle EC2.');
        }

        return self::point($data);
    }

    /** Courbe d une cle EC2, nom du registre COSE. */
    public static function curve(array $map): string
    {
        $data = self::read($map);

        if ($data['type'] !== 'EC2') {
            throw new \InvalidArgumentException('Cle COSE non EC2.');
        }

        return $data['curve'];
    }

    // ------------------------------------------------------------------

    /**
     * Cle lue avec la seule numerotation de labels qui la rend coherent.
     *
     * @return array{type: string, alg: string, curve: string, oid: string, x: string, y: string, n: string, e: string}
     */
    private static function read(array $map): array
    {
        $readings = [];

        foreach (self::SHAPES as $shape) {
            $data = self::shape($map, $shape);

            if ($data !== null) {
                $readings[] = $data;
            }
        }

        if ($readings === []) {
            // Un kty du registre qui n est ni EC2 ni RSA est connu mais ne se verifie pas
            // ici (OKP). Le nommer rend l erreur lisible devant un navigateur.
            foreach (self::SHAPES as $shape) {
                $kty = self::label($map, $shape['kty']);

                if (is_int($kty) && $kty !== self::KTY_EC2 && $kty !== self::KTY_RSA) {
                    throw new \InvalidArgumentException('Type de cle COSE non supporte.');
                }
            }

            throw new \InvalidArgumentException('Cle COSE incomplete ou incoherente.');
        }

        foreach ($readings as $data) {
            if ($data !== $readings[0]) {
                throw new \InvalidArgumentException('Cle COSE : deux numerotations de labels ne donnent pas la meme cle.');
            }
        }

        return $readings[0];
    }

    /**
     * @param array<string, list<string>> $labels
     * @return array{type: string, alg: string, curve: string, oid: string, x: string, y: string, n: string, e: string}|null
     */
    private static function shape(array $map, array $labels): ?array
    {
        $kty = self::label($map, $labels['kty']);

        if (!is_int($kty) || ($kty !== self::KTY_EC2 && $kty !== self::KTY_RSA)) {
            return null;
        }

        $data = [
            'type' => $kty === self::KTY_EC2 ? 'EC2' : 'RSA',
            'alg' => '', 'curve' => '', 'oid' => '',
            'x' => '', 'y' => '', 'n' => '', 'e' => '',
        ];

        if ($data['type'] === 'EC2') {
            $curve = self::curveValue(self::label($map, $labels['crv']));

            if ($curve === null) {
                return null;
            }

            $x = self::label($map, $labels['x']);
            $y = self::label($map, $labels['y']);

            // Les coordonnees doivent avoir la taille de la courbe : sans cela le map
            // n est pas lisible avec cette numerotation.
            if (!is_string($x) || !is_string($y) || strlen($x) !== $curve['size'] || strlen($y) !== $curve['size']) {
                return null;
            }

            $data['curve'] = $curve['name'];
            $data['oid'] = $curve['oid'];
            $data['x'] = $x;
            $data['y'] = $y;
        } else {
            $n = self::label($map, $labels['n']);
            $e = self::label($map, $labels['e']);

            if (!is_string($n) || $n === '' || !is_string($e) || $e === '') {
                return null;
            }

            $data['n'] = $n;
            $data['e'] = $e;
        }

        $data['alg'] = self::algName($map, $labels, $data);

        return $data['alg'] === '' ? null : $data;
    }

    /**
     * Nom de l algorithme. Il est optionnel dans un map COSE : sans lui, il vient de la
     * courbe. Sans cette remise, une cle EC2 recue du navigateur ne peut pas etre verifiee,
     * parce que la signature brute r || s ne serait pas convertie en DER.
     *
     * Quand il est present, il doit convenir a la courbe (RFC 9053 : l implementation
     * verifie que la courbe et l algorithme sont corrects).
     *
     * @param array<string, list<string>> $labels
     * @param array<string, string> $data
     */
    private static function algName(array $map, array $labels, array $data): string
    {
        $value = self::label($map, $labels['alg']);

        if ($value === null) {
            return $data['type'] === 'EC2' ? self::CURVES[$data['curve']]['alg'] : 'RS256';
        }

        $name = is_string($value) ? $value : (string) array_search($value, self::ALGS, true);

        if (!isset(self::ALGS[$name])) {
            return '';
        }

        if ($data['type'] === 'EC2' && $name !== self::CURVES[$data['curve']]['alg']) {
            return '';
        }

        return $name;
    }

    /**
     * Valeur d un label, par sa forme actuelle ou sa forme heritee quand la numerotation
     * le permet. Quand deux formes sont presentes elles doivent dire la meme chose.
     *
     * @param list<string> $labels
     */
    private static function label(array $map, array $labels): mixed
    {
        $value = null;

        foreach ($labels as $label) {
            if (!array_key_exists($label, $map)) {
                continue;
            }

            if ($value !== null && $value !== $map[$label]) {
                throw new \InvalidArgumentException('Cle COSE : deux formes du meme label sont incoherentes.');
            }

            $value = $map[$label];
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private static function curveValue(mixed $value): ?array
    {
        if (is_string($value) && isset(self::CURVES[$value])) {
            return self::CURVES[$value];
        }

        if (!is_int($value)) {
            return null;
        }

        foreach (self::CURVES as $curve) {
            if (in_array($value, $curve['values'], true)) {
                return $curve;
            }
        }

        return null;
    }

    /** @param array<string, string> $data */
    private static function point(array $data): string
    {
        return "\x04" . $data['x'] . $data['y'];
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
