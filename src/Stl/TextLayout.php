<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Mise en page du texte grave au dos du porte-cles.
 *
 * Le texte est grave en sillons continus (StrokeFont) sur une grille fine : chaque
 * cellule gravee est une alveole, et l empreinte de la grille est un trou de la face
 * du dos, referme par les cellules claires (le meme procede que l empreinte du QR sur
 * la face haute). Une grille tournee reste une grille : les aretes des cellules sont
 * exactement les segments du contour de l empreinte, subdivies a chaque cellule, donc
 * le solide reste manifold.
 *
 * La grille est tournee (45 degres par defaut) : le texte court en diagonale, d un
 * bord a l autre de la plaque. Sa taille est la plus grande qui tienne dans la plaque,
 * loin du trou ; quand une seule ligne ne tient pas, le texte passe sur deux lignes.
 * Ce qui ne tient meme pas sur deux lignes est tronque, et le tilde final dit qu il
 * manque des caracteres : le texte grave ne doit pas promettre une adresse complete
 * quand il ne l imprime pas.
 *
 * Le texte est grave miroir dans les coordonnees de la plaque : le dos se regarde a
 * l envers (x inverse, y conserve), donc les positions de lecture sont comptees du
 * cote des coordonnees elevees, et le bas des glyphes comme la premiere ligne du
 * texte du cote des v eleves. Le miroir porte sur le bloc entier, a partir de la ligne
 * la plus longue : les deux lignes d un texte commencent du meme cote, donc le bloc
 * se lit aligne a gauche depuis le dos. Vu depuis le dos, les deux inversions
 * s annulent et le texte se lit a l endroit ; grave a l endroit dans les coordonnees
 * de la plaque, il se lirait a l envers, ce qui est meconnaissable alveole par alveole.
 */
final class TextLayout
{
    public const GAP = 2;         // cellules fines libres entre deux glyphes
    public const LINE_GAP = 5;    // rangees libres entre deux lignes de texte
    public const MAX_PIXEL = 0.40; // cote d une cellule gravee, en millimetres
    public const MIN_PIXEL = 0.18; // en dessous, le sillon devient plus fin que la buse
    public const EDGE_MARGIN = 1.2;  // distance entre l empreinte et le bord de la plaque
    public const HOLE_MARGIN = 0.8;  // distance entre l empreinte et le trou
    public const PIXEL_STEP = 0.01;

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
     * Empreinte gravee au dos : contour de la grille et cellules, avec leur orientation.
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
     * Positions de lecture des glyphes et taille de la grille, calculees une fois
     * pour ces lignes : avec des avances variables, la largeur d une ligne ne se
     * devine pas au nombre de caracteres.
     *
     * @param list<string> $rows
     * @return array{
     *     cols: int,
     *     gridRows: int,
     *     charAt: list<array<int, array{string, int}>>
     * }
     */
    private static function plan(array $rows): array
    {
        $cols = 0;
        $charAt = [];

        foreach ($rows as $row) {
            $x = 0;
            $map = [];

            for ($i = 0; $i < strlen($row); $i++) {
                $advance = StrokeFont::advance($row[$i]);

                for ($c = 0; $c < $advance; $c++) {
                    $map[$x + $c] = [$row[$i], $c];
                }

                $x += $advance + ($i + 1 < strlen($row) ? self::GAP : 0);
            }

            $charAt[] = $map;
            $cols = max($cols, $x);
        }

        $lines = count($rows);
        $gridRows = StrokeFont::LINE_ROWS * $lines + self::LINE_GAP * ($lines - 1);

        return ['cols' => $cols, 'gridRows' => $gridRows, 'charAt' => $charAt];
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
        $plan = self::plan($rows);

        $centers = [
            [$design->width / 2.0, $design->height / 2.0],
            [$design->width / 2.0, ($design->height - $design->band - $design->holeRadius) / 2.0],
        ];

        $steps = (int) round((self::MAX_PIXEL - self::MIN_PIXEL) / self::PIXEL_STEP);

        for ($i = 0; $i <= $steps; $i++) {
            $pixel = self::MAX_PIXEL - $i * self::PIXEL_STEP;

            foreach ($centers as [$cx, $cy]) {
                // L empreinte est un rectangle tourne : deux solides convexes, il
                // suffit de tester les quatre coins avant de batir les cellules.
                if (!self::fits(self::rectPoints($plan, $pixel, $cx, $cy, $design), $design)) {
                    continue;
                }

                return self::geometry($rows, $plan, $pixel, $cx, $cy, $design);
            }
        }

        return null;
    }

    /**
     * Les quatre coins du rectangle tourne de l empreinte.
     *
     * @return list<array{float, float}>
     */
    private static function rectPoints(array $plan, float $pixel, float $cx, float $cy, Design $design): array
    {
        $point = self::pointAt($plan, $pixel, $cx, $cy, $design);

        return [
            $point(0, 0),
            $point($plan['cols'], 0),
            $point($plan['cols'], $plan['gridRows']),
            $point(0, $plan['gridRows']),
        ];
    }

    /**
     * Le point (u, v) de la grille, dans les coordonnees de la plaque.
     *
     * Les points sont calcules depuis les coordonnees entieres de la grille, donc un
     * coin partage par deux cellules est ecrit avec les memes chiffres.
     *
     * @return callable(int, int): array{float, float}
     */
    private static function pointAt(array $plan, float $pixel, float $cx, float $cy, Design $design): callable
    {
        $angle = $design->textAngle * M_PI / 180.0;
        $cos = cos($angle);
        $sin = sin($angle);
        $spanX = $plan['cols'] * $pixel;
        $spanY = $plan['gridRows'] * $pixel;

        return static function (int $u, int $v) use ($cx, $cy, $pixel, $cos, $sin, $spanX, $spanY): array {
            $dx = $u * $pixel - $spanX / 2.0;
            $dy = $v * $pixel - $spanY / 2.0;

            return [$cx + $dx * $cos - $dy * $sin, $cy + $dx * $sin + $dy * $cos];
        };
    }

    /**
     * Grille tournee et ses cellules.
     *
     * @param list<string> $rows
     * @return array{pixel: float, rows: list<string>, boundary: list<array{float, float}>, cells: array<string, array<string, mixed>>}
     */
    private static function geometry(array $rows, array $plan, float $pixel, float $cx, float $cy, Design $design): array
    {
        $cols = $plan['cols'];
        $gridRows = $plan['gridRows'];
        $point = self::pointAt($plan, $pixel, $cx, $cy, $design);

        // Le contour est subdivie au coin de chaque cellule : ses segments sont exactement
        // les aretes des cellules de bord, comme l empreinte du QR sur la face haute. Un
        // contour a quatre coins laisserait des aretes non partagees sur la face du dos.
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

        for ($v = 0; $v < $gridRows; $v++) {
            for ($u = 0; $u < $cols; $u++) {
                $corners = [
                    $point($u, $v),
                    $point($u + 1, $v),
                    $point($u + 1, $v + 1),
                    $point($u, $v + 1),
                ];

                $cells[$u . ',' . $v] = [
                    'engraved' => self::cellIsInk($rows, $plan, $u, $v),
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
     * La cellule (u, v) de la grille fine fait-elle partie d un trait grave ?
     *
     * La grille est gravee miroir : la position de lecture 0 est posee du cote des u
     * eleves, et le bas des glyphes comme la premiere ligne du texte du cote des v
     * eleves. Le bloc entier se miroir a partir de la ligne la plus longue : les lignes
     * plus courtes laissent le bout des u libre, et se lisent alignees a gauche depuis
     * le dos. Le dos se regardant a l envers de la plaque, ces comptes a rebours rendent
     * le texte lisible depuis le dos, glyphe par glyphe.
     */
    private static function cellIsInk(array $rows, array $plan, int $u, int $v): bool
    {
        $blockStep = StrokeFont::LINE_ROWS + self::LINE_GAP;
        $block = intdiv($v, $blockStep);
        $rank = $v - $block * $blockStep;

        if ($rank >= StrokeFont::LINE_ROWS) {
            return false; // rangee de separation entre deux lignes de texte
        }

        $line = count($rows) - 1 - $block;

        if (!isset($rows[$line])) {
            return false;
        }

        $x = $plan['cols'] - 1 - $u;

        if (!isset($plan['charAt'][$line][$x])) {
            return false; // colonne de separation, ou rang de grille hors du texte
        }

        [$char, $column] = $plan['charAt'][$line][$x];

        return StrokeFont::inkMap($char)[$rank][$column];
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
