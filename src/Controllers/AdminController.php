<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;

/**
 * Page d'administration, protegee par YubiKey (WebAuthn/FIDO2).
 *
 * Deux cas :
 *   - compte deja connu (session) : la liste des credentials est proposee au
 *     navigateur, l'assertion doit porter l'une d'entre elles ;
 *   - credential decouvrable (residente, comme une YubiKey 5) : le navigateur
 *     choisit la credential, le serveur la retrouve par son ID.
 *
 * Le challenge est stocke en session et ne vit que pour la ceremony en cours.
 */
final class AdminController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function dashboard(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return Response::redirect($this->app->url('admin/yubikey'));
        }

        if ((int) $user['is_admin'] !== 1) {
            return Response::html('Acces reserve a l administration.', 403);
        }

        if ($this->app->session->get('webauthn_at') === null) {
            return Response::redirect($this->app->url('admin/yubikey'));
        }

        $credentials = array_map(function (array $credential): array {
            $key = $this->app->credentials->algorithm($credential);

            return $credential + ['type' => $key['type'] ?? '-', 'alg' => $key['alg'] ?? '-'];
        }, $this->app->credentials->list((int) $user['id']));

        return Response::html($this->app->view->render('admin.php', [
            'title' => 'Administration',
            'user' => $user,
            'credentials' => $credentials,
            'users' => $this->app->db->all(
                'SELECT u.*,
                    (SELECT COUNT(*) FROM pages p WHERE p.user_id = u.id) AS pages,
                    (SELECT COUNT(*) FROM badges b WHERE b.user_id = u.id) AS badges
                 FROM users u ORDER BY u.id'
            ),
            'pages' => $this->app->db->all(
                'SELECT p.*, u.email AS user_email FROM pages p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 100'
            ),
            'badges' => $this->app->db->all(
                'SELECT b.*, p.slug, u.email AS user_email
                 FROM badges b JOIN pages p ON p.id = b.page_id JOIN users u ON u.id = b.user_id
                 ORDER BY b.id DESC LIMIT 100'
            ),
            'audit' => $this->app->db->all(
                'SELECT a.*, u.email AS user_email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100'
            ),
            'dailyLimit' => $this->app->config->dailyLimit,
            'usedToday' => $this->app->db->count(
                'SELECT COUNT(*) FROM badges WHERE substr(created_at, 1, 10) = :day',
                [':day' => today()]
            ),
            'sessionAt' => (string) $this->app->session->get('webauthn_at'),
            'csrf' => $this->app->session->token(),
            'challenge' => $this->app->challenge(),
            'rpId' => $this->app->config->rpId,
            'flash' => $this->app->takeFlash(),
        ]));
    }

    public function yubikey(Request $request): Response
    {
        $user = $this->app->currentUser();

        return Response::html($this->app->view->render('yubikey.php', [
            'title' => 'YubiKey',
            'user' => $user,
            'credentials' => $user === null ? [] : $this->app->credentials->list((int) $user['id']),
            'csrf' => $this->app->session->token(),
            'challenge' => $this->app->challenge(),
            'rpId' => $this->app->config->rpId,
            'flash' => $this->app->takeFlash(),
        ]));
    }

    /** Challenge + params pour le navigateur (JSON). */
    public function challenge(Request $request): Response
    {
        return Response::json([
            'challenge' => $this->app->challenge(),
            'rpId' => $this->app->config->rpId,
            'allowCredentials' => array_map(static fn (array $credential): array => [
                'id' => $credential['credential_id'],
                'type' => 'public-key',
            ], $this->credentialsFor($request)),
        ]);
    }

    /** Enregistrement d'une credential (YubiKey) pour le compte connecte. */
    public function register(Request $request): Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Connexion requise.'], 401);
        }

        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::json(['error' => 'Jeton CSRF invalide.'], 400);
        }

        try {
            $credential = $this->app->credentials->register(
                (int) $user['id'],
                $request->input('attestation'),
                $request->input('client_data'),
                $this->app->challenge()
            );
        } catch (\Throwable $error) {
            return Response::json(['error' => $error->getMessage()], 400);
        }

        $this->app->users->audit((int) $user['id'], 'webauthn_register', $credential['credential_id']);

        return Response::json(['ok' => true, 'credential' => $credential], 201);
    }

    /** Assertion : la YubiKey prouve la possession de la cle. */
    public function assert(Request $request): Response
    {
        if (!$this->app->session->checkToken($request->input('csrf'))) {
            return Response::json(['error' => 'Jeton CSRF invalide.'], 400);
        }

        $credentialId = $request->input('credential_id');
        $user = $this->app->currentUser();

        if ($user === null) {
            // Credential decouvrable : on retrouve le compte par l'ID de credential.
            $stored = $this->app->db->one(
                'SELECT * FROM webauthn_credentials WHERE credential_id = :credential_id LIMIT 1',
                [':credential_id' => $credentialId]
            );

            if ($stored === null) {
                return Response::json(['error' => 'Credential inconnue.'], 403);
            }

            $user = $this->app->users->find((int) $stored['user_id']);

            if ($user === null || (int) $user['is_admin'] !== 1) {
                return Response::json(['error' => 'Credential d un compte non administrateur.'], 403);
            }

            $storedCredential = $stored;
        } else {
            if ((int) $user['is_admin'] !== 1) {
                return Response::json(['error' => 'Acces reserve a l administration.'], 403);
            }

            $storedCredential = $this->app->db->one(
                'SELECT * FROM webauthn_credentials WHERE user_id = :user_id AND credential_id = :credential_id LIMIT 1',
                [':user_id' => (int) $user['id'], ':credential_id' => $credentialId]
            );

            if ($storedCredential === null) {
                return Response::json(['error' => 'Credential inconnue pour ce compte.'], 403);
            }
        }

        try {
            $verified = $this->app->credentials->assert(
                $storedCredential,
                $request->input('auth_data'),
                $request->input('client_data'),
                $request->input('signature'),
                $this->app->challenge()
            );
        } catch (\Throwable $error) {
            $this->app->users->audit((int) $storedCredential['user_id'], 'webauthn_assert_failed', $error->getMessage());

            return Response::json(['error' => $error->getMessage()], 400);
        }

        $this->app->session->regenerate();
        $this->app->session->set('user_id', (int) $storedCredential['user_id']);
        $this->app->session->set('webauthn_at', now());

        $this->app->users->audit((int) $storedCredential['user_id'], 'webauthn_assert', $verified['credential_id']);

        return Response::json(['ok' => true, 'redirect' => $this->app->url('admin')]);
    }

    /** @return list<array<string, mixed>> */
    private function credentialsFor(Request $request): array
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            $credentialId = $request->input('credential_id');

            if ($credentialId !== '') {
                $stored = $this->app->db->one(
                    'SELECT * FROM webauthn_credentials WHERE credential_id = :credential_id LIMIT 1',
                    [':credential_id' => $credentialId]
                );

                return $stored === null ? [] : [$stored];
            }

            return $this->app->db->all(
                'SELECT c.* FROM webauthn_credentials c JOIN users u ON u.id = c.user_id WHERE u.is_admin = 1 ORDER BY c.id',
            );
        }

        return $this->app->credentials->list((int) $user['id']);
    }
}
