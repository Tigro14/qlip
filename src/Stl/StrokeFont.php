<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Police a traits continus pour graver du texte dans la plaque.
 *
 * L'application n'a pas de police : les glyphes sont dessines a la main, en
 * polylignes. Chaque trait est grave en sillon : la grille fine grave une cellule
 * quand son centre tombe a moins de STROKE unites d'un trait, donc le sillon fait
 * environ 2,4 cellules de large. Gravees en sillons, les lettres se lisent sans
 * avoir a deviner les signes derriere des carres.
 *
 * Unites du dessin : ligne de base 0, hauteur des minuscules 10, montants 13,
 * descendants -3,8. Une rangee de glyphes couvre DESCENT a DESCENT + LINE_ROWS :
 * le centre de la rangee 0 de la grille tombe a DESCENT + 0,5, et tout trait a
 * moins de STROKE de la limite de la rangee est grave dans cette rangee. Chaque
 * glyphe porte son avance : avec des traits continus la largeur n'est plus fixe,
 * 'l' ne doit pas couter la place d'un 'o'. La colonne libre entre deux glyphes
 * est portee par TextLayout, pas par l'avance.
 *
 * Le jeu couvert est celui d'une URL : minuscules (le texte grave est minusculise
 * par TextLayout::label), chiffres, et les signes d'une adresse (point,
 * deux-points, barre, tiret, tiret bas, tilde). Un caractere inconnu rend '?',
 * pour que le texte grave dise qu'il manque un signe, au lieu de le taire.
 */
final class StrokeFont
{
    public const X_HEIGHT = 10;  // haut des lettres comme 'a' ou 'o'
    public const ASCENT = 14;    // rangee atteinte par les lettres comme 'l' ou 'f'
    public const DESCENT = -5;   // rangee atteinte par les lettres comme 'g' ou 'q'
    public const LINE_ROWS = 20; // rangees de la grille fine occupees par une ligne de texte
    public const STROKE = 1.2;   // rayon du sillon grave, en unites du dessin

    /** @var array<string, array{int, list<list<array{float, float}>>}> avance, puis polylignes des traits */
    private const GLYPHS = [
        ' ' => [4, []],

        '.' => [3, [[[1.5, 1.8], [1.5, 1.8]]]],
        ':' => [3, [[[1.5, 7.0], [1.5, 7.0]], [[1.5, 2.0], [1.5, 2.0]]]],
        '-' => [6, [[[1.0, 5.2], [3.6, 5.2]]]],
        '_' => [6, [[[0.8, -0.4], [4.6, -0.4]]]],
        '/' => [7, [[[0.8, -2.2], [5.4, 11.2]]]],
        '~' => [7, [[[0.8, 6.6], [2.2, 7.8], [4.2, 6.2], [5.6, 7.4]]]],
        '?' => [7, [[[1.2, 7.6], [2.2, 8.8], [4.2, 8.8], [5.2, 7.6], [5.2, 6.2], [3.2, 4.8], [3.2, 3.4]],
                    [[3.2, 1.6], [3.2, 1.6]]]],

        '0' => [8, [[[3.0, 8.8], [4.4, 8.8], [5.4, 7.4], [5.4, 2.6], [4.4, 1.2], [3.0, 1.2], [1.8, 2.6], [1.8, 7.4], [3.0, 8.8]]]],
        '1' => [6, [[[1.2, 7.4], [2.6, 8.8], [2.6, 1.2]], [[1.0, 1.2], [4.6, 1.2]]]],
        '2' => [8, [[[1.2, 7.6], [2.4, 8.8], [4.4, 8.8], [5.4, 7.6], [5.4, 6.2], [1.4, 2.2], [1.4, 1.2], [5.6, 1.2]]]],
        '3' => [8, [[[1.2, 8.2], [2.4, 8.8], [4.4, 8.8], [5.4, 7.7], [5.4, 6.0], [4.2, 5.0], [3.0, 5.0]],
                    [[3.0, 5.0], [4.4, 5.0], [5.4, 4.0], [5.4, 2.3], [4.2, 1.2], [2.4, 1.2], [1.2, 1.9]]]],
        '4' => [7, [[[4.8, 8.8], [4.8, 1.2]], [[4.8, 3.6], [1.0, 3.6], [4.2, 8.8]]]],
        '5' => [8, [[[5.4, 8.8], [1.4, 8.8], [1.4, 6.0]],
                    [[1.4, 6.0], [3.2, 6.4], [4.6, 5.8], [5.4, 4.4], [4.8, 2.2], [3.2, 1.2], [1.4, 1.9]]]],
        '6' => [8, [[[4.8, 8.8], [2.6, 8.8], [1.4, 7.4], [1.4, 2.5], [2.6, 1.2], [4.4, 1.2], [5.4, 2.5], [5.4, 4.2], [4.2, 5.2], [2.6, 5.2], [1.4, 4.0]]]],
        '7' => [7, [[[1.2, 8.8], [5.6, 8.8], [2.8, 1.2]]]],
        '8' => [7, [[[3.4, 5.4], [2.2, 6.4], [2.2, 7.8], [3.4, 8.8], [4.6, 7.8], [4.6, 6.4], [3.4, 5.4]],
                    [[3.4, 5.4], [4.8, 4.4], [4.8, 2.4], [3.4, 1.2], [2.0, 2.4], [2.0, 4.4], [3.4, 5.4]]]],
        '9' => [8, [[[5.4, 7.5], [4.2, 8.8], [2.4, 8.8], [1.2, 7.5], [1.2, 5.7], [2.4, 4.5], [4.2, 4.5], [5.4, 5.7], [5.4, 7.5]],
                    [[5.4, 5.7], [5.4, 1.2]]]],

        'a' => [8, [[[2.5, 8.8], [4.6, 8.8], [5.8, 7.6], [5.8, 1.2], [4.6, 1.2], [2.5, 1.2], [1.2, 2.4], [1.2, 7.6], [2.5, 8.8]]]],
        'b' => [8, [[[1.2, 13.0], [1.2, 1.2]],
                    [[1.2, 7.6], [2.5, 8.8], [4.6, 8.8], [5.8, 7.6], [5.8, 2.4], [4.6, 1.2], [1.2, 1.2]]]],
        'c' => [8, [[[5.7, 8.3], [4.6, 8.8], [2.5, 8.8], [1.2, 7.6], [1.2, 2.4], [2.5, 1.2], [4.6, 1.2], [5.7, 1.7]]]],
        'd' => [8, [[[5.8, 13.0], [5.8, 1.2]],
                    [[4.6, 8.8], [2.5, 8.8], [1.2, 7.6], [1.2, 2.4], [2.5, 1.2], [4.6, 1.2]]]],
        'e' => [8, [[[5.7, 2.0], [4.6, 1.2], [2.5, 1.2], [1.2, 2.4], [1.2, 7.6], [2.5, 8.8], [4.6, 8.8], [5.8, 7.5], [5.8, 5.6], [1.2, 5.6]]]],
        'f' => [7, [[[3.8, 12.4], [2.6, 13.4], [1.5, 12.3], [1.5, 1.2]],
                    [[0.5, 10.2], [4.5, 10.2]]]],
        'g' => [8, [[[2.5, 8.8], [4.6, 8.8], [5.8, 7.6], [5.8, 1.2], [4.6, 1.2], [2.5, 1.2], [1.2, 2.4], [1.2, 7.6], [2.5, 8.8]],
                    [[5.8, 7.6], [5.8, -3.2], [4.6, -3.8], [2.6, -3.8]]]],
        'h' => [8, [[[1.2, 13.0], [1.2, 1.2]],
                    [[1.2, 6.8], [2.5, 8.8], [4.6, 8.8], [5.8, 7.5], [5.8, 1.2]]]],
        'i' => [4, [[[1.5, 8.8], [1.5, 1.2]],
                    [[1.5, 11.6], [1.5, 11.6]]]],
        'j' => [5, [[[2.5, 8.8], [2.5, -3.2], [1.5, -3.8], [0.5, -3.8]],
                    [[2.5, 11.6], [2.5, 11.6]]]],
        'k' => [7, [[[1.2, 13.0], [1.2, 1.2]],
                    [[5.6, 8.8], [2.0, 5.0], [5.6, 1.2]]]],
        'l' => [4, [[[1.5, 13.0], [1.5, 1.2]]]],
        'm' => [11, [[[1.2, 8.8], [1.2, 1.2]],
                     [[1.2, 6.9], [2.4, 8.8], [4.2, 8.8], [5.2, 7.2], [5.2, 1.2]],
                     [[5.2, 6.9], [6.4, 8.8], [8.2, 8.8], [9.2, 7.2], [9.2, 1.2]]]],
        'n' => [8, [[[1.2, 8.8], [1.2, 1.2]],
                    [[1.2, 6.9], [2.5, 8.8], [4.6, 8.8], [5.8, 7.5], [5.8, 1.2]]]],
        'o' => [8, [[[2.5, 8.8], [4.5, 8.8], [5.8, 7.5], [5.8, 2.5], [4.5, 1.2], [2.5, 1.2], [1.2, 2.5], [1.2, 7.5], [2.5, 8.8]]]],
        'p' => [8, [[[1.2, 8.8], [1.2, -3.8]],
                    [[1.2, 7.6], [2.5, 8.8], [4.6, 8.8], [5.8, 7.6], [5.8, 2.4], [4.6, 1.2], [1.2, 1.2]]]],
        'q' => [8, [[[2.5, 8.8], [4.6, 8.8], [5.8, 7.6], [5.8, 1.2], [4.6, 1.2], [2.5, 1.2], [1.2, 2.4], [1.2, 7.6], [2.5, 8.8]],
                    [[5.8, 7.6], [5.8, -3.8]]]],
        'r' => [7, [[[1.2, 8.8], [1.2, 1.2]],
                    [[1.2, 6.6], [2.4, 8.6], [3.8, 8.8], [5.0, 7.8]]]],
        's' => [8, [[[5.4, 8.2], [4.2, 8.8], [2.4, 8.8], [1.2, 7.7], [1.2, 6.3], [2.4, 5.2], [4.2, 5.2], [5.4, 4.1], [5.4, 2.3], [4.2, 1.2], [2.4, 1.2], [1.2, 1.8]]]],
        't' => [7, [[[1.5, 12.4], [1.5, 2.4]],
                    [[0.4, 9.4], [4.4, 9.4]],
                    [[1.5, 2.4], [2.7, 1.2], [4.4, 1.2]]]],
        'u' => [8, [[[1.2, 8.8], [1.2, 2.5], [2.4, 1.2], [4.4, 1.2], [5.6, 2.5], [5.6, 8.8]]]],
        'v' => [8, [[[1.0, 8.8], [3.4, 1.4], [5.8, 8.8]]]],
        'w' => [10, [[[0.8, 8.8], [2.6, 1.4], [4.2, 6.2], [5.8, 1.4], [7.6, 8.8]]]],
        'x' => [8, [[[1.0, 8.8], [5.4, 1.2]],
                    [[5.4, 8.8], [1.0, 1.2]]]],
        'y' => [8, [[[1.0, 8.8], [3.8, 2.8]],
                    [[5.8, 8.8], [2.4, -3.8]]]],
        'z' => [8, [[[1.0, 8.8], [5.4, 8.8], [1.0, 1.2], [5.4, 1.2]]]],
    ];

    /** @var array<string, list<list<bool>>> */
    private static array $ink = [];

    public static function advance(string $char): int
    {
        return self::GLYPHS[$char][0] ?? self::GLYPHS['?'][0];
    }

    /**
     * Le glyphe : avance, puis polylignes des traits.
     *
     * @return array{int, list<list<array{float, float}>>}
     */
    public static function glyph(string $char): array
    {
        return self::GLYPHS[$char] ?? self::GLYPHS['?'];
    }

    /**
     * Les cellules de la grille fine couvertes par le glyphe : LINE_ROWS rangees,
     * avance colonnes. Le centre d'une cellule tombe a une demi-unite ; une cellule
     * est gravee quand son centre est a moins de STROKE d'un trait. Un trait de un
     * point (un segment de longueur nulle) est un point grave. La carte est mise en
     * cache : elle ne depend que du glyphe.
     *
     * @return list<list<bool>>
     */
    public static function inkMap(string $char): array
    {
        if (isset(self::$ink[$char])) {
            return self::$ink[$char];
        }

        [$advance, $strokes] = self::glyph($char);
        $limit = self::STROKE * self::STROKE;
        $map = [];

        for ($r = 0; $r < self::LINE_ROWS; $r++) {
            $y = self::DESCENT + $r + 0.5;
            $row = [];

            for ($c = 0; $c < $advance; $c++) {
                $x = $c + 0.5;
                $ink = false;

                foreach ($strokes as $stroke) {
                    $n = count($stroke);

                    for ($k = 0; $k + 1 < $n; $k++) {
                        if (self::distance2($x, $y, $stroke[$k], $stroke[$k + 1]) <= $limit) {
                            $ink = true;
                            break 2;
                        }
                    }
                }

                $row[$c] = $ink;
            }

            $map[$r] = $row;
        }

        return self::$ink[$char] = $map;
    }

    /**
     * Carre de la distance d'un point a un segment. Un segment de longueur nulle
     * compte comme un point.
     *
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    private static function distance2(float $x, float $y, array $a, array $b): float
    {
        $ux = $b[0] - $a[0];
        $uy = $b[1] - $a[1];
        $length = $ux * $ux + $uy * $uy;

        if ($length <= 0.0) {
            return ($x - $a[0]) * ($x - $a[0]) + ($y - $a[1]) * ($y - $a[1]);
        }

        $t = max(0.0, min(1.0, (($x - $a[0]) * $ux + ($y - $a[1]) * $uy) / $length));
        $dx = $x - ($a[0] + $t * $ux);
        $dy = $y - ($a[1] + $t * $uy);

        return $dx * $dx + $dy * $dy;
    }
}
