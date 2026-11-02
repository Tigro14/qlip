<?php

declare(strict_types=1);

namespace Qlip\Stl;

use Qlip\Qr\QrCode;

/**
 * Construction du porte-cles : plaque, anneau (trou), modules QR.
 *
 * La surface est traitee module par module : chaque cellule est un carre, et les
 * aretes d'une cellule sombre sont partagees entre la face de la plaque (elle est
 * un trou de la face haute) et la paroi de l'alveole ou du relief. Le mesh est
 * ainsi un seul solide manifold, sans polygone a trous imbriques (la seule face a
 * trous est le cadre de la plaque, ou le cercle ne touche jamais l'empreinte du QR).
 *
 * Convention d'orientation : la matiere reste a gauche de chaque contour. La
 * normale d'une face est vers le haut si le contour est trigonometrique, vers le
 * bas s'il est horaire ; la normale d'une paroi est du cote droit du contour.
 */
final class Badge
{
    /** @return list<array{name: string, color: string, mesh: Mesh}> */
    public static function build(QrCode $qr, Design $design): array
    {
        $module = $design->moduleSize($qr->size);
        $bounds = self::qrBounds($qr, $module, $design);

        $plate = Triangulator::roundedRectangle(0.0, 0.0, $design->width, $design->height, $design->cornerRadius);

        $circle = Triangulator::orient(
            Triangulator::circle($design->width / 2.0, $design->height - $design->band, $design->holeRadius, 24),
            false
        );

        $footprint = self::frameBoundary($bounds, $qr->size, $module);

        $bottom = 0.0;
        $top = $design->thickness;
        $raised = $design->style === Design::STYLE_RAISED;
        $feature = $raised ? $top + $design->depth : $top - $design->depth;

        $cells = self::cells($qr, $bounds, $module);

        if ($design->bicolor) {
            // Deux volumes : chacun est ferme de facon autonome, les faces
            // confondues au plan de la surface sont acceptees par les slicers.
            $base = new Mesh();
            $base->addPrism($plate, [$circle], $bottom, $top);

            $ink = new Mesh();

            foreach ($cells as $cell) {
                if (!$cell['dark']) {
                    continue;
                }

                $ink->addRectPrism($cell['rect'], min($top, $feature), max($top, $feature));
            }

            return [
                ['name' => 'base', 'color' => $design->colorBase, 'mesh' => $base],
                ['name' => 'ink', 'color' => $design->colorInk, 'mesh' => $ink],
            ];
        }

        $mesh = new Mesh();

        // Face haute : le cadre de la plaque, puis les modules clairs.
        $mesh->addFace(Triangulator::triangulate($plate, [$footprint, $circle]), $top, true);

        foreach ($cells as $cell) {
            if ($cell['dark']) {
                continue;
            }

            $mesh->addFace(Triangulator::rectangle($cell['rect']), $top, true);
        }

        $mesh->addFace(Triangulator::triangulate($plate, [$circle]), $bottom, false);
        $mesh->addWalls($plate, $bottom, $top);
        $mesh->addWalls($circle, $bottom, $top);

        $low = min($top, $feature);
        $high = max($top, $feature);

        foreach ($cells as $cell) {
            if (!$cell['dark']) {
                continue;
            }

            // Fond de l'alveole ou sommet du relief.
            $mesh->addFace(Triangulator::rectangle($cell['rect']), $feature, true);

            // Parois : la matiere (le module clair d'enface, ou le module sombre
            // en relief) doit rester a gauche du contour parcouru.
            foreach (self::edges($cell) as [$edge, $neighbour]) {
                if ($neighbour !== null && $cells[$neighbour]['dark']) {
                    continue;
                }

                $a = $raised ? $edge[0] : $edge[1];
                $b = $raised ? $edge[1] : $edge[0];

                $mesh->addWallEdge($a, $b, $low, $high);
            }
        }

        return [['name' => 'badge', 'color' => $design->colorBase, 'mesh' => $mesh]];
    }

    /** @return array{float, float, float, float} empreinte du QR sur la plaque */
    public static function qrBounds(QrCode $qr, float $module, Design $design): array
    {
        $total = $module * $qr->size;
        $verticalSpace = $design->height - $design->margin - $design->band - $design->holeRadius;

        $x0 = ($design->width - $total) / 2.0;
        $y0 = $design->margin + max(0.0, ($verticalSpace - $total) / 2.0);

        return [$x0, $y0, $x0 + $total, $y0 + $total];
    }

    /**
     * Contour de l'empreinte du QR, subdivise au coin de chaque module.
     *
     * Ses segments sont exactement les aretes des cellules de bord : les triangles
     * du cadre et les faces des cellules clairs partagent ainsi les memes aretes,
     * sans T-jonction sur la face haute.
     *
     * @param array{float, float, float, float} $bounds
     * @return list<array{float, float}>
     */
    private static function frameBoundary(array $bounds, int $size, float $module): array
    {
        [$x0, $y0, $x1, $y1] = $bounds;
        $points = [];

        for ($k = 0; $k < $size; $k++) {
            $points[] = [$x0 + $k * $module, $y0];
        }

        for ($k = 0; $k < $size; $k++) {
            $points[] = [$x1, $y0 + $k * $module];
        }

        for ($k = 0; $k < $size; $k++) {
            $points[] = [$x1 - $k * $module, $y1];
        }

        for ($k = 0; $k < $size; $k++) {
            $points[] = [$x0, $y1 - $k * $module];
        }

        return $points;
    }

    /**
     * Cellules de la grille, avec leurs quatre aretes (dans le sens
     * trigonometrique du carre) et la cellule en face de chaque arete.
     *
     * @return array<string, array{dark: bool, rect: array{float, float, float, float}, corners: list<array{float, float}>, neighbours: list<string|null}>
     */
    private static function cells(QrCode $qr, array $bounds, float $module): array
    {
        $cells = [];

        for ($row = 0; $row < $qr->size; $row++) {
            for ($col = 0; $col < $qr->size; $col++) {
                // v = rangee depuis le bas : les coordonnees sont alors dans le
                // sens trigonometrique, comme les contours.
                $u = $col;
                $v = $qr->size - 1 - $row;

                $x0 = $bounds[0] + $u * $module;
                $y0 = $bounds[1] + $v * $module;

                $cells[$row . ',' . $col] = [
                    'dark' => $qr->isDark($row, $col),
                    'rect' => [$x0, $y0, $x0 + $module, $y0 + $module],
                    'corners' => [
                        [$x0, $y0],
                        [$x0 + $module, $y0],
                        [$x0 + $module, $y0 + $module],
                        [$x0, $y0 + $module],
                    ],
                    // Cellule en face de chaque arete (dans le sens du carre) :
                    // bas, droite, haut, gauche. Null hors de la grille.
                    'neighbours' => [
                        self::key($row + 1, $col, $qr->size),
                        self::key($row, $col + 1, $qr->size),
                        self::key($row - 1, $col, $qr->size),
                        self::key($row, $col - 1, $qr->size),
                    ],
                ];
            }
        }

        return $cells;
    }

    /**
     * Aretes du carre dans le sens trigonometrique, avec la cellule en face.
     *
     * @param array{dark: bool, rect: array, corners: list, neighbours: list} $cell
     * @return list<array{array{array{float, float}, array{float, float}}, string|null}>
     */
    private static function edges(array $cell): array
    {
        $corners = $cell['corners'];
        $edges = [];

        for ($i = 0; $i < 4; $i++) {
            $edges[] = [[$corners[$i], $corners[($i + 1) % 4]], $cell['neighbours'][$i]];
        }

        return $edges;
    }

    private static function key(int $row, int $col, int $size): ?string
    {
        if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
            return null;
        }

        return $row . ',' . $col;
    }
}
