<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;
use Qlip\Webauthn\Cbor;
use Qlip\Webauthn\CoseKey;
use Qlip\Webauthn\Verifier;

/**
 * Credentials WebAuthn/FIDO2 (YubiKey). Les tampons sont stockes en base64url :
 * la cle COSE est conserve telle quelle (les octets d'origine), l'ID de credential
 * egalement.
 */
final class Credentials
{
    private Db $db;
    private Verifier $verifier;

    public function __construct(Db $db, string $rpId, array $origins, string $rootsPem = '')
    {
        $this->db = $db;
        $this->verifier = new Verifier($rpId, $origins, $rootsPem);
    }

    /** @return array<string, mixed> */
    public function register(int $userId, string $attestationB64url, string $clientDataB64url, string $challengeB64url): array
    {
        $verified = $this->verifier->verifyRegistration($attestationB64url, $clientDataB64url, $challengeB64url);

        $this->db->exec(
            'INSERT INTO webauthn_credentials (user_id, credential_id, cose, sign_count, aaguid, created_at)
             VALUES (:user_id, :credential_id, :cose, :sign_count, :aaguid, :created_at)',
            [
                ':user_id' => $userId,
                // L'ID de credential est binaire : on le stocke en base64url.
                ':credential_id' => base64url_encode($verified['credential_id']),
                ':cose' => $verified['cose'],
                ':sign_count' => $verified['sign_count'],
                ':aaguid' => $verified['aaguid'],
                ':created_at' => now(),
            ]
        );

        return [
            'id' => (int) $this->db->pdo()->lastInsertId(),
            'user_id' => $userId,
            'credential_id' => base64url_encode($verified['credential_id']),
            'cose' => $verified['cose'],
            'sign_count' => $verified['sign_count'],
            'fmt' => $verified['fmt'],
            'attested' => $verified['attested'],
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{credential_id: string, sign_count: int}
     */
    public function assert(array $credential, string $authDataB64url, string $clientDataB64url, string $signatureB64url, string $challengeB64url): array
    {
        $verified = $this->verifier->verifyAssertion(
            $authDataB64url,
            $clientDataB64url,
            $signatureB64url,
            ['credential_id' => base64url_decode((string) $credential['credential_id']), 'cose' => (string) $credential['cose'], 'sign_count' => (int) $credential['sign_count']],
            $challengeB64url
        );

        $this->db->exec(
            'UPDATE webauthn_credentials SET sign_count = :sign_count WHERE id = :id',
            [':sign_count' => $verified['sign_count'], ':id' => $credential['id']]
        );

        return $verified;
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        return $this->db->all('SELECT * FROM webauthn_credentials WHERE user_id = :user_id ORDER BY id', [':user_id' => $userId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM webauthn_credentials WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function algorithm(array $credential): ?array
    {
        try {
            return CoseKey::toPem(Cbor::decode(base64url_decode((string) $credential['cose'])));
        } catch (\Throwable) {
            return null;
        }
    }
}
