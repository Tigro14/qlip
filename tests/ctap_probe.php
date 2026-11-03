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
 *   sudo php tests/ctap_probe.php /dev/hidraw2
 *   sudo podman run --rm -v "$PWD:/app:ro" --device /dev/hidraw0 localhost/qlip-test php tests/ctap_probe.php
 */

const REPORT_SIZE = 64;

/** Le bit haut du byte de command distingue une trame d initialisation d une continuation. */
const TYPE_INIT = 0x80;

const CMD_INIT = 0x06;
const CMD_CBOR = 0x10;
const CMD_KEEPALIVE = 0x3B;
const CMD_ERROR = 0x3F;

/** Premier byte du payload d un CTAPHID_CBOR : authenticatorGetInfo. */
const CTAP_GET_INFO = 0x02;

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

const BROADCAST = "\xff\xff\xff\xff";

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
 * Interface HID qui porte la page d'usage FIDO (0xF1D0). Les autres interfaces du meme
 * device sont le clavier (OTP) ou le lecteur de cartes (PIV, OpenPGP) : une cle vue seulement
 * comme carte n est pas un authenticateur WebAuthn. La page d'usage se lit dans le descripteur
 * de rapport, pas dans MODALIAS : MODALIAS porte le type de bus, pas la page d usage.
 * L item Usage Page 2 octets est 0x06 suivi de la valeur en petit boutien (d0 f1).
 */
function find_fido_device(): ?string
{
    foreach (glob('/sys/class/hidraw/hidraw*') ?: [] as $path) {
        $descriptor = file_get_contents($path . '/device/report_descriptor');

        if ($descriptor !== false && str_contains(bin2hex($descriptor), 'd0f1')) {
            return basename($path);
        }
    }

    return null;
}

function send($handle, string $cid, int $cmd, string $payload): void
{
    $size = strlen($payload);
    $frame = chr(0) . $cid . chr($cmd | TYPE_INIT)
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
        return null;
    }

    $frame = fread($handle, REPORT_SIZE);

    return $frame === false || $frame === '' ? null : $frame;
}

/**
 * Lit une reponse complete (trame d initialisation puis continuations). Le canal de la cle
 * est celui qu elle a attribue, donc il peut differer du canal demande.
 *
 * @return array{cmd: int, payload: string}|null
 */
function response($handle, int $seconds): ?array
{
    $frame = receive($handle, $seconds);

    if ($frame === null || strlen($frame) < 9) {
        return null;
    }

    $cmd = ord($frame[5]);
    $size = (ord($frame[6]) << 16) + (ord($frame[7]) << 8) + ord($frame[8]);
    $payload = substr($frame, 9);

    while (strlen($payload) < $size) {
        $next = receive($handle, $seconds);

        if ($next === null || strlen($next) < 6) {
            break;
        }

        // Le byte de sequence n est pas verifie : la cle envoie les continuations dans l ordre.
        $payload .= substr($next, 5);
    }

    return ['cmd' => $cmd, 'payload' => substr($payload, 0, $size)];
}

$device = $argv[1] ?? null;

if ($device === null) {
    $found = find_fido_device();

    if ($found === null) {
        check('cle vue comme authenticateur FIDO (page d usage F1D0)', false, 'rien sur /sys/class/hidraw');
        exit(1);
    }

    check('cle vue comme authenticateur FIDO (page d usage F1D0)', true, $found);
    $device = '/dev/' . $found;
}

$handle = fopen($device, 'rb+');

if ($handle === false) {
    check('device accessible', false, 'acces root indispensable (' . $device . ')');
    exit(1);
}

check('device accessible', true, $device);

// CTAPHID_INIT : la cle attribue le canal sur le canal de diffusion.
$nonce = random_bytes(8);
send($handle, BROADCAST, CMD_INIT, $nonce);
$answer = response($handle, 10);

if ($answer === null) {
    check('CTAPHID_INIT', false, 'aucune reponse en 10 s');
    exit(1);
}

$payload = $answer['payload'];

if ($answer['cmd'] !== CMD_INIT || strlen($payload) < 16 || substr($payload, 0, 8) !== $nonce) {
    check('CTAPHID_INIT', false, 'reponse sans le nonce : cle CTAP1 seule');
    exit(1);
}

$cid = substr($payload, 8, 4);
$capabilities = ord($payload[strlen($payload) - 1]);

echo '  canal : ' . bin2hex($cid), PHP_EOL;
echo '  version interface : ' . ord($payload[12]) . '.' . ord($payload[13]), PHP_EOL;
echo '  capabilities : 0x' . str_pad(dechex($capabilities), 2, '0', STR_PAD_LEFT)
    , ' (' . (($capabilities & 0x02) === 0x02 ? 'CTAP1' : 'CTAP1 non annonce') . ')'
    , PHP_EOL;

send($handle, $cid, CMD_CBOR, chr(CTAP_GET_INFO));
$answer = response($handle, 15);

if ($answer === null) {
    check('authenticatorGetInfo', false, 'aucune reponse en 15 s');
    exit(1);
}

if ($answer['payload'] === '') {
    check('authenticatorGetInfo', false, 'reponse vide');
    exit(1);
}

if ($answer['cmd'] === CMD_ERROR) {
    check('authenticatorGetInfo', false, 'statut CTAP 0x' . str_pad(dechex(ord($answer['payload'][0])), 2, '0', STR_PAD_LEFT));
    exit(1);
}

if (ord($answer['payload'][0]) !== 0) {
    check('authenticatorGetInfo', false, 'statut CTAP 0x' . str_pad(dechex(ord($answer['payload'][0])), 2, '0', STR_PAD_LEFT));
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
echo '  aaguid : ', strlen($aaguid) === 16 ? bin2hex($aaguid) : 'absent', PHP_EOL;
echo '  options : ', json_encode($info['4'] ?? [], JSON_UNESCAPED_SLASHES), PHP_EOL;

echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
exit($failures === 0 ? 0 : 1);
