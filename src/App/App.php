<?php

declare(strict_types=1);

namespace Qlip\App;

use Qlip\Db;
use Qlip\Services\Badges;
use Qlip\Services\Credentials;
use Qlip\Services\Mailer;
use Qlip\Services\Users;
use Qlip\Webauthn\Policy;

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
        $this->mailer = new Mailer($this->config->mailTransport, $this->config->varPath, $this->config->smtp, $this->config->sendmail);

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

    /**
     * Compte en attente de second facteur : le mot de passe est valide, la ceremonie
     * n'est pas faite. Cette session ne donne aucun acces tant que la cle n'a pas
     * prouve sa possession.
     *
     * @return array<string, mixed>|null
     */
    public function pendingUser(): ?array
    {
        $userId = (int) $this->session->get('pending_user_id', 0);

        return $userId > 0 ? $this->users->find($userId) : null;
    }

    /**
     * Politique de verification utilisateur pour la ceremonie en cours.
     *
     * Le profil est pose par le serveur, quand la page de la ceremonie est rendue :
     * le navigateur ne peut pas choisir la sienne. Elle est garde en session parce que
     * la ceremonie se fait en deux requetes : les options, puis la reponse de
     * l'authenticateur.
     *
     *   - 'presence' : le mot de passe a deja prouve l'utilisateur, la cle prouve la
     *     possession. C'est le profil du second facteur et de l'enrolement depuis le
     *     tableau de bord ;
     *   - 'passkey' : la cle est le seul moyen d'identifier l'utilisateur (page
     *     d'administration), donc la verification utilisateur reste exigee.
     */
    public function ceremonyPolicy(?string $profile = null): string
    {
        if ($profile !== null) {
            $this->session->set('ceremony_profile', Policy::forProfile($profile));
        }

        return Policy::normalize((string) $this->session->get('ceremony_profile', Policy::forProfile(Policy::PROFILE_PASSKEY)));
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
