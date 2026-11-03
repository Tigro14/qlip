<?php

declare(strict_types=1);

namespace Qlip\Controllers;

use Qlip\App\App;
use Qlip\App\Request;
use Qlip\App\Response;
use Qlip\Services\Journal;
use Qlip\Support\Pager;
use Qlip\Webauthn\Policy;

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

        // Listes paginatees : ce qui depasse la limite ne doit pas disparaitre en silence. Le
        // nombre de pages vient du COUNT, pas des lignes lues, sinon la derniere page ne serait
        // jamais proposee quand elle est pleine. Chaque liste a son propre numero, donc une
        // liste ne deplace pas les autres sur la meme page.
        $usersPager = new Pager('users_page', $this->app->db->count('SELECT COUNT(*) FROM users'), (int) $request->input('users_page'));
        $credentialsPager = new Pager('credentials_page', $this->app->db->count('SELECT COUNT(*) FROM webauthn_credentials'), (int) $request->input('credentials_page'));
        $pagesPager = new Pager('pages_page', $this->app->db->count('SELECT COUNT(*) FROM pages'), (int) $request->input('pages_page'));
        $badgesPager = new Pager('badges_page', $this->app->db->count('SELECT COUNT(*) FROM badges'), (int) $request->input('badges_page'));
        $journalPager = new Pager('journal_page', $this->app->journal->count(), (int) $request->input('journal_page'));

        $cutoff = $this->app->journal->cutoff();

        return Response::html($this->app->view->render('admin.php', [
            'title' => 'Administration',
            'user' => $user,
            'credentials' => $credentials,
            'users' => $this->app->db->all(
                'SELECT u.*,
                    (SELECT COUNT(*) FROM pages p WHERE p.user_id = u.id) AS pages,
                    (SELECT COUNT(*) FROM badges b WHERE b.user_id = u.id) AS badges,
                    (SELECT COUNT(*) FROM webauthn_credentials c WHERE c.user_id = u.id) AS credentials
                 FROM users u ORDER BY u.id LIMIT :limit OFFSET :offset',
                [':limit' => Pager::PER_PAGE, ':offset' => $usersPager->offset()]
            ),
            'usersPager' => $usersPager,
            'keys' => $this->app->db->all(
                'SELECT c.*, u.email AS user_email
                 FROM webauthn_credentials c JOIN users u ON u.id = c.user_id
                 ORDER BY c.id LIMIT :limit OFFSET :offset',
                [':limit' => Pager::PER_PAGE, ':offset' => $credentialsPager->offset()]
            ),
            'credentialsPager' => $credentialsPager,
            'pages' => $this->app->db->all(
                'SELECT p.*, u.email AS user_email
                 FROM pages p JOIN users u ON u.id = p.user_id
                 ORDER BY p.id DESC LIMIT :limit OFFSET :offset',
                [':limit' => Pager::PER_PAGE, ':offset' => $pagesPager->offset()]
            ),
            'pagesPager' => $pagesPager,
            'badges' => $this->app->db->all(
                'SELECT b.*, p.slug, u.email AS user_email
                 FROM badges b JOIN pages p ON p.id = b.page_id JOIN users u ON u.id = b.user_id
                 ORDER BY b.id DESC LIMIT :limit OFFSET :offset',
                [':limit' => Pager::PER_PAGE, ':offset' => $badgesPager->offset()]
            ),
            'badgesPager' => $badgesPager,
            'audit' => $this->app->journal->page($journalPager),
            'journalPager' => $journalPager,
            'retention' => Journal::RETENTION_MONTHS,
            'cutoff' => $cutoff,
            'oldLines' => $this->app->journal->oldCount($cutoff),
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

    /**
     * Effacement du journal plus vieux que la retention. Suppression definitive, dans le meme
     * style que les autres : POST seulement, jeton CSRF, confirmation explicite et mot de passe
     * du compte, parce que rien ne revient et qu une session laissee ouverte ne doit pas
     * permettre de detruire la trace. La garde est la meme que pour les autres suppressions de
     * l administration (voir guard()).
     *
     * La trace est ecrite par le service, apres le DELETE : la ligne qui consigne l effacement
     * ne peut pas etre effacee par l effacement.
     */
    public function purgeJournal(Request $request): Response
    {
        $guard = $this->guard($request);

        if ($guard !== null) {
            return $guard;
        }

        $user = $this->app->currentUser();
        $before = $this->app->journal->cutoff();
        $deleted = $this->app->journal->purge((int) $user['id'], $before);

        if ($request->wantsJson()) {
            return Response::json(['deleted' => true, 'rows' => $deleted, 'before' => $before]);
        }

        $this->app->flash($deleted . ' lignes du journal effacees (avant le ' . $before . ').');

        return Response::redirect($this->app->url('admin'));
    }

    /**
     * Suppression definitive d un compte. Le controle d appartenance est le proprietaire, ou
     * l administrateur : il peut detruire ce qu il peut lire. Le mot de passe demande est celui
     * du compte qui agit, pas celui du compte supprime : on ne peut pas le connaitre, et il prouve
     * que la session n est pas une session laissee ouverte.
     *
     * Le compte emporte ses pages, ses badges, ses credentials et ses jetons. La trace est ecrite
     * apres le DELETE, avec l adresse dans le detail, parce que audit_log.user_id devient NULL.
     */
    public function deleteUser(Request $request): Response
    {
        $guard = $this->guard($request);

        if ($guard !== null) {
            return $guard;
        }

        $user = $this->app->currentUser();
        $id = (int) $request->params['id'];

        try {
            $counts = $this->app->users->deleteAccount($id);
        } catch (\Throwable $error) {
            return $request->wantsJson()
                ? Response::json(['error' => $error->getMessage()], 404)
                : Response::html($error->getMessage(), 404);
        }

        // Le compte n existe plus : la session ne peut plus y pointer, y compris quand
        // l administrateur supprime son propre compte.
        if ($id === (int) $user['id']) {
            $this->app->session->destroy();
        }

        if ($request->wantsJson()) {
            return Response::json([
                'deleted' => true,
                'id' => $id,
                'pages' => $counts['pages'],
                'badges' => $counts['badges'],
            ]);
        }

        $this->app->flash('Compte ' . $counts['email'] . ' supprime (' . $counts['pages'] . ' pages, ' . $counts['badges'] . ' badges).');

        return Response::redirect($this->app->url($id === (int) $user['id'] ? 'login' : 'admin'));
    }

    /**
     * Cle 2FA supprimee par l administrateur, dans le meme style que la suppression du compte.
     *
     * Enlever la cle remet le compte au seul mot de passe. La session garde la credential qui l a
     * confirmee : supprimer cette cle emporte la confirmation, donc la page d administration
     * redemande une assertion ; supprimer une autre cle du meme compte ne la change pas.
     */
    public function deleteCredential(Request $request): Response
    {
        $guard = $this->guard($request);

        if ($guard !== null) {
            return $guard;
        }

        $user = $this->app->currentUser();
        $credential = $this->app->credentials->find((int) $request->params['id']);

        if ($credential === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Credential introuvable.'], 404)
                : Response::html('Credential introuvable.', 404);
        }

        // La trace pointe sur le proprietaire de la cle, pas sur l administrateur qui agit.
        $this->app->credentials->delete((int) $credential['user_id'], (int) $credential['id']);

        if ((string) $this->app->session->get('webauthn_credential', '') === (string) $credential['credential_id']) {
            $this->app->session->remove('webauthn_at');
            $this->app->session->remove('webauthn_credential');
        }

        if ($request->wantsJson()) {
            return Response::json(['deleted' => true, 'id' => (int) $credential['id']]);
        }

        $this->app->flash('Credential ' . $credential['credential_id'] . ' supprimee. Le compte se reconnecte avec le seul mot de passe.');

        return Response::redirect($this->app->url('admin'));
    }

    /**
     * Garde des suppressions definitives de l administration, la meme pour toutes : POST seulement
     * (le routeur refuse un GET sur ces routes), jeton CSRF, confirmation explicite, mot de passe,
     * et session confirmee par une assertion comme le reste de la page.
     *
     * @return Response|null La reponse quand la garde refuse, rien quand elle passe.
     */
    private function guard(Request $request): ?Response
    {
        $user = $this->app->currentUser();

        if ($user === null) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Connexion requise.'], 401)
                : Response::html('Connexion requise.', 401);
        }

        if ((int) $user['is_admin'] !== 1) {
            return $request->wantsJson()
                ? Response::json(['error' => 'Acces reserve a l administration.'], 403)
                : Response::html('Acces reserve a l administration.', 403);
        }

        if ($this->app->session->get('webauthn_at') === null) {
            return Response::redirect($this->app->url('admin/yubikey'));
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

        return null;
    }

    public function yubikey(Request $request): Response
    {
        // Ceremonie passkey : la cle est le seul moyen d'identifier l'utilisateur sur la
        // page d'administration, donc la verification utilisateur reste exigee.
        $this->app->ceremonyPolicy(Policy::PROFILE_PASSKEY);

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
            // La politique vient du serveur : le navigateur ne la choisit pas.
            'policy' => $this->app->ceremonyPolicy(),
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
                $this->app->challenge(),
                $this->app->ceremonyPolicy()
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
                $this->app->challenge(),
                $this->app->ceremonyPolicy()
            );
        } catch (\Throwable $error) {
            $this->app->users->audit((int) $storedCredential['user_id'], 'webauthn_assert_failed', $error->getMessage());

            return Response::json(['error' => $error->getMessage()], 400);
        }

        $this->app->session->regenerate();
        $this->app->session->set('user_id', (int) $storedCredential['user_id']);
        $this->app->session->set('webauthn_at', now());

        // La session garde la credential qui l'a confirmee : la supprimer enleve la
        // confirmation, supprimer une autre cle du meme compte ne la change pas.
        $this->app->session->set('webauthn_credential', (string) $storedCredential['credential_id']);

        $this->app->users->audit((int) $storedCredential['user_id'], 'webauthn_assert', $verified['credential_id']);

        return Response::json(['ok' => true, 'redirect' => $this->app->url('admin')]);
    }

    /** @return list<array<string, mixed>> */
    private function credentialsFor(Request $request): array
    {
        // Session en attente de second facteur : la liste vient du compte en attente, une
        // cle U2F ne stocke pas la credential en resident et ne peut pas retrouver son
        // identifiant seule.
        $pending = $this->app->pendingUser();

        if ($pending !== null) {
            return $this->app->credentials->list((int) $pending['id']);
        }

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
