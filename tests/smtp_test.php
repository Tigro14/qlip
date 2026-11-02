<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\App\Config;
use Qlip\Services\Mailer;
use Qlip\Support\Smtp;

/**
 * Client SMTP ecrit a la main, verifie contre un serveur mock en Python
 * (tests/smtp_mock.py, lance en tache de fond par tests/run.sh). Le mock controle ce qu il
 * recoit ; ce script controle ce que le client envoie et comment il reagite a une reponse
 * inattendue. Les deux doivent etre d accord.
 *
 * Les cinq connexions sont faites dans l ordre que le mock attend.
 */

$out = getenv('QLIP_OUT') ?: '/tmp/opencode/badge';
$host = '127.0.0.1';
$port = (int) (getenv('QLIP_SMTP_MOCK_PORT') ?: 2525);
$cert = $out . '/smtp_mock_cert.pem';
$reference = $out . '/smtp_expected.txt';

$user = 'app@qlip.test';
$pass = 'mot-de-passe';
$to = 'test@qlip.test';

if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

// On repart d un etat propre : un fichier stale ferait passer ou echouer un check pour la
// mauvaise raison.
foreach (glob($out . '/smtp_expected.txt') ?: [] as $stale) {
    unlink($stale);
}

$var = $out . '/smtp_var';

if (!is_dir($var . '/log')) {
    mkdir($var . '/log', 0775, true);
}

if (!is_dir($var . '/outbox')) {
    mkdir($var . '/outbox', 0775, true);
}

foreach (glob($var . '/log/*') ?: [] as $stale) {
    unlink($stale);
}

foreach (glob($var . '/outbox/*') ?: [] as $stale) {
    unlink($stale);
}

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo "  ", $ok ? 'OK' : 'KO', '   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

function wait_for(string $file): bool
{
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if (is_file($file)) {
            return true;
        }

        usleep(250000);
    }

    return false;
}

function without_date(string $text): string
{
    return implode("\r\n", array_filter(explode("\r\n", $text), fn (string $line): bool => !str_starts_with($line, 'Date:')));
}

function seen(array $transcript, string $needle): bool
{
    return in_array($needle, $transcript, true);
}

if (!wait_for($out . '/smtp_ready')) {
    check('serveur mock joignable', false, 'smtp_ready absent');

    echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;

    exit(1);
}

$message = "To: {$to}\r\nFrom: {$user}\r\nSubject: Verifie ton email\r\nDate: " . date('r')
    . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nLigne normale\r\n"
    . ".Point qui doit etre double par le client\r\nFin";

$config = [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'pass' => $pass,
    'from' => $user,
    'hello' => 'php-cli',
];

// 1. Sans liste de confiance, un certificat auto signe est refuse avant toute commande :
// c'est la seule facon d'etre sur que la verification du pair est active.
$result = (new Smtp($config))->send($to, $message);

check('TLS refuse sans liste de confiance', !$result['ok'] && $result['transcript'] === [], $result['error']);

// 2. Envoi complet, avec le certificat du mock dans la liste.
file_put_contents($reference, $message);

$result = (new Smtp($config + ['cafile' => $cert]))->send($to, $message);

check('envoi complet', $result['ok'], $result['error']);
check('transcript complet', $result['ok'] && count($result['transcript']) === 28, (string) count($result['transcript']));
check('point en debut de ligne double', seen($result['transcript'], 'B: ..Point qui doit etre double par le client'), implode(' | ', $result['transcript']));
check('mot de passe absent du transcript', !seen($result['transcript'], 'C: ' . base64_encode($pass)) && seen($result['transcript'], 'C: > ****'), implode(' | ', $result['transcript']));
check('fin de session propre', seen($result['transcript'], 'C: QUIT') && seen($result['transcript'], 'S: 221 Bye'), '');

// 3. Mot de passe errone : le client doit s'arreter la, sans envoyer de message.
$result = (new Smtp(array_merge($config, ['cafile' => $cert, 'pass' => 'mauvais'])))->send($to, $message);

check('echec d authentification signale', !$result['ok'] && str_contains($result['error'], '535'), $result['error']);
check('pas de message apres un echec d authentification', !seen($result['transcript'], 'C: DATA'), implode(' | ', $result['transcript']));

// 4. Serveur qui n annonce pas AUTH : le client refuse avant de s'authentifier.
$result = (new Smtp($config + ['cafile' => $cert]))->send($to, $message);

check('AUTH refuse quand il n est pas annonce', !$result['ok'] && str_contains($result['error'], 'AUTH'), $result['error']);
check('pas d authentification lancee', !seen($result['transcript'], 'C: AUTH LOGIN'), implode(' | ', $result['transcript']));

// 5. Le service Mailer, transport smtp : pas de copie dans l outbox, le journal suffit.
$configured = new Config(['mail_transport' => 'smtp', 'var_path' => $var, 'smtp_host' => $host, 'smtp_port' => $port, 'smtp_user' => $user, 'smtp_pass' => $pass, 'smtp_from' => $user, 'smtp_cafile' => $cert]);
$service = new Mailer($configured->mailTransport, $configured->varPath, $configured->smtp);

$result = $service->send($to, 'Verifie ton email', "Ligne normale\n.Point qui doit etre double par le client\nFin");

check('envoi par le service', $result['ok'], $result['error']);
check('message construit en CRLF', without_date($result['message']) === without_date($message), $result['message']);
check('pas de copie dans l outbox', $result['path'] === $var . '/log/mail.log' && glob($var . '/outbox/*.eml') === [], $result['path']);

$log = (string) file_get_contents($var . '/log/mail.log');

check('journal avec le resultat', str_contains($log, 'test@qlip.test') && str_contains($log, 'envoye'), trim($log));

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;

exit($failures === 0 ? 0 : 1);
