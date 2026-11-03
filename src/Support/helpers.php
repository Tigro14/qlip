<?php

declare(strict_types=1);

/**
 * Fonctions globales utiles (echappement, formatage, etc.).
 */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_field(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function base64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function base64url_decode(string $value): string
{
    $normalized = strtr($value, '-_', '+/');
    $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');
    $decoded = base64_decode($padded, true);

    if ($decoded === false) {
        throw new \InvalidArgumentException('Base64URL invalide.');
    }

    return $decoded;
}

/**
 * Variable d'environnement. Apache ne la transmet pas de la meme facon selon le module :
 * PutEnv arrive dans getenv(), SetEnv dans $_SERVER, et une requete reecrite par
 * mod_rewrite la porte en REDIRECT_*. PHP-FPM ne voit pas PutEnv : il faut l'environment
 * du pool, ou SetEnv (donc $_SERVER). On lit les trois pour que la conf du vhost marche
 * dans les deux cas.
 */
function env(string $name, string $default = ''): string
{
    foreach ([
        getenv($name),
        $_SERVER[$name] ?? null,
        $_ENV[$name] ?? null,
        $_SERVER['REDIRECT_' . $name] ?? null,
        $_ENV['REDIRECT_' . $name] ?? null,
    ] as $value) {
        if ($value !== null && $value !== false && $value !== '') {
            return (string) $value;
        }
    }

    return $default;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Handle d utilisateur transmis au navigateur. La spec demande au moins 16 octets pour
 * PublicKeyCredentialUserEntity.id : un id de 1 octet est refuse par certains moteurs
 * (NotAllowedError). L id est ecrit sur 16 octets, entier non signe big-endian complete par
 * des zeros, donc il reste stable pour un compte. Il part en base64url comme les autres
 * tampons, parce que le champ est un tampon et pas une chaine.
 */
function user_handle(int $id): string
{
    return base64url_encode(str_pad(pack('J', $id), 16, "\0", STR_PAD_LEFT));
}

/**
 * URL de l'application (base configurable). Utilisee dans les templates.
 */
function url(string $path = ''): string
{
    $base = rtrim(env('QLIP_BASE_URL'), '/');

    return $base . '/' . ltrim($path, '/');
}

/** Echappement pour un attribut HTML. */
function attr(string $value): string
{
    return e($value);
}
