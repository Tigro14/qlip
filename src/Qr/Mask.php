<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Masques de la norme ISO/IEC 18004 (8 motifs).
 */
final class Mask
{
    public static function apply(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2 + ($row * $col) % 3) === 0,
            6 => (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            7 => (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            default => false,
        };
    }
}
