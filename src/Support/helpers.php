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

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * URL de l'application (base configurable). Utilisee dans les templates.
 */
function url(string $path = ''): string
{
    $base = rtrim((string) (getenv('QLIP_BASE_URL') ?: ''), '/');

    return $base . '/' . ltrim($path, '/');
}

/** Echappement pour un attribut HTML. */
function attr(string $value): string
{
    return e($value);
}
