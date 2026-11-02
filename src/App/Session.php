<?php

declare(strict_types=1);

namespace Qlip\App;

/**
 * Session PHP, dure : cookie HttpOnly + SameSite=Strict, regenerree a chaque
 * changement d'identite, duree limitee, token CSRF par session.
 */
final class Session
{
    private const LIFETIME = 43200;

    private array $data;
    private bool $started;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [], bool $started = false)
    {
        $this->data = $data;
        $this->started = $started;

        if (!$started && session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            $this->data = &$_SESSION;
        }
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // Une session vraiment demarree (auto_start, requete precedente) : on reprend
        // ses donnees. Sans identifiant, session_status() peut mentir, donc on demarre.
        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            $this->data = &$_SESSION;
            $this->started = true;

            return;
        }

        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => (getenv('QLIP_SECURE_COOKIES') ?: '0') === '1',
        ]);

        session_name('qlip_session');

        // En CLI (tests) ou apres des en-tetes deja envoyes, session_start echoue :
        // on garde la session en memoire pour la duree de la requete.
        if (@session_start()) {
            $this->data = &$_SESSION;
        }

        $this->started = true;

        if (!isset($this->data['token'])) {
            $this->data['token'] = random_token(32);
        }

        if (!isset($this->data['created_at'])) {
            $this->data['created_at'] = time();
        }

        if (time() - (int) $this->data['created_at'] > self::LIFETIME) {
            $this->data = [];
            $this->data['token'] = random_token(32);
            $this->data['created_at'] = time();
        }
    }

    public function token(): string
    {
        $this->start();

        return (string) ($this->data['token'] ?? random_token(32));
    }

    public function checkToken(string $value): bool
    {
        $this->start();

        return hash_equals((string) ($this->data['token'] ?? ''), $value);
    }

    public function regenerate(): void
    {
        $this->start();

        if (session_id() !== '') {
            session_regenerate_id(true);
        }

        $this->data['token'] = random_token(32);
        $this->data['created_at'] = time();
    }

    /** @param array<string, mixed> $values */
    public function replace(array $values): void
    {
        $this->start();
        $this->data = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $this->data[$key] = $value;
    }

    public function destroy(): void
    {
        $this->data = [];

        if ($this->started && function_exists('session_destroy')) {
            session_destroy();
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $this->start();

        return $this->data;
    }
}
