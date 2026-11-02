<?php

declare(strict_types=1);

namespace Qlip\App;

use Qlip\Db;
use Qlip\Services\Badges;
use Qlip\Services\Credentials;
use Qlip\Services\Mailer;
use Qlip\Services\Users;

/**
 * Conteneur : config, base, services. Les controllers ne recoivent que cela.
 */
final class App
{
    public Config $config;
    public Db $db;
    public View $view;
    public Session $session;
    public Mailer $mailer;
    public Users $users;
    public Badges $badges;
    public Credentials $credentials;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->db = new Db($this->config->dbPath);
        $this->view = new View(dirname(__DIR__, 2) . '/templates');
        $this->session = new Session();
        $this->mailer = new Mailer($this->config->mailTransport, $this->config->varPath);

        $this->users = new Users($this->db, $this->mailer, $this->config->baseUrl);
        $this->badges = new Badges($this->db, $this->config->dailyLimit, $this->config->baseUrl);
        $this->credentials = new Credentials($this->db, $this->config->rpId, $this->config->origins, $this->config->rootsPem());
    }

    /** URL absolue depuis la base de configuration. */
    public function url(string $path): string
    {
        return $this->config->baseUrl . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $userId = (int) $this->session->get('user_id', 0);

        return $userId > 0 ? $this->users->find($userId) : null;
    }

    /** @param array<string, string> $flash */
    public function flash(string $message, string $level = 'ok'): void
    {
        $this->session->set('flash', ['message' => $message, 'level' => $level]);
    }

    /** @return array<string, string>|null */
    public function takeFlash(): ?array
    {
        $flash = $this->session->get('flash');

        if (!is_array($flash)) {
            return null;
        }

        $this->session->set('flash', null);

        return $flash;
    }

    /**
     * Challenge courant (stocke en session). Un challenge est un tampon de 32
     * octets, transporte en base64url.
     */
    public function challenge(): string
    {
        $token = $this->session->get('challenge');

        if (!is_string($token) || $token === '') {
            $token = base64url_encode(random_bytes(32));
            $this->session->set('challenge', $token);
        }

        return $token;
    }
}
