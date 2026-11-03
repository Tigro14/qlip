<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Webauthn\Cbor;

/**
 * Sonde CTAP2 : lit authenticatorGetInfo d'une cle FIDO2 sur /dev/hidraw.
 *
 * Elle repond a ce que la suite ne peut pas verifier, parce que la suite n'a pas de vrai
 * authenticateur : la cle est-elle vue comme un authenticateur CTAP (et pas seulement comme
 * un lecteur de cartes), et peut-elle faire la verification utilisateur demandee par
 * l'app (userVerification 'required').
 *
 * La cle doit etre branchee sur la machine qui execute le script. Le device est en acces root
 * (crw-------), ou ouvert par une regle udev seulement a des utilisateurs systeme (sssd,
 * gdm-greeter pour la verification PAM) : un utilisateur normal doit passer par root. La seule
 * commande envoyee est une lecture, authenticatorGetInfo, rien n'est ecrit sur la cle. Le
 * script n'est pas lance par tests/run.sh, il demande du materiel.
 *
 *   sudo php tests/ctap_probe.php
 *   sudo php tests/ctap_probe.php /dev/hidraw5
 *   sudo podman run --rm -v "$PWD:/app:ro" -w /app --device /dev/hidraw5 docker.io/library/php:8.5-cli php tests/ctap_probe.php /dev/hidraw5
 *
 * Tramage HID : l identifiant de rapport n est pas une convention (0x20/0x21), il vient du
 * descripteur. Quand le device n utilise pas de reports numerotes, hidraw ne met rien au debut
 * des octets lus, et il faut un zero au debut de ce qu on ecrit. Le descripteur est donc lu, et
 * la trame recue est imprimee en octets bruts : sans elle, une reponse sans echo du nonce ne
 * permet pas de distinguer une cle CTAP1 d une erreur de tramage.
 */

const REPORT_SIZE = 64;

/** Le bit haut du byte de command distingue une trame d initialisation d une continuation. */
const TYPE_INIT = 0x80;

const CMD_INIT = 0x06;
const CMD_CBOR = 0x10;
const CMD_ERROR = 0x3F;

/** Premier byte du payload d un CTAPHID_CBOR : authenticatorGetInfo. */
const CTAP_GET_INFO = 0x02;

const BROADCAST = "\xff\xff\xff\xff";

/** Methodes de verification utilisateur (key 9 du GetInfo). */
const UV_METHODS = [
    1 => 'presence (toucher la cle)',
    2 => 'biometrie interne',
    3 => 'biometrie externe',
    4 => 'deblocage par motif',
    5 => 'deblocage par motif externe',
    6 => 'connaissance interne (PIN)',
    7 => 'connaissance externe (PIN)',
];

/** Codes d erreur CTAPHID (le premier octet du payload d une reponse CTAPHID_ERROR). */
const CTAPHID_ERRORS = [
    0x01 => 'commande invalide',
    0x02 => 'payload invalide',
    0x03 => 'taille annoncee invalide (BCNT)',
    0x04 => 'numero de sequence invalide',
    0x05 => 'timeout',
    0x06 => 'canal occupe',
    0x0A => 'verrou demande',
    0x0B => 'canal invalide',
    0x7F => 'autre',
];

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo $ok ? '  OK   ' : '  KO   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

function hex(string $value): string
{
    return trim(chunk_split(bin2hex($value), 2, ' '));
}

function byte(int $value): string
{
    return '0x' . str_pad(dechex($value), 2, '0', STR_PAD_LEFT);
}

/**
 * Item Report ID du descripteur de rapport, ou null quand le device n utilise pas de reports
 * numerotes. Les items sont parcourus, pas cherches dans l hexadecimal : un octet 85 peut etre
 * la donnee d un autre item. Item court : balise dans le nibble haut, type dans les bits 2-3,
 * taille dans les bits 0-1 (la valeur 3 vaut 4 octets). L item Report ID est global, balise 8 ;
 * la meme balise en type Main est l item Input, donc le type compte (85 est un Report ID, 81 est
 * un Input). Item long : prefixe FE, puis la taille reelle et la balise reelle ; il ne porte pas
 * de Report ID.
 */
function report_id(string $descriptor): ?int
{
    $offset = 0;

    while ($offset < strlen($descriptor)) {
        $header = ord($descriptor[$offset++]);
        $tag = $header >> 4;
        $type = ($header >> 2) & 3;
        $size = $header & 3;

        if ($size === 3) {
            $size = 4;
        }

        if ($tag === 15 && $type === 3) {
            if ($offset >= strlen($descriptor)) {
                break;
            }

            $offset += ord($descriptor[$offset]);

            continue;
        }

        if ($size === 0 || $offset + $size > strlen($descriptor)) {
            break;
        }

        if ($type === 1 && $tag === 8) {
            return (int) hexdec(bin2hex(substr($descriptor, $offset, $size)));
        }

        $offset += $size;
    }

    return null;
}

/**
 * Interface HID qui porte la page d'usage FIDO (0xF1D0). Les autres interfaces du meme device
 * sont le clavier (OTP) ou le lecteur de cartes (PIV, OpenPGP) : une cle vue seulement comme
 * carte n est pas un authenticateur WebAuthn. La page d'usage se lit dans le descripteur de
 * rapport, pas dans MODALIAS : MODALIAS porte le type de bus, pas la page d usage. L item Usage
 * Page 2 octets est 0x06 suivi de la valeur en petit boutien (d0 f1).
 */
function fido_interface(string $path): ?string
{
    $descriptor = file_get_contents($path . '/device/report_descriptor');

    if ($descriptor !== false && str_contains(bin2hex($descriptor), 'd0f1')) {
        return basename($path);
    }

    return null;
}

function send($handle, string $cid, int $cmd, string $payload, int $report): void
{
    $size = strlen($payload);

    // Le premier octet est l identifiant de rapport : 0 quand le device n en a pas.
    $frame = chr($report) . $cid . chr($cmd | TYPE_INIT)
        . chr(($size >> 16) & 255) . chr(($size >> 8) & 255) . chr($size & 255)
        . $payload;

    fwrite($handle, str_pad($frame, REPORT_SIZE, "\0"));
}

function receive($handle, int $seconds): ?string
{
    $read = [$handle];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, $seconds) < 1) {
        echo '  trame recue : rien en ', $seconds, " s", PHP_EOL;

        return null;
    }

    $frame = fread($handle, REPORT_SIZE);

    if ($frame === false || $frame === '') {
        echo '  trame recue : rien', PHP_EOL;

        return null;
    }

    echo '  trame recue : ', hex($frame), PHP_EOL;

    return $frame;
}

/**
 * Trame d initialisation : identifiant de rapport (quand il existe), canal, balise, taille,
 * payload. La balise recue porte le drapeau d initialisation (0x80) : une erreur sur la trame
 * d initialisation est 0xbf, pas 0x3f. Le code est donc compare sans le drapeau, et le drapeau
 * est garde separement.
 *
 * @return array{cid: string, init: bool, cmd: int, size: int, payload: string}|null
 */
function parse(string $frame, int $skip): ?array
{
    if (strlen($frame) < $skip + 8) {
        return null;
    }

    $header = ord($frame[$skip + 4]);
    $size = (ord($frame[$skip + 5]) << 16) + (ord($frame[$skip + 6]) << 8) + ord($frame[$skip + 7]);

    return [
        'cid' => substr($frame, $skip, 4),
        'init' => ($header & TYPE_INIT) === TYPE_INIT,
        'cmd' => $header & 0x7F,
        'size' => $size,
        'payload' => substr($frame, $skip + 8, $size),
    ];
}

/** Lit une reponse complete : la taille annoncee peut depasser une trame. */
function read_answer($handle, int $seconds, int $skip): ?array
{
    $frame = receive($handle, $seconds);
    $answer = parse((string) $frame, $skip);

    if ($answer === null) {
        return null;
    }

    // Une continuation a la meme forme, avec le numero de sequence a la place de la taille.
    // Le numero n est pas verifie : les continuations arrivent dans l ordre.
    while (strlen($answer['payload']) < $answer['size']) {
        $next = receive($handle, $seconds);

        if ($next === null || strlen($next) < $skip + 5) {
            break;
        }

        $answer['payload'] .= substr($next, $skip + 5);
    }

    return $answer;
}

$device = $argv[1] ?? null;

if ($device === null) {
    $found = null;

    foreach (glob('/sys/class/hidraw/hidraw*') ?: [] as $path) {
        $found = fido_interface($path);

        if ($found !== null) {
            break;
        }
    }

    if ($found === null) {
        check('cle vue comme authenticateur FIDO (page d usage F1D0)', false, 'rien sur /sys/class/hidraw');
        exit(1);
    }

    check('cle vue comme authenticateur FIDO (page d usage F1D0)', true, $found);
    $device = '/dev/' . $found;
}

$name = basename($device);
$descriptor = (string) file_get_contents('/sys/class/hidraw/' . $name . '/device/report_descriptor');
$report = $descriptor === '' ? null : report_id($descriptor);

// Information, pas un check : une cle sans item Report ID est normale, et la valeur n est pas
// fixee par la spec. Ce qui compte est ce que la cle fait avec, ce que l echo du nonce montre.
echo '  reports numerotes : ', match (true) {
    $descriptor === '' => 'descripteur illisible (le conteneur ne monte pas /sys), les deux formes sont testees',
    $report === null => 'non (zero au debut de ce qu on ecrit, rien au debut de ce qu on lit)',
    default => 'item Report ID ' . byte((int) $report),
}, PHP_EOL;

$handle = fopen($device, 'rb+');

if ($handle === false) {
    check('device accessible', false, 'acces root indispensable (' . $device . ')');
    exit(1);
}

check('device accessible', true, $device);

$skip = $report === null ? 0 : 1;

// CTAPHID_INIT sur le canal de diffusion : la cle attribue le canal.
$nonce = random_bytes(8);
send($handle, BROADCAST, CMD_INIT, $nonce, $report ?? 0);
$frame = receive($handle, 10);

if ($frame === null) {
    exit(1);
}

// Le descripteur dit quelle forme attendre, et l echo du nonce la confirme. Les deux sont
// testees sur la trame recue : si aucune ne fait l echo, ce n est pas une question de tramage.
$answer = null;

foreach ([$skip, $skip === 0 ? 1 : 0] as $form) {
    $try = parse($frame, $form);

    if ($try !== null && $try['init'] && $try['cmd'] === CMD_INIT && strlen($try['payload']) >= 16 && substr($try['payload'], 0, 8) === $nonce) {
        $answer = $try;
        $skip = $form;

        break;
    }
}

if ($answer === null) {
    $refused = false;

    foreach ([0, 1] as $form) {
        $try = parse($frame, $form);

        if ($try === null) {
            continue;
        }

        echo '  relu avec le decalage ', $form, ' : canal ', hex($try['cid']),
            ' balise ', byte($try['cmd']), ' (trame ', $try['init'] ? 'd initialisation' : 'de continuation', ')',
            ', taille annoncee ', $try['size'], ', payload ', hex($try['payload']), PHP_EOL;

        if ($try['cmd'] === CMD_ERROR && $try['payload'] !== '') {
            $code = ord($try['payload'][0]);

            echo '    balise erreur : code lu ', byte($code), ' ', CTAPHID_ERRORS[$code] ?? 'code inconnu', PHP_EOL;

            if ($try['size'] > strlen($frame) && strlen($frame) > $form + 7) {
                $alt = ord($frame[$form + 7]);

                echo '    la taille annoncee (', $try['size'], ') ne tient pas dans une trame de ', REPORT_SIZE, ' octets', PHP_EOL;
                echo '    si BCNT est ecrit sur 2 octets, le code est ', byte($alt), ' ', CTAPHID_ERRORS[$alt] ?? 'code inconnu', PHP_EOL;
            }

            $refused = true;
        }
    }

    // Le canal de diffusion est repris dans la reponse : le tramage est donc correct, et la
    // cle a refuse le handshake. CTAPHID_INIT est obligatoire sur le transport HID pour un
    // authenticateur CTAP2, donc un refus ici veut dire que cette interface ne fait pas CTAP2.
    check('CTAPHID_INIT', false, $refused
        ? 'reponse d erreur au lieu de l echo du nonce : la cle refuse le handshake, donc pas de CTAP2 sur cette interface'
        : 'pas d echo du nonce, et la trame n est pas lisible comme une trame d initialisation');
    exit(1);
}

echo '  forme de trame retenue : ', $skip === 0 ? 'sans identifiant de rapport' : 'avec identifiant de rapport', PHP_EOL;

$cid = substr($answer['payload'], 8, 4);
$capabilities = ord($answer['payload'][strlen($answer['payload']) - 1]);

echo '  canal : ', hex($cid), PHP_EOL;
echo '  version interface : ', ord($answer['payload'][12]), '.', ord($answer['payload'][13]), PHP_EOL;
echo '  capabilities : ', byte($capabilities) . ' (' . (($capabilities & 0x02) === 0x02 ? 'CTAP1' : 'CTAP1 non annonce') . ')', PHP_EOL;

send($handle, $cid, CMD_CBOR, chr(CTAP_GET_INFO), $report ?? 0);
$answer = read_answer($handle, 15, $skip);

if ($answer === null) {
    exit(1);
}

if ($answer['payload'] === '') {
    check('authenticatorGetInfo', false, 'reponse vide');
    exit(1);
}

if ($answer['cmd'] === CMD_ERROR) {
    $code = ord($answer['payload'][0]);

    check('authenticatorGetInfo', false, 'erreur CTAPHID ' . byte($code) . ' (' . (CTAPHID_ERRORS[$code] ?? 'code inconnu') . ') : la cle ne parle pas CTAP2 sur cette interface');
    exit(1);
}

if ($answer['cmd'] !== CMD_CBOR) {
    check('authenticatorGetInfo', false, 'trame recue avec la balise ' . byte($answer['cmd']));
    exit(1);
}

$status = ord($answer['payload'][0]);

if ($status !== 0) {
    check('authenticatorGetInfo', false, 'statut CTAP ' . byte($status) . ' (' . (CTAPHID_ERRORS[$status] ?? 'code inconnu') . ')');
    exit(1);
}

$info = Cbor::decode(substr($answer['payload'], 1));

if (!is_array($info)) {
    check('authenticatorGetInfo', false, 'reponse CBOR illisible');
    exit(1);
}

$versions = is_array($info['1'] ?? null) ? array_map('strval', $info['1']) : [];

check('CTAP2 annonce par la cle', in_array('FIDO_2_0', $versions, true), implode(', ', $versions));

$flags = (int) ($info['3'] ?? 0);
$flags_hex = str_pad(dechex($flags), 2, '0', STR_PAD_LEFT);

check('drapeau presence (bit 0)', ($flags & 1) === 1, 'flags 0x' . $flags_hex);
check('drapeau verification utilisateur (bit 1)', ($flags & 2) === 2, 'flags 0x' . $flags_hex);

$methods = is_array($info['9'] ?? null) ? array_map('intval', $info['9']) : [];
$names = array_map(static fn (int $method): string => UV_METHODS[$method] ?? ('methode ' . $method), $methods);

echo '  methodes de verification utilisateur : ', implode(', ', $names), PHP_EOL;

$with_pin = array_intersect($methods, [4, 5, 6, 7]) !== [];

check(
    'la cle peut satisfaire userVerification required',
    ($flags & 2) === 2 && $with_pin,
    'presence seule : la ceremonie est refusee avant le prompt, et ce controle ne sera pas relache'
);

$transports = is_array($info['6'] ?? null) ? array_map('strval', $info['6']) : [];
$aaguid = (string) ($info['2'] ?? '');

echo '  transports : ', implode(', ', $transports), PHP_EOL;
echo '  aaguid : ', strlen($aaguid) === 16 ? hex($aaguid) : 'absent', PHP_EOL;
echo '  options : ', json_encode($info['4'] ?? [], JSON_UNESCAPED_SLASHES), PHP_EOL;

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
