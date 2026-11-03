<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\Webauthn\Policy;

/**
 * Pages publiques de contact, QR en PNG, edition par le proprietaire.
 */
final class PageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function home(Request $request): Response
    {
        return Response::html($this->app->view->render('home.php', [
            'title' => 'Qlip',
            'user' => $this->app->currentUser(),
        ]));
    }

    public function show(Request $request): Response
    {
        $page = $this->app->badges->pageBySlug($request->params['slug']);

        if ($page === null) {
            return Response::html('Page introuvable.', 404);
        }

        return Response::html($this->app->view->render('page.php', [
            'title' => (string) $page['title'],
            'page' => $page,
            'user' => $this->app->currentUser(),
            'qrUrl' => $this->app->url('qr/' . $page['slug'] . '.png'),
        ]));
    }

    public function qr(Request $request): Response
    {
        $page = $this->app->badges->pageBySlug($request->params['slug']);

        if ($page === null) {
            return Response::html('Page introuvable.', 404);
        }

        return new Response($this->app->badges->png($page), 200, ['Content-Type' => 'image/png']);
    }

    public function dashboard(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return Response::redirect($this->app->url('login'));
        }

        // L'enrolement depuis le tableau de bord se fait sur une session deja confirmee
        // par le mot de passe : le profil est 'presence', la cle prouve la possession.
        $this->app->ceremonyPolicy(Policy::PROFILE_PRESENCE);

        // Les dimensions affichees sur chaque onglet viennent du design enregistre avec la
        // creation, pas des valeurs par defaut du formulaire.
        $badges = array_map(
            fn (array $badge): array => $badge + ['dimensions' => $this->app->badges->designOf($badge)],
            $this->app->badges->badgesOf((int) $user['id'])
        );

        return Response::html($this->app->view->render('dashboard.php', [
            'title' => 'Mes pages',
            'user' => $user,
            'pages' => $this->app->badges->pagesOf((int) $user['id']),
            'badges' => $badges,
            'remaining' => $this->app->badges->remaining((int) $user['id']),
            'credentials' => $this->app->credentials->list((int) $user['id']),
            'rpId' => $this->app->config->rpId,
            'csrf' => $this->app->session->token(),
            'flash' => $this->app->takeFlash(),
        ]));
    }

    public function create(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return Response::html('Connexion requise.', 401);
        }

        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        try {
            $page = $this->app->badges->createPage((int) $user['id'], $request->body);
        } catch (\Throwable $error) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $error->getMessage()], 422);
            }

            $this->app->flash($error->getMessage(), 'err');

            return Response::redirect($this->app->url('dashboard'));
        }

        return Response::redirect($this->app->url('p/' . $page['slug']));
    }

    public function update(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return Response::html('Connexion requise.', 401);
        }

        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::html('Jeton CSRF invalide.', 400);
        }

        $page = $this->app->badges->pageBySlug($request->params['slug']);

        if ($page === null) {
            return Response::html('Page introuvable.', 404);
        }

        if ((int) $page['user_id'] !== (int) $user['id'] && (int) $user['is_admin'] !== 1) {
            return Response::html('Page d un autre compte.', 403);
        }

        try {
            $page = $this->app->badges->updatePage((int) $page['id'], $request->body) ?? $page;
        } catch (\Throwable $error) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $error->getMessage()], 422);
            }

            $this->app->flash($error->getMessage(), 'err');

            return Response::redirect($this->app->url('dashboard'));
        }

        return Response::redirect($this->app->url('p/' . $page['slug']));
    }

    /**
     * Suppression d une page, et de ses badges. Suppression definitive : elle demande le
     * jeton CSRF et une confirmation explicite, parce qu un simple lien ne doit pas detruire.
     */
    public function delete(Request $request): Response
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

        $page = $this->app->badges->pageBySlug($request->params['slug']);

        if ($page === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Page introuvable.'], 404)
                : Response::html('Page introuvable.', 404);
        }

        if ((int) $page['user_id'] !== (int) $user['id'] && (int) $user['is_admin'] !== 1) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Page d un autre compte.'], 403)
                : Response::html('Page d un autre compte.', 403);
        }

        $slug = (string) $page['slug'];
        $this->app->badges->deletePage((int) $user['id'], (int) $page['id']);

        if ($request->wantsJson()) {
            return Response::json(['deleted' => true, 'slug' => $slug]);
        }

        $this->app->flash('Page ' . $slug . ' supprimee.');

        return Response::redirect($this->app->url('dashboard'));
    }
}
