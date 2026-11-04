<?php

declare(strict_types=1);

namespace Qlip;

use PDO;
use PDOStatement;

/**
 * Acces SQLite. Le schema est cree au premier demarrage (SQL simple, pas de
 * framework de migration : les colonnes existantes ne sont jamais modifiees,
 * seulement ajoutees).
 */
final class Db
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string, mixed> $params */
    public function exec(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->exec($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->exec($sql, $params)->fetchAll();
    }

    public function count(string $sql, array $params = []): int
    {
        return (int) $this->exec($sql, $params)->fetchColumn();
    }

    private function migrate(): void
    {
        foreach (self::statements() as $sql) {
            $this->pdo->exec($sql);
        }

        $this->oneBadgePerPage();

        // Colonnes ajoutees apres la premiere version du schema : une base existante
        // n'est jamais reconstruite, seulement completee. La politique par credential, la
        // trace de la premiere verification utilisateur et le format d'attestation sont lus
        // par Verifier a chaque assertion, donc une base creee avant doit les avoir.
        $columns = $this->columns('webauthn_credentials');

        if (!in_array('policy', $columns, true)) {
            $this->pdo->exec("ALTER TABLE webauthn_credentials ADD COLUMN policy TEXT NOT NULL DEFAULT 'required'");
        }

        if (!in_array('uv_seen', $columns, true)) {
            $this->pdo->exec('ALTER TABLE webauthn_credentials ADD COLUMN uv_seen INTEGER NOT NULL DEFAULT 0');
        }

        // Le format d'attestation enregistre avec la credential porte la base de signature de
        // l'assertion : une cle U2F seule signe la base U2F, une autre cle la base WebAuthn.
        // Sans cette colonne, la base ne peut pas etre choisie pour une credential deja en base.
        if (!in_array('fmt', $columns, true)) {
            $this->pdo->exec("ALTER TABLE webauthn_credentials ADD COLUMN fmt TEXT NOT NULL DEFAULT 'none'");
        }
    }

    /**
     * Noms des colonnes d'une table. Le nom de la table vient du schema, jamais d'une
     * requete : PRAGMA ne prend pas de parametre prepare.
     *
     * @return list<string>
     */
    private function columns(string $table): array
    {
        return array_column($this->all('PRAGMA table_info(' . $table . ')'), 'name');
    }

    /**
     * Une page porte un seul badge. Le QR encode le slug de la page, donc deux badges sur la
     * meme page sont le meme QR montre deux fois : l onglet du tableau de bord est la page.
     *
     * Une base creee avant cette regle peut avoir plusieurs badges pour une page. On garde le
     * plus recent (le plus grand id, l ordre d insertion), celui dont l onglet montre le design,
     * et on retire les autres. C est une suppression definitive, donc la trace est ecrite : le
     * nom de l action est distinct pour ne pas compter ces lignes dans le quota de creations, et
     * le detail dit que la ligne vient de la migration et non d une route. L index unique ne peut
     * etre pose qu apres ce tri.
     */
    private function oneBadgePerPage(): void
    {
        $duplicates = $this->all(
            'SELECT b.id, b.user_id, p.slug FROM badges b JOIN pages p ON p.id = b.page_id
             WHERE b.id NOT IN (SELECT MAX(id) FROM badges GROUP BY page_id)
             ORDER BY b.id',
            []
        );

        if ($duplicates === []) {
            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS badges_page_unique ON badges(page_id)');

            return;
        }

        $this->pdo->beginTransaction();

        try {
            foreach ($duplicates as $row) {
                $this->exec('DELETE FROM badges WHERE id = :id', [':id' => (int) $row['id']]);

                $this->exec(
                    'INSERT INTO audit_log (user_id, action, detail, created_at)
                     VALUES (:user_id, :action, :detail, :created_at)',
                    [
                        ':user_id' => (int) $row['user_id'],
                        ':action' => 'badge_doublon_supprime',
                        ':detail' => 'badge ' . (int) $row['id'] . ' page ' . $row['slug']
                            . ' (migration une page = un badge)',
                        ':created_at' => \now(),
                    ]
                );
            }

            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS badges_page_unique ON badges(page_id)');

            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();

            throw $error;
        }
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                verified_at TEXT,
                created_at TEXT NOT NULL
            )',

            'CREATE TABLE IF NOT EXISTS verify_tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL,
                used_at TEXT
            )',

            'CREATE TABLE IF NOT EXISTS reset_tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL,
                used_at TEXT
            )',

            "CREATE TABLE IF NOT EXISTS webauthn_credentials (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                credential_id TEXT NOT NULL,
                cose TEXT NOT NULL,
                sign_count INTEGER NOT NULL DEFAULT 0,
                aaguid TEXT,
                policy TEXT NOT NULL DEFAULT 'required',
                uv_seen INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",

            'CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                phone TEXT,
                email_contact TEXT,
                note TEXT,
                created_at TEXT NOT NULL
            )',

            'CREATE TABLE IF NOT EXISTS badges (
                id INTEGER PRIMARY KEY,
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                style TEXT NOT NULL,
                bicolor INTEGER NOT NULL DEFAULT 0,
                design TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',

            'CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY,
                user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                action TEXT NOT NULL,
                detail TEXT,
                created_at TEXT NOT NULL
            )',
        ];
    }
}
