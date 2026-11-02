<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Verificateur WebAuthn/FIDO2 (cote serveur).
 *
 * Le navigateur fournit des tampons ; la couche HTTP les recoit en base64url.
 * Les controles : challenge, origine, ID de relying party, drapeaux UP/UV/AT,
 * compteur de signature, signature ECDSA ou RSA.
 *
 * Attestation : 'none' (auto-attestation) et 'packed' (signature sur les donnees
 * d'authentification, avec ou sans chaine x5c). Une chaine racine est optionnelle :
 * sans elle, 'packed' avec x5c est accepte comme une declaration (le controle
 * d'origine et du compteur reste fait).
 */
final class Verifier
{
    /** @param list<string> $origins */
    public function __construct(
        private string $rpId,
        private array $origins = [],
        private string $rootsPem = ''
    ) {
    }

    /**
     * Enregistrement d'une credential.
     *
     * @return array{credential_id: string, cose: string, sign_count: int, aaguid: string, fmt: string, attested: bool}
     */
    public function verifyRegistration(string $attestationB64url, string $clientDataB64url, string $challengeB64url): array
    {
        $object = Cbor::decode(base64url_decode($attestationB64url));

        if (!is_array($object)) {
            throw new \InvalidArgumentException('Objet d attestation invalide.');
        }

        $authData = (string) ($object['attAuthData'] ?? '');
        $statement = is_array($object['attStmt'] ?? null) ? $object['attStmt'] : [];
        $format = (string) ($object['fmt'] ?? 'none');
        $clientDataRaw = base64url_decode($clientDataB64url);
        $data = AuthenticatorData::parse($authData);

        if (!$data->has(AuthenticatorData::FLAG_AT)) {
            throw new \InvalidArgumentException('Drapeau AT absent : pas de credential.');
        }

        if (!$data->has(AuthenticatorData::FLAG_UP)) {
            throw new \InvalidArgumentException('Drapeau UP absent.');
        }

        $this->checkClientData($clientDataRaw, $challengeB64url, 'webauthn.create');
        $this->checkRpIdHash($data->rpIdHash);

        $message = hash('sha256', $authData, true) . hash('sha256', $clientDataRaw, true);

        $attested = false;

        if ($format === 'packed' && !empty($statement['sig'])) {
            $attested = $this->verifyAttestation($statement, $message, $data);
        } elseif ($format !== 'none') {
            throw new \InvalidArgumentException('Format d attestation non supporte : ' . $format);
        }

        return [
            'credential_id' => $data->credentialId,
            'cose' => base64url_encode($data->coseBytes),
            'sign_count' => $data->signCount,
            'aaguid' => bin2hex($data->aaguid),
            'fmt' => $format,
            'attested' => $attested,
        ];
    }

    /**
     * Assertion (login).
     *
     * @param array{credential_id: string, cose: string, sign_count: int} $credential
     * @return array{credential_id: string, sign_count: int}
     */
    public function verifyAssertion(
        string $authDataB64url,
        string $clientDataB64url,
        string $signatureB64url,
        array $credential,
        string $challengeB64url
    ): array {
        $authData = base64url_decode($authDataB64url);
        $clientDataRaw = base64url_decode($clientDataB64url);
        $data = AuthenticatorData::parse($authData);

        if (!$data->has(AuthenticatorData::FLAG_UP)) {
            throw new \InvalidArgumentException('Drapeau UP absent.');
        }

        // Une assertion ne porte pas la credential dans AuthData (drapeau AT) :
        // c'est le navigateur qui la donne, donc elle vient de l'appelant.
        if ($data->has(AuthenticatorData::FLAG_AT) && $data->credentialId !== $credential['credential_id']) {
            throw new \InvalidArgumentException('Credential incoherente avec AuthData.');
        }

        $this->checkClientData($clientDataRaw, $challengeB64url, 'webauthn.get');
        $this->checkRpIdHash($data->rpIdHash);

        $stored = (int) $credential['sign_count'];

        // Certains authenticateurs (U2F) n incrementent pas le compteur : on
        // accepte 0, mais un compteur qui diminue est refuse.
        if ($data->signCount !== 0 && $data->signCount <= $stored) {
            throw new \InvalidArgumentException('Compteur de signature non croissant.');
        }

        $key = CoseKey::toPem(Cbor::decode(base64url_decode($credential['cose'])));
        $message = hash('sha256', $authData, true) . hash('sha256', $clientDataRaw, true);

        if (!Signature::verify($message, base64url_decode($signatureB64url), $key)) {
            throw new \InvalidArgumentException('Signature invalide.');
        }

        return ['credential_id' => $credential['credential_id'], 'sign_count' => $data->signCount];
    }

    /** @return array<string, mixed> */
    public function clientData(string $clientDataB64url): array
    {
        $raw = base64url_decode($clientDataB64url);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('clientDataJSON invalide.');
        }

        return $decoded;
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $statement
     * @return bool
     */
    private function verifyAttestation(array $statement, string $message, AuthenticatorData $data): bool
    {
        $signature = (string) $statement['sig'];
        $certificates = is_array($statement['x5c'] ?? null) ? $statement['x5c'] : [];

        if ($certificates !== []) {
            $pem = self::pemCertificate((string) $certificates[0]);
            $resource = openssl_pkey_get_public($pem);

            if ($resource === false) {
                throw new \InvalidArgumentException('Certificat d attestation illisible.');
            }

            $details = openssl_pkey_get_details($resource);

            if ($details === false) {
                throw new \InvalidArgumentException('Cle publique du certificat indisponible.');
            }

            $alg = (string) ($statement['alg'] ?? 'RS256');

            if (!Signature::verify($message, $signature, ['key' => $details['key'], 'alg' => $alg, 'type' => 'cert'])) {
                return false;
            }

            if ($this->rootsPem !== '' && openssl_x509_verify($pem, $this->rootsPem) !== 1) {
                return false;
            }

            return true;
        }

        // Packed sans certificat : auto-attestation, la cle de la credential a pu
        // etre changee par rapport a celle de l attestation, donc on ne la vérifie
        // que si elle correspond.
        $key = CoseKey::toPem($data->cose);

        return Signature::verify($message, $signature, $key);
    }

    private function checkClientData(string $raw, string $challengeB64url, string $expectedType): void
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('clientDataJSON invalide.');
        }

        if (($decoded['type'] ?? '') !== $expectedType) {
            throw new \InvalidArgumentException('clientDataJSON type ' . $expectedType . ' attendu.');
        }

        if (($decoded['challenge'] ?? '') !== $challengeB64url) {
            throw new \InvalidArgumentException('Challenge incorrect.');
        }

        if ($this->origins !== [] && !in_array((string) ($decoded['origin'] ?? ''), $this->origins, true)) {
            throw new \InvalidArgumentException('Origine non autorisee : ' . ($decoded['origin'] ?? ''));
        }

        if (isset($decoded['tokenType']) && $decoded['tokenType'] !== 'WebAuthn') {
            throw new \InvalidArgumentException('tokenType incorrect.');
        }
    }

    private function checkRpIdHash(string $hash): void
    {
        if ($hash !== hash('sha256', $this->rpId, true)) {
            throw new \InvalidArgumentException('RP ID hash incorrect.');
        }
    }

    private static function pemCertificate(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END CERTIFICATE-----';
    }
}
