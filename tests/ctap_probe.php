<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Webauthn\Cbor;

/**
 * Sonde CTAP : lit ce que la suite ne peut pas verifier, parce que la suite n'a pas de vrai
 * authenticateur : la cle est-elle vue comme un authenticateur CTAP (et pas seulement comme un
 * lecteur de cartes), parle-t-elle CTAP2 (CBOR) ou seulement CTAP1 (U2F), et peut-elle faire la
 * verification utilisateur exigee par le profil passkey (userVerification 'required'). Le profil
 * presence (second facteur, discouraged) est le seul qu une cle CTAP1 peut satisfaire : elle sert
 * comme preuve de possession, le mot de passe restant la preuve de l utilisateur.
 *
 * La cle doit etre branchee sur la machine qui execute le script. Le device est en acces root
 * (crw-------), ou ouvert par une regle udev seulement a des utilisateurs systeme (sssd,
 * gdm-greeter pour la verification PAM) : un utilisateur normal doit passer par root. Les seules
 * commandes envoyees sont des lectures (handshake, authenticatorGetInfo, version U2F), rien n'est
 * ecrit sur la cle. Le script n'est pas lance par tests/run.sh, il demande du materiel.
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
 *
 * Trame d initialisation : la taille annoncee est BCNTH + BCNTL, soit 2 octets, et le payload
 * commence a l offset 7. C est la meme forme pour CTAPHID et pour U2FHID. Une implementation qui
 * ecrit 3 octets fait lire une taille de 0 par la cle, qui repond une erreur de longueur : cette
 * erreur dit ce que le script a ecrit, pas ce que la cle sait faire. La sonde ecrit la forme de
 * la spec et lit la reponse dans les deux formes, pour ne pas confondre les deux.
 */

const REPORT_SIZE = 64;

/** Le bit haut du byte de command distingue une trame d initialisation d une continuation. */
const TYPE_INIT = 0x80;

/** Largeur du champ de taille d une trame d initialisation : BCNTH + BCNTL. */
const LEN_SPEC = 2;
const LEN_ALT = 3;

const CMD_INIT = 0x06;

/** CTAPHID_MSG porte un message CTAP1 : le premier octet du payload est la commande U2F. */
const CMD_MSG = 0x03;

const CMD_CBOR = 0x10;
const CMD_ERROR = 0x3F;

/** Commande U2F envoyee dans CTAPHID_MSG : la version, la reponse est "U2F_V2". */
const U2F_VERSION = 0x03;

/** Mots d etat ISO 7816 que la reponse U2F peut porter (les 2 derniers octets du payload). */
const STATUS_WORDS = [
    0x9000 => 'succes',
    0x6E00 => 'longueur de donnee incorrecte (Lc ne correspond pas a la donnee recue)',
    0x6700 => 'longueur incorrecte',
    0x6400 => 'conditions non remplies (presence utilisateur demandee)',
    0x6A8A => 'donnees incorrectes (key handle invalide)',
    0x6D00 => 'commande non supportee',
];

/** Premier byte du payload d un CTAPHID_CBOR : authenticatorGetInfo. */
const CTAP_GET_INFO = 0x02;

const BROADCAST = "\xff\xff\xff\xff";

/** Drapeaux de capabilities de la reponse CTAPHID_INIT. */
const CAP_WINK = 0x01;
const CAP_CBOR = 0x04;

/** Le drapeau NMSG est mis quand la cle ne fait PAS CTAPHID_MSG, donc quand elle ne fait pas CTAP1. */
const CAP_NMSG = 0x08;

/** Codes d erreur du transport HID, les memes pour CTAP1 et CTAP2. */
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

function printable(string $value): string
{
    return preg_match('/^[ -~]+$/', $value) === 1 ? $value : '';
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
        . chr(($size >> 8) & 255) . chr($size & 255)
        . $payload;

    $frame = str_pad($frame, REPORT_SIZE, "\0");

    echo '  trame envoyee : ', hex($frame), PHP_EOL;

    fwrite($handle, $frame);
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
 * La taille est lue sur $width octets, la trame etant lisible des deux façons quand le champ fait
 * 2 octets et que la valeur tient sur 3. La lecture est retenue quand la taille annoncee tient
 * dans la trame : une taille de 259 annoncee dans une trame de 64 octets remplie de zeros ne
 * peut pas etre la bonne.
 *
 * @return array{cid: string, init: bool, cmd: int, size: int, payload: string, fits: bool}|null
 */
function parse(string $frame, int $skip, int $width): ?array
{
    if (strlen($frame) < $skip + 5 + $width) {
        return null;
    }

    $header = ord($frame[$skip + 4]);
    $size = 0;

    for ($i = 0; $i < $width; $i++) {
        $size = ($size << 8) + ord($frame[$skip + 5 + $i]);
    }

    $data = substr($frame, $skip + 5 + $width);

    return [
        'cid' => substr($frame, $skip, 4),
        'init' => ($header & TYPE_INIT) === TYPE_INIT,
        'cmd' => $header & 0x7F,
        'size' => $size,
        'payload' => substr($data, 0, $size),
        'fits' => $size <= strlen($data),
    ];
}

function print_reading(array $try, int $skip, int $width): void
{
    echo '  relu avec le decalage ', $skip, ' et la taille sur ', $width, ' octets : canal ', hex($try['cid']),
        ' balise ', byte($try['cmd']), ' (trame ', $try['init'] ? 'd initialisation' : 'de continuation', ')',
        ', taille annoncee ', $try['size'],
        $try['fits'] ? '' : ' (ne tient pas dans la trame)',
        ', payload ', hex($try['payload']), PHP_EOL;

    if ($try['cmd'] === CMD_ERROR && $try['payload'] !== '') {
        $code = ord($try['payload'][0]);

        echo '    balise erreur : code ', byte($code), ' ', CTAPHID_ERRORS[$code] ?? 'code inconnu', PHP_EOL;
    }
}

/** Lit une reponse complete : la taille annoncee peut depasser une trame. */
function read_answer($handle, int $seconds, int $skip): ?array
{
    $frame = receive($handle, $seconds);
    $answer = parse((string) $frame, $skip, LEN_SPEC);

    if ($answer === null) {
        return null;
    }

    // Une continuation a la meme forme, avec le numero de sequence a la place de la taille.
    // Le numero n est pas verifie : les continuations arrivent dans l ordre. Le payload commence
    // a l offset 5, la largeur du champ de taille ne change rien ici.
    while (strlen($answer['payload']) < $answer['size']) {
        $next = receive($handle, $seconds);

        if ($next === null || strlen($next) < $skip + 5) {
            break;
        }

        $answer['payload'] .= substr($next, $skip + 5);
    }

    return $answer;
}

function capabilities(int $flags): string
{
    $names = [];

    if (($flags & CAP_WINK) === CAP_WINK) {
        $names[] = 'wink';
    }

    $names[] = ($flags & CAP_CBOR) === CAP_CBOR ? 'CBOR (CTAP2)' : 'CBOR non annonce';

    if (($flags & CAP_NMSG) === CAP_NMSG) {
        $names[] = 'CTAPHID_MSG non fait (donc pas de CTAP1)';
    }

    return implode(', ', $names);
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

// CTAPHID_INIT sur le canal de diffusion : la cle attribue le canal. La taille est ecrite sur
// 2 octets, comme la spec.
$nonce = random_bytes(8);
send($handle, BROADCAST, CMD_INIT, $nonce, $report ?? 0);
$frame = receive($handle, 10);

if ($frame === null) {
    exit(1);
}

// Les formes testees sont le decalage (avec ou sans identifiant de rapport) et la largeur du
// champ de taille. L echo du nonce montre la bonne.
$readings = [];

foreach ([$skip, $skip === 0 ? 1 : 0] as $form) {
    foreach ([LEN_SPEC, LEN_ALT] as $width) {
        $try = parse($frame, $form, $width);

        if ($try === null) {
            continue;
        }

        $try['form'] = $form;
        $try['width'] = $width;
        $readings[] = $try;

        print_reading($try, $form, $width);
    }
}

$answer = null;

foreach ($readings as $try) {
    if ($try['init'] && $try['cmd'] === CMD_INIT && strlen($try['payload']) >= 16 && substr($try['payload'], 0, 8) === $nonce) {
        $answer = $try;
        $skip = $try['form'];

        break;
    }
}

if ($answer === null) {
    $consistent = null;

    foreach ($readings as $try) {
        if ($try['cmd'] === CMD_ERROR && $try['payload'] !== '' && $try['fits']) {
            $consistent = $try;
        }
    }

    if ($consistent === null) {
        check('CTAPHID_INIT', false, 'pas d echo du nonce, et aucune lecture de la trame ne donne une trame d initialisation');
        exit(1);
    }

    $code = ord($consistent['payload'][0]);

    echo '    lecture retenue : la taille sur ', $consistent['width'], ' octets, ',
        $consistent['size'], ' octet(s) annonce(s), ce qui tient dans la trame', PHP_EOL;

    if ($code === 0x03) {
        echo '    la sonde ecrit la taille sur 2 octets, comme la spec : une erreur de longueur ici veut dire que ', PHP_EOL;
        echo '    la cle lit le champ sur 3 octets, ou refuse la taille annoncee pour la commande INIT (8 octets)', PHP_EOL;
    }

    // Le canal de diffusion est repris dans la reponse : le tramage est donc correct, et la cle a
    // repondu. Ce que la reponse dit depend de ce qu elle a lu dans le champ de taille.
    check('CTAPHID_INIT', false, 'reponse d erreur ' . byte($code) . ' (' . (CTAPHID_ERRORS[$code] ?? 'code inconnu') . ')');
    exit(1);
}

echo '  forme de trame retenue : ', $skip === 0 ? 'sans identifiant de rapport' : 'avec identifiant de rapport',
    ', taille sur ', $answer['width'], ' octets', PHP_EOL;

check('CTAPHID_INIT', true, 'echo du nonce : le tramage est correct');

$cid = substr($answer['payload'], 8, 4);

// Le dernier octet est le drapeau de capabilities : la reponse peut etre plus longue que 16 octets
// pour laisser de la place aux extensions.
$flags = ord($answer['payload'][strlen($answer['payload']) - 1]);

echo '  canal : ', hex($cid), PHP_EOL;
echo '  version interface : ', ord($answer['payload'][12]), ' (identifiant du protocole), appareil ',
    ord($answer['payload'][13]), '.', ord($answer['payload'][14]), '.', ord($answer['payload'][15]), PHP_EOL;
echo '  capabilities : ', byte($flags) . ' (' . capabilities($flags) . ')', PHP_EOL;

if (($flags & CAP_CBOR) !== CAP_CBOR) {
    // Pas de CBOR : la cle ne peut pas repondre a un message CTAP2, donc pas de ceremonie WebAuthn
    // par cette interface. La version U2F se lit par CTAPHID_MSG, qui est une lecture.
    //
    // Une requete U2F est une APDU : CLA pose a zero par le client, INS, P1, P2, puis la longueur
    // des donnees. La longueur est annoncee sur 1 octet (APDU courte) ou 3 (APDU etendue), et la
    // description de CTAPHID_MSG fait commencer le payload par le byte de commande, donc sans CLA.
    // Ce que l applet attend n est pas impose par la spec : sur la YubiKey NEO les deux formes sans
    // CLA repondent 6E 00 (longueur de donnee incorrecte), et la forme avec CLA (5 octets) repond
    // U2F_V2 puis 9000. Les formes sont donc essayees une par une, et c est la cle qui dit laquelle
    // elle lit.
    $forms = [
        'INS + P1 + P2 + LC sur 1 octet' => chr(U2F_VERSION) . "\x00\x00\x00",
        'INS + P1 + P2 + LC sur 3 octets' => chr(U2F_VERSION) . "\x00\x00\x00\x00\x00",
        'CLA + INS + P1 + P2 + LC sur 1 octet' => "\x00" . chr(U2F_VERSION) . "\x00\x00\x00",
    ];

    $is_u2f = false;

    foreach ($forms as $label => $payload) {
        send($handle, $cid, CMD_MSG, $payload, $report ?? 0);
        $answer = read_answer($handle, 10, $skip);

        if ($answer === null) {
            continue;
        }

        // Le mot d etat est en fin de payload (format APDU), la forme CTAP le met au debut : les
        // deux donnent les memes octets quand il n y a pas de donnee, comme ici.
        $word = strlen($answer['payload']) >= 2
            ? (ord(substr($answer['payload'], -2, 1)) << 8) + ord(substr($answer['payload'], -1))
            : 0;
        $data = substr($answer['payload'], 0, strlen($answer['payload']) - 2);
        $text = printable($data);

        echo '  requete ', $label, ' : mot d etat ', strtoupper(str_pad(dechex($word), 4, '0', STR_PAD_LEFT)),
            ' ', STATUS_WORDS[$word] ?? 'mot d etat inconnu',
            ', donnee ', hex($data), $text !== '' ? ' (' . $text . ')' : '', PHP_EOL;

        if ($word === 0x9000 && $text === 'U2F_V2') {
            $is_u2f = true;

            break;
        }
    }

    check('cle qui parle CTAP1 (U2F)', $is_u2f, $is_u2f ? 'reponse U2F_V2' : 'aucune forme de requete ne donne U2F_V2');

    // Ce qui manque a une cle CTAP1 est la verification utilisateur, pas l attestation : le
    // navigateur convertit la reponse U2F en objet d attestation avec les cles de la spec (fmt =
    // fido-u2f, attStmt avec sig et x5c, authData), et ce format est accepte par l app. Donc la cle
    // sert comme second facteur, la ou la politique est celle du profil presence (discouraged) : le
    // mot de passe prouve l utilisateur, la cle prouve la possession. Elle ne peut pas satisfaire le
    // profil passkey (required) de la page d administration.

    check(
        'cle utilisable comme second facteur (profil presence)',
        $is_u2f,
        'format fido-u2f accepte par l app, et le profil presence est discouraged : le mot de passe '
            . 'a deja prouve l utilisateur'
    );
    check(
        'profil passkey (required) inaccessible avec cette cle',
        $is_u2f,
        'la cle ne sait pas faire la verification utilisateur : un administrateur dont la seule cle '
            . 'est une cle U2F entre par mot de passe + possession, pas par une assertion seule'
    );

    echo PHP_EOL, 'problemes : ', $failures, PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

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
