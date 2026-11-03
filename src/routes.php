<?php

declare(strict_types=1);

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\App\Router;
use Qlip\Controllers\AdminController;
use Qlip\Controllers\AuthController;
use Qlip\Controllers\BadgeController;
use Qlip\Controllers\PageController;

/**
 * Table des routes, separee du front controller pour que les tests puissent la
 * parcourir sans serveur HTTP.
 */
function qlip_routes(App $app): Router
{
    $auth = new AuthController($app);
    $pages = new PageController($app);
    $badges = new BadgeController($app);
    $admin = new AdminController($app);

    $router = new Router();

    $router->get('/', fn (Request $request): Response => $pages->home($request));

    $router->get('/register', fn (Request $request): Response => $auth->showRegister($request));
    $router->post('/register', fn (Request $request): Response => $auth->register($request));
    $router->get('/verify/{token}', fn (Request $request): Response => $auth->verify($request));

    $router->get('/password', fn (Request $request): Response => $auth->showForgot($request));
    $router->post('/password', fn (Request $request): Response => $auth->forgot($request));
    $router->get('/password/reset/{token}', fn (Request $request): Response => $auth->showReset($request));
    $router->post('/password/reset/{token}', fn (Request $request): Response => $auth->reset($request));

    $router->get('/login', fn (Request $request): Response => $auth->showLogin($request));
    $router->post('/login', fn (Request $request): Response => $auth->login($request));
    $router->post('/logout', fn (Request $request): Response => $auth->logout($request));

    $router->get('/dashboard', fn (Request $request): Response => $pages->dashboard($request));
    $router->post('/pages', fn (Request $request): Response => $pages->create($request));
    $router->get('/p/{slug}', fn (Request $request): Response => $pages->show($request));
    $router->post('/p/{slug}', fn (Request $request): Response => $pages->update($request));
    $router->post('/p/{slug}/delete', fn (Request $request): Response => $pages->delete($request));
    $router->get('/qr/{slug}.png', fn (Request $request): Response => $pages->qr($request));

    $router->post('/badges', fn (Request $request): Response => $badges->create($request));
    $router->post('/badges/{id}/delete', fn (Request $request): Response => $badges->delete($request));
    $router->get('/badges/{id}/{format}', fn (Request $request): Response => $badges->download($request));

    $router->post('/account/delete', fn (Request $request): Response => $auth->deleteAccount($request));

    $router->get('/admin', fn (Request $request): Response => $admin->dashboard($request));
    $router->get('/admin/yubikey', fn (Request $request): Response => $admin->yubikey($request));
    $router->get('/webauthn/challenge', fn (Request $request): Response => $admin->challenge($request));
    $router->post('/webauthn/register', fn (Request $request): Response => $admin->register($request));
    $router->post('/webauthn/assert', fn (Request $request): Response => $admin->assert($request));

    $router->get('/health', fn (Request $request): Response => Response::json(['ok' => true, 'time' => now()]));

    return $router;
}
