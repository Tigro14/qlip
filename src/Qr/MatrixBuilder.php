<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Placement des motifs : logique fidele a l'implementation de reference (ISO 18004).
 *
 * La matrice est remplie module par module ; les modules "fonctionnels" (motifs de
 * repetition, synchronisation, alignement, informations de format/version) ne sont
 * jamais ecrases par les donnees.
 */
final class MatrixBuilder
{
    /** @param list<int> $codewords */
    public static function build(int $version, string $level, int $mask, array $codewords): array
    {
        $size = $version * 4 + 17;

        // null = module libre (donnees), sinon module fonctionnel.
        $grid = array_fill(0, $size, array_fill(0, $size, null));

        // Motifs de repetition + separateurs.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;
                    if ($rr <= -1 || $size <= $rr || $cc <= -1 || $size <= $cc) {
                        continue;
                    }

                    $dark = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                        || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);

                    $grid[$rr][$cc] = $dark;
                }
            }
        }

        // Motifs d'alignement : ils sont poses AVANT la ligne de synchronisation,
        // un motif deja centre sur un module de repetition n'est pas pose (ISO 18004).
        foreach (Tables::ALIGNMENT[$version] as $row) {
            foreach (Tables::ALIGNMENT[$version] as $col) {
                if ($grid[$row][$col] !== null) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        if ($grid[$row + $r][$col + $c] !== null) {
                            continue;
                        }
                        $grid[$row + $r][$col + $c] = ($r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0));
                    }
                }
            }
        }

        // Motifs de synchronisation (la ligne ne remplace pas un motif d'alignement).
        for ($i = 8; $i < $size - 8; $i++) {
            if ($grid[$i][6] !== null) {
                continue;
            }
            $grid[$i][6] = $i % 2 === 0;

            if ($grid[6][$i] !== null) {
                continue;
            }
            $grid[6][$i] = $i % 2 === 0;
        }

        // Informations de format (BCH).
        $formatBits = FormatInfo::typeInfo($level, $mask);
        for ($i = 0; $i < 15; $i++) {
            $bit = ((int) $formatBits >> $i) & 1;

            if ($i < 6) {
                $grid[$i][8] = $bit === 1;
            } elseif ($i < 8) {
                $grid[$i + 1][8] = $bit === 1;
            } else {
                $grid[$size - 15 + $i][8] = $bit === 1;
            }

            if ($i < 8) {
                $grid[8][$size - $i - 1] = $bit === 1;
            } elseif ($i < 9) {
                $grid[8][15 - $i] = $bit === 1;
            } else {
                $grid[8][15 - $i - 1] = $bit === 1;
            }
        }
        $grid[$size - 8][8] = true; // module sombre fixe

        // Informations de version.
        if ($version >= 7) {
            $versionBits = FormatInfo::typeNumber($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = ((int) $versionBits >> $i) & 1;
                $grid[intdiv($i, 3)][$size - 8 - 3 + ($i % 3)] = $bit === 1;
                $grid[$size - 8 - 3 + ($i % 3)][intdiv($i, 3)] = $bit === 1;
            }
        }

        // Données en zigzag.
        $bitIndex = 7;
        $byteIndex = 0;
        $inc = -1;
        $row = $size - 1;

        for ($pair = $size - 1; $pair > 0; $pair -= 2) {
            // Les colonnes de la paire peuvent etre decalees d'une unite une fois
            // qu'on a franchi la colonne 6 (motif de synchronisation).
            $col = $pair <= 6 ? $pair - 1 : $pair;

            while (true) {
                for ($c = $col; $c >= $col - 1; $c--) {
                    if ($grid[$row][$c] !== null) {
                        continue;
                    }

                    $dark = false;
                    if ($byteIndex < count($codewords)) {
                        $dark = (((int) $codewords[$byteIndex] >> $bitIndex) & 1) === 1;
                    }

                    if (Mask::apply($mask, $row, $c)) {
                        $dark = !$dark;
                    }

                    $grid[$row][$c] = $dark;

                    $bitIndex--;
                    if ($bitIndex === -1) {
                        $bitIndex = 7;
                        $byteIndex++;
                    }
                }

                $row += $inc;
                if ($row < 0 || $row >= $size) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }

        return $grid;
    }
}
