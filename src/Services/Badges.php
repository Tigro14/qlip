<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Db;
use Qlip\Qr\QrCode;
use Qlip\Qr\Renderer;
use Qlip\Stl\Badge;
use Qlip\Stl\Design;
use Qlip\Stl\StlWriter;
use Qlip\Stl\ThreeMfWriter;

/**
 * Pages de contact et badges. Le quota de 10 creations par jour et par compte
 * porte sur les badges (les fichiers STL/3MF), pas sur les pages.
 */
final class Badges
{
    private Db $db;
    private int $dailyLimit;
    private string $baseUrl;

    public function __construct(Db $db, int $dailyLimit, string $baseUrl)
    {
        $this->db = $db;
        $this->dailyLimit = $dailyLimit;
        $this->baseUrl = $baseUrl;
    }

    /** @param array<string, mixed> $input */
    public function createPage(int $userId, array $input): array
    {
        $slug = $this->uniqueSlug((string) ($input['slug'] ?? ''));

        $this->db->exec(
            'INSERT INTO pages (slug, user_id, title, phone, email_contact, note, created_at)
             VALUES (:slug, :user_id, :title, :phone, :email_contact, :note, :created_at)',
            [
                ':slug' => $slug,
                ':user_id' => $userId,
                ':title' => trim((string) ($input['title'] ?? 'Objet trouve')),
                ':phone' => trim((string) ($input['phone'] ?? '')),
                ':email_contact' => trim((string) ($input['email_contact'] ?? '')),
                ':note' => trim((string) ($input['note'] ?? '')),
                ':created_at' => now(),
            ]
        );

        return $this->pageBySlug($slug);
    }

    /** @param array<string, mixed> $input */
    public function updatePage(int $pageId, array $input): array
    {
        $this->db->exec(
            'UPDATE pages SET title = :title, phone = :phone, email_contact = :email_contact, note = :note WHERE id = :id',
            [
                ':title' => trim((string) ($input['title'] ?? '')),
                ':phone' => trim((string) ($input['phone'] ?? '')),
                ':email_contact' => trim((string) ($input['email_contact'] ?? '')),
                ':note' => trim((string) ($input['note'] ?? '')),
                ':id' => $pageId,
            ]
        );

        return $this->pageById($pageId);
    }

    /** @return array<string, mixed>|null */
    public function pageBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE slug = :slug', [':slug' => $slug]);
    }

    /** @return array<string, mixed>|null */
    public function pageById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE id = :id', [':id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function pagesOf(int $userId): array
    {
        return $this->db->all('SELECT * FROM pages WHERE user_id = :user_id ORDER BY id DESC', [':user_id' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function badgesOf(int $userId): array
    {
        return $this->db->all(
            'SELECT b.*, p.slug FROM badges b JOIN pages p ON p.id = b.page_id
             WHERE b.user_id = :user_id ORDER BY b.id DESC',
            [':user_id' => $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function badgeById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM badges WHERE id = :id', [':id' => $id]);
    }

    /** Le texte encode dans le QR. */
    public function qrText(array $page): string
    {
        return $this->baseUrl . '/p/' . $page['slug'];
    }

    public function usedToday(int $userId): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM badges WHERE user_id = :user_id AND substr(created_at, 1, 10) = :day',
            [':user_id' => $userId, ':day' => today()]
        );
    }

    public function remaining(int $userId): int
    {
        return max(0, $this->dailyLimit - $this->usedToday($userId));
    }

    /**
     * Creation d'un badge (quota applique).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBadge(int $userId, int $pageId, array $input): array
    {
        if ($this->remaining($userId) <= 0) {
            throw new \RuntimeException('Quota atteint : ' . $this->dailyLimit . ' creations par jour.');
        }

        $page = $this->pageById($pageId);

        if ($page === null) {
            throw new \RuntimeException('Page de contact introuvable.');
        }

        $design = Design::fromArray($input);

        $this->db->exec(
            'INSERT INTO badges (page_id, user_id, style, bicolor, design, created_at)
             VALUES (:page_id, :user_id, :style, :bicolor, :design, :created_at)',
            [
                ':page_id' => $pageId,
                ':user_id' => $userId,
                ':style' => $design->style,
                ':bicolor' => $design->bicolor ? 1 : 0,
                ':design' => json_encode($design->toArray(), JSON_UNESCAPED_SLASHES),
                ':created_at' => now(),
            ]
        );

        $badge = $this->badgeById((int) $this->db->pdo()->lastInsertId());

        $this->db->exec(
            'INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, :action, :detail, :created_at)',
            [':user_id' => $userId, ':action' => 'badge', ':detail' => 'badge ' . $badge['id'] . ' page ' . $page['slug'], ':created_at' => now()]
        );

        return $badge;
    }

    /** @return list<array{name: string, color: string, mesh: \Qlip\Stl\Mesh}> */
    public function volumes(array $badge): array
    {
        $design = Design::fromArray(json_decode((string) $badge['design'], true) ?: []);
        $page = $this->pageById((int) $badge['page_id']);
        $qr = QrCode::encode($this->qrText($page ?? ['slug' => '']), 'M');

        return Badge::build($qr, $design);
    }

    /** @return array{stl: string, threeMf: string, volumes: list<array<string, mixed>>} */
    public function export(array $badge): array
    {
        $page = $this->pageById((int) $badge['page_id']);
        $volumes = $this->volumes($badge);

        return [
            'stl' => StlWriter::write($volumes),
            'threeMf' => ThreeMfWriter::write($volumes, [
                'title' => 'Badge Qlip ' . ($page['slug'] ?? ''),
                'designer' => 'Qlip',
            ]),
            'volumes' => $volumes,
        ];
    }

    public function png(array $page, int $modulePx = 4): string
    {
        return Renderer::png(QrCode::encode($this->qrText($page), 'M'), $modulePx);
    }

    private function uniqueSlug(string $wanted): string
    {
        $wanted = preg_replace('/[^a-z0-9]+/', '', strtolower($wanted)) ?: '';

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $slug = $wanted !== '' && $attempt === 0 ? $wanted : substr(bin2hex(random_bytes(4)), 0, 6);

            if ($this->db->one('SELECT id FROM pages WHERE slug = :slug', [':slug' => $slug]) === null) {
                return $slug;
            }
        }

        throw new \RuntimeException('Impossible de generer un identifiant unique.');
    }
}
