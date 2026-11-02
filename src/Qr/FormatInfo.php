<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Informations de format et de version (codes BCH de la norme).
 */
final class FormatInfo
{
    private const G15 = 0x537;
    private const G15_MASK = 0x5412;
    private const G18 = 0x1F25;

    /** Indicateur de niveau dans les informations de format : L=01, M=00, Q=11, H=10. */
    public static function indicator(string $level): int
    {
        return ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2][strtoupper($level)];
    }

    public static function typeInfo(string $level, int $mask): int
    {
        $data = (self::indicator($level) << 3) | $mask;

        return self::bch($data, 10, self::G15) ^ self::G15_MASK;
    }

    public static function typeNumber(int $version): int
    {
        return self::bch($version, 12, self::G18);
    }

    private static function bch(int $data, int $shift, int $generator): int
    {
        $d = $data << $shift;
        while (self::bitLength($d) - self::bitLength($generator) >= 0) {
            $d ^= $generator << (self::bitLength($d) - self::bitLength($generator));
        }

        return ($data << $shift) | $d;
    }

    private static function bitLength(int $value): int
    {
        $length = 0;
        while ($value !== 0) {
            $length++;
            $value >>= 1;
        }

        return $length;
    }
}
