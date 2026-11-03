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
 * Attestation : 'none' (auto-attestation), 'packed' (signature sur les donnees
 * d'authentification, avec ou sans chaine x5c) et 'fido-u2f' (une cle U2F seule,
 * comme la YubiKey NEO, ne produit que ce format : signature + chaine x5c, et une
 * base de signature distincte). Une chaine racine est optionnelle : sans elle,
 * l'attestation est acceptee comme une declaration (le controle d'origine et du
 * compteur reste fait).
 *
 * La base de signature d'une assertion vient du format enregistre avec la credential :
 * la base U2F pour une credential fido-u2f, la base WebAuthn pour les autres. Elle n'est
 * pas essayee dans les deux sens au moment de verifier : voir assertionBase.
 *
 * La politique de verification utilisateur vient du profil de ceremonie (voir
 * Policy) : elle n'est pas laisse au choix du navigateur.
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
     * @return array{credential_id: string, cose: string, sign_count: int, aaguid: string, fmt: string, attested: bool, policy: string, user_verified: bool}
     */
    public function verifyRegistration(
        string $attestationB64url,
        string $clientDataB64url,
        string $challengeB64url,
        string $policy = Policy::REQUIRED
    ): array {
        $object = Cbor::decode(base64url_decode($attestationB64url));

        if (!is_array($object)) {
            throw new \InvalidArgumentException('Objet d attestation invalide.');
        }

        // Les cles du map CBOR sont imposees par la spec : fmt, attStmt, authData.
        // Un navigateur reel n emet jamais attAuthData, donc lire une autre cle rend
        // l enregistrement impossible en production.
        $authData = (string) ($object['authData'] ?? '');
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

        $policy = Policy::normalize($policy);
        $verified = $data->has(AuthenticatorData::FLAG_UV);

        if (Policy::enforces($policy, $verified) && !$verified) {
            throw new \InvalidArgumentException('Verification utilisateur absente : politique ' . $policy . '.');
        }

        $message = hash('sha256', $authData, true) . hash('sha256', $clientDataRaw, true);

        $attested = false;

        if ($format === 'packed' && !empty($statement['sig'])) {
            $attested = $this->verifyAttestation($statement, $message, $data);
        } elseif ($format === 'fido-u2f') {
            // La base de signature n'est pas la meme ici : voir verifyU2fAttestation.
            // Une signature calculee sur le message WebAuthn ne passe pas, et c'est
            // justement ce que verifie le harnais avec le cas 'bad_base'.
            $attested = $this->verifyU2fAttestation($statement, $data, $clientDataRaw);
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
            'policy' => $policy,
            'user_verified' => $verified,
        ];
    }

    /**
     * Assertion (login).
     *
     * @param array{credential_id: string, cose: string, sign_count: int, policy?: string, uv_seen?: bool, fmt?: string} $credential
     * @return array{credential_id: string, sign_count: int, policy: string, user_verified: bool}
     */
    public function verifyAssertion(
        string $authDataB64url,
        string $clientDataB64url,
        string $signatureB64url,
        array $credential,
        string $challengeB64url,
        string $policy = Policy::REQUIRED
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

        // La politique de la ceremonie ne peut pas descendre en dessous de celle de la
        // credential : une credential enregistree sous un profil fort garde cette
        // politique, meme quand la ceremonie se fait sous le profil presence.
        $effective = Policy::strongest(Policy::normalize($policy), Policy::normalize((string) ($credential['policy'] ?? Policy::REQUIRED)));
        $verified = $data->has(AuthenticatorData::FLAG_UV);

        if (Policy::enforces($effective, $verified || (bool) ($credential['uv_seen'] ?? false)) && !$verified) {
            throw new \InvalidArgumentException('Verification utilisateur exigee par la politique ' . $effective . '.');
        }

        $stored = (int) $credential['sign_count'];

        // Certains authenticateurs (U2F) n incrementent pas le compteur : on
        // accepte 0, mais un compteur qui diminue est refuse.
        if ($data->signCount !== 0 && $data->signCount <= $stored) {
            throw new \InvalidArgumentException('Compteur de signature non croissant.');
        }

        $key = CoseKey::toPem(Cbor::decode(base64url_decode($credential['cose'])));
        $message = $this->assertionBase($authData, $clientDataRaw, (string) ($credential['fmt'] ?? 'none'));

        // La forme de la signature n'est pas un choix de controle : r || s (la spec) ou DER
        // (la couche de compatibilite CTAP1 d une cle U2F seule) sont acceptees. La base, en
        // revanche, est choisie par le format enregistre avec la credential : elle ne peut pas
        // etre testee dans les deux sens au moment de verifier, sinon le controle ne porte plus
        // sur ce sur quoi la signature est faite. Une forme malformee est refusee.
        if (!Signature::verify($message, base64url_decode($signatureB64url), $key)) {
            throw new \InvalidArgumentException('Signature invalide.');
        }

        return [
            'credential_id' => $credential['credential_id'],
            'sign_count' => $data->signCount,
            'policy' => $effective,
            'user_verified' => $verified,
        ];
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
     * Base de signature d'une assertion, choisie par le format d'attestation enregistre avec
     * la credential.
     *
     * WebAuthn (Level 1 et Level 2) : hash(AuthData) + hash(clientDataJSON).
     *
     * FIDO U2F, reponse d'authentification : application parameter (le hash du RP ID) + octet
     * de presence utilisateur + counter + challenge parameter (le hash des donnees client).
     * Les trois premiers sont exactement les 37 octets d'AuthData, sans hash : AuthData vaut
     * ici rpIdHash + drapeau + counter, et le drapeau d'une reponse U2F est l'octet de presence.
     * La difference avec la base WebAuthn est que AuthData n'est pas soumis au hash.
     *
     * Ce n'est pas une supposition : la base a ete mesuree sur une cle reelle (YubiKey NEO),
     * et une assertion d'une cle U2F seule ne verifie que sur la base U2F. Le harnais produit
     * les deux bases et controle que la mauvaise est refusee pour chaque credential.
     */
    private function assertionBase(string $authData, string $clientDataRaw, string $format): string
    {
        $clientHash = hash('sha256', $clientDataRaw, true);

        if ($format === 'fido-u2f') {
            return $authData . $clientHash;
        }

        return hash('sha256', $authData, true) . $clientHash;
    }

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
        // etre changee par rapport a celle de l attestation, donc on ne la verifie
        // que si elle correspond.
        $key = CoseKey::toPem($data->cose);

        return Signature::verify($message, $signature, $key);
    }

    /**
     * Attestation U2F (fmt = 'fido-u2f'), la seule que produit une cle U2F seule
     * (YubiKey NEO) : la cle ne sait pas faire CBOR, donc elle n'emmet ni 'none' ni
     * 'packed'. Le navigateur, qui sait faire les deux, convertit la reponse de la
     * cle en objet d'attestation avec les memes cles fmt, attStmt, authData.
     *
     * La base de signature est particuliere :
     *   0x00 || rpIdHash || clientDataHash || credentialId || publicKeyU2F
     * avec publicKeyU2F = 0x04 || x || y, le point COSE de la credential. Ce n'est
     * pas le message WebAuthn hash(authData) + hash(clientData) : le premier octet
     * est un contre-signal fixe (0x00 pour l'attestation, 0x01 pour l'assertion).
     * Une signature calculee sur l'autre base ne passe pas, et c'est ce que verifie
     * le harnais avec le cas 'bad_base'.
     *
     * x5c est obligatoire ici : sans certificat il n'y a rien a verifier, et ce
     * format n'a pas d'auto-attestation. Le certificat est accepte comme une
     * declaration quand aucune racine n'est fournie, comme pour 'packed'. L'identite
     * de l'appareil vient du numero de serie du certificat : il n'y a pas d'AAGUID
     * dans U2F, donc aucune decision d'administration ne peut se prendre sur la cle.
     *
     * @param array<string, mixed> $statement
     */
    private function verifyU2fAttestation(array $statement, AuthenticatorData $data, string $clientDataRaw): bool
    {
        $signature = (string) ($statement['sig'] ?? '');
        $certificates = is_array($statement['x5c'] ?? null) ? $statement['x5c'] : [];

        if ($signature === '' || $certificates === []) {
            throw new \InvalidArgumentException('Attestation fido-u2f sans signature ou sans certificat x5c.');
        }

        // Le contre-signal : il vaut 0 dans le map CBOR d'attestation, le vrai
        // compteur est lu dans les 4 octets d'AuthData.
        if (isset($statement['counter']) && (int) $statement['counter'] !== 0) {
            throw new \InvalidArgumentException('Compteur d attestation U2F autre que 0.');
        }

        // Une cle U2F signe en EC2/P-256 : le format fido-u2f n a pas d autre courbe.
        // La courbe est lue par CoseKey, qui connait les deux numerotations de labels.
        if (CoseKey::curve($data->cose) !== 'P-256') {
            throw new \InvalidArgumentException('Courbe d attestation U2F autre que P-256.');
        }

        $message = "\x00"
            . $data->rpIdHash
            . hash('sha256', $clientDataRaw, true)
            . $data->credentialId
            . CoseKey::x962($data->cose);

        // La signature d attestation doit verifier : x5c n est pas optionnel dans ce
        // format, donc il n y a pas de cas « declaration » comme pour 'packed'.
        if (!$this->verifyAttestation($statement, $message, $data)) {
            throw new \InvalidArgumentException('Signature d attestation U2F invalide.');
        }

        return true;
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
