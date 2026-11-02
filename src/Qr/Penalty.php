<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Score de penalite d'un masque (ISO/IEC 18004, table 10). Le masque au score
 * le plus faible est retenu.
 */
final class Penalty
{
    /** @param array<int, array<int, bool|null>> $grid */
    public static function score(array $grid): int
    {
        $size = count($grid);
        $score = 0;
        $dark = 0;

        // Regle 1 : groupes consecutifs de 5+ modules de la meme couleur.
        for ($i = 0; $i < $size; $i++) {
            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                if ($grid[$i][$j] === $grid[$i][$j - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }

            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                if ($grid[$j][$i] === $grid[$j - 1][$i]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        // Regle 2 : blocs 2x2 de la meme couleur.
        for ($row = 0; $row + 1 < $size; $row++) {
            for ($col = 0; $col + 1 < $size; $col++) {
                $value = $grid[$row][$col];
                if ($value === null) {
                    continue;
                }

                if ($grid[$row][$col + 1] === $value && $grid[$row + 1][$col] === $value && $grid[$row + 1][$col + 1] === $value) {
                    $score += 3;
                }
            }
        }

        // Regle 3 : motif 1011101 encadre de 4 modules clairs (ou son miroir).
        $window = [true, false, true, true, true, false, true, false, false, false, false];
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j + 11 <= $size; $j++) {
                foreach (['row', 'col'] as $direction) {
                    $straight = true;
                    $reversed = true;
                    for ($k = 0; $k < 11; $k++) {
                        $cell = $direction === 'row' ? $grid[$i][$j + $k] : $grid[$j + $k][$i];
                        if ($cell === null) {
                            $straight = false;
                            $reversed = false;
                            break;
                        }
                        if ($cell !== $window[$k]) {
                            $straight = false;
                        }
                        if ($cell !== $window[10 - $k]) {
                            $reversed = false;
                        }
                    }

                    if ($straight || $reversed) {
                        $score += 40;
                    }
                }
            }
        }

        // Regle 4 : proportion de modules sombres (ecart de 5 % -> +10 points).
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($grid[$row][$col] === true) {
                    $dark++;
                }
            }
        }

        $percent = $dark * 100 / ($size * $size);
        $score += (int) (floor(abs($percent - 50) / 5) * 10);

        return $score;
    }
}
