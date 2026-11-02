<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/routes.php';

use Qlip\App\App;
use Qlip\App\Config;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\App\Session;
use Qlip\Stl\Design;
use Qlip\Stl\StlWriter;
use Qlip\Stl\ThreeMfWriter;

/**
 * Test d'ensemble de l'application, sans HTTP : schema SQLite, inscription,
 * verification d'email, connexion, quotas, pages, badges, exports, CSRF,
 * credentials WebAuthn, echappement et controle des champs. Le routeur est le meme
 * que celui du front controller.
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

// 2b. Transport smtp : rien n'est ecrit dans l outbox, le lien n'est pas affiche
// (sinon n'importe qui activerait le compte qu'il vient de creer) et l'echec d envoi est
// signale. Ici rien n'ecoute sur le port : le refus est attendu.
$smtpConfig = new Config([
    'db_path' => $config->dbPath,
    'var_path' => $config->varPath,
    'base_url' => $config->baseUrl,
    'mail_transport' => 'smtp',
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 2599,
    'smtp_user' => 'app@qlip.test',
    'smtp_pass' => 'mot-de-passe',
    'smtp_from' => 'app@qlip.test',
]);

$smtpApp = new App($smtpConfig);
$smtpApp->session = new Session(['token' => random_token(16)], true);

$response = qlip_routes($smtpApp)->dispatch(new Request('POST', '/register', [], [
    'email' => 'normal@qlip.test',
    'password' => 'mot-de-passe-long',
    'csrf' => $smtpApp->session->token(),
]));

$body = $response->body();

check('envoi smtp refuse par le serveur et signale', $response->status() === 200 && str_contains($body, 'flash-err') && str_contains($body, 'refusee'), trim((string) preg_replace('/\s+/', ' ', strip_tags($body))));
check('lien masque quand le transport est smtp', !str_contains($body, '/verify/'), '');
check('pas de copie dans l outbox en smtp', count(glob($config->varPath . '/outbox/*.eml') ?: []) === 1, (string) count(glob($config->varPath . '/outbox/*.eml') ?: []));

// 2c. Transport local : le lien est masque pour la meme raison, et l echec de la remise est
// signale. Ici il n y a pas de MTA : on donne un chemin absent, donc la remise est refusee
// avant meme de lancer quoi que ce soit.
$localConfig = new Config([
    'db_path' => $config->dbPath,
    'var_path' => $config->varPath,
    'base_url' => $config->baseUrl,
    'mail_transport' => 'local',
    'sendmail_path' => $out . '/no_sendmail_here',
    'sendmail_from' => 'app@qlip.test',
]);

$localApp = new App($localConfig);
$localApp->session = new Session(['token' => random_token(16)], true);

$response = qlip_routes($localApp)->dispatch(new Request('POST', '/register', [], [
    'email' => 'local@qlip.test',
    'password' => 'mot-de-passe-long',
    'csrf' => $localApp->session->token(),
]));

$body = $response->body();

check('remise au MTA local refusee et signalee', $response->status() === 200 && str_contains($body, 'flash-err') && str_contains($body, 'absent'), trim((string) preg_replace('/\s+/', ' ', strip_tags($body))));
check('lien masque quand le transport est local', !str_contains($body, '/verify/'), '');
check('pas de copie dans l outbox en local', count(glob($config->varPath . '/outbox/*.eml') ?: []) === 1, (string) count(glob($config->varPath . '/outbox/*.eml') ?: []));

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

// 14. Echappement et controle des champs : l app est exposee au public.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

// Le maxlength des formulaires n'est pas une protection : on poste directement.
$response = $send('POST', '/pages', ['title' => str_repeat('a', 81), 'slug' => 'outretroplong']);

check('titre trop long refuse', $response->status() === 422, $response->body());

$response = $send('POST', '/pages', ['title' => 'Note', 'note' => str_repeat('b', 501)]);

check('note trop longue refusee', $response->status() === 422, $response->body());

$response = $send('POST', '/pages', ['title' => 'Email', 'email_contact' => 'pas un email']);

check('email de contact refuse', $response->status() === 422, $response->body());

$response = $send('POST', '/pages', ['title' => 'Email valide', 'email_contact' => 'contact@qlip.test', 'slug' => 'emailvalide']);

check('email de contact accepte', $response->status() === 302, $response->body());

$slugPage = $app->badges->createPage((int) $admin['id'], ['title' => 'Long slug', 'slug' => str_repeat('s', 60)]);

check('slug tronque a 32', strlen((string) $slugPage['slug']) === 32, (string) $slugPage['slug']);

// Le XSS se traite a l'affichage : les valeurs sont stockees telles quelles, et echappees
// par e() dans les templates. On verifie le rendu, pas seulement l'enregistrement.
$xssPage = $app->badges->createPage((int) $admin['id'], [
    'title' => '<script>alert(1)</script>',
    'slug' => 'xsstest',
    'note' => '<img src=x onerror=alert(1)>',
]);

$html = $send('GET', '/p/xsstest')->body();

check('titre echappe au rendu', str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)'));
check('note echappee au rendu', str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x onerror='));

check('echappement des guillemets', attr('a"b') === 'a&quot;b' && e("l'et") === 'l&#039;et');

// Les URL de template partent dans des attributs : attr() est utilise autour de url().
check('url echappee dans un attribut', attr(url('p/xsstest')) === 'http://qlip.test/p/xsstest');

// En-tetes de securite sur toutes les reponses, y compris le JSON (verifie aussi par
// tests/http_test.py sur une vraie reponse HTTP).
$json = Response::json(['ok' => true])->withSecurityHeaders();

check('nosniff sur une reponse JSON', ($json->headers()['X-Content-Type-Options'] ?? '') === 'nosniff');
check('frame-ancestors sur une reponse JSON', str_contains($json->headers()['Content-Security-Policy'] ?? '', 'frame-ancestors'));

$disposition = Response::file('x', "mauvais\nnom\".stl", 'model/stl')->header('Content-Disposition');

check('nom de fichier nettoye', $disposition === 'attachment; filename="mauvaisnom.stl"', (string) $disposition);

$location = (string) Response::redirect("/login\r\nEn-tete-injectee: 1")->header('Location');

check('Location sans retour a la ligne', !str_contains($location, "\n") && !str_contains($location, "\r"), $location);

// Parametres de design : tout est borne, les couleurs doivent rester un hexadecimal.
$design = Design::fromArray([
    'cornerRadius' => 99,
    'band' => 0.5,
    'margin' => 50,
    'colorBase' => '"><script>',
    'colorInk' => '#00ff00',
]);

check('cornerRadius borne', $design->cornerRadius <= 8.0, (string) $design->cornerRadius);
check('band borne', $design->band >= $design->holeRadius + 1.0 && $design->band <= 15.0, (string) $design->band);
check('margin borne', $design->margin <= 10.0, (string) $design->margin);
check('couleur invalide rejetee', $design->colorBase === '1A1A1A', $design->colorBase);
check('couleur valide acceptee', $design->colorInk === '00FF00', $design->colorInk);

// Nom de volume : un jeton dans un STL ASCII, un retour a la ligne casserait le fichier.
$stl = StlWriter::write([['name' => "base\nnom casse", 'mesh' => $export['volumes'][0]['mesh']]]);

check('nom STL normalise', str_starts_with($stl, 'solid basenomcasse') && str_contains($stl, "endsolid basenomcasse"), substr($stl, 0, 24));

// Le .3MF est un ZIP sans compression : le XML est lisible tel quel.
$model = ThreeMfWriter::write([['name' => '"><script>', 'color' => '"><script>', 'mesh' => $export['volumes'][0]['mesh']]], ['title' => '<script>']);

check('3MF : nom et couleur echappes', !str_contains($model, '"><script>') && str_contains($model, '&quot;&gt;&lt;script&gt;'), substr($model, 0, 40));

// 15. Variables d'environnement : Apache ne les transmet pas de la meme facon selon le
// module. PutEnv arrive dans getenv(), SetEnv dans $_SERVER, et une requete reecrite par
// mod_rewrite porte les valeurs en REDIRECT_*. PHP-FPM ne voit pas PutEnv.
check('env par defaut', env('QLIP_TEST_ABSENT', 'defaut') === 'defaut');

putenv('QLIP_TEST_GETENV=de-getenv');
$_SERVER['QLIP_TEST_SERVER'] = 'de-server';
$_SERVER['REDIRECT_QLIP_TEST_REDIR'] = 'de-redirect';

check('env par getenv', env('QLIP_TEST_GETENV') === 'de-getenv');
check('env par $_SERVER', env('QLIP_TEST_SERVER') === 'de-server');
check('env par REDIRECT_', env('QLIP_TEST_REDIR') === 'de-redirect');

// 16. Deconnexion : c'est un POST, donc il exige le jeton CSRF. Sans lui, une page
// externe peut faire perdre la session a un compte connecte.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

check('deconnexion refusee sans jeton', $send('POST', '/logout', ['csrf' => 'mauvais-jeton'])->status() === 400);

$response = $send('POST', '/logout', ['csrf' => $app->session->token()]);

check('deconnexion acceptee', $response->status() === 302, $response->body());
check('session videes', $app->session->get('user_id') === null);

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
