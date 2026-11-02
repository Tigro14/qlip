<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;

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

        return Response::html($this->app->view->render('dashboard.php', [
            'title' => 'Mes pages',
            'user' => $user,
            'pages' => $this->app->badges->pagesOf((int) $user['id']),
            'badges' => $this->app->badges->badgesOf((int) $user['id']),
            'remaining' => $this->app->badges->remaining((int) $user['id']),
            'csrf' => $this->app->session->token(),
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

        $page = $this->app->badges->createPage((int) $user['id'], $request->body);

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

        $this->app->badges->updatePage((int) $page['id'], $request->body);

        return Response::redirect($this->app->url('p/' . $page['slug']));
    }
}
