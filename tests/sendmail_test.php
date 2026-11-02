<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\App\Config;
use Qlip\Services\Mailer;
use Qlip\Support\Sendmail;

/**
 * Transport local : remise au MTA de la machine, verifiee contre un faux sendmail ecrit ici
 * dans QLIP_OUT. Le faux MTA enregistre les arguments qu il recoit (un par ligne) et ce qu il
 * lit sur stdin : ce que le check peut verifier est la forme de la remise. Les arguments
 * doivent etre une liste, jamais une chaine de commande, et le message doit arriver en CRLF
 * tel quel.
 */

$out = getenv('QLIP_OUT') ?: '/tmp/opencode/badge';

if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

$fake = $out . '/fake_sendmail.sh';
$args_file = $out . '/sendmail_args.txt';
$stdin_file = $out . '/sendmail_stdin.txt';

// On repart d un etat propre : un fichier stale ferait passer ou echouer un check pour la
// mauvaise raison, parce que le faux MTA n est lance que quand l app accepte l adresse.
foreach (glob($out . '/sendmail_*.txt') ?: [] as $stale) {
    unlink($stale);
}

$fake_script = <<<'SH'
#!/bin/sh
# Faux MTA, pour les tests seulement : enregistre les arguments recus, recopie l entree, puis
# renvoie le code demande. Il ne cherche pas a envoyer quoi que ce soit.
printf '%s\n' "$@" > "$QLIP_SENDMAIL_ARGS"
cat > "$QLIP_SENDMAIL_STDIN"

if [ -n "$QLIP_SENDMAIL_REPLY" ]; then
    printf '%s\n' "$QLIP_SENDMAIL_REPLY" >&2
fi

exit "${QLIP_SENDMAIL_CODE:-0}"
SH;

file_put_contents($fake, $fake_script);
chmod($fake, 0755);

putenv('QLIP_SENDMAIL_ARGS=' . $args_file);
putenv('QLIP_SENDMAIL_STDIN=' . $stdin_file);

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo "  ", $ok ? 'OK' : 'KO', '   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

function recorded_args(): array
{
    global $args_file;

    if (!is_file($args_file)) {
        return [];
    }

    return array_values(array_filter(
        explode("\n", (string) file_get_contents($args_file)),
        fn (string $line): bool => $line !== ''
    ));
}

function recorded_stdin(): string
{
    global $stdin_file;

    return is_file($stdin_file) ? (string) file_get_contents($stdin_file) : '';
}

function reset_recorder(): void
{
    global $args_file, $stdin_file;

    foreach ([$args_file, $stdin_file] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$from = 'app@qlip.test';
$to = 'test@qlip.test';

// Message tel qu il part sur la ligne, avec un point seul sur une ligne : le -i est la pour
// que le MTA ne le lise pas comme la fin de l entree.
$message = "To: {$to}\r\nFrom: {$from}\r\nSubject: Verifie ton email\r\nDate: " . date('r')
    . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nLigne normale\r\n.\r\nFin";

// 1. MTA absent : rien n est lance, et l app ne cree pas le MTA elle-meme.
$result = (new Sendmail(['path' => $out . '/no_sendmail_here', 'from' => $from]))->send($to, $message);

check('MTA absent refuse', !$result['ok'] && str_contains($result['error'], 'absent'), $result['error']);
check('rien lance pour un MTA absent', recorded_args() === [], implode(' ', recorded_args()));

// 2. Adresses refusees avant la remise : une adresse n est pas un argument, et un retour a la
// ligne dans une adresse est une injection dans la commande.
$result = (new Sendmail(['path' => $fake, 'from' => $from]))->send("test@qlip.test\n-t", $message);

check('adresse de destination avec un retour a la ligne refusee', !$result['ok'] && str_contains($result['error'], 'destination'), $result['error']);
check('pas d argument pour une adresse refusee', recorded_args() === [], implode(' ', recorded_args()));

// Une adresse avec un nom d affichage n est pas une adresse d enveloppe : le -f veut l adresse
// seule.
$result = (new Sendmail(['path' => $fake, 'from' => 'Qlip <noreply@qlip.test>']))->send($to, $message);

check('adresse d envoi avec un nom d affichage refusee', !$result['ok'] && str_contains($result['error'], 'envoi'), $result['error']);
check('pas d argument pour une adresse d envoi refusee', recorded_args() === [], implode(' ', recorded_args()));

// 3. Remise correcte : arguments en liste, message en CRLF, point seul conserve.
reset_recorder();

$result = (new Sendmail(['path' => $fake, 'from' => $from]))->send($to, $message);

check('remise au MTA acceptee', $result['ok'], $result['error']);
check('arguments en liste, jamais en chaine de commande', recorded_args() === ['-t', '-i', '-f', $from], implode(' ', recorded_args()));
check('message recu en CRLF tel quel', recorded_stdin() === $message, recorded_stdin());
check('point seul sur une ligne conserve', str_contains(recorded_stdin(), "\r\n.\r\n"), trim(recorded_stdin()));

// 4. Le code du MTA est le resultat : un envoi accepte n est pas un envoi parti.
putenv('QLIP_SENDMAIL_CODE=1');
putenv('QLIP_SENDMAIL_REPLY=serveur local refuse le domaine');

reset_recorder();

$result = (new Sendmail(['path' => $fake, 'from' => $from]))->send($to, $message);

check('code du MTA signale', !$result['ok'] && str_contains($result['error'], 'code 1'), $result['error']);
check('message du MTA rendu', str_contains($result['error'], 'refuse le domaine'), $result['error']);

putenv('QLIP_SENDMAIL_CODE=0');
putenv('QLIP_SENDMAIL_REPLY=');

// 5. Le service Mailer, transport local : pas de copie dans l outbox, le journal suffit.
$var = $out . '/sendmail_var';

foreach ([$var . '/log', $var . '/outbox'] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

foreach (glob($var . '/log/*') ?: [] as $stale) {
    unlink($stale);
}

foreach (glob($var . '/outbox/*') ?: [] as $stale) {
    unlink($stale);
}

$configured = new Config([
    'mail_transport' => 'local',
    'var_path' => $var,
    'sendmail_path' => $fake,
    'sendmail_from' => $from,
]);

check('transport local lu par la config', $configured->mailTransport === 'local' && $configured->sendmail['path'] === $fake, (string) $configured->sendmail['path']);

$service = new Mailer($configured->mailTransport, $configured->varPath, $configured->smtp, $configured->sendmail);
$result = $service->send($to, 'Verifie ton email', "Ligne normale\n.Point qui doit rester une ligne du corps\nFin");

check('envoi par le service', $result['ok'], $result['error']);
check('message construit en CRLF', str_contains($result['message'], "Ligne normale\r\n.Point qui doit rester une ligne du corps\r\nFin"), $result['message']);
check('pas de copie dans l outbox en local', $result['path'] === $var . '/log/mail.log' && glob($var . '/outbox/*.eml') === [], $result['path']);

$log = (string) file_get_contents($var . '/log/mail.log');

check('journal avec le resultat', str_contains($log, 'test@qlip.test') && str_contains($log, 'confie au MTA local'), trim($log));

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;

exit($failures === 0 ? 0 : 1);
