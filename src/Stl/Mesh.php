<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Enveloppe de triangles (coordonnees en millimetres).
 *
 * L'ordre des sommets definit la normale (sortante). Les triangles peuvent etre
 * repartis en "parties" : chaque partie doit etre un solide ferme independamment
 * (cas des volumes bicolor).
 */
final class Mesh
{
    /** @var list<array{array{float, float, float}, array{float, float, float}, array{float, float, float}}> */
    public array $triangles = [];

    /** @var list<string> partie de chaque triangle */
    public array $parts = [];

    private string $currentPart = '';

    public function setPart(string $part): void
    {
        $this->currentPart = $part;
    }

    /**
     * Ferme un polygone extrude entre $z0 et $z1.
     *
     * @param list<array{float, float}> $outer contour exterieur (CCW)
     * @param list<list<array{float, float}>> $holes trous (CW)
     */
    public function addPrism(array $outer, array $holes, float $z0, float $z1): void
    {
        $triangles = Triangulator::triangulate($outer, $holes);

        $this->addFace($triangles, $z0, false);
        $this->addFace($triangles, $z1, true);

        $this->addWalls($outer, $z0, $z1);

        foreach ($holes as $hole) {
            $this->addWalls($hole, $z0, $z1);
        }
    }

    /**
     * Paroi laterale le long d'un contour. Le contour est parcouru avec la
     * matiere a sa gauche : la normale de la paroi pointe donc vers l'exterieur
     * du solide. L'arete basse suit le sens du contour, l'arete haute le sens
     * inverse (elles sont partagees avec la face basse et la face haute).
     *
     * @param list<array{float, float}> $boundary
     */
    public function addWalls(array $boundary, float $z0, float $z1): void
    {
        $n = count($boundary);

        for ($i = 0; $i < $n; $i++) {
            $this->addWallEdge($boundary[$i], $boundary[($i + 1) % $n], $z0, $z1);
        }
    }

    /**
     * Paroi d'une seule arete.
     *
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    public function addWallEdge(array $a, array $b, float $z0, float $z1): void
    {
        $a0 = [$a[0], $a[1], $z0];
        $b0 = [$b[0], $b[1], $z0];
        $a1 = [$a[0], $a[1], $z1];
        $b1 = [$b[0], $b[1], $z1];

        $this->push([$a0, $b0, $b1]);
        $this->push([$a0, $b1, $a1]);
    }

    /**
     * @param list<array{array{float, float}, array{float, float}, array{float, float}}> $triangles
     */
    public function addFace(array $triangles, float $z, bool $up): void
    {
        foreach ($triangles as [$a, $b, $c]) {
            $pa = [$a[0], $a[1], $z];
            $pb = [$b[0], $b[1], $z];
            $pc = [$c[0], $c[1], $z];

            $this->push($up ? [$pa, $pb, $pc] : [$pa, $pc, $pb]);
        }
    }

    /** @param array{float, float, float, float} $rect [x0, y0, x1, y1] */
    public function addRectPrism(array $rect, float $z0, float $z1): void
    {
        $this->addFace(Triangulator::rectangle($rect), $z0, false);
        $this->addFace(Triangulator::rectangle($rect), $z1, true);
        $this->addWalls(Triangulator::rectanglePolygon($rect), $z0, $z1);
    }

    public function count(): int
    {
        return count($this->triangles);
    }

    /** @param array{array{float, float, float}, array{float, float, float}, array{float, float, float}} $triangle */
    public static function normal(array $triangle): array
    {
        [$a, $b, $c] = $triangle;

        $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];

        $nx = $u[1] * $v[2] - $u[2] * $v[1];
        $ny = $u[2] * $v[0] - $u[0] * $v[2];
        $nz = $u[0] * $v[1] - $u[1] * $v[0];

        $length = sqrt($nx * $nx + $ny * $ny + $nz * $nz);

        if ($length < 1e-12) {
            return [0.0, 0.0, 0.0];
        }

        return [$nx / $length, $ny / $length, $nz / $length];
    }

    /** Aire d'un triangle. */
    public static function area(array $triangle): float
    {
        [$a, $b, $c] = $triangle;

        $u = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $v = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];

        $nx = $u[1] * $v[2] - $u[2] * $v[1];
        $ny = $u[2] * $v[0] - $u[0] * $v[2];
        $nz = $u[0] * $v[1] - $u[1] * $v[0];

        return sqrt($nx * $nx + $ny * $ny + $nz * $nz) / 2.0;
    }

    /**
     * Volume signe (theoreme de la divergence) : positif si les normales
     * pointent vers l'exterieur du solide.
     */
    public function signedVolume(): float
    {
        $volume = 0.0;

        foreach ($this->triangles as $triangle) {
            [$a, $b, $c] = $triangle;

            $volume += $a[0] * ($b[1] * $c[2] - $b[2] * $c[1])
                - $a[1] * ($b[0] * $c[2] - $b[2] * $c[0])
                + $a[2] * ($b[0] * $c[1] - $b[1] * $c[0]);
        }

        return $volume / 6.0;
    }

    /** @return array{float, float, float, float, float, float} minx, miny, minz, maxx, maxy, maxz */
    public function bounds(): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];

        foreach ($this->triangles as $triangle) {
            foreach ($triangle as $point) {
                for ($i = 0; $i < 3; $i++) {
                    $min[$i] = min($min[$i], $point[$i]);
                    $max[$i] = max($max[$i], $point[$i]);
                }
            }
        }

        return [$min[0], $min[1], $min[2], $max[0], $max[1], $max[2]];
    }

    /**
     * Fermeture : chaque arete doit etre parcourue autant de fois dans un sens
     * que dans l'autre (mesh ferme et orientable).
     *
     * Une arete peut etre utilisee plus d'une fois par sens lorsque deux solids
     * se touchent (point de contact d'une grille de modules) : ce n'est pas une
     * erreur, c'est un contact, signale separement par contacts().
     *
     * @return list<string> problems (vide si le mesh est correct)
     */
    public function checkWatertight(): array
    {
        $problems = [];
        $edges = [];

        foreach ($this->triangles as $triangle) {
            if (self::area($triangle) <= 1e-9) {
                $problems[] = sprintf(
                    'triangle degenere : %.4f,%.4f,%.4f - %.4f,%.4f,%.4f - %.4f,%.4f,%.4f',
                    $triangle[0][0], $triangle[0][1], $triangle[0][2],
                    $triangle[1][0], $triangle[1][1], $triangle[1][2],
                    $triangle[2][0], $triangle[2][1], $triangle[2][2]
                );
            }

            for ($i = 0; $i < 3; $i++) {
                $p = $triangle[$i];
                $q = $triangle[($i + 1) % 3];
                $edges[self::vertexKey($p) . '|' . self::vertexKey($q)][] = 1;
            }
        }

        foreach ($edges as $key => $uses) {
            $reverse = $edges[self::reverseKey($key)] ?? [];

            if (count($uses) !== count($reverse)) {
                $problems[] = sprintf(
                    'arete %s : %d fois dans ce sens, %d fois en sens inverse (mesh ouvert)',
                    $key,
                    count($uses),
                    count($reverse)
                );
            }
        }

        return $problems;
    }

    /**
     * Aretes utilisees plus d'une fois par sens : contact entre deux parties
     * (deux modules touches en coin, ou faces confondues d'un volume bicolor).
     *
     * @return list<string>
     */
    public function contacts(): array
    {
        $edges = [];

        foreach ($this->triangles as $triangle) {
            for ($i = 0; $i < 3; $i++) {
                $p = $triangle[$i];
                $q = $triangle[($i + 1) % 3];
                $edges[self::vertexKey($p) . '|' . self::vertexKey($q)][] = 1;
            }
        }

        $contacts = [];

        // Un contact est une arete parcourue deux fois dans le meme sens : deux
        // faces confondues (solides qui se touchent), pas une arete partagee.
        foreach ($edges as $key => $uses) {
            if (count($uses) > 1) {
                $contacts[] = $key;
            }
        }

        return $contacts;
    }

    private function push(array $triangle): void
    {
        $this->triangles[] = $triangle;
        $this->parts[] = $this->currentPart;
    }

    private static function vertexKey(array $v): string
    {
        return sprintf('%.4f,%.4f,%.4f', $v[0], $v[1], $v[2]);
    }

    private static function reverseKey(string $key): string
    {
        [$a, $b] = explode('|', $key);

        return $b . '|' . $a;
    }
}
