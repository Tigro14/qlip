<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;

final class Users
{
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

        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('Mot de passe trop court (10 caracteres minimum).');
        }

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

    public function isAdminEmail(string $email): bool
    {
        $list = array_map('trim', explode(',', \env('QLIP_ADMIN_EMAILS')));

        return in_array(strtolower(trim($email)), $list, true);
    }

    public function audit(?int $userId, string $action, string $detail = ''): void
    {
        $this->db->exec(
            'INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, :action, :detail, :created_at)',
            [':user_id' => $userId, ':action' => $action, ':detail' => $detail, ':created_at' => now()]
        );
    }
}
