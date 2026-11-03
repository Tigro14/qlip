<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Webauthn\Cbor;
use Qlip\Webauthn\CoseKey;
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

foreach ($json['fixtures'] as $fixture) {
    echo $fixture['name'], PHP_EOL;

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
        $registered = $verifier->verifyRegistration($fixture['register']['attestation'], $fixture['register']['client_data'], $json['challenge']);
    } catch (Throwable $error) {
        check('enregistrement', false, $error->getMessage());
        continue;
    }

    check('enregistrement', true, 'fmt=' . $registered['fmt']);
    check('credential id', base64url_encode($registered['credential_id']) === $fixture['register']['credential_id']);
    check('cle COSE conservée', $registered['cose'] === $fixture['register']['cose']);
    check('compteur', $registered['sign_count'] === $fixture['register']['sign_count']);

    $key = CoseKey::toPem(Cbor::decode(base64url_decode($registered['cose'])));
    check('PEM', str_contains($key['key'], '-----BEGIN PUBLIC KEY-----'), $key['type'] . '/' . $key['alg']);

    // Assertion
    try {
        $asserted = $verifier->verifyAssertion(
            $fixture['assert']['auth_data'],
            $fixture['assert']['client_data'],
            $fixture['assert']['signature'],
            ['credential_id' => base64url_decode($fixture['register']['credential_id']), 'cose' => $fixture['register']['cose'], 'sign_count' => 0],
            $json['challenge']
        );

        check('assertion', true, 'compteur ' . $asserted['sign_count']);
    } catch (Throwable $error) {
        check('assertion', false, $error->getMessage());
    }

    // Rejoue : le meme compteur ne doit pas etre accepte deux fois.
    try {
        $verifier->verifyAssertion(
            $fixture['replay']['auth_data'],
            $fixture['replay']['client_data'],
            $fixture['replay']['signature'],
            ['credential_id' => base64url_decode($fixture['register']['credential_id']), 'cose' => $fixture['register']['cose'], 'sign_count' => 2],
            $json['challenge']
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
            ['credential_id' => base64url_decode($fixture['register']['credential_id']), 'cose' => $fixture['register']['cose'], 'sign_count' => 1],
            $json['challenge']
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
            ['credential_id' => base64url_decode($fixture['register']['credential_id']), 'cose' => $fixture['register']['cose'], 'sign_count' => 1],
            $json['challenge']
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
            ['credential_id' => base64url_decode($fixture['register']['credential_id']), 'cose' => $fixture['register']['cose'], 'sign_count' => 0],
            base64url_encode(random_bytes(32))
        );

        check('challenge refuse', false);
    } catch (Throwable $error) {
        check('challenge refuse', true, $error->getMessage());
    }
}

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
