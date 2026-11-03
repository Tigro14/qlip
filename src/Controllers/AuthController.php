<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;

/**
 * Inscription, verification d'email, connexion, deconnexion.
 */
final class AuthController
{
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 300;

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

}
