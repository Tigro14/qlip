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

            'CREATE TABLE IF NOT EXISTS webauthn_credentials (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                credential_id TEXT NOT NULL,
                cose TEXT NOT NULL,
                sign_count INTEGER NOT NULL DEFAULT 0,
                aaguid TEXT,
                created_at TEXT NOT NULL
            )',

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
