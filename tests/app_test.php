<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/routes.php';

use Qlip\App\App;
use Qlip\App\Config;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\App\Session;

/**
 * Test d'ensemble de l'application, sans HTTP : schema SQLite, inscription,
 * verification d'email, connexion, quotas, pages, badges, exports, CSRF,
 * credentials WebAuthn. Le routeur est le meme que celui du front controller.
 *
 * Usage : php tests/app_test.php
 */

$out = getenv('QLIP_OUT') ?: '/tmp/opencode/badge';

if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

putenv('QLIP_BASE_URL=http://qlip.test');
putenv('QLIP_ADMIN_EMAILS=admin@qlip.test');

$config = new Config([
    'db_path' => $out . '/app_test.sqlite',
    'var_path' => $out . '/app_test_var',
    'base_url' => 'http://qlip.test',
    'rp_id' => 'qlip.test',
    'origins' => ['https://qlip.test'],
    'mail_transport' => 'outbox',
    'daily_limit' => 10,
]);

foreach (glob($config->dbPath . '*') as $stale) {
    if (is_file($stale)) {
        unlink($stale);
    }
}

foreach (glob($config->varPath . '/*/*') as $stale) {
    if (is_file($stale)) {
        unlink($stale);
    }
}

$app = new App($config);
$router = qlip_routes($app);

// Session factice : les tests ne passent pas par un vrai cookie.
$app->session = new Session(['token' => random_token(16)], true);

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo $ok ? '  OK   ' : '  KO   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

/**
 * Requete avec le jeton CSRF courant de la session, comme le fait le navigateur
 * (le jeton est regenere a chaque changement d'identite).
 */
$send = static function (string $method, string $path, array $body = []) use ($app, $router): Response {
    $request = new Request($method, $path, [], $body + ['csrf' => $app->session->token()]);
    $request->headers['accept'] = 'application/json';

    return $router->dispatch($request);
};

// 1. Schema
$tables = array_column($app->db->all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name');

check('schema', $tables === [
    'audit_log',
    'badges',
    'pages',
    'users',
    'verify_tokens',
    'webauthn_credentials',
], implode(', ', $tables));

// 2. Inscription : compte non verifie, email dans l outbox
$response = $send('POST', '/register', ['email' => 'admin@qlip.test', 'password' => 'mot-de-passe-long']);

check('inscription', $response->status() === 200);

$admin = $app->users->findByEmail('admin@qlip.test');

check('compte non verifie', $admin !== null && $admin['verified_at'] === null);
check('compte admin', $admin !== null && (int) $admin['is_admin'] === 1);

$eml = glob($config->varPath . '/outbox/*.eml') ?: [];

check('email dans l outbox', count($eml) === 1, $eml[0] ?? 'aucun fichier');
check('journal mail', is_file($config->varPath . '/log/mail.log'));

$token = (string) $app->db->one('SELECT token FROM verify_tokens WHERE user_id = :id', [':id' => $admin['id']])['token'];

check('jeton de verification', strlen($token) === 64, $token);

// 3. Connexion refusee avant verification
check('connexion avant verification', $send('POST', '/login', ['email' => 'admin@qlip.test', 'password' => 'mot-de-passe-long'])->status() === 401);

// 4. Verification, puis rejet du second usage du lien
check('verification', $send('GET', '/verify/' . $token)->status() === 200);
check('email verifie', $app->users->findByEmail('admin@qlip.test')['verified_at'] !== null);
check('lien usage unique', $send('GET', '/verify/' . $token)->status() === 400);

// 5. Jeton CSRF invalide
$response = $router->dispatch(new Request('POST', '/pages', [], ['csrf' => 'mauvais-jeton', 'title' => 'X']));

check('CSRF refuse', $response->status() === 400, $response->body());

// 6. Threpse de connexion : 8 tentatives, puis verrou
$app->session = new Session(['token' => random_token(16)], true);

for ($attempt = 1; $attempt <= 8; $attempt++) {
    $response = $send('POST', '/login', ['email' => 'admin@qlip.test', 'password' => 'pas-le-bon-mot-de-passe']);

    if ($response->status() !== 401) {
        check('tentatives rejouees', false, 'tentative ' . $attempt . ' : ' . $response->status());
        break;
    }
}

check('tentatives rejouees', $response->status() === 401);
check('verrou apres 8 tentatives', $send('POST', '/login', ['email' => 'admin@qlip.test', 'password' => 'pas-le-bon-mot-de-passe'])->status() === 429);

// 7. Connexion
$app->session = new Session(['token' => random_token(16)], true);

$response = $send('POST', '/login', ['email' => 'admin@qlip.test', 'password' => 'mot-de-passe-long']);

check('connexion', $response->status() === 302 && str_ends_with($response->header('Location') ?? '', '/dashboard'), $response->header('Location') ?? '');

// 8. Pages : creation, lecture, edition
$response = $send('POST', '/pages', [
    'title' => 'Portefeuille trouve',
    'slug' => 'portefeuille',
    'phone' => '06 12 34 56 78',
    'note' => 'Trouve sur le marche.',
]);

check('creation de page', $response->status() === 302, $response->header('Location') ?? '');

$page = $app->badges->pageBySlug('portefeuille');

check('slug conserve', $page !== null, $page['slug'] ?? '');
check('page publique', $send('GET', '/p/portefeuille')->status() === 200);

$response = $send('POST', '/p/portefeuille', ['title' => 'Portefeuille trouve', 'note' => 'Mis de cote au bureau.']);

check('edition de page', $response->status() === 302);
check('note enregistree', ($app->badges->pageBySlug('portefeuille')['note'] ?? '') === 'Mis de cote au bureau.');

// Un autre compte ne peut pas editer la page d un tiers
$other = $app->users->register('quelqu-un@qlip.test', 'un-mot-de-passe-long');
$otherUser = $other['user'];
$otherPage = $app->badges->createPage((int) $otherUser['id'], ['title' => 'Autre page', 'slug' => 'autre']);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id']], true);

check('page d un autre compte', $send('POST', '/p/portefeuille', ['title' => 'Vol'])->status() === 403);
check('page de l autre compte', $send('GET', '/p/autre')->status() === 200);

// 9. Quota : 10 badges par jour et par compte
$otherUser = $app->users->find((int) $otherUser['id']);

check('compte non verifie refuse', $send('POST', '/badges', ['slug' => 'autre'])->status() === 403);

$app->users->verifyToken($other['token']);

$response = $send('POST', '/badges', ['slug' => 'autre']);

check('quota par compte', $response->status() === 201, $response->body());
check('quota par compte (autre compte)', $app->badges->usedToday((int) $otherUser['id']) === 1);
check('quota du compte admin intact', $app->badges->usedToday((int) $admin['id']) === 0);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

check('quota au depart', $app->badges->remaining((int) $admin['id']) === 10);

$badgeIds = [];

for ($index = 1; $index <= 10; $index++) {
    $response = $send('POST', '/badges', [
        'slug' => 'portefeuille',
        'style' => $index % 2 === 0 ? 'raised' : 'engraved',
        'bicolor' => $index === 3 ? '1' : '0',
    ]);

    if ($response->status() !== 201) {
        check('badge ' . $index, false, $response->body());
        break;
    }

    $badgeIds[] = (int) json_decode($response->body(), true)['badge']['id'];
}

check('10 badges creationnes', count($badgeIds) === 10, (string) count($badgeIds));
check('quota consomme', $app->badges->usedToday((int) $admin['id']) === 10);

$response = $send('POST', '/badges', ['slug' => 'portefeuille']);

check('11e refusee', $response->status() === 422, $response->body());

// 10. Exports
$export = $app->badges->export($app->badges->badgeById($badgeIds[2]));

check('STL ASCII', str_starts_with($export['stl'], 'solid'));
check('3MF est un ZIP', str_starts_with($export['threeMf'], "PK\x03\x04"));
check('deux volumes en bicolore', count($export['volumes']) === 2);
check('un volume en monochrome', count($app->badges->export($app->badges->badgeById($badgeIds[0]))['volumes']) === 1);

$problems = [];
$triangles = 0;

foreach ($export['volumes'] as $volume) {
    $problems = array_merge($problems, $volume['mesh']->checkWatertight());
    $triangles += $volume['mesh']->count();

    if ($volume['mesh']->signedVolume() <= 0.0) {
        $problems[] = 'volume negatif pour ' . $volume['name'];
    }
}

check('mesh etanche', $problems === [], $problems[0] ?? '');
check('triangles', $triangles > 1000, (string) $triangles);

file_put_contents($out . '/app_export.stl', $export['stl']);
file_put_contents($out . '/app_export.3mf', $export['threeMf']);

// Telechargement par la route
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id']], true);

check('badge d un autre compte', $send('GET', '/badges/' . $badgeIds[0] . '/stl')->status() === 403);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

$response = $send('GET', '/badges/' . $badgeIds[0] . '/stl');

check('telechargement STL', $response->status() === 200 && $response->header('Content-Type') ?? '' === 'model/stl');
check('nom de fichier', str_contains($response->header('Content-Disposition') ?? '', 'qlip-' . $badgeIds[0] . '.stl'));

check('badge introuvable', $send('GET', '/badges/9999/stl')->status() === 404);

// 11. PNG du QR
$png = $app->badges->png($app->badges->pageBySlug('portefeuille'));

check('PNG', str_starts_with($png, "\x89PNG\r\n\x1a\n") && str_contains($png, 'IHDR'));
check('texte encode', $app->badges->qrText($app->badges->pageBySlug('portefeuille')) === 'http://qlip.test/p/portefeuille');

// 12. Routes : 404 et 405
check('404', $send('GET', '/inconnu')->status() === 404);
check('405', $send('POST', '/health')->status() === 405);

$health = json_decode($send('GET', '/health')->body(), true);

check('sante', ($health['ok'] ?? false) === true);

// 13. WebAuthn : ceremonies par les routes, avec les fixtures du faux authenticateur
$fixtures = json_decode((string) file_get_contents(getenv('QLIP_WEBAUTHN_JSON') ?: '/tmp/opencode/webauthn.json'), true);

if (!is_array($fixtures)) {
    check('fixtures WebAuthn', false, 'JSON absent : ' . (getenv('QLIP_WEBAUTHN_JSON') ?: '/tmp/opencode/webauthn.json'));
} else {
    $fixture = $fixtures['fixtures'][0];
    $credentialId = $fixture['register']['credential_id'];

    $app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

    // La page d'enrolement est le seul endroit ou la premiere credential est
    // enregistree : elle doit proposer le bouton pour un compte administrateur, et
    // `/admin` renvoie encore a cette page tant qu'aucune assertion n'a ete faite.
    $response = $send('GET', '/admin/yubikey');

    check('bouton d enrolement pour un admin', str_contains($response->body(), 'data-action="register"'));

    $response = $send('GET', '/admin');

    check('admin bloque avant la premiere assertion', str_contains($response->header('Location') ?? '', '/admin/yubikey'));

    // Le challenge de la fixture doit etre celui stocke en session.
    $app->session->set('challenge', $fixtures['challenge']);

    $response = $send('POST', '/webauthn/register', [
        'credential_id' => $credentialId,
        'attestation' => $fixture['register']['attestation'],
        'client_data' => $fixture['register']['client_data'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('enregistrement par la route', $response->status() === 201, $data['error'] ?? $response->body());
    check('attestation none', ($data['credential']['attested'] ?? false) === false, json_encode($data['credential'] ?? []));

    $stored = $app->db->one(
        'SELECT * FROM webauthn_credentials WHERE credential_id = :credential_id',
        [':credential_id' => $credentialId]
    );

    check('credential en base', $stored !== null && (int) $stored['sign_count'] === 1);

    $key = $app->credentials->algorithm($stored);

    check('algorithme de la credential', ($key['alg'] ?? '') === 'ES256' && ($key['type'] ?? '') === 'EC2', json_encode($key));

    $assert = static function (array $parts) use ($send, $credentialId): array {
        $response = $send('POST', '/webauthn/assert', [
            'credential_id' => $credentialId,
            'auth_data' => $parts['auth_data'],
            'client_data' => $parts['client_data'],
            'signature' => $parts['signature'],
        ]);

        return [$response->status(), json_decode($response->body(), true) ?: []];
    };

    [$status, $data] = $assert($fixture['assert']);

    check('assertion par la route', $status === 200 && ($data['ok'] ?? false) === true, $data['error'] ?? '');
    check('session confirme par la YubiKey', $app->session->get('webauthn_at') !== null);
    check('compteur mis a jour', (int) $app->db->one(
        'SELECT sign_count FROM webauthn_credentials WHERE credential_id = :credential_id',
        [':credential_id' => $credentialId]
    )['sign_count'] === 2);

    // Rejoue : le meme compteur n'est pas accepte deux fois.
    [$status, $data] = $assert($fixture['replay']);

    check('rejoue refuse', $status === 400, $data['error'] ?? '');

    // Signature calculee avec une autre cle.
    [$status, $data] = $assert($fixture['bad_signature']);

    check('signature refusee', $status === 400, $data['error'] ?? '');

    // Assertion avec une origine etrangere.
    [$status, $data] = $assert($fixture['bad_origin']);

    check('origine refusee a l assertion', $status === 400, $data['error'] ?? '');

    // Enregistrement avec une origine etrangere.
    $evilCreate = base64url_encode(json_encode([
        'type' => 'webauthn.create',
        'challenge' => $fixtures['challenge'],
        'origin' => 'https://evil.example',
        'tokenType' => 'WebAuthn',
    ]));

    $response = $send('POST', '/webauthn/register', [
        'credential_id' => $credentialId,
        'attestation' => $fixture['register']['attestation'],
        'client_data' => $evilCreate,
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('origine refusee a l enregistrement', $response->status() === 400, $data['error'] ?? '');

    $response = $send('POST', '/webauthn/assert', [
        'credential_id' => 'claie-inconnue',
        'auth_data' => $fixture['bad_origin']['auth_data'],
        'client_data' => $fixture['bad_origin']['client_data'],
        'signature' => $fixture['bad_origin']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('credential inconnue', $response->status() === 403, $data['error'] ?? '');

    $counts = [
        'register' => (int) $app->db->count("SELECT COUNT(*) FROM audit_log WHERE action = 'webauthn_register'"),
        'assert' => (int) $app->db->count("SELECT COUNT(*) FROM audit_log WHERE action = 'webauthn_assert'"),
        'failed' => (int) $app->db->count("SELECT COUNT(*) FROM audit_log WHERE action = 'webauthn_assert_failed'"),
    ];

    check('journal d audit', $counts === ['register' => 1, 'assert' => 1, 'failed' => 3], json_encode($counts));
}

// 14. Variables d'environnement : Apache ne les transmet pas de la meme facon selon le
// module. PutEnv arrive dans getenv(), SetEnv dans $_SERVER, et une requete reecrite par
// mod_rewrite porte les valeurs en REDIRECT_*. PHP-FPM ne voit pas PutEnv.
check('env par defaut', env('QLIP_TEST_ABSENT', 'defaut') === 'defaut');

putenv('QLIP_TEST_GETENV=de-getenv');
$_SERVER['QLIP_TEST_SERVER'] = 'de-server';
$_SERVER['REDIRECT_QLIP_TEST_REDIR'] = 'de-redirect';

check('env par getenv', env('QLIP_TEST_GETENV') === 'de-getenv');
check('env par $_SERVER', env('QLIP_TEST_SERVER') === 'de-server');
check('env par REDIRECT_', env('QLIP_TEST_REDIR') === 'de-redirect');

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
