<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Reed-Solomon sur GF(256) (polynome primitif 0x11D), comme l'exige ISO/IEC 18004.
 */
final class ReedSolomon
{
    private const PRIMITIVE = 0x11D;

    /** @var array<int,int>|null */
    private static ?array $exp = null;
    /** @var array<int,int>|null */
    private static ?array $log = null;

    private static function tables(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = array_fill(0, 256, 0);
        $log = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= self::PRIMITIVE;
            }
        }

        self::$exp = $exp;
        self::$log = $log;
    }

    private static function exp(int $n): int
    {
        self::tables();
        return self::$exp[$n % 255];
    }

    private static function log(int $n): int
    {
        self::tables();
        if ($n < 1) {
            throw new \InvalidArgumentException('log(0) indefini');
        }
        return self::$log[$n];
    }

    /**
     * Polynome generateur de degre $degree : prod (x - a^i) pour i = 0..degree-1.
     *
     * @return list<int> coefficients, du plus haut degre au plus bas
     */
    public static function generator(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] ^= $poly[$j];
                $next[$j + 1] ^= self::exp(self::log($poly[$j]) + $i);
            }
            $poly = $next;
        }

        return $poly;
    }

    /**
     * Division polynomialle : $degree reste de la division de (data * x^degree) par le generateur.
     *
     * @param list<int> $data mots de code de donnees
     * @return list<int> $degree mots de code de correction
     */
    public static function encode(array $data, int $degree): array
    {
        if ($degree === 0) {
            return [];
        }

        $generator = self::generator($degree);          // monique : generator[0] == 1
        $coefficients = array_slice($generator, 1);     // les $degree coefficients utilises par la division

        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];

            for ($i = 0; $i < $degree - 1; $i++) {
                $remainder[$i] = $remainder[$i + 1];
            }
            $remainder[$degree - 1] = 0;

            if ($factor === 0) {
                continue;
            }

            for ($i = 0; $i < $degree; $i++) {
                if ($coefficients[$i] !== 0) {
                    $remainder[$i] ^= self::exp(self::log($coefficients[$i]) + self::log($factor));
                }
            }
        }

        return $remainder;
    }
}
