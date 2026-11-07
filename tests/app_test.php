<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/routes.php';

use Qlip\App\App;
use Qlip\App\Config;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\App\Session;
use Qlip\Db;
use Qlip\Stl\Design;
use Qlip\Stl\StlWriter;
use Qlip\Stl\ThreeMfWriter;
use Qlip\Support\Pager;
use Qlip\Webauthn\Policy;

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
    'reset_tokens',
    'users',
    'verify_tokens',
    'webauthn_credentials',
], implode(', ', $tables));

// Une page porte un seul badge. L index est une protection, pas une convention du service :
// sans lui, deux ecritures pour la meme page donneraient deux badges portant le meme QR.
$appIndexes = array_column($app->db->all("SELECT name FROM sqlite_master WHERE type = 'index' ORDER BY name"), 'name');

check('index unique par page', in_array('badges_page_unique', $appIndexes, true), implode(', ', $appIndexes));

// 1b. Migration d une base creee avant la regle. La base de production n est jamais
// reconstruite, donc la migration est mesuree sur une base dans l ancien forme : les memes
// tables, sans l index unique, avec trois badges pour la meme page. On ne peut pas la fabriquer
// avec new Db() seul : le constructeur pose l index, et trois badges ne passeraient pas. On
// ouvre une base, on enleve l index, on met trois badges, puis on la rouvre : c est l etat que
// la migration trouve sur le serveur.
$oldPath = $out . '/old_shape.sqlite';

foreach (glob($oldPath . '*') as $stale) {
    if (is_file($stale)) {
        unlink($stale);
    }
}

$old = new Db($oldPath);

$old->exec(
    'INSERT INTO users (email, password_hash, is_admin, verified_at, created_at)
     VALUES (:email, :hash, 0, NULL, :created_at)',
    [':email' => 'migration@qlip.test', ':hash' => 'pas-un-hash', ':created_at' => now()]
);
$oldUserId = (int) $old->pdo()->lastInsertId();

$old->exec(
    'INSERT INTO pages (slug, user_id, title, created_at) VALUES (:slug, :user_id, :title, :created_at)',
    [':slug' => 'ancienne-page', ':user_id' => $oldUserId, ':title' => 'Ancienne page', ':created_at' => now()]
);
$oldPageId = (int) $old->pdo()->lastInsertId();

// L index est enleve pour reproduire l ancien forme : sans lui rien n'empechait deux badges
// pour une page, et c est ce que la migration doit trouver.
$old->exec('DROP INDEX badges_page_unique');

for ($index = 1; $index <= 3; $index++) {
    $old->exec(
        'INSERT INTO badges (page_id, user_id, style, bicolor, design, created_at)
         VALUES (:page_id, :user_id, :style, 0, :design, :created_at)',
        [
            ':page_id' => $oldPageId,
            ':user_id' => $oldUserId,
            ':style' => 'engraved',
            ':design' => '{"width":' . $index . '}',
            ':created_at' => now(),
        ]
    );
}

$newest = (int) $old->one('SELECT MAX(id) AS newest FROM badges WHERE page_id = :page_id', [':page_id' => $oldPageId])['newest'];

$old = new Db($oldPath);

$kept = (int) $old->one('SELECT id FROM badges WHERE page_id = :page_id', [':page_id' => $oldPageId])['id'];
$traces = $old->count("SELECT COUNT(*) FROM audit_log WHERE action = 'badge_doublon_supprime'", []);
$quota = $old->count("SELECT COUNT(*) FROM audit_log WHERE action = 'badge'", []);
$indexes = array_column($old->all("SELECT name FROM sqlite_master WHERE type = 'index' ORDER BY name"), 'name');

check('migration : le badge le plus recent est garde', $kept === $newest, (string) $kept);
check('migration : les doublons sont retires', $old->count('SELECT COUNT(*) FROM badges WHERE page_id = :page_id', [':page_id' => $oldPageId]) === 1);
check('migration : une trace par ligne retiree', $traces === 2, (string) $traces);
check('migration : la trace ne compte pas dans le quota', $quota === 0, (string) $quota);
check('migration : l index est pose apres le tri', in_array('badges_page_unique', $indexes, true), implode(', ', $indexes));

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

// 7b. Reinitialisation de mot de passe. Le lien est usage unique et valable 24 h, la
// reponse ne dit pas si l'adresse a un compte, et le verou est compte sur la demande
// (une adresse sans compte consomme la meme part, sinon le formulaire servirait a tester
// les adresses sans limite).
$app->session = new Session(['token' => random_token(16)], true);

/**
 * Nombre de messages journalises pour une adresse. On compte dans le journal, pas dans
 * l outbox : le nom de fichier porte la seconde et l adresse, donc deux envois a la meme
 * adresse dans la meme seconde ecrivent le meme fichier.
 */
$mailed = static function (string $address) use ($config): int {
    $log = (string) file_get_contents($config->varPath . '/log/mail.log');

    return count(array_filter(explode("\n", $log), static fn (string $line): bool => str_contains($line, $address)));
};

$forgotten = $app->users->register('oublie@qlip.test', 'un-mot-de-passe-long');
$before = $mailed('oublie@qlip.test');

$response = $send('POST', '/password', ['email' => 'oublie@qlip.test']);

check('reinitialisation : demande acceptee', $response->status() === 200, (string) $response->status());
check('reinitialisation : la reponse ne dit pas si le compte existe', str_contains($response->body(), 'Si cette adresse a un compte'));

$unknown = $send('POST', '/password', ['email' => 'sans-compte@qlip.test']);

check('reinitialisation : une adresse sans compte rend la meme page', $unknown->status() === 200 && str_contains($unknown->body(), 'Si cette adresse a un compte'));
check('reinitialisation : un message envoye pour un compte qui existe', $mailed('oublie@qlip.test') === $before + 1, (string) $mailed('oublie@qlip.test'));
check('reinitialisation : rien envoye pour une adresse sans compte', $mailed('sans-compte@qlip.test') === 0, (string) $mailed('sans-compte@qlip.test'));

$first = $app->users->requestReset('oublie@qlip.test');
$second = $app->users->requestReset('oublie@qlip.test');

check('jeton de reinitialisation', strlen($first['token']) === 64, $first['token']);

check('formulaire de reinitialisation', $send('GET', '/password/reset/' . $first['token'])->status() === 200);
check('CSRF refuse sur la reinitialisation', $router->dispatch(new Request('POST', '/password/reset/' . $first['token'], [], ['csrf' => 'mauvais-jeton', 'password' => 'un-mot-de-passe-long']))->status() === 400);

$refused = $send('POST', '/password/reset/' . $first['token'], ['password' => 'court']);

check('mot de passe trop court refuse', $refused->status() === 200 && str_contains($refused->body(), 'trop court'), trim((string) preg_replace('/\s+/', ' ', strip_tags($refused->body()))));
check('le lien n est pas consomme par un refus', $app->users->resetToken($first['token']) !== null);

$response = $send('POST', '/password/reset/' . $first['token'], ['password' => 'nouveau-mot-de-passe-long']);

check('mot de passe change', $response->status() === 302 && str_ends_with($response->header('Location') ?? '', '/dashboard'), $response->header('Location') ?? '');
check('session pointee sur le compte', (int) $app->session->get('user_id', 0) === (int) $forgotten['user']['id']);
check('nouveau mot de passe accepte', $app->users->attempt('oublie@qlip.test', 'nouveau-mot-de-passe-long') !== null);
check('ancien mot de passe refuse', $app->users->attempt('oublie@qlip.test', 'un-mot-de-passe-long') === null);
check('compte active par le lien de reinitialisation', $app->users->find((int) $forgotten['user']['id'])['verified_at'] !== null);
check('lien usage unique', $send('POST', '/password/reset/' . $first['token'], ['password' => 'un-mot-de-passe-long'])->status() === 400);

// Un lien demande avant le changement ne doit plus servir apres : les jetons du compte
// sont fermes quand le mot de passe change.
check('autres liens du compte fermes', $app->users->resetToken($second['token']) === null);
check('formulaire ferme pour un lien usage', $send('GET', '/password/reset/' . $second['token'])->status() === 400);

$stale = random_token(32);

$app->db->exec(
    'INSERT INTO reset_tokens (user_id, token, created_at) VALUES (:user_id, :token, :created_at)',
    [':user_id' => (int) $forgotten['user']['id'], ':token' => $stale, ':created_at' => date('Y-m-d H:i:s', time() - 86401)]
);

check('lien expire refuse', $send('GET', '/password/reset/' . $stale)->status() === 400);

$trace = $app->db->one("SELECT user_id, detail FROM audit_log WHERE action = 'reset_request' AND user_id IS NULL ORDER BY id DESC LIMIT 1");

check('trace d une demande pour une adresse sans compte',
    $trace !== null && str_contains((string) $trace['detail'], 'sans-compte@qlip.test'),
    (string) ($trace['detail'] ?? ''));

check('trace de changement de mot de passe', $app->db->count("SELECT COUNT(*) FROM audit_log WHERE action = 'reset' AND user_id = :user_id", [':user_id' => (int) $forgotten['user']['id']]) === 1);

// Verou : 5 demandes par heure et par navigateur, sur une session separee.
$app->session = new Session(['token' => random_token(16)], true);

for ($attempt = 1; $attempt <= 5; $attempt++) {
    $response = $send('POST', '/password', ['email' => 'oublie@qlip.test']);

    if ($response->status() !== 200) {
        check('demandes rejouees', false, 'demande ' . $attempt . ' : ' . $response->status());
        break;
    }
}

check('demandes rejouees', $response->status() === 200);
check('verou apres 5 demandes', $send('POST', '/password', ['email' => 'oublie@qlip.test'])->status() === 429);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

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

// Le slug est l identite de la page : ce que le QR encode. Un slug deja pris n est pas remplace
// par un autre, sinon la meme page existerait sous deux adresses et on ne saurait plus laquelle
// le QR imprime designe.
$response = $send('POST', '/pages', ['title' => 'Encore le portefeuille', 'slug' => 'portefeuille']);

check('page doublon refusee', $response->status() === 422, json_decode($response->body(), true)['error'] ?? '');
check('page doublon non creee', count($app->db->all("SELECT id FROM pages WHERE slug = 'portefeuille'")) === 1);

// Un autre compte ne peut pas editer la page d un tiers
$other = $app->users->register('quelqu-un@qlip.test', 'un-mot-de-passe-long');
$otherUser = $other['user'];
$otherPage = $app->badges->createPage((int) $otherUser['id'], ['title' => 'Autre page', 'slug' => 'autre']);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id']], true);

check('page d un autre compte', $send('POST', '/p/portefeuille', ['title' => 'Vol'])->status() === 403);
check('page de l autre compte', $send('GET', '/p/autre')->status() === 200);

// 9. Quota : 10 enregistrements par jour et par compte. Une page porte un seul badge, donc
// enregistrer la meme page dix fois ne fait pas dix badges.
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
        'bicolor' => $index === 10 ? '1' : '0',
    ]);

    if ($response->status() !== 201) {
        check('enregistrement ' . $index, false, $response->body());
        break;
    }

    $badgeIds[] = (int) json_decode($response->body(), true)['badge']['id'];
}

check('10 enregistrements', count($badgeIds) === 10, (string) count($badgeIds));
check('un seul badge pour la page', count(array_unique($badgeIds)) === 1, implode(',', array_unique($badgeIds)));
check('quota consomme', $app->badges->usedToday((int) $admin['id']) === 10);

// Une page sans badge est normale : le formulaire du panneau propose son premier badge.
$noBadge = $app->badges->createPage((int) $admin['id'], ['title' => 'Page sans badge', 'slug' => 'sansbadge']);

check('page sans badge', $app->badges->badgeByPage((int) $noBadge['id']) === null);

// L index est la protection, pas le service : une ecriture directe pour une page deja badgee est
// refusee. Sans lui, dix enregistrements feraient dix badges portant le meme QR.
try {
    $app->db->exec(
        'INSERT INTO badges (page_id, user_id, style, bicolor, design, created_at)
         VALUES (:page_id, :user_id, :style, :bicolor, :design, :created_at)',
        [
            ':page_id' => (int) $page['id'],
            ':user_id' => (int) $admin['id'],
            ':style' => 'engraved',
            ':bicolor' => 0,
            ':design' => '{}',
            ':created_at' => now(),
        ]
    );

    check('second badge pour la meme page refuse en base', false, 'insert accepte');
} catch (\Throwable $error) {
    check('second badge pour la meme page refuse en base', str_contains($error->getMessage(), 'UNIQUE'), $error->getMessage());
}

$response = $send('POST', '/badges', ['slug' => 'portefeuille']);

check('11e refusee', $response->status() === 422, $response->body());

// 10. Exports
$export = $app->badges->export($app->badges->badgeById($badgeIds[0]));

check('STL ASCII', str_starts_with($export['stl'], 'solid'));
check('3MF est un ZIP', str_starts_with($export['threeMf'], "PK\x03\x04"));
check('deux volumes en bicolore', count($export['volumes']) === 2);
check('un volume en monochrome', count($app->badges->export($app->badges->badgeByPage((int) $otherPage['id']))['volumes']) === 1);

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

    // La ceremonie WebAuthn se fait dans le navigateur, dans app.js. Le CSP autorise
    // script-src 'self' : un fichier du site, pas une balise inline. Si le layout ne charge
    // pas le script, les boutons ne font rien et l enrolement est impossible.
    check('app.js charge par la page', str_contains($response->body(), 'src="http://qlip.test/assets/app.js"'), $response->body());

    // L identite transmise au navigateur est un tampon : le champ id de
    // PublicKeyCredentialUserEntity n est pas une chaine. Passe tel quel en JSON il reste une
    // chaine, et credentials.create refuse la ceremonie. Il est donc transmis en base64url,
    // comme le reste des tampons echanges avec le navigateur. La spec demande au moins 16
    // octets pour ce tampon : un id de 1 octet est refuse par certains moteurs.
    preg_match('/data-user="([^"]+)"/', $response->body(), $match);
    $raw = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES, 'UTF-8');
    $entity = json_decode($raw, true);

    try {
        $handle = base64url_decode((string) ($entity['id'] ?? ''));
    } catch (\Throwable $error) {
        $handle = '';
    }

    check('id d utilisateur transmis en base64url', $handle !== '', $raw);
    check('handle d utilisateur : 16 octets minimum', strlen($handle) >= 16, $raw);
    check(
        'handle d utilisateur : le compte est retrouvable',
        (int) hexdec(bin2hex(substr($handle, -8))) === (int) $admin['id'],
        $raw
    );

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

// Texte grave au dos : angle et profondeur bornes, et une alveole du QR ne perce jamais
// la plaque jusqu'aux alveoles gravees au dos.
$engrave = Design::fromArray(['textAngle' => 200, 'textDepth' => 3.0, 'thickness' => 1.5, 'depth' => 2.0]);

check('textAngle borne', $engrave->textAngle >= -90.0 && $engrave->textAngle <= 90.0, (string) $engrave->textAngle);
check('profondeur de gravure bornee', $engrave->textDepth >= 0.1 && $engrave->textDepth <= $engrave->thickness / 4.0, (string) $engrave->textDepth);
check('alveole QR sans percee du dos', $engrave->depth + $engrave->textDepth + 0.2 <= $engrave->thickness, sprintf('QR %.2f + gravure %.2f <= %.2f', $engrave->depth, $engrave->textDepth, $engrave->thickness));
check('gravure activee par defaut', $engrave->engraveUrl);
check('gravure desactivee acceptee', Design::fromArray(['engraveUrl' => false])->engraveUrl === false);
check('champs graves persistes dans le design', isset($engrave->toArray()['engraveUrl'], $engrave->toArray()['textDepth'], $engrave->toArray()['textAngle']));

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

// 17. Suppression : elle est definitive, donc jeton CSRF + confirmation explicite, et le
// mot de passe pour le compte. Une session laissee ouverte sur un poste partage ne doit pas
// permettre de detruire un compte.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

check('suppression refusee sans jeton', $send('POST', '/p/portefeuille/delete', ['csrf' => 'mauvais-jeton', 'confirm' => '1'])->status() === 400);
check('suppression refusee sans confirmation', $send('POST', '/p/portefeuille/delete', ['confirm' => '0'])->status() === 400);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id']], true);

check('suppression d une page d un autre compte', $send('POST', '/p/portefeuille/delete', ['confirm' => '1'])->status() === 403);
check('suppression d un badge d un autre compte', $send('POST', '/badges/' . $badgeIds[0] . '/delete', ['confirm' => '1'])->status() === 403);
check('page d un autre compte toujours la', $app->badges->pageBySlug('portefeuille') !== null);

// L administrateur peut supprimer ce qu il peut lire : la page et le badge de l autre compte.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

$otherBadges = $app->db->count('SELECT COUNT(*) FROM badges WHERE user_id = :user_id', [':user_id' => (int) $otherUser['id']]);

check('admin supprime la page d un autre compte', $send('POST', '/p/autre/delete', ['confirm' => '1'])->status() === 200);
check('page disparue', $app->badges->pageBySlug('autre') === null);
check('les badges partent avec la page', $otherBadges > 0 && $app->db->count('SELECT COUNT(*) FROM badges WHERE user_id = :user_id', [':user_id' => (int) $otherUser['id']]) === 0, (string) $otherBadges);

$usedBefore = $app->badges->usedToday((int) $admin['id']);

check('suppression d un badge', $send('POST', '/badges/' . $badgeIds[0] . '/delete', ['confirm' => '1'])->status() === 200);
check('badge disparu', $app->badges->badgeById((int) $badgeIds[0]) === null);

// Le quota porte sur les creations, pas sur les lignes qui restent : sinon supprimer
// rembourserait le quota et on pourrait creer, supprimer, creer sans limite.
check('suppression ne rembourse pas le quota', $app->badges->usedToday((int) $admin['id']) === $usedBefore, (string) $usedBefore);

// Le compte : le mot de passe doit etre donne et correct.
$gone = $app->users->register('compte-a-detruire@qlip.test', 'un-mot-de-passe-long');
$goneUser = $gone['user'];
$goneReset = $app->users->requestReset('compte-a-detruire@qlip.test');
$app->badges->createPage((int) $goneUser['id'], ['title' => 'Page du compte a detruire', 'slug' => 'adetruire']);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $goneUser['id']], true);

check('compte sans mot de passe refuse', $send('POST', '/account/delete', ['confirm' => '1'])->status() === 401);
check('compte avec un mauvais mot de passe refuse', $send('POST', '/account/delete', ['confirm' => '1', 'password' => 'pas-le-bon-mot-de-passe'])->status() === 401);
check('compte encore la apres un refus', $app->users->findByEmail('compte-a-detruire@qlip.test') !== null);

$response = $send('POST', '/account/delete', ['confirm' => '1', 'password' => 'un-mot-de-passe-long']);

check('compte supprime', $response->status() === 200, $response->body());
check('compte disparu', $app->users->findByEmail('compte-a-detruire@qlip.test') === null);
check('page du compte disparue', $app->badges->pageBySlug('adetruire') === null);
check('jeton de verification disparu avec le compte', $app->db->count("SELECT COUNT(*) FROM verify_tokens WHERE token = :token", [':token' => $gone['token']]) === 0);
check('jeton de reinitialisation disparu avec le compte', $app->db->count("SELECT COUNT(*) FROM reset_tokens WHERE token = :token", [':token' => $goneReset['token']]) === 0);
check('session videe apres la suppression du compte', $app->session->get('user_id') === null);

// La trace survit au compte : la ligne est ecrite avec l adresse dans le detail, parce que
// user_id devient NULL (ON DELETE SET NULL).
$trace = $app->db->one("SELECT user_id, detail FROM audit_log WHERE action = 'compte_supprime' ORDER BY id DESC LIMIT 1");

check('trace de suppression du compte', $trace !== null && $trace['user_id'] === null && str_contains((string) $trace['detail'], 'compte-a-detruire@qlip.test'), (string) ($trace['detail'] ?? ''));

// 18. Second facteur avec mot de passe. La cle prouve la possession, le mot de passe a deja
// prouve l'utilisateur : la politique stockee par la credential est celle du profil
// 'presence'. Une cle U2F ne peut pas satisfaire une ceremonie reglee en 'required', donc la
// page d'administration reste fermee a une assertion passkey faite avec cette cle.
$u2f = null;

if (is_array($fixtures)) {
    foreach ($fixtures['fixtures'] as $fixture2) {
        if ($fixture2['name'] === 'fido_u2f') {
            $u2f = $fixture2;
        }
    }
}

if ($u2f === null) {
    check('fixture fido-u2f presente', false);
} else {
    $two = $app->users->register('2fa@qlip.test', 'un-mot-de-passe-long');
    $twoUser = $two['user'];

    check('compte 2fa verifie', $app->users->verifyToken($two['token']) !== null && (int) $twoUser['is_admin'] === 0);

    // Sans credential, le mot de passe suffit encore : le second facteur ne s'applique qu'aux
    // comptes qui ont une credential.
    $app->session = new Session(['token' => random_token(16)], true);

    $response = $send('POST', '/login', ['email' => '2fa@qlip.test', 'password' => 'un-mot-de-passe-long']);

    check('connexion sans credential', $response->status() === 302, (string) $response->header('Location'));

    // Le tableau de bord est la page qui propose l'enrolement d'un second facteur : c'est elle
    // qui pose le profil sur la session, donc la politique ne vient pas du client.
    $response = $send('GET', '/dashboard');

    check('enrolement propose sur le tableau de bord', str_contains($response->body(), 'data-action="register"'));
    check('profil pose par la page : presence', $app->ceremonyPolicy() === Policy::DISCOURAGED, $app->ceremonyPolicy());

    // Le challenge de la fixture doit etre celui stocke en session.
    $app->session->set('challenge', $fixtures['challenge']);

    $response = $send('POST', '/webauthn/register', [
        'credential_id' => $u2f['register']['credential_id'],
        'attestation' => $u2f['register']['attestation'],
        'client_data' => $u2f['register']['client_data'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('enrolement d une cle U2F', $response->status() === 201, $data['error'] ?? $response->body());
    check('politique stockee par la credential', ($data['credential']['policy'] ?? '') === Policy::DISCOURAGED, json_encode($data['credential'] ?? []));

    $u2fRow = $app->db->one(
        'SELECT * FROM webauthn_credentials WHERE credential_id = :credential_id',
        [':credential_id' => $u2f['register']['credential_id']]
    );

    check('uv_seen a zero pour une cle U2F', (int) ($u2fRow['uv_seen'] ?? 0) === 0, json_encode($u2fRow));

    // La politique stockee ne peut pas etre abaissee par le profil de la ceremonie : la meme
    // cle refuse une ceremonie passkey. Elle ouvre la session par mot de passe + possession,
    // elle ne peut pas ouvrir l'administration par une assertion seule.
    try {
        $app->credentials->assert(
            $u2fRow,
            $u2f['assert']['auth_data'],
            $u2f['assert']['client_data'],
            $u2f['assert']['signature'],
            $fixtures['challenge'],
            Policy::REQUIRED
        );

        check('la ceremonie passkey refuse la cle U2F', false);
    } catch (Throwable $error) {
        check('la ceremonie passkey refuse la cle U2F', true, $error->getMessage());
    }

    // Mot de passe valide, session en attente : elle ne donne aucun acces.
    $app->session = new Session(['token' => random_token(16)], true);

    $response = $send('POST', '/login', ['email' => '2fa@qlip.test', 'password' => 'un-mot-de-passe-long']);

    check('second facteur demande apres le mot de passe', $response->status() === 302 && str_contains((string) $response->header('Location'), '/login/2fa'), (string) $response->header('Location'));
    check('session en attente : aucune identite', $app->session->get('user_id') === null && (int) $app->session->get('pending_user_id', 0) === (int) $twoUser['id']);

    $response = $send('GET', '/dashboard');

    check('aucun acces avant la ceremonie', $response->status() === 302 && str_contains((string) $response->header('Location'), '/login'), (string) $response->header('Location'));

    // La page du second facteur pose le profil 'presence' sur la session : la politique de la
    // ceremonie ne vient pas du client. La cible de l assertion vient de la page, pas du script.
    $response = $send('GET', '/login/2fa');

    check('page du second facteur', $response->status() === 200 && str_contains($response->body(), 'data-action="assert"'));
    check('la page vise la ceremonie du second facteur', str_contains($response->body(), 'data-assert="http://qlip.test/login/2fa"'));
    check('profil pose par la page : presence', $app->ceremonyPolicy() === Policy::DISCOURAGED, $app->ceremonyPolicy());

    $ceremony = static function (array $parts) use ($send): Response {
        return $send('POST', '/login/2fa', $parts);
    };

    // La credential d'un autre compte ne peut pas ouvrir cette session : le controle
    // d'appartenance est fait avant la ceremonie.
    $other = $app->db->one(
        'SELECT credential_id FROM webauthn_credentials WHERE user_id = :user_id LIMIT 1',
        [':user_id' => (int) $admin['id']]
    );

    $response = $ceremony([
        'credential_id' => (string) $other['credential_id'],
        'auth_data' => $u2f['assert']['auth_data'],
        'client_data' => $u2f['assert']['client_data'],
        'signature' => $u2f['assert']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('credential d un autre compte refusee', $response->status() === 403, $data['error'] ?? '');

    // Le verrou est commun au mot de passe et au second facteur : 8 tentatives au total sur la
    // session, la premiere etant la credential d'un autre compte. Une ceremonie refusee ne doit
    // pas permettre de tester les credentials sans limite.
    for ($i = 1; $i < 8; $i++) {
        $send('POST', '/login/2fa', [
            'credential_id' => (string) $other['credential_id'],
            'auth_data' => $u2f['assert']['auth_data'],
            'client_data' => $u2f['assert']['client_data'],
            'signature' => $u2f['assert']['signature'],
        ]);
    }

    $response = $ceremony([
        'credential_id' => $u2f['register']['credential_id'],
        'auth_data' => $u2f['assert']['auth_data'],
        'client_data' => $u2f['assert']['client_data'],
        'signature' => $u2f['assert']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('verrou applique au second facteur', $response->status() === 429, $data['error'] ?? '');

    // Jeton CSRF : la ceremonie est un POST, elle l'exige comme tout POST.
    $app->session = new Session(['token' => random_token(16), 'pending_user_id' => (int) $twoUser['id']], true);

    $response = $ceremony([
        'csrf' => 'jeton different',
        'credential_id' => $u2f['register']['credential_id'],
        'auth_data' => $u2f['assert']['auth_data'],
        'client_data' => $u2f['assert']['client_data'],
        'signature' => $u2f['assert']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('jeton CSRF exige par le second facteur', $response->status() === 400, $data['error'] ?? '');

    // Sans mise en attente, la ceremonie refuse : une session authentifiee par le seul mot de
    // passe ne peut pas etre confirmee par une assertion faite en dehors du parcours.
    $app->session = new Session(['token' => random_token(16), 'user_id' => (int) $twoUser['id']], true);

    $response = $ceremony([
        'credential_id' => $u2f['register']['credential_id'],
        'auth_data' => $u2f['assert']['auth_data'],
        'client_data' => $u2f['assert']['client_data'],
        'signature' => $u2f['assert']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('ceremonie sans session en attente refusee', $response->status() === 401, $data['error'] ?? '');

    // La ceremonie acceptee : la session devient celle du compte, et l'assertion est la trace
    // que la page d'administration regarde.
    $app->session = new Session(['token' => random_token(16), 'pending_user_id' => (int) $twoUser['id']], true);
    $send('GET', '/login/2fa');
    $app->session->set('challenge', $fixtures['challenge']);

    $response = $ceremony([
        'credential_id' => $u2f['register']['credential_id'],
        'auth_data' => $u2f['assert']['auth_data'],
        'client_data' => $u2f['assert']['client_data'],
        'signature' => $u2f['assert']['signature'],
    ]);

    $data = json_decode($response->body(), true) ?: [];

    check('second facteur accepte', $response->status() === 200 && ($data['ok'] ?? false) === true, $data['error'] ?? $response->body());
    check('session confirmee par le second facteur', (int) $app->session->get('user_id', 0) === (int) $twoUser['id'] && $app->session->get('pending_user_id') === null);
    check('trace de l assertion pour la page d administration', $app->session->get('webauthn_at') !== null);
    check('la session garde la credential qui l a confirmee', (string) $app->session->get('webauthn_credential', '') === (string) $u2f['register']['credential_id'], json_encode($app->session->data()));

    $actions = [];

    foreach ($app->db->all(
        'SELECT action, COUNT(*) AS n FROM audit_log WHERE user_id = :user_id GROUP BY action ORDER BY action',
        [':user_id' => (int) $twoUser['id']]
    ) as $line) {
        $actions[(string) $line['action']] = (int) $line['n'];
    }

    check('journal d audit du second facteur', $actions === [
        'login' => 1,
        'login_2fa' => 1,
        'login_pending' => 1,
        'register' => 1,
        'verify' => 1,
        'webauthn_assert' => 1,
        'webauthn_assert_failed' => 8,
        'webauthn_register' => 1,
    ], json_encode($actions));

    // 19. Oublier la cle 2FA. Suppression definitive : jeton CSRF, confirmation explicite, mot
    // de passe du compte et controle d appartenance. Le mot de passe est demande parce que la
    // cle ne peut plus prouver la possession au moment ou on l enleve.
    $credentialId = (int) $u2fRow['id'];

    // Session confirmee par cette cle : la suppression doit emporter la confirmation.
    $app->session = new Session([
        'token' => random_token(16),
        'user_id' => (int) $twoUser['id'],
        'webauthn_at' => now(),
        'webauthn_credential' => $u2f['register']['credential_id'],
    ], true);

    $delete = static function (array $body) use ($send, $credentialId): Response {
        return $send('POST', '/credentials/' . $credentialId . '/delete', $body);
    };

    check('suppression de la cle refusee sans jeton', $delete(['csrf' => 'mauvais-jeton', 'confirm' => '1', 'password' => 'un-mot-de-passe-long'])->status() === 400);
    check('suppression de la cle refusee sans confirmation', $delete(['confirm' => '0', 'password' => 'un-mot-de-passe-long'])->status() === 400);
    check('suppression de la cle refusee sans mot de passe', $delete(['confirm' => '1'])->status() === 401);
    check('suppression de la cle refusee avec un mauvais mot de passe', $delete(['confirm' => '1', 'password' => 'pas-le-bon-mot-de-passe'])->status() === 401);
    check('cle encore la apres un refus', $app->credentials->find($credentialId) !== null);

    $app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id']], true);

    check('suppression de la cle d un autre compte', $delete(['confirm' => '1', 'password' => 'un-mot-de-passe-long'])->status() === 403);
    check('cle d un autre compte toujours la', $app->credentials->find($credentialId) !== null);

    // Le formulaire est sur la page du proprietaire, pas sur celle d un autre compte.
    $response = $send('GET', '/dashboard');

    check('pas de formulaire pour une cle d un autre compte', !str_contains($response->body(), 'credentials/' . $credentialId . '/delete'));

    $app->session = new Session([
        'token' => random_token(16),
        'user_id' => (int) $twoUser['id'],
        'webauthn_at' => now(),
        'webauthn_credential' => $u2f['register']['credential_id'],
    ], true);

    $response = $send('GET', '/dashboard');

    check('formulaire oublier la cle sous la liste', str_contains($response->body(), 'credentials/' . $credentialId . '/delete'));

    // Une autre cle du meme compte ne change pas la confirmation : elle vient de la credential
    // qui a fait l assertion. La seconde est prise dans un autre fixture, donc son ID de
    // credential est different.
    $otherFixture = $fixtures['fixtures'][0];

    $second = $app->credentials->register(
        (int) $twoUser['id'],
        $otherFixture['register']['attestation'],
        $otherFixture['register']['client_data'],
        $fixtures['challenge'],
        Policy::DISCOURAGED
    );

    $response = $send('POST', '/credentials/' . (int) $second['id'] . '/delete', ['confirm' => '1', 'password' => 'un-mot-de-passe-long']);

    check('autre cle du meme compte supprimee', $response->status() === 200, $response->body());
    check('la confirmation vient de la cle qui a fait l assertion', $app->session->get('webauthn_at') !== null);

    // Le nom de l action doit rester distinct : le quota du jour compte les creations de badge
    // dans audit_log, une suppression ne doit pas entrer dans ce comptage.
    $usedBefore = $app->badges->usedToday((int) $twoUser['id']);

    $response = $delete(['confirm' => '1', 'password' => 'un-mot-de-passe-long']);

    check('cle supprimee', $response->status() === 200, $response->body());
    check('cle disparue', $app->credentials->find($credentialId) === null);
    check('suppression de la cle ne rembourse pas le quota', $app->badges->usedToday((int) $twoUser['id']) === $usedBefore, (string) $usedBefore);

    $trace = $app->db->one(
        "SELECT user_id, action, detail FROM audit_log WHERE action = 'credential_supprimee' ORDER BY id DESC LIMIT 1"
    );

    check(
        'trace de suppression de la cle',
        $trace !== null
            && (int) $trace['user_id'] === (int) $twoUser['id']
            && str_contains((string) $trace['detail'], (string) $u2f['register']['credential_id']),
        json_encode($trace)
    );

    // La session n a plus de preuve de possession derriere elle : la cle qui la confirmait est
    // partie, donc la page d administration redemande une assertion.
    check('confirmation de la session emportee par la cle', $app->session->get('webauthn_at') === null && $app->session->get('webauthn_credential') === null, json_encode($app->session->data()));

    // L administrateur peut supprimer ce qu il peut lire : la cle d un autre compte. La cle
    // est enregistree par le service, avec les memes octets de la fixture.
    $foreign = $app->credentials->register(
        (int) $otherUser['id'],
        $u2f['register']['attestation'],
        $u2f['register']['client_data'],
        $fixtures['challenge'],
        Policy::DISCOURAGED
    );

    $app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

    check('administrateur supprime la cle d un autre compte', $send('POST', '/credentials/' . $foreign['id'] . '/delete', ['confirm' => '1', 'password' => 'mot-de-passe-long'])->status() === 200);
    check('cle d un autre compte disparue', $app->credentials->find((int) $foreign['id']) === null);

    // Le compte est de nouveau un compte au seul mot de passe : il n est plus mis en attente.
    $app->session = new Session(['token' => random_token(16)], true);

    $response = $send('POST', '/login', ['email' => '2fa@qlip.test', 'password' => 'un-mot-de-passe-long']);

    check('connexion au seul mot de passe apres la suppression', $response->status() === 302 && !(str_contains((string) $response->header('Location'), '2fa')), (string) $response->header('Location'));
    check('aucune action de suppression dans le comptage des badges', $app->badges->usedToday((int) $twoUser['id']) === 0);
}

// 19. Journal : pagination et effacement de ce qui a plus de 2 mois.
//
// Le nombre de pages vient d un COUNT, pas des lignes lues : sinon la derniere page, quand
// elle n est pas pleine, ne serait jamais proposee. Le numero demande est borne : un numero
// hors plage ne part pas dans la requete, parce qu un offset negatif est une reponse.
$pager = new Pager('journal_page', 110, 3);

check('pagination : le nombre de pages vient du COUNT', $pager->pages() === 3, (string) $pager->pages());
check('pagination : offset de la troisieme page', $pager->offset() === 100, (string) $pager->offset());
check('pagination : numero hors plage ramene a la derniere page', (new Pager('journal_page', 110, 99))->page() === 3);
check('pagination : numero invalide ramene a la premiere page', (new Pager('journal_page', 110, -2))->page() === 1);
check('pagination : une liste vide tient sur une page', (new Pager('journal_page', 0, 4))->page() === 1 && (new Pager('journal_page', 0, 4))->pages() === 1);

// On ecrit 100 lignes avec des marqueurs : les checks ne dependent pas des lignes deja
// presentes, seulement des marqueurs. La premiere page donne les 50 plus recentes.
foreach (range(1, 100) as $index) {
    $app->journal->audit((int) $admin['id'], 'test_journal', 'ligne test ' . $index);
}

$total = $app->db->count('SELECT COUNT(*) FROM audit_log');
$pages = (int) ceil($total / Pager::PER_PAGE);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id'], 'webauthn_at' => now()], true);

$journalPage = static function (int $number) use ($app, $router): Response {
    $request = new Request('GET', '/admin', ['journal_page' => $number]);

    return $router->dispatch($request);
};

$body = $journalPage(2)->body();

check('journal : la page donne son numero', str_contains($body, 'lignes, page 2 sur ' . $pages), 'page 2 sur ' . $pages);
check('journal : la page 2 donne les lignes de la page 2', str_contains($body, 'ligne test 50') && !str_contains($body, 'ligne test 51'));
check('journal : lien vers la page suivante', str_contains($body, 'journal_page=3') || $pages === 2, (string) $pages);

$body = $journalPage(999)->body();

check('journal : numero hors plage ramene a la derniere page', str_contains($body, '<span class="current">' . $pages . '</span>'), 'page ' . $pages);
check('journal : une page hors plage ne rend pas les lignes de la premiere', !str_contains($body, 'ligne test 100'));

// Effacement : POST seulement, jeton CSRF, confirmation explicite, mot de passe du compte, et
// la session confirmee par une assertion comme le reste de la page d administration.
$purge = static function (array $body) use ($send): Response {
    return $send('POST', '/admin/journal/purge', $body);
};

check('GET sur la route effacer le journal', $router->dispatch(new Request('GET', '/admin/journal/purge'))->status() === 405);
check('effacement refuse sans jeton', $purge(['csrf' => 'mauvais-jeton', 'confirm' => '1', 'password' => 'mot-de-passe-long'])->status() === 400);
check('effacement refuse sans confirmation', $purge(['confirm' => '0', 'password' => 'mot-de-passe-long'])->status() === 400);
check('effacement refuse sans mot de passe', $purge(['confirm' => '1'])->status() === 401);
check('effacement refuse avec un mauvais mot de passe', $purge(['confirm' => '1', 'password' => 'pas-le-bon-mot-de-passe'])->status() === 401);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id'], 'webauthn_at' => now()], true);

check('effacement reserve a l administration', $purge(['confirm' => '1', 'password' => 'un-mot-de-passe-long'])->status() === 403);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

$response = $purge(['confirm' => '1', 'password' => 'mot-de-passe-long']);

check('effacement refuse quand la session n est pas confirmee par une assertion',
    str_contains((string) $response->header('Location'), '/admin/yubikey'),
    (string) $response->header('Location'));

// Ce qui est efface est seulement ce qui est plus vieux que la retention : une ligne de 65 jours
// part, une ligne de 40 jours reste. Le nom de l action est distinct, et la trace est ecrite
// apres le DELETE : la ligne qui consigne l effacement ne peut pas s effacer elle-meme.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id'], 'webauthn_at' => now()], true);

$insert = static function (int $days, string $detail) use ($app, $admin): void {
    $app->db->exec(
        "INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, 'test_journal', :detail, :created_at)",
        [
            ':user_id' => (int) $admin['id'],
            ':detail' => $detail,
            ':created_at' => date('Y-m-d H:i:s', time() - $days * 86400),
        ]
    );
};

$insert(65, 'ligne de 65 jours');
$insert(40, 'ligne de 40 jours');

$quota = $app->badges->usedToday((int) $admin['id']);

$response = $purge(['confirm' => '1', 'password' => 'mot-de-passe-long']);
$data = json_decode($response->body(), true) ?: [];

check('effacement accepte pour le compte administrateur', $response->status() === 200, $response->body());
check('effacement : seulement ce qui a plus de 2 mois', ($data['rows'] ?? -1) === 1, json_encode($data));
check('ligne de 65 jours effacee', $app->db->count("SELECT COUNT(*) FROM audit_log WHERE detail = 'ligne de 65 jours'") === 0);
check('ligne de 40 jours garde e', $app->db->count("SELECT COUNT(*) FROM audit_log WHERE detail = 'ligne de 40 jours'") === 1);
$kept = $app->db->count('SELECT COUNT(*) FROM audit_log');
$keptPages = (int) ceil($kept / Pager::PER_PAGE);

check('le numero de page est ramene a ce qui reste', str_contains($journalPage(999)->body(), 'page ' . $keptPages . ' sur ' . $keptPages), (string) $keptPages);

// Le quota porte sur les creations du jour, pas sur les lignes qui restent : effacer un journal
// de 2 mois ne doit pas rembourser le quota.
check('effacement ne rembourse pas le quota', $app->badges->usedToday((int) $admin['id']) === $quota, (string) $quota);

$trace = $app->db->one("SELECT user_id, action, detail, created_at FROM audit_log WHERE action = 'journal_efface' ORDER BY id DESC LIMIT 1");

check(
    'trace de l effacement du journal',
    $trace !== null
        && (int) $trace['user_id'] === (int) $admin['id']
        && str_contains((string) $trace['detail'], 'effacement du journal')
        && str_ends_with((string) $trace['detail'], ': 1'),
    json_encode($trace)
);
check('la trace de l effacement n est pas effacee par l effacement', $trace !== null && strtotime((string) $trace['created_at']) > time() - 120, (string) ($trace['created_at'] ?? ''));

// 20. Suppression definitive par l administration : compte et cle 2FA d un autre compte. La garde
// est la meme que pour l effacement du journal : POST seulement, jeton CSRF, confirmation
// explicite, mot de passe, session confirmee par une assertion. Le mot de passe est celui du
// compte qui agit, pas celui du compte efface : on ne peut pas le connaitre, et il prouve que la
// session n est pas laissee ouverte.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id'], 'webauthn_at' => now()], true);

$victim = $app->users->register('victim@qlip.test', 'un-mot-de-passe-long')['user'];
$victimPage = $app->badges->createPage((int) $victim['id'], ['title' => 'Page du compte efface', 'slug' => 'efface']);

// La cle est une ligne en base : ce que le controle d administration mesure est l appartenance,
// pas la ceremony, et la suite ne peut pas savoir si une cle a fait la verification utilisateur.
$key = static function (int $userId, string $label) use ($app): int {
    $app->db->exec(
        'INSERT INTO webauthn_credentials (user_id, credential_id, cose, policy, uv_seen, created_at) VALUES (:user_id, :credential_id, :cose, :policy, 0, :created_at)',
        [
            ':user_id' => $userId,
            ':credential_id' => $label,
            ':cose' => '-',
            ':policy' => Policy::DISCOURAGED,
            ':created_at' => now(),
        ]
    );

    return (int) $app->db->one('SELECT id FROM webauthn_credentials WHERE credential_id = :credential_id', [':credential_id' => $label])['id'];
};

$victimKey = $key((int) $victim['id'], 'cle du compte efface');

$body = $send('GET', '/admin')->body();

check('administration : formulaire pour effacer un compte', str_contains($body, 'admin/users/' . (int) $victim['id'] . '/delete'), '');
check('administration : formulaire pour effacer la cle 2FA d un compte', str_contains($body, 'admin/credentials/' . $victimKey . '/delete'), '');

// POST seulement : un GET ne detruit pas.
check('GET sur la route effacer un compte', $router->dispatch(new Request('GET', '/admin/users/' . (int) $victim['id'] . '/delete'))->status() === 405);
check('GET sur la route effacer une cle', $router->dispatch(new Request('GET', '/admin/credentials/' . $victimKey . '/delete'))->status() === 405);

$deleteUser = static function (array $body) use ($send, $victim): Response {
    return $send('POST', '/admin/users/' . (int) $victim['id'] . '/delete', $body);
};

check('effacer un compte refuse sans jeton', $deleteUser(['csrf' => 'mauvais-jeton', 'confirm' => '1', 'password' => 'mot-de-passe-long'])->status() === 400);
check('effacer un compte refuse sans confirmation', $deleteUser(['confirm' => '0', 'password' => 'mot-de-passe-long'])->status() === 400);
check('effacer un compte refuse sans mot de passe', $deleteUser(['confirm' => '1'])->status() === 401);
check('effacer un compte refuse avec un mauvais mot de passe', $deleteUser(['confirm' => '1', 'password' => 'pas-le-bon-mot-de-passe'])->status() === 401);
check('compte encore la apres un refus', $app->users->find((int) $victim['id']) !== null);

// Un compte dont la session est confirmee par une assertion mais qui n est pas administrateur
// ne peut rien effacer avec son propre mot de passe.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $otherUser['id'], 'webauthn_at' => now()], true);

check('effacer un compte reserve a l administration', $deleteUser(['confirm' => '1', 'password' => 'un-mot-de-passe-long'])->status() === 403);

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id']], true);

$response = $deleteUser(['confirm' => '1', 'password' => 'mot-de-passe-long']);

check('effacer un compte refuse quand la session n est pas confirmee par une assertion',
    str_contains((string) $response->header('Location'), '/admin/yubikey'),
    (string) $response->header('Location'));

$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id'], 'webauthn_at' => now()], true);

// La cle effacee n est pas celle qui a confirme la session : la confirmation reste.
$response = $send('POST', '/admin/credentials/' . $victimKey . '/delete', ['confirm' => '1', 'password' => 'mot-de-passe-long']);

check('administrateur efface la cle 2FA d un compte', $response->status() === 200, $response->body());
check('cle 2FA disparue', $app->credentials->find($victimKey) === null);
check('la cle effacee n emporte pas la confirmation de la session', $app->session->get('webauthn_at') !== null);

$trace = $app->db->one("SELECT user_id, action, detail FROM audit_log WHERE action = 'credential_supprimee' ORDER BY id DESC LIMIT 1");

check('trace de la cle 2FA : elle pointe sur le proprietaire, pas sur l administrateur',
    $trace !== null && (int) $trace['user_id'] === (int) $victim['id'],
    json_encode($trace));

// Le compte emporte ses pages, ses badges, ses credentials et ses jetons. Une seconde cle est
// enregistree pour mesurer la cascade.
$victimKey2 = $key((int) $victim['id'], 'seconde cle du compte efface');

$response = $deleteUser(['confirm' => '1', 'password' => 'mot-de-passe-long']);
$data = json_decode($response->body(), true) ?: [];

check('administrateur efface un compte', $response->status() === 200 && ($data['deleted'] ?? false) === true, $response->body());
check('compte disparu', $app->users->find((int) $victim['id']) === null);
check('page du compte emportee par la cascade', $app->db->count('SELECT COUNT(*) FROM pages WHERE id = :id', [':id' => (int) $victimPage['id']]) === 0);
check('cle 2FA emportee par la cascade', $app->credentials->find($victimKey2) === null);

$trace = $app->db->one("SELECT user_id, action, detail FROM audit_log WHERE action = 'compte_supprime' ORDER BY id DESC LIMIT 1");

check('trace de l effacement du compte : l adresse dans le detail, user_id NULL',
    $trace !== null && $trace['user_id'] === null && str_contains((string) $trace['detail'], 'victim@qlip.test'),
    json_encode($trace));

// Le formulaire d un compte efface ne peut plus apparaitre sur la page.
check('formulaire disparu avec le compte', !str_contains($send('GET', '/admin')->body(), 'admin/users/' . (int) $victim['id'] . '/delete'));

// La liste des cle 2FA est paginee comme les autres, par page de 50, avec son propre parametre.
foreach (range(1, 60) as $index) {
    $app->db->exec(
        "INSERT INTO webauthn_credentials (user_id, credential_id, cose, policy, uv_seen, created_at) VALUES (:user_id, :credential_id, 'cle test', :policy, 0, :created_at)",
        [
            ':user_id' => (int) $admin['id'],
            ':credential_id' => 'cle test ' . $index,
            ':policy' => Policy::REQUIRED,
            ':created_at' => now(),
        ]
    );
}

$keyPages = (int) ceil($app->db->count('SELECT COUNT(*) FROM webauthn_credentials') / Pager::PER_PAGE);
$keyRequest = new Request('GET', '/admin', ['credentials_page' => $keyPages]);
$keyRequest->headers['accept'] = 'application/json';
$body = $router->dispatch($keyRequest)->body();

check('liste des cle 2FA : plus d une page', $keyPages > 1, (string) $keyPages);
check('liste des cle 2FA : le numero vient du COUNT, la derniere page est proposee',
    str_contains($body, 'page ' . $keyPages . ' sur ' . $keyPages), (string) $keyPages);
check('liste des cle 2FA : la page demande est la page rendue', str_contains($body, 'cle test 60'), '');
check('liste des cle 2FA : son propre parametre dans l URL', str_contains($body, 'credentials_page='), '');

// L administration efface son propre compte, et la session ne peut plus y pointer.
$adminKey = $key((int) $admin['id'], 'cle de l admin');

$app->session = new Session([
    'token' => random_token(16),
    'user_id' => (int) $admin['id'],
    'webauthn_at' => now(),
    'webauthn_credential' => 'cle de l admin',
], true);

$response = $send('POST', '/admin/credentials/' . $adminKey . '/delete', ['confirm' => '1', 'password' => 'mot-de-passe-long']);

check('administration : la cle qui confirmait la session emporte la confirmation',
    $response->status() === 200 && $app->session->get('webauthn_at') === null,
    (string) $response->status());

// La session doit etre de nouveau confirmee par une assertion pour effacer le compte.
$app->session = new Session(['token' => random_token(16), 'user_id' => (int) $admin['id'], 'webauthn_at' => now()], true);

$response = $send('POST', '/admin/users/' . (int) $admin['id'] . '/delete', ['confirm' => '1', 'password' => 'mot-de-passe-long']);

check('administration efface son propre compte', $response->status() === 200, $response->body());
check('la session est videe quand le compte qui l a ouverte est efface',
    $app->session->get('user_id') === null && $app->session->get('webauthn_at') === null,
    json_encode($app->session->data()));

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
