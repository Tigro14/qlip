<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;
use Qlip\Support\Pager;

/**
 * Journal des evenements (audit_log) : lecture par page et effacement de ce qui est plus
 * vieux que la retention.
 */
final class Journal
{
    /** Duree de garde. Au dela, les lignes peuvent etre effacees depuis l administration. */
    public const RETENTION_MONTHS = 2;

    /** Le nom de l action est distinct des actions de suppression : le quota du jour compte
        les creations de badge dans le journal, une ligne d effacement ne doit pas entrer dans
        ce comptage. */
    public const ACTION = 'journal_efface';

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Date la plus ancienne que le journal doit garder, au format des lignes (`Y-m-d H:i:s`) :
     * une ligne est effaceable quand sa date est strictement avant.
     */
    public function cutoff(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_MONTHS . ' months'));
    }

    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM audit_log');
    }

    /** Lignes deja effacables, donc plus vieilles que la retention. */
    public function oldCount(string $before): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM audit_log WHERE created_at < :before',
            [':before' => $before]
        );
    }

    /**
     * Lignes d une page, les plus recentes d abord. L adresse est garde avec la ligne quand le
     * compte existe encore : une ligne survit a son compte (`ON DELETE SET NULL`).
     *
     * @return list<array<string, mixed>>
     */
    public function page(Pager $pager): array
    {
        return $this->db->all(
            'SELECT a.*, u.email AS user_email
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT :limit OFFSET :offset',
            [':limit' => Pager::PER_PAGE, ':offset' => $pager->offset()]
        );
    }

    /**
     * Effacement des lignes plus vieilles que la date, avec la trace. La trace est ecrite apres
     * le DELETE : la ligne qui consigne l effacement ne peut pas etre effacee par l effacement,
     * et elle doit dire combien de lignes sont parties.
     */
    public function purge(int $userId, string $before): int
    {
        $deleted = $this->db->exec(
            'DELETE FROM audit_log WHERE created_at < :before',
            [':before' => $before]
        )->rowCount();

        $this->audit($userId, self::ACTION, 'effacement du journal avant le ' . $before . ' : ' . $deleted);

        return $deleted;
    }

    public function audit(int $userId, string $action, string $detail): void
    {
        $this->db->exec(
            'INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, :action, :detail, :created_at)',
            [':user_id' => $userId, ':action' => $action, ':detail' => $detail, ':created_at' => now()]
        );
    }
}
