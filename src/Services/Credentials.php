<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;
use Qlip\Webauthn\Cbor;
use Qlip\Webauthn\CoseKey;
use Qlip\Webauthn\Policy;
use Qlip\Webauthn\Verifier;

/**
 * Credentials WebAuthn/FIDO2 (YubiKey). Les tampons sont stockes en base64url :
 * la cle COSE est conserve telle quelle (les octets d'origine), l'ID de credential
 * egalement.
 *
 * La politique de verification utilisateur est stockee par credential, au moment de
 * l'enregistrement : elle ne change plus ensuite. `uv_seen` garde la trace de la
 * premiere verification utilisateur faite par la cle, ce qui permet de continuer a
 * l'exiger quand la cle est capable de la faire.
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
    public function register(
        int $userId,
        string $attestationB64url,
        string $clientDataB64url,
        string $challengeB64url,
        string $policy = Policy::REQUIRED
    ): array {
        $verified = $this->verifier->verifyRegistration($attestationB64url, $clientDataB64url, $challengeB64url, $policy);

        $this->db->exec(
            'INSERT INTO webauthn_credentials (user_id, credential_id, cose, sign_count, aaguid, policy, uv_seen, created_at)
             VALUES (:user_id, :credential_id, :cose, :sign_count, :aaguid, :policy, :uv_seen, :created_at)',
            [
                ':user_id' => $userId,
                // L'ID de credential est binaire : on le stocke en base64url.
                ':credential_id' => base64url_encode($verified['credential_id']),
                ':cose' => $verified['cose'],
                ':sign_count' => $verified['sign_count'],
                ':aaguid' => $verified['aaguid'],
                ':policy' => $verified['policy'],
                ':uv_seen' => $verified['user_verified'] ? 1 : 0,
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
            'policy' => $verified['policy'],
        ];
    }

    /**
     * Assertion. Le controle d'appartenance est fait par l'appelant : la ligne passe
     * par ici seulement si elle appartient au compte qui fait la ceremonie.
     *
     * @param array<string, mixed> $credential
     * @return array{credential_id: string, sign_count: int, policy: string, user_verified: bool}
     */
    public function assert(
        array $credential,
        string $authDataB64url,
        string $clientDataB64url,
        string $signatureB64url,
        string $challengeB64url,
        string $policy = Policy::REQUIRED
    ): array {
        $verified = $this->verifier->verifyAssertion(
            $authDataB64url,
            $clientDataB64url,
            $signatureB64url,
            [
                'credential_id' => base64url_decode((string) $credential['credential_id']),
                'cose' => (string) $credential['cose'],
                'sign_count' => (int) $credential['sign_count'],
                'policy' => (string) ($credential['policy'] ?? Policy::REQUIRED),
                'uv_seen' => (bool) ($credential['uv_seen'] ?? false),
            ],
            $challengeB64url,
            $policy
        );

        // uv_seen ne se remet jamais a 0 : la cle a prouve une fois qu'elle savait
        // faire la verification utilisateur, on continue de la demander.
        $this->db->exec(
            'UPDATE webauthn_credentials SET sign_count = :sign_count, uv_seen = :uv_seen WHERE id = :id',
            [
                ':sign_count' => $verified['sign_count'],
                ':uv_seen' => ($verified['user_verified'] || (bool) ($credential['uv_seen'] ?? false)) ? 1 : 0,
                ':id' => $credential['id'],
            ]
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

    /**
     * Credential d'un compte, par ID de credential (forme base64url telle qu'elle
     * arrive du navigateur). Le controle d'appartenance est ici : la credential d'un
     * autre compte ne peut pas servir, meme si le navigateur la renvoie.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, string $credentialId): ?array
    {
        return $this->db->one(
            'SELECT * FROM webauthn_credentials WHERE user_id = :user_id AND credential_id = :credential_id LIMIT 1',
            [':user_id' => $userId, ':credential_id' => $credentialId]
        );
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
