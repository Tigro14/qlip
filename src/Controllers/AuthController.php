<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;

/**
 * Inscription, verification d'email, connexion, reinitialisation de mot de passe,
 * deconnexion.
 */
final class AuthController
{
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 300;

    private const MAX_RESET_REQUESTS = 5;
    private const RESET_WINDOW = 3600;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function showRegister(Request $request): Response
    {
        return Response::html($this->app->view->render('register.php', [
            'title' => 'Inscription',
            'user' => $this->app->currentUser(),
            'csrf' => $this->app->session->token(),
            'flash' => $this->app->takeFlash(),
        ]));
    }

    public function register(Request $request): Response
    {
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        try {
            $result = $this->app->users->register($request->input('email'), $request->input('password'));
        } catch (\Throwable $error) {
            return Response::html($this->app->view->render('register.php', [
                'title' => 'Inscription',
                'user' => $this->app->currentUser(),
                'csrf' => $this->app->session->token(),
                'error' => $error->getMessage(),
            ]));
        }

        $this->app->session->regenerate();

        return Response::html($this->app->view->render('verify_sent.php', [
            'title' => 'Verifie ton email',
            'user' => $this->app->currentUser(),
            'email' => $result['user']['email'],
            'link' => $result['link'],
            'transport' => $this->app->mailer->transport(),
            'path' => $result['mail']['path'],
            'ok' => (bool) $result['mail']['ok'],
            'error' => (string) $result['mail']['error'],
        ]));
    }

    public function verify(Request $request): Response
    {
        $user = $this->app->users->verifyToken($request->input('token'));

        if ($user === null) {
            return Response::html('Lien de verification invalide ou expire.', 400);
        }

        $this->app->session->regenerate();
        $this->app->session->set('user_id', (int) $user['id']);

        return Response::html($this->app->view->render('verify_done.php', [
            'title' => 'Compte active',
            'user' => $this->app->currentUser(),
        ]));
    }

    public function showForgot(Request $request): Response
    {
        return Response::html($this->app->view->render('forgot.php', [
            'title' => 'Mot de passe oublie',
            'user' => $this->app->currentUser(),
            'csrf' => $this->app->session->token(),
            'locked' => $this->resetLockedUntil(),
            'flash' => $this->app->takeFlash(),
        ]));
    }

    /**
     * Demande de reinitialisation. La reponse ne dit pas si l'adresse a un compte, et le
     * verou est compte sur la demande (pas sur l envoi) : une adresse sans compte consomme
     * la meme part, sinon le formulaire servirait a tester les adresses sans limite.
     */
    public function forgot(Request $request): Response
    {
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        $locked = $this->resetLockedUntil();

        if ($locked > time()) {
            return Response::html('Trop de demandes. Recomence dans ' . ($locked - time()) . ' secondes.', 429);
        }

        $this->countResetRequest();

        try {
            $result = $this->app->users->requestReset($request->input('email'));
        } catch (\Throwable $error) {
            return Response::html($this->app->view->render('forgot.php', [
                'title' => 'Mot de passe oublie',
                'user' => $this->app->currentUser(),
                'csrf' => $this->app->session->token(),
                'locked' => $this->resetLockedUntil(),
                'error' => $error->getMessage(),
            ]));
        }

        $found = $result['user'] !== null;
        $mail = $result['mail'];

        return Response::html($this->app->view->render('reset_sent.php', [
            'title' => 'Reinitialisation demandee',
            'user' => $this->app->currentUser(),
            'email' => $result['email'],
            'found' => $found,
            'link' => $result['link'],
            'transport' => $this->app->mailer->transport(),
            'path' => $mail === null ? '' : (string) $mail['path'],
            'ok' => $mail === null || (bool) $mail['ok'],
            'error' => $mail === null ? '' : (string) $mail['error'],
        ]));
    }

    public function showReset(Request $request): Response
    {
        $token = $request->input('token');

        if ($this->app->users->resetToken($token) === null) {
            return Response::html('Lien de reinitialisation invalide ou expire.', 400);
        }

        return Response::html($this->app->view->render('reset.php', [
            'title' => 'Nouveau mot de passe',
            'user' => $this->app->currentUser(),
            'csrf' => $this->app->session->token(),
            'token' => $token,
        ]));
    }

    /**
     * Nouveau mot de passe. Le jeton est consomme par le service, usage unique ; un lien
     * deja utilise, ou plus vieux que 24 h, rend 400. La session est regeneree parce que
     * l'identite change.
     */
    public function reset(Request $request): Response
    {
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        $token = $request->input('token');

        try {
            $user = $this->app->users->resetPassword($token, $request->input('password'));
        } catch (\Throwable $error) {
            // Un mot de passe trop court ne consomme pas le jeton : le lien est encore
            // valable, on peut reprendre le formulaire.
            return Response::html($this->app->view->render('reset.php', [
                'title' => 'Nouveau mot de passe',
                'user' => $this->app->currentUser(),
                'csrf' => $this->app->session->token(),
                'token' => $token,
                'error' => $error->getMessage(),
            ]));
        }

        if ($user === null) {
            return Response::html('Lien de reinitialisation invalide ou expire.', 400);
        }

        $this->app->session->regenerate();
        $this->app->session->set('user_id', (int) $user['id']);

        $this->app->flash('Mot de passe change pour ' . $user['email'] . '.');

        return Response::redirect($this->app->url('dashboard'));
    }

    public function showLogin(Request $request): Response
    {
        return Response::html($this->app->view->render('login.php', [
            'title' => 'Connexion',
            'user' => $this->app->currentUser(),
            'csrf' => $this->app->session->token(),
            'locked' => $this->lockedUntil(),
            'flash' => $this->app->takeFlash(),
        ]));
    }

    public function login(Request $request): Response
    {
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        $locked = $this->lockedUntil();

        if ($locked > time()) {
            return Response::html('Trop de tentatives. Recomence dans ' . ($locked - time()) . ' secondes.', 429);
        }

        $user = $this->app->users->attempt($request->input('email'), $request->input('password'));

        if ($user === null) {
            $this->app->session->set('attempts', (int) $this->app->session->get('attempts', 0) + 1);

            if ((int) $this->app->session->get('attempts', 0) >= self::MAX_ATTEMPTS) {
                $this->app->session->set('locked_until', time() + self::LOCK_SECONDS);
            }

            return Response::html('Identifiants incorrects, ou email non verifie.', 401);
        }

        $this->app->session->set('attempts', 0);
        $this->app->session->regenerate();
        $this->app->session->set('user_id', (int) $user['id']);

        $this->app->users->audit((int) $user['id'], 'login', (string) $user['email']);

        return Response::redirect($this->app->url('dashboard'));
    }

    public function logout(Request $request): Response
    {
        // La deconnexion est un POST : sans le jeton, une page externe peut faire
        // partir l'utilisateur de sa session (CSRF).
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        $this->app->session->destroy();

        return Response::redirect($this->app->url('login'));
    }

    /**
     * Suppression du compte. Comme la deconnexion, c est un POST avec le jeton CSRF, plus
     * une confirmation explicite et le mot de passe : ce qui est detruit ne revient pas, et
     * le mot de passe prouve que la session est bien celle du proprietaire (une session
     * laissee ouverte sur un poste partage ne doit pas permettre de detruire un compte).
     */
    public function deleteAccount(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Connexion requise.'], 401)
                : Response::html('Connexion requise.', 401);
        }

        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Jeton CSRF invalide.'], 400)
                : Response::html('Jeton CSRF invalide.', 400);
        }

        if ($request->input('confirm') !== '1') {
            return $request->wantsJson()
                ? Response::json(['error' => 'Confirmation requise.'], 400)
                : Response::html('Confirmation requise.', 400);
        }

        $password = (string) $request->input('password');

        if ($password === '' || !password_verify($password, (string) $user['password_hash'])) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Mot de passe incorrect.'], 401)
                : Response::html('Mot de passe incorrect.', 401);
        }

        $counts = $this->app->users->deleteAccount((int) $user['id']);

        // Le compte n existe plus : la session ne peut plus y pointer.
        $this->app->session->destroy();

        if ($request->wantsJson()) {
            return Response::json(['deleted' => true, 'pages' => $counts['pages'], 'badges' => $counts['badges']]);
        }

        $this->app->flash('Compte ' . $counts['email'] . ' supprime (' . $counts['pages'] . ' pages, ' . $counts['badges'] . ' badges).');

        return Response::redirect($this->app->url('login'));
    }

    private function lockedUntil(): int
    {
        return (int) $this->app->session->get('locked_until', 0);
    }

    /**
     * Fenetre glissante des demandes de reinitialisation : 5 par heure et par session.
     *
     * @return list<int>
     */
    private function resetWindow(): array
    {
        $sent = $this->app->session->get('reset_sent', []);

        if (!is_array($sent)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $sent),
            static fn (int $at): bool => $at > time() - self::RESET_WINDOW
        ));
    }

    /** Le verou n est pas une duree posee : il tombe quand la plus vieille demande sort de la fenetre. */
    private function resetLockedUntil(): int
    {
        $window = $this->resetWindow();

        return count($window) >= self::MAX_RESET_REQUESTS ? $window[0] + self::RESET_WINDOW : 0;
    }

    private function countResetRequest(): void
    {
        $window = $this->resetWindow();
        $window[] = time();

        $this->app->session->set('reset_sent', $window);
    }

}
