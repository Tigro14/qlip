<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * AuthData (block d'authentification, Loyal et al. section 6.1) :
 * rpIdHash (32, SHA-256) | flags (1) | signCount (4) | [ AT : aaguid (16), credIdLen (2),
 * credId, credPublicKey (CBOR) ] | [ ED : extensions ].
 */
final class AuthenticatorData
{
    public const FLAG_UP = 0x01;
    public const FLAG_BE = 0x02;
    public const FLAG_UV = 0x04;
    public const FLAG_BS = 0x08;
    public const FLAG_ED = 0x80;
    public const FLAG_AT = 0x40;

    public string $rpIdHash = '';
    public int $flags = 0;
    public int $signCount = 0;
    public string $aaguid = '';
    public string $credentialId = '';
    public array $cose = [];
    public string $coseBytes = '';

    public static function parse(string $bytes): self
    {
        // SHA-256 = 32 octets.
        if (strlen($bytes) < 37) {
            throw new \InvalidArgumentException('AuthData trop courte.');
        }

        $data = new self();
        $data->rpIdHash = substr($bytes, 0, 32);
        $data->flags = ord($bytes[32]);
        $data->signCount = unpack('N', substr($bytes, 33, 37))[1];

        if (($data->flags & self::FLAG_AT) === 0) {
            return $data;
        }

        if (strlen($bytes) < 55) {
            throw new \InvalidArgumentException('AuthData tronquee (AT).');
        }

        $data->aaguid = substr($bytes, 37, 53 - 37);
        $length = unpack('n', substr($bytes, 53, 55))[1];

        if (strlen($bytes) < 55 + $length) {
            throw new \InvalidArgumentException('AuthData tronquee (credIdLen).');
        }

        $data->credentialId = substr($bytes, 55, $length);
        $cose = substr($bytes, 55 + $length);

        if ($cose === '') {
            throw new \InvalidArgumentException('Cle COSE absente.');
        }

        $decoded = Cbor::decode($cose);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Cle COSE invalide.');
        }

        $data->cose = $decoded;
        $data->coseBytes = $cose;

        return $data;
    }

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }
}
