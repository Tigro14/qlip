<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;

final class Users
{
    private const MIN_PASSWORD = 10;

    /** Validite d un jeton de reinitialisation : 24 h, usage unique. */
    private const RESET_TTL = 86400;

    private Db $db;
    private Mailer $mailer;
    private string $baseUrl;

    public function __construct(Db $db, Mailer $mailer, string $baseUrl)
    {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->baseUrl = $baseUrl;
    }

    /**
     * Inscription : un compte est cree non verifie, un token de verification est
     * gene et envoyé par email. Tant que l'email n'est pas verifie, le compte ne
     * peut rien creer.
     *
     * @return array{user: array<string, mixed>, token: string, link: string, mail: array<string, string>}
     */
    public function register(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        $this->checkPassword($password);

        $existing = $this->findByEmail($email);

        if ($existing !== null) {
            throw new \RuntimeException('Cette adresse est deja utilisee.');
        }

        $this->db->exec(
            'INSERT INTO users (email, password_hash, is_admin, created_at) VALUES (:email, :hash, :admin, :created_at)',
            [
                ':email' => $email,
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':admin' => $this->isAdminEmail($email) ? 1 : 0,
                ':created_at' => now(),
            ]
        );

        $user = $this->findByEmail($email);
        $token = random_token(32);

        $this->db->exec(
            'INSERT INTO verify_tokens (user_id, token, created_at) VALUES (:user_id, :token, :created_at)',
            [':user_id' => $user['id'], ':token' => $token, ':created_at' => now()]
        );

        $link = $this->baseUrl . '/verify/' . $token;

        $mail = $this->mailer->send(
            $email,
            'Verifie ton adresse email (Qlip)',
            "Clique sur ce lien pour activer ton compte :\n\n{$link}\n\nLe lien est valable 7 jours."
        );

        $this->audit((int) $user['id'], 'register', $email);

        return ['user' => $user, 'token' => $token, 'link' => $link, 'mail' => $mail];
    }

    /** @return array<string, mixed>|null */
    public function verifyToken(string $token): ?array
    {
        $row = $this->db->one('SELECT * FROM verify_tokens WHERE token = :token LIMIT 1', [':token' => $token]);

        if ($row === null) {
            return null;
        }

        if ($row['used_at'] !== null) {
            return null;
        }

        if (strtotime((string) $row['created_at']) < time() - 7 * 86400) {
            return null;
        }

        $this->db->exec('UPDATE verify_tokens SET used_at = :used_at WHERE id = :id', [':used_at' => now(), ':id' => $row['id']]);
        $this->db->exec('UPDATE users SET verified_at = :verified_at WHERE id = :id', [':verified_at' => now(), ':id' => $row['user_id']]);

        $user = $this->find((int) $row['user_id']);
        $this->audit((int) $row['user_id'], 'verify', (string) $user['email']);

        return $user;
    }

    /**
     * Demande de reinitialisation : un jeton est gene et envoye par email.
     *
     * La reponse ne dit pas si l'adresse a un compte : elle est la meme dans les deux cas,
     * sinon le formulaire servirait a dresser la liste des comptes. Avec le transport
     * outbox le lien est quand meme affiche, parce que c'est un transport de developpement
     * (les tests en ont besoin) : il ne cache pas l'existence du compte.
     *
     * @return array{email: string, user: array<string, mixed>|null, token: string, link: string, mail: array<string, string>|null}
     */
    public function requestReset(string $email): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        $user = $this->findByEmail($email);

        if ($user === null) {
            // Rien n'est envoye, mais la reponse est identique : le compte n'existe pas,
            // et la trace le dit avec l'adresse dans le detail (user_id reste NULL).
            $this->audit(null, 'reset_request', 'demande pour une adresse sans compte : ' . $email);

            return ['email' => $email, 'user' => null, 'token' => '', 'link' => '', 'mail' => null];
        }

        $token = random_token(32);

        $this->db->exec(
            'INSERT INTO reset_tokens (user_id, token, created_at) VALUES (:user_id, :token, :created_at)',
            [':user_id' => (int) $user['id'], ':token' => $token, ':created_at' => now()]
        );

        $link = $this->baseUrl . '/password/reset/' . $token;

        $mail = $this->mailer->send(
            $email,
            'Reinitialisation du mot de passe (Qlip)',
            "Clique sur ce lien pour definir un nouveau mot de passe :\n\n{$link}\n\n"
                . "Le lien est valable 24 h et ne peut etre utilise qu'une fois. "
                . "Si tu n'as pas demande cette reinitialisation, ignore le message : le mot de passe ne change pas."
        );

        $this->audit((int) $user['id'], 'reset_request', 'demande de reinitialisation');

        return ['email' => $email, 'user' => $user, 'token' => $token, 'link' => $link, 'mail' => $mail];
    }

    /**
     * Jeton de reinitialisation, controle : usage unique et 24 h.
     *
     * @return array<string, mixed>|null
     */
    public function resetToken(string $token): ?array
    {
        $row = $this->db->one('SELECT * FROM reset_tokens WHERE token = :token LIMIT 1', [':token' => $token]);

        if ($row === null || $row['used_at'] !== null) {
            return null;
        }

        if (strtotime((string) $row['created_at']) < time() - self::RESET_TTL) {
            return null;
        }

        return $row;
    }

    /**
     * Nouveau mot de passe. Le jeton est consomme, et tous les jetons du compte sont fermes :
     * un lien envoye la semaine precedente ne doit plus pouvoir changer le mot de passe.
     *
     * Le lien est envoye a la boite et usage unique : s'il est utilise, le controle de la
     * boite est prouve, ce qui vaut exactement la verification d'email. Un compte encore
     * non active est donc active par la reinitialisation, sinon il n'aurait plus aucun moyen
     * d'entrer (le lien de verification est usage unique lui aussi).
     *
     * @return array<string, mixed>|null
     */
    public function resetPassword(string $token, string $password): ?array
    {
        $row = $this->resetToken($token);

        if ($row === null) {
            return null;
        }

        $this->checkPassword($password);

        $user = $this->find((int) $row['user_id']);

        if ($user === null) {
            return null;
        }

        $this->db->exec(
            'UPDATE users SET password_hash = :hash, verified_at = COALESCE(verified_at, :verified_at) WHERE id = :id',
            [
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':verified_at' => now(),
                ':id' => (int) $user['id'],
            ]
        );

        $this->db->exec(
            'UPDATE reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL',
            [':used_at' => now(), ':user_id' => (int) $user['id']]
        );

        $this->audit((int) $user['id'], 'reset', 'mot de passe change par le lien de reinitialisation');

        return $this->find((int) $user['id']);
    }

    /**
     * Connexion. Le compte doit etre verifie.
     *
     * @return array<string, mixed>|null
     */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->findByEmail(strtolower(trim($email)));

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if ($user['verified_at'] === null) {
            return null;
        }

        return $user;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = :email', [':email' => strtolower(trim($email))]);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->all('SELECT * FROM users ORDER BY id');
    }

    /**
     * Suppression du compte, et de tout ce qui est lie (ON DELETE CASCADE : pages, badges,
     * credentials, jetons de verification). Rien ne revient, donc le mot de passe est
     * verifie avant par le controleur.
     *
     * La ligne d audit est ecrite apres le DELETE, avec l adresse dans le detail : user_id
     * devient NULL quand le compte part (ON DELETE SET NULL), et la trace doit survivre au
     * compte.
     *
     * @return array{email: string, pages: int, badges: int}
     */
    public function deleteAccount(int $userId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            throw new \RuntimeException('Compte introuvable.');
        }

        $counts = [
            'pages' => $this->db->count('SELECT COUNT(*) FROM pages WHERE user_id = :user_id', [':user_id' => $userId]),
            'badges' => $this->db->count('SELECT COUNT(*) FROM badges WHERE user_id = :user_id', [':user_id' => $userId]),
        ];

        $this->db->exec('DELETE FROM users WHERE id = :id', [':id' => $userId]);

        $this->audit(
            null,
            'compte_supprime',
            'compte ' . $user['email'] . ' supprime (' . $counts['pages'] . ' pages, ' . $counts['badges'] . ' badges)'
        );

        return ['email' => (string) $user['email'], 'pages' => (int) $counts['pages'], 'badges' => (int) $counts['badges']];
    }

    public function isAdminEmail(string $email): bool
    {
        $list = array_map('trim', explode(',', \env('QLIP_ADMIN_EMAILS')));

        return in_array(strtolower(trim($email)), $list, true);
    }

    /** Le controle de taille se fait cote serveur : le minlength d un formulaire n est pas une protection. */
    private function checkPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Mot de passe trop court (' . self::MIN_PASSWORD . ' caracteres minimum).');
        }
    }

    public function audit(?int $userId, string $action, string $detail = ''): void
    {
        $this->db->exec(
            'INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, :action, :detail, :created_at)',
            [':user_id' => $userId, ':action' => $action, ':detail' => $detail, ':created_at' => now()]
        );
    }
}
