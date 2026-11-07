<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Triangulation d'une region (contour exterieur + trous).
 *
 * Les trous sont rattaches au contour principal par des ponts : les deux
 * extremites du pont sont dupliquees pour que le pont soit parcouru dans les
 * deux sens. Le polygone obtenu est parcouru dans un ordre unique (contour
 * exterieur trigonometrique, trous horaire) et eclaire d'oreilles.
 *
 * La condition d'appartenance est evaluate avec la definition de la region
 * (interieur du contour, exterieur des trous) : un pont peut mettre deux trous
 * en contact, et une simple inclusion dans le polygone ne suffirait pas.
 */
final class Triangulator
{
    /**
     * @param list<array{float, float}> $outer
     * @param list<list<array{float, float}>> $holes
     * @return list<array{array{float, float}, array{float, float}, array{float, float}}>>
     */
    public static function triangulate(array $outer, array $holes = []): array
    {
        $outer = self::orient($outer, true);
        $holes = array_map(static fn (array $hole): array => self::orient($hole, false), $holes);

        $walk = $outer;

        // Segments deja placeses (contours + ponts) : un nouveau pont ne peut
        // croiser aucun d'entre eux, sinon le parcours n'est plus simple.
        $segments = self::edges($outer);

        // Sommets deja employes par un pont : un sommet ne peut servir deux fois
        // sinon le parcours se touche en ce sommet et l'eclairement d'oreilles ne
        // peut plus le traiter.
        $used = [];

        foreach ($holes as $hole) {
            foreach (self::edges($hole) as $edge) {
                $segments[] = $edge;
            }

            $bridge = self::findBridge($walk, $hole, $outer, $holes, $segments, $used);

            if ($bridge === null) {
                // Trou tangent au contour : region degenere, on l'ignore.
                continue;
            }

            [$i, $j] = $bridge;

            $used[self::vertexKey($walk[$i])] = true;
            $used[self::vertexKey($hole[$j])] = true;

            $segments[] = [$walk[$i], $hole[$j]];

            $rotated = array_merge(array_slice($hole, $j), array_slice($hole, 0, $j + 1));

            $walk = array_merge(
                array_slice($walk, 0, $i + 1),
                $rotated,
                [$walk[$i]],
                array_slice($walk, $i + 1)
            );
        }

        // Contours d'origine (sans les ponts) : une diagonale ne doit jamais les
        // traverser, meme si l'arete correspondante a deja ete consommee par une
        // oreille.
        $contours = self::edges($outer);

        foreach ($holes as $hole) {
            $contours = array_merge($contours, self::edges($hole));
        }

        return self::triangulateWalk($walk, $outer, $holes, $contours);
    }

    /** Un rectangle, triangule directement (2 triangles). */
    public static function rectangle(array $rect): array
    {
        $polygon = self::rectanglePolygon($rect);

        return [
            [$polygon[0], $polygon[1], $polygon[2]],
            [$polygon[0], $polygon[2], $polygon[3]],
        ];
    }

    /**
     * Contour d'un rectangle, sens trigonometrique.
     *
     * @param array{float, float, float, float} $rect [x0, y0, x1, y1]
     * @return list<array{float, float}>
     */
    public static function rectanglePolygon(array $rect): array
    {
        return [
            [$rect[0], $rect[1]],
            [$rect[2], $rect[1]],
            [$rect[2], $rect[3]],
            [$rect[0], $rect[3]],
        ];
    }

    /**
     * Polygone regulier approximant un cercle (anneau du porte-cles), sens CCW.
     *
     * @return list<array{float, float}>
     */
    public static function circle(float $cx, float $cy, float $radius, int $segments = 24): array
    {
        $points = [];

        for ($i = 0; $i < $segments; $i++) {
            $angle = 2 * M_PI * $i / $segments;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }

    /**
     * Rectangle arrondi, sens trigonometrique.
     *
     * @return list<array{float, float}>
     */
    public static function roundedRectangle(float $x0, float $y0, float $x1, float $y1, float $radius, int $segments = 6): array
    {
        $radius = min($radius, min($x1 - $x0, $y1 - $y0) / 2);

        if ($radius <= 0.0) {
            return self::rectanglePolygon([$x0, $y0, $x1, $y1]);
        }

        $corners = [
            [$x1 - $radius, $y0 + $radius, -M_PI / 2],
            [$x1 - $radius, $y1 - $radius, 0.0],
            [$x0 + $radius, $y1 - $radius, M_PI / 2],
            [$x0 + $radius, $y0 + $radius, M_PI],
        ];

        $points = [];

        foreach ($corners as [$cx, $cy, $start]) {
            for ($i = 0; $i <= $segments; $i++) {
                $angle = $start + M_PI / 2 * $i / $segments;
                $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
            }
        }

        return $points;
    }

    // ------------------------------------------------------------------

    /** @param list<array{float, float}> $points */
    public static function orient(array $points, bool $counterClockwise): array
    {
        if (self::signedArea($points) > 0 !== $counterClockwise) {
            $points = array_reverse($points);
        }

        return $points;
    }

    public static function signedArea(array $points): float
    {
        $area = 0.0;
        $n = count($points);

        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $n];
            $area += $x1 * $y2 - $x2 * $y1;
        }

        return $area / 2;
    }

    private static function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    private static function between(array $p, array $q, array $point): bool
    {
        return $point[0] >= min($p[0], $q[0]) - 1e-9
            && $point[0] <= max($p[0], $q[0]) + 1e-9
            && $point[1] >= min($p[1], $q[1]) - 1e-9
            && $point[1] <= max($p[1], $q[1]) + 1e-9;
    }

    public static function pointInPolygon(array $polygon, array $point): bool
    {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$x1, $y1] = $polygon[$i];
            [$x2, $y2] = $polygon[$j];

            if (($y1 > $point[1]) !== ($y2 > $point[1])
                && $point[0] < ($x2 - $x1) * ($point[1] - $y1) / ($y2 - $y1) + $x1) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** @param list<list<array{float, float}>> $holes */
    private static function inRegion(array $point, array $outer, array $holes): bool
    {
        if (!self::pointInPolygon($outer, $point)) {
            return false;
        }

        foreach ($holes as $hole) {
            if (self::pointInPolygon($hole, $point)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Croisement strict (les extremites ne comptent pas) : chaque segment doit
     * separer les extremites de l'autre.
     *
     * cross(a, b, p) = sens de p par rapport a la droite ab.
     */
    private static function segmentsIntersect(array $p1, array $p2, array $q1, array $q2): bool
    {
        $d1 = self::cross($p1, $p2, $q1);
        $d2 = self::cross($p1, $p2, $q2);
        $d3 = self::cross($q1, $q2, $p1);
        $d4 = self::cross($q1, $q2, $p2);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    /** Aire signee du triangle abc : positif si a-b-c est dans le sens trigonometrique. */

    /**
     * Pont entre le parcours (contour ou trou deja rattache) et un trou.
     *
     * Le pont doit rester a l'interieur de la region, ne passer par aucun autre
     * sommet, et ne croiser aucun segment deja place.
     *
     * @param list<array{float, float>> $polygon
     * @param list<array{float, float}> $hole
     * @param list<array{float, float>> $outer
     * @param list<list<array{float, float}>> $holes
     * @param list<array{array{float, float}, array{float, float}}> $segments
     * @return array{int, int}|null
     */
    /** @param array<string, bool> $used */
    private static function findBridge(array $polygon, array $hole, array $outer, array $holes, array $segments, array $used): ?array
    {
        $counts = [];

        foreach ($polygon as $point) {
            $key = self::vertexKey($point);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $candidates = [];

        foreach ($hole as $j => $q) {
            if (isset($used[self::vertexKey($q)])) {
                continue;
            }

            $best = null;

            foreach ($polygon as $i => $p) {
                if ($p === $q || ($counts[self::vertexKey($p)] ?? 0) > 1 || isset($used[self::vertexKey($p)])) {
                    continue;
                }

                $distance = ($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2;

                if ($best === null || $distance < $best[1]) {
                    $best = [$i, $distance];
                }
            }

            if ($best !== null) {
                $candidates[] = [$best[0], $j, $best[1]];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

        foreach ($candidates as $candidate) {
            if (self::visible($candidate[0], $candidate[1], $polygon, $hole, $outer, $holes, $segments)) {
                return [$candidate[0], $candidate[1]];
            }
        }

        foreach ($polygon as $i => $p) {
            if (($counts[self::vertexKey($p)] ?? 0) > 1 || isset($used[self::vertexKey($p)])) {
                continue;
            }

            foreach ($hole as $j => $q) {
                if (isset($used[self::vertexKey($q)])) {
                    continue;
                }

                if (self::visible($i, $j, $polygon, $hole, $outer, $holes, $segments)) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{float, float>> $polygon
     * @param list<array{float, float}> $hole
     * @param list<array{float, float>> $outer
     * @param list<list<array{float, float}>> $holes
     * @param list<array{array{float, float}, array{float, float}}> $segments
     */
    private static function visible(int $i, int $j, array $polygon, array $hole, array $outer, array $holes, array $segments): bool
    {
        $p = $polygon[$i];
        $q = $hole[$j];

        if ($p === $q) {
            return false;
        }

        $mid = [($p[0] + $q[0]) / 2, ($p[1] + $q[1]) / 2];

        if (!self::inRegion($mid, $outer, $holes)) {
            return false;
        }

        // Un pont ne doit pas passer par un autre sommet.
        $points = array_merge($polygon, ...$holes);

        foreach ($points as $point) {
            if ($point === $p || $point === $q) {
                continue;
            }

            if (self::cross($p, $q, $point) === 0.0 && self::between($p, $q, $point)) {
                return false;
            }
        }

        foreach ($segments as [$a, $b]) {
            if ($a === $p || $b === $p || $a === $q || $b === $q) {
                continue;
            }

            if (self::segmentsIntersect($p, $q, $a, $b)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{float, float>> $polygon */
    private static function edges(array $polygon): array
    {
        $n = count($polygon);
        $edges = [];

        for ($i = 0; $i < $n; $i++) {
            $edges[] = [$polygon[$i], $polygon[($i + 1) % $n]];
        }

        return $edges;
    }

    /**
     * Triangulation du parcours (contour + ponts), parcouru dans le sens du
     * contour exterieur.
     *
     * Un sommet aligne ne peut pas etre le sommet d'une oreille : le segment de
     * contour qui le touche serait perdu, et l'arete correspondante ne serait pas
     * refermee. On decoupe alors le polygone par une diagonale issue de ce sommet.
     *
     * @param list<array{float, float}> $vertices
     * @param list<array{float, float}> $outer
     * @param list<list<array{float, float}>> $holes
     * @param list<array{array{float, float}, array{float, float}}> $contours
     * @return list<array{array{float, float}, array{float, float}, array{float, float}}>>
     */
    private static function triangulateWalk(array $vertices, array $outer, array $holes, array $contours): array
    {
        $n = count($vertices);

        if ($n < 3) {
            return [];
        }

        for ($i = 0; $i < $n; $i++) {
            $prev = $vertices[($i - 1 + $n) % $n];
            $curr = $vertices[$i];
            $next = $vertices[($i + 1) % $n];

            if (self::cross($prev, $curr, $next) <= 1e-12) {
                continue; // sommet concave ou aligne
            }

            $centroid = [($prev[0] + $curr[0] + $next[0]) / 3, ($prev[1] + $curr[1] + $next[1]) / 3];

            if (!self::inRegion($centroid, $outer, $holes)) {
                continue; // l'oreille sort de la region
            }

            if (!self::diagonalValid($vertices, $prev, $next, $outer, $holes, $contours)) {
                continue;
            }

            // Aucun autre sommet ne doit tomber a l'interieur de l'oreille, y
            // compris un sommet de contour deja consomme par une autre oreille.
            $blocked = false;

            for ($k = 0; $k < $n; $k++) {
                if ($k === $i || $vertices[$k] === $prev || $vertices[$k] === $curr || $vertices[$k] === $next) {
                    continue;
                }

                if (self::cross($prev, $curr, $vertices[$k]) > 1e-12
                    && self::cross($curr, $next, $vertices[$k]) > 1e-12
                    && self::cross($next, $prev, $vertices[$k]) > 1e-12) {
                    $blocked = true;
                    break;
                }
            }

            foreach ($contours as [$a, $b]) {
                foreach ([$a, $b] as $point) {
                    if ($point === $prev || $point === $curr || $point === $next) {
                        continue;
                    }

                    if (self::cross($prev, $curr, $point) > 1e-12
                        && self::cross($curr, $next, $point) > 1e-12
                        && self::cross($next, $prev, $point) > 1e-12) {
                        $blocked = true;
                        break 2;
                    }
                }
            }

            if ($blocked) {
                continue;
            }

            // Le polygone restant est le parcours sans le sommet de l'oreille.
            $remaining = array_merge(array_slice($vertices, $i + 1), array_slice($vertices, 0, $i));

            return array_merge(
                [[$prev, $curr, $next]],
                $remaining === [] ? [] : self::triangulateWalk($remaining, $outer, $holes, $contours)
            );
        }

        // Aucune oreille : un sommet est aligne. On cherche une diagonale valide
        // issue de ce sommet, puis on traite les deux parts obtenues.
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue; // arete du contour, pas une diagonale
                }

                $a = $vertices[$i];
                $b = $vertices[$j];

                if (!self::diagonalValid($vertices, $a, $b, $outer, $holes, $contours)) {
                    continue;
                }

                $part1 = array_slice($vertices, $i, $j - $i + 1);
                $part2 = array_merge(array_slice($vertices, $j), array_slice($vertices, 0, $i + 1));

                return array_merge(
                    self::triangulateWalk($part1, $outer, $holes, $contours),
                    self::triangulateWalk($part2, $outer, $holes, $contours)
                );
            }
        }

        // Region degenere : tous les sommets sont alignes.
        return [];
    }

    /**
     * La diagonale doit rester a l'interieur de la region (contour exterieur,
     * exterieur des trous) et ne croiser aucun segment du parcours.
     *
     * @param list<array{float, float}> $vertices
     * @param list<array{float, float>> $outer
     * @param list<list<array{float, float}>> $holes
     */
    private static function diagonalValid(array $vertices, array $prev, array $next, array $outer, array $holes, array $contours): bool
    {
        $n = count($vertices);
        $mid = [($prev[0] + $next[0]) / 2, ($prev[1] + $next[1]) / 2];

        if (!self::inRegion($mid, $outer, $holes)) {
            return false;
        }

        for ($k = 0; $k < $n; $k++) {
            $a = $vertices[$k];
            $b = $vertices[($k + 1) % $n];

            // Une diagonale qui passe par un autre sommet sort de la region au
            // dela de ce sommet : elle n'est pas valide. Les extremites de la
            // diagonale ne comptent pas.
            if ($a !== $prev && $a !== $next
                && abs(self::cross($prev, $next, $a)) < 1e-9
                && self::between($prev, $next, $a)) {
                return false;
            }

            // segmentsIntersect ne signale pas les contacts en extremites, donc les
            // aretes incidentes a la diagonale peuvent etre testees sans risque.
            if (self::segmentsIntersect($prev, $next, $a, $b)) {
                return false;
            }
        }

        // Le contour d'origine est une frontiere definitive : une diagonale ne
        // doit pas le traverser, ni passer par un de ses sommets, meme si l'arete
        // correspondante a deja ete consommee par une oreille.
        foreach ($contours as [$a, $b]) {
            if (self::segmentsIntersect($prev, $next, $a, $b)) {
                return false;
            }

            foreach ([$a, $b] as $point) {
                if ($point === $prev || $point === $next) {
                    continue;
                }

                if (abs(self::cross($prev, $next, $point)) < 1e-9 && self::between($prev, $next, $point)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function vertexKey(array $point): string
    {
        return sprintf('%.6f,%.6f', $point[0], $point[1]);
    }
}
