<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Webauthn\Cbor;
use Qlip\Webauthn\CoseKey;
use Qlip\Webauthn\Policy;
use Qlip\Webauthn\Signature;
use Qlip\Webauthn\Verifier;

$json = json_decode(file_get_contents(getenv('QLIP_WEBAUTHN_JSON') ?: '/tmp/opencode/webauthn.json'), true);

if (!is_array($json)) {
    fwrite(STDERR, "fixtures JSON illisibles\n");
    exit(1);
}

$verifier = new Verifier($json['rp_id'], [$json['origin']]);
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo $ok ? '  OK   ' : '  KO   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

/** PEM -> DER : le DER produit par PHP doit etre celui de la reference Python. */
function der_from_pem(string $pem): string
{
    $body = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

    return base64_decode($body, true) ?: '';
}

foreach ($json['fixtures'] as $fixture) {
    echo $fixture['name'], PHP_EOL;

    // La politique vient du profil de ceremonie, donc elle est donnee a chaque fixture :
    // une fixture reglee en 'required' ne peut pas etre acceptee sans verification
    // utilisateur, et une cle U2F (politique 'discouraged') ne peut pas la faire.
    $policy = (string) $fixture['policy'];

    // Le nom des cles du map CBOR vient de la spec : fmt, attStmt, authData. Un navigateur
    // reel n emet pas attAuthData. Le harnais doit ecrire la cle de la spec, pas celle de
    // l implementation, sinon l erreur ne se voit que sur l instance deployee.
    $object = Cbor::decode(base64url_decode($fixture['register']['attestation']));
    check(
        'cle authData de la spec',
        is_array($object) && isset($object['authData']),
        implode(', ', array_keys(is_array($object) ? $object : []))
    );

    // Enregistrement
    try {
        $registered = $verifier->verifyRegistration(
            $fixture['register']['attestation'],
            $fixture['register']['client_data'],
            $json['challenge'],
            $policy
        );
    } catch (Throwable $error) {
        check('enregistrement', false, $error->getMessage());
        continue;
    }

    check('enregistrement', true, 'fmt=' . $registered['fmt']);
    check('politique de la ceremonie', $registered['policy'] === $policy, $policy);
    check('drapeau UV', $registered['user_verified'] === $fixture['user_verified'], $policy);
    check('credential id', base64url_encode($registered['credential_id']) === $fixture['register']['credential_id']);
    check('cle COSE conservee', $registered['cose'] === $fixture['register']['cose']);
    check('compteur', $registered['sign_count'] === $fixture['register']['sign_count']);

    try {
        $key = CoseKey::toPem(Cbor::decode(base64url_decode($registered['cose'])));
    } catch (Throwable $error) {
        if (($fixture['refused'] ?? null) !== null) {
            check('cle refusee', $error->getMessage() === $fixture['refused'], $error->getMessage());
        } else {
            check('PEM', false, $error->getMessage());
        }

        continue;
    }

    if (($fixture['refused'] ?? null) !== null) {
        check('cle refusee', false, 'acceptee : ' . $key['type']);
        continue;
    }

    check('PEM', str_contains($key['key'], '-----BEGIN PUBLIC KEY-----'), $key['type'] . '/' . $key['alg']);

    // Contre-verification independante : le PEM construit par PHP doit donner exactement le
    // DER que la reference Python produit a partir de la cle, et le point x962 doit correspondre
    // au point des coordonnees. Comparer PHP a lui meme ne suffirait pas : une numerotation de
    // labels lue de travers passerait les tests et echouerait devant un vrai navigateur.
    check('DER identique a la reference', der_from_pem($key['key']) === base64url_decode((string) ($fixture['der'] ?? '')), $key['type']);

    if ($key['type'] === 'EC2') {
        check('point x962 identique a la reference', CoseKey::x962(Cbor::decode(base64url_decode($registered['cose']))) === base64url_decode((string) ($fixture['point'] ?? '')));
    }

    // La forme normalisee par PHP doit donner celle de la reference : comparer PHP a lui meme
    // ne montrerait pas une longueur mal calculee.
    check(
        'forme de signature identique a la reference',
        Signature::form(base64url_decode($fixture['assert']['signature']), $key['alg']) === base64url_decode((string) ($fixture['reference_form'] ?? ''))
    );

    // Format fido-u2f : la signature porte sur 0x00 || rpIdHash || clientDataHash ||
    // credentialId || publicKeyU2F, pas sur hash(authData) + hash(clientData). Une signature
    // calculee sur l'autre base doit etre refusee : le controle porte sur ce sur quoi la
    // signature est faite, pas seulement sur sa forme.
    if (isset($fixture['bad_base'])) {
        try {
            $verifier->verifyRegistration(
                $fixture['bad_base']['attestation'],
                $fixture['bad_base']['client_data'],
                $json['challenge'],
                $policy
            );

            check('base de signature U2F refusee', false);
        } catch (Throwable $error) {
            check('base de signature U2F refusee', true, $error->getMessage());
        }
    }

    $stored = [
        'credential_id' => base64url_decode($fixture['register']['credential_id']),
        'cose' => $fixture['register']['cose'],
        'sign_count' => 0,
        'policy' => $policy,
        'uv_seen' => $fixture['user_verified'],
    ];

    // Assertion
    try {
        $asserted = $verifier->verifyAssertion(
            $fixture['assert']['auth_data'],
            $fixture['assert']['client_data'],
            $fixture['assert']['signature'],
            $stored,
            $json['challenge'],
            $policy
        );

        check('assertion', true, 'compteur ' . $asserted['sign_count']);
    } catch (Throwable $error) {
        check('assertion', false, $error->getMessage());
    }

    // Les deux formes de la signature doivent verifier la meme base : r || s (la forme de la
    // spec) et le DER (la forme que la couche de compatibilite CTAP1 d une cle U2F seule
    // transmet). Une forme malformee doit etre refusee : accepter le DER ne veut pas dire
    // accepter n importels octets, et la base de signature n est pas la forme.
    try {
        $otherForm = $verifier->verifyAssertion(
            $fixture['other_form']['auth_data'],
            $fixture['other_form']['client_data'],
            $fixture['other_form']['signature'],
            $stored,
            $json['challenge'],
            $policy
        );

        check('autre forme de signature acceptee', $otherForm['sign_count'] === $fixture['other_form']['sign_count'], 'compteur ' . $otherForm['sign_count']);
    } catch (Throwable $error) {
        check('autre forme de signature acceptee', false, $error->getMessage());
    }

    try {
        $verifier->verifyAssertion(
            $fixture['malformed']['auth_data'],
            $fixture['malformed']['client_data'],
            $fixture['malformed']['signature'],
            $stored,
            $json['challenge'],
            $policy
        );

        check('forme de signature malformee refusee', false);
    } catch (Throwable $error) {
        check('forme de signature malformee refusee', true, $error->getMessage());
    }

    // Rejoue : le meme compteur ne doit pas etre accepte deux fois.
    $replay = array_merge($stored, ['sign_count' => 2]);

    try {
        $verifier->verifyAssertion(
            $fixture['replay']['auth_data'],
            $fixture['replay']['client_data'],
            $fixture['replay']['signature'],
            $replay,
            $json['challenge'],
            $policy
        );

        check('rejoue refuse', false);
    } catch (Throwable $error) {
        check('rejoue refuse', true, $error->getMessage());
    }

    // Origine etrangere.
    try {
        $verifier->verifyAssertion(
            $fixture['bad_origin']['auth_data'],
            $fixture['bad_origin']['client_data'],
            $fixture['bad_origin']['signature'],
            array_merge($stored, ['sign_count' => 1]),
            $json['challenge'],
            $policy
        );

        check('origine refusee', false);
    } catch (Throwable $error) {
        check('origine refusee', true, $error->getMessage());
    }

    // Signature d'une autre cle.
    try {
        $verifier->verifyAssertion(
            $fixture['bad_signature']['auth_data'],
            $fixture['bad_signature']['client_data'],
            $fixture['bad_signature']['signature'],
            array_merge($stored, ['sign_count' => 1]),
            $json['challenge'],
            $policy
        );

        check('signature refusee', false);
    } catch (Throwable $error) {
        check('signature refusee', true, $error->getMessage());
    }

    // Challenge different.
    try {
        $verifier->verifyAssertion(
            $fixture['assert']['auth_data'],
            $fixture['assert']['client_data'],
            $fixture['assert']['signature'],
            $stored,
            base64url_encode(random_bytes(32)),
            $policy
        );

        check('challenge refuse', false);
    } catch (Throwable $error) {
        check('challenge refuse', true, $error->getMessage());
    }

    // Une ceremonie reglee en 'required' ne peut pas etre abaissee par la politique stockee
    // de la credential : une cle U2F ne peut pas satisfaire une ceremonie passkey, et une
    // cle qui a deja fait la verification utilisateur continue de la demander.
    try {
        $verifier->verifyAssertion(
            $fixture['assert']['auth_data'],
            $fixture['assert']['client_data'],
            $fixture['assert']['signature'],
            $stored,
            $json['challenge'],
            Policy::REQUIRED
        );

        check('required ne se relache pas pour la politique de la credential', $fixture['user_verified'], 'acceptee sans UV');
    } catch (Throwable $error) {
        check('required ne se relache pas pour la politique de la credential', !$fixture['user_verified'], $error->getMessage());
    }

    // 'preferred' autorise de passer la verification une fois, mais pas de la passer a
    // chaque fois quand la cle est capable de la faire : une credential qui l a deja faite
    // continue de la demander.
    $preferred = array_merge($stored, ['policy' => Policy::PREFERRED, 'uv_seen' => true]);

    try {
        $verifier->verifyAssertion(
            $fixture['assert']['auth_data'],
            $fixture['assert']['client_data'],
            $fixture['assert']['signature'],
            $preferred,
            $json['challenge'],
            Policy::PREFERRED
        );

        check('preferred garde la verification quand la cle l a deja faite', $fixture['user_verified'], 'acceptee sans UV');
    } catch (Throwable $error) {
        check('preferred garde la verification quand la cle l a deja faite', !$fixture['user_verified'], $error->getMessage());
    }
}

echo PHP_EOL, 'politique de verification utilisateur', PHP_EOL;

check('profil presence (second facteur avec mot de passe)', Policy::forProfile(Policy::PROFILE_PRESENCE) === Policy::DISCOURAGED);
check('profil passkey (la cle identifie l utilisateur)', Policy::forProfile(Policy::PROFILE_PASSKEY) === Policy::REQUIRED);
check('la ceremonie ne peut pas descendre en dessous de la credential', Policy::strongest(Policy::DISCOURAGED, Policy::REQUIRED) === Policy::REQUIRED);
check('required exige la verification utilisateur', Policy::enforces(Policy::REQUIRED, false));
check('preferred l exige quand la cle l a deja faite', Policy::enforces(Policy::PREFERRED, true) && !Policy::enforces(Policy::PREFERRED, false));
check('discouraged ne l exige jamais', !Policy::enforces(Policy::DISCOURAGED, true));

try {
    Policy::normalize('jamais demandee par la spec');
    check('politique inconnue refusee', false);
} catch (Throwable $error) {
    check('politique inconnue refusee', true, $error->getMessage());
}

try {
    Policy::forProfile('profil invente');
    check('profil inconnu refuse', false);
} catch (Throwable $error) {
    check('profil inconnu refuse', true, $error->getMessage());
}

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
