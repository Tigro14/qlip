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

    /**
     * Longueurs maximales des champs d une page, en octets UTF-8. Le maxlength des
     * formulaires n est pas une protection : on peut poster directement, donc le
     * controle se fait cote serveur avant l insertion. L echappement HTML se fait a
     * l affichage (e() dans les templates), pas ici.
     */
    private const MAX_FIELD_LENGTH = [
        'title' => 80,
        'phone' => 40,
        'email_contact' => 120,
        'note' => 500,
    ];

    private const MAX_SLUG_LENGTH = 32;

    /** @param array<string, mixed> $input */
    public function createPage(int $userId, array $input): array
    {
        $fields = $this->cleanFields($input);

        if ($fields['title'] === '') {
            $fields['title'] = 'Objet trouve';
        }

        $slug = $this->uniqueSlug((string) ($input['slug'] ?? ''));

        $this->db->exec(
            'INSERT INTO pages (slug, user_id, title, phone, email_contact, note, created_at)
             VALUES (:slug, :user_id, :title, :phone, :email_contact, :note, :created_at)',
            [
                ':slug' => $slug,
                ':user_id' => $userId,
                ':title' => $fields['title'],
                ':phone' => $fields['phone'],
                ':email_contact' => $fields['email_contact'],
                ':note' => $fields['note'],
                ':created_at' => now(),
            ]
        );

        $this->audit($userId, 'page', 'page ' . $slug . ' creee');

        return $this->pageBySlug($slug);
    }

    /** @param array<string, mixed> $input */
    public function updatePage(int $pageId, array $input): array
    {
        $fields = $this->cleanFields($input);

        $this->db->exec(
            'UPDATE pages SET title = :title, phone = :phone, email_contact = :email_contact, note = :note WHERE id = :id',
            [
                ':title' => $fields['title'],
                ':phone' => $fields['phone'],
                ':email_contact' => $fields['email_contact'],
                ':note' => $fields['note'],
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

    /**
     * Creations du jour. On compte les evenements de creation (audit_log), pas les lignes
     * qui restent : sinon supprimer un badge rembourserait le quota, et on pourrait creer,
     * supprimer, creer sans limite.
     */
    public function usedToday(int $userId): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'badge' AND user_id = :user_id AND substr(created_at, 1, 10) = :day",
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

        $this->audit($userId, 'badge', 'badge ' . $badge['id'] . ' page ' . $page['slug']);

        return $badge;
    }

    /**
     * Suppressions definitives. Le controle d appartenance est fait par le controleur :
     * ici on suppose qu il a ete fait. Le detail est pris avant le DELETE, parce qu apres
     * la ligne n existe plus, et les badges d une page partent avec elle (ON DELETE CASCADE).
     */
    public function deletePage(int $userId, int $pageId): void
    {
        $page = $this->pageById($pageId);

        if ($page === null) {
            return;
        }

        $badges = $this->db->count('SELECT COUNT(*) FROM badges WHERE page_id = :page_id', [':page_id' => $pageId]);

        $this->db->exec('DELETE FROM pages WHERE id = :id', [':id' => $pageId]);

        $this->audit($userId, 'page_supprimee', 'page ' . $page['slug'] . ' supprimee (' . $badges . ' badges)');
    }

    public function deleteBadge(int $userId, int $badgeId): void
    {
        $badge = $this->badgeById($badgeId);

        if ($badge === null) {
            return;
        }

        $this->db->exec('DELETE FROM badges WHERE id = :id', [':id' => $badgeId]);

        $this->audit($userId, 'badge_supprime', 'badge ' . $badge['id'] . ' supprime');
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

    private function audit(int $userId, string $action, string $detail): void
    {
        $this->db->exec(
            'INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (:user_id, :action, :detail, :created_at)',
            [':user_id' => $userId, ':action' => $action, ':detail' => $detail, ':created_at' => now()]
        );
    }

    /**
     * Champs d une page, controles cote serveur : taille maximale et forme de l email
     * de contact (il part dans un href="mailto:", donc il doit rester une adresse
     * simple). Rien n est echappe ici : l echappement appartient au rendu.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function cleanFields(array $input): array
    {
        $fields = [];

        foreach (self::MAX_FIELD_LENGTH as $key => $max) {
            $value = trim((string) ($input[$key] ?? ''));

            if (strlen($value) > $max) {
                throw new \RuntimeException('Champ trop long : ' . $key . ' (' . $max . ' caracteres max.)');
            }

            $fields[$key] = $value;
        }

        if ($fields['email_contact'] !== '' && filter_var($fields['email_contact'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Email de contact invalide.');
        }

        return $fields;
    }

    private function uniqueSlug(string $wanted): string
    {
        $wanted = substr(preg_replace('/[^a-z0-9]+/', '', strtolower($wanted)) ?: '', 0, self::MAX_SLUG_LENGTH);

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $slug = $wanted !== '' && $attempt === 0 ? $wanted : substr(bin2hex(random_bytes(4)), 0, 6);

            if ($this->db->one('SELECT id FROM pages WHERE slug = :slug', [':slug' => $slug]) === null) {
                return $slug;
            }
        }

        throw new \RuntimeException('Impossible de generer un identifiant unique.');
    }
}
