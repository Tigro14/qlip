<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;

/**
 * Creations de badges (quota) et telechargement des fichiers STL / 3MF.
 */
final class BadgeController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function create(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Connexion requise.'], 401)
                : Response::redirect($this->app->url('login'));
        }

        if ($user['verified_at'] === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Email non verifie.'], 403)
                : Response::redirect($this->app->url('login'));
        }

        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Jeton CSRF invalide.'], 400)
                : Response::html('Jeton CSRF invalide.', 400);
        }

        $page = $this->app->badges->pageBySlug($request->input('slug'));

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

        try {
            $badge = $this->app->badges->createBadge((int) $user['id'], (int) $page['id'], $request->body);
        } catch (\Throwable $error) {
            if ($request->wantsJson()) {
                return Response::json(['error' => $error->getMessage()], 422);
            }

            $this->app->flash($error->getMessage(), 'err');

            return Response::redirect($this->app->url('dashboard'));
        }

        if ($request->wantsJson()) {
            return Response::json([
                'badge' => ['id' => (int) $badge['id'], 'style' => $badge['style'], 'bicolor' => (bool) $badge['bicolor']],
                'remaining' => $this->app->badges->remaining((int) $user['id']),
            ], 201);
        }

        $this->app->flash('Badge ' . $badge['id'] . ' cree.');

        return Response::redirect($this->app->url('dashboard'));
    }

    /** Suppression d une creation, avec le meme garde que la creation : jeton + confirmation. */
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

        $badge = $this->app->badges->badgeById((int) $request->params['id']);

        if ($badge === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Badge introuvable.'], 404)
                : Response::html('Badge introuvable.', 404);
        }

        if ((int) $badge['user_id'] !== (int) $user['id'] && (int) $user['is_admin'] !== 1) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Badge d un autre compte.'], 403)
                : Response::html('Badge d un autre compte.', 403);
        }

        $id = (int) $badge['id'];
        $this->app->badges->deleteBadge((int) $user['id'], $id);

        if ($request->wantsJson()) {
            return Response::json(['deleted' => true, 'id' => $id]);
        }

        $this->app->flash('Badge ' . $id . ' supprime.');

        return Response::redirect($this->app->url('dashboard'));
    }

    public function download(Request $request): Response
    {
        $user = $this->app->currentUser();
        $badge = $this->app->badges->badgeById((int) $request->params['id']);

        if ($badge === null) {
            return Response::json(['error' => 'Badge introuvable.'], 404);
        }

        if ((int) $badge['user_id'] !== (int) ($user['id'] ?? 0) && (int) ($user['is_admin'] ?? 0) !== 1) {
            return Response::json(['error' => 'Badge d un autre compte.'], 403);
        }

        // Le format est une liste fermee : sinon /badges/5/delete passe ici et rend un STL.
        if (!in_array($request->params['format'], ['stl', '3mf'], true)) {
            return Response::json(['error' => 'Format inconnu.'], 404);
        }

        $export = $this->app->badges->export($badge);
        $name = 'qlip-' . $badge['id'];

        if ($request->params['format'] === '3mf') {
            return Response::file($export['threeMf'], $name . '.3mf', 'model/3mf');
        }

        return Response::file($export['stl'], $name . '.stl', 'model/stl');
    }
}
