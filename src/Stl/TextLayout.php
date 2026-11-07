<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Mise en page du texte grave au dos du porte-cles.
 *
 * Le texte est une grille de pixels, comme les modules du QR : chaque pixel grave est une
 * alveole, et l empreinte de la grille est un trou de la face du dos, referme par les
 * pixels clairs (le meme procede que l empreinte du QR sur la face haute). Une grille
 * tournee reste une grille : les aretes des pixels sont exactement les segments du contour
 * de l empreinte, subdivies a chaque pixel, donc le solide reste manifold.
 *
 * La grille est tournee (45 degres par defaut) : le texte court en diagonale, d un bord a
 * l autre de la plaque. Sa taille est la plus grande qui tienne dans la plaque, loin du
 * trou ; quand une seule ligne ne tient pas, le texte passe sur deux lignes. Ce qui ne
 * tient meme pas sur deux lignes est tronque, et le tilde final dit qu il manque des
 * caracteres : le texte grave ne doit pas promettre une adresse complete quand il ne
 * l imprime pas.
 *
 * Le texte est grave miroir dans les coordonnees de la plaque : le dos se regarde a
 * l envers (x inverse, y conserve), donc les signes et le haut des glyphes sont comptes
 * du cote des coordonnees elevees. Vu depuis le dos, les deux inversions s annulent et
 * le texte se lit a l endroit. Grave a l endroit dans les coordonnees de la plaque, il
 * se lirait a l envers depuis le dos, ce qui est meconnaissable alveole par alveole.
 */
final class TextLayout
{
    public const GAP = 1;         // colonne libre entre deux glyphes
    public const LINE_GAP = 2;    // rangee libre entre deux lignes
    public const MAX_PIXEL = 1.0; // cote d un pixel grave, en millimetres
    public const MIN_PIXEL = 0.35;
    public const EDGE_MARGIN = 1.2;  // distance entre l empreinte et le bord de la plaque
    public const HOLE_MARGIN = 0.8;  // distance entre l empreinte et le trou
    public const PIXEL_STEP = 0.02;

    private const BREAKS = '/-._~';

    /**
     * Le texte grave : une adresse sans son protocole.
     *
     * Le protocole occupe huit caracteres pour une information que le QR donne deja, et un
     * texte grave n a pas d espace. Les caracteres hors du jeu de la police deviennent '?'.
     */
    public static function label(string $url): string
    {
        $text = strtolower(trim($url));
        $text = (string) preg_replace('#^[a-z][a-z0-9+.:-]*://#', '', $text);
        $text = (string) preg_replace('/\s+/', '', $text);

        return $text;
    }

    /**
     * Empreinte gravee au dos : contour de la grille et pixels, avec leur orientation.
     *
     * @return array{
     *     text: string,
     *     truncated: bool,
     *     pixel: float,
     *     rows: list<string>,
     *     boundary: list<array{float, float}>,
     *     cells: array<string, array{engraved: bool, corners: list<array{float, float}>, triangles: list<array{array{float, float}, array{float, float}, array{float, float}}>, neighbours: list<string|null>}>
     * }|null null quand la plaque est trop petite pour y graver quoi que ce soit
     */
    public static function build(string $url, Design $design): ?array
    {
        $label = self::label($url);

        if ($label === '') {
            return null;
        }

        // Le texte est racourci tant qu il ne tient pas : le tilde marque la coupure.
        for ($limit = strlen($label); $limit >= 1; $limit--) {
            $text = $limit === strlen($label) ? $label : substr($label, 0, $limit) . '~';
            $best = null;

            foreach (self::rowsFor($text) as $rows) {
                $layout = self::fit($rows, $design);

                if ($layout !== null && ($best === null || $layout['pixel'] > $best['pixel'] + 1e-9)) {
                    $best = $layout;
                }
            }

            if ($best !== null) {
                $best['text'] = $text;
                $best['truncated'] = $limit < strlen($label);

                return $best;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------

    /**
     * Présentations essayees : une ligne, puis deux lignes coupees sur un separateur.
     *
     * @return list<list<string>>
     */
    private static function rowsFor(string $text): array
    {
        $rows = [[$text]];
        $n = strlen($text);
        $half = (int) ceil($n / 2);
        $cut = $half;
        $balance = PHP_INT_MAX;

        // La meilleure coupure est la plus proche du milieu, sur un separateur d URL.
        for ($p = 1; $p < $n; $p++) {
            if (strpos(self::BREAKS, $text[$p - 1]) === false) {
                continue;
            }

            $gap = abs(2 * $p - $n);

            if ($gap <= 4 && $gap < $balance) {
                $balance = $gap;
                $cut = $p;
            }
        }

        if ($n > 1 && $cut > 0 && $cut < $n) {
            $rows[] = [substr($text, 0, $cut), substr($text, $cut)];
        }

        return $rows;
    }

    /**
     * Plus grande taille de pixel qui tient pour ces lignes, ou null si aucune ne tient.
     *
     * Les centres tries du haut vers le bas : un centre plus bas eloigne le texte du trou
     * et permet des caracteres plus gros, ce qui compte plus que la place du texte.
     *
     * @param list<string> $rows
     * @return array{pixel: float, rows: list<string>, boundary: list<array{float, float}>, cells: array<string, array<string, mixed>>}|null
     */
    private static function fit(array $rows, Design $design): ?array
    {
        $longest = 0;

        foreach ($rows as $row) {
            $longest = max($longest, strlen($row));
        }

        $cols = PixelFont::WIDTH * $longest + self::GAP * max(0, $longest - 1);
        $lines = count($rows);
        $gridRows = PixelFont::HEIGHT * $lines + self::LINE_GAP * ($lines - 1);

        $centers = [
            [$design->width / 2.0, $design->height / 2.0],
            [$design->width / 2.0, ($design->height - $design->band - $design->holeRadius) / 2.0],
        ];

        for ($pixel = self::MAX_PIXEL; $pixel >= self::MIN_PIXEL; $pixel -= self::PIXEL_STEP) {
            foreach ($centers as [$cx, $cy]) {
                $layout = self::geometry($rows, $pixel, $cx, $cy, $cols, $gridRows, $design);

                if (self::fits($layout['boundary'], $design)) {
                    return $layout;
                }
            }
        }

        return null;
    }

    /**
     * Grille tournee : les points sont calcules depuis les coordonnees entieres de la
     * grille, donc un coin partage par deux pixels est ecrit avec les memes chiffres.
     *
     * @param list<string> $rows
     * @return array{pixel: float, rows: list<string>, boundary: list<array{float, float}>, cells: array<string, array<string, mixed>>}
     */
    private static function geometry(array $rows, float $pixel, float $cx, float $cy, int $cols, int $gridRows, Design $design): array
    {
        $angle = $design->textAngle * M_PI / 180.0;
        $cos = cos($angle);
        $sin = sin($angle);
        $spanX = $cols * $pixel;
        $spanY = $gridRows * $pixel;

        $point = static function (int $u, int $v) use ($cx, $cy, $pixel, $cos, $sin, $spanX, $spanY): array {
            $dx = $u * $pixel - $spanX / 2.0;
            $dy = $v * $pixel - $spanY / 2.0;

            return [$cx + $dx * $cos - $dy * $sin, $cy + $dx * $sin + $dy * $cos];
        };

        $boundary = [];

        for ($u = 0; $u < $cols; $u++) {
            $boundary[] = $point($u, 0);
        }

        for ($v = 0; $v < $gridRows; $v++) {
            $boundary[] = $point($cols, $v);
        }

        for ($u = $cols; $u > 0; $u--) {
            $boundary[] = $point($u, $gridRows);
        }

        for ($v = $gridRows; $v > 0; $v--) {
            $boundary[] = $point(0, $v);
        }

        $cells = [];
        $longest = 0;

        foreach ($rows as $row) {
            $longest = max($longest, strlen($row));
        }

        for ($v = 0; $v < $gridRows; $v++) {
            for ($u = 0; $u < $cols; $u++) {
                $corners = [
                    $point($u, $v),
                    $point($u + 1, $v),
                    $point($u + 1, $v + 1),
                    $point($u, $v + 1),
                ];

                $cells[$u . ',' . $v] = [
                    'engraved' => self::pixelIsInk($rows, $u, $v, $longest),
                    'corners' => $corners,
                    // Un carre convexe se coupe selon sa diagonale : les deux triangles
                    // gardent le sens trigonometrique du carre.
                    'triangles' => [
                        [$corners[0], $corners[1], $corners[2]],
                        [$corners[0], $corners[2], $corners[3]],
                    ],
                    // Cellule en face de chaque arete, dans le sens du carre : bas, droite, haut, gauche.
                    'neighbours' => [
                        self::cellKey($u, $v - 1, $cols, $gridRows),
                        self::cellKey($u + 1, $v, $cols, $gridRows),
                        self::cellKey($u, $v + 1, $cols, $gridRows),
                        self::cellKey($u - 1, $v, $cols, $gridRows),
                    ],
                ];
            }
        }

        return ['pixel' => $pixel, 'rows' => $rows, 'boundary' => $boundary, 'cells' => $cells];
    }

    /**
     * Le pixel (u, v) de la grille fait-il partie d un glyphe ?
     *
     * La grille est gravee miroir : le signe 0 est pose du cote des u eleves, comme la
     * colonne 0 du glyphe, et la rangee 0 du glyphe comme la premiere ligne du texte du
     * cote des v eleves. Le miroir porte sur le bloc entier, a partir de la ligne la plus
     * longue : les deux lignes d un texte commenceent du meme cote, donc le bloc se lit
     * aligne a gauche depuis le dos. Le dos se regardant a l envers de la plaque, ces
     * comptes a rebours rendent le texte lisible depuis le dos.
     */
    private static function pixelIsInk(array $rows, int $u, int $v, int $longest): bool
    {
        $block = intdiv($v, PixelFont::HEIGHT + self::LINE_GAP);
        $row = $v - $block * (PixelFont::HEIGHT + self::LINE_GAP);

        if ($row >= PixelFont::HEIGHT) {
            return false; // rangee de separation entre deux lignes
        }

        $line = count($rows) - 1 - $block;

        if (!isset($rows[$line])) {
            return false;
        }

        $advance = PixelFont::WIDTH + self::GAP;
        $index = $longest - 1 - intdiv($u, $advance);
        $col = $u - intdiv($u, $advance) * $advance;

        if ($col >= PixelFont::WIDTH || $index >= strlen($rows[$line])) {
            return false; // colonne de separation, ou rang de grille hors du texte
        }

        return PixelFont::glyph($rows[$line][$index])
            [PixelFont::HEIGHT - 1 - $row][PixelFont::WIDTH - 1 - $col] === '#';
    }

    private static function cellKey(int $u, int $v, int $cols, int $gridRows): ?string
    {
        if ($u < 0 || $u >= $cols || $v < 0 || $v >= $gridRows) {
            return null;
        }

        return $u . ',' . $v;
    }

    /**
     * L empreinte doit rester dans la plaque, coins arrondis compris, et ne pas toucher
     * le trou : un pont de triangulation ne peut pas traverser une paroi.
     *
     * @param list<array{float, float}> $boundary
     */
    private static function fits(array $boundary, Design $design): bool
    {
        $inner = Triangulator::roundedRectangle(
            self::EDGE_MARGIN,
            self::EDGE_MARGIN,
            $design->width - self::EDGE_MARGIN,
            $design->height - self::EDGE_MARGIN,
            max(0.0, $design->cornerRadius - self::EDGE_MARGIN)
        );

        $n = count($boundary);
        $clear = $design->holeRadius + self::HOLE_MARGIN;
        $hole = [$design->width / 2.0, $design->height - $design->band];

        if (Triangulator::pointInPolygon($boundary, $hole)) {
            return false;
        }

        for ($i = 0; $i < $n; $i++) {
            $a = $boundary[$i];
            $b = $boundary[($i + 1) % $n];

            if (!Triangulator::pointInPolygon($inner, $a)) {
                return false;
            }

            // Une arete peut se rapprocher du trou entre ses deux extremites.
            if (self::distanceToSegment($hole, $a, $b) < $clear) {
                return false;
            }
        }

        return true;
    }

    /** @param array{float, float} $point */
    private static function distanceToSegment(array $point, array $a, array $b): float
    {
        $ux = $b[0] - $a[0];
        $uy = $b[1] - $a[1];
        $length = $ux * $ux + $uy * $uy;

        if ($length <= 0.0) {
            return hypot($point[0] - $a[0], $point[1] - $a[1]);
        }

        $t = max(0.0, min(1.0, (($point[0] - $a[0]) * $ux + ($point[1] - $a[1]) * $uy) / $length));

        return hypot($point[0] - ($a[0] + $t * $ux), $point[1] - ($a[1] + $t * $uy));
    }
}
