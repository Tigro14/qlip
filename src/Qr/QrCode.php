<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Encodeur QR autonome (ISO/IEC 18004) : modes numerique, alphanumerique et octet,
 * selection automatique de la version, Reed-Solomon, choix du masque par penalite.
 */
final class QrCode
{
    public readonly int $version;
    public readonly string $level;
    public readonly int $size;
    public readonly string $mode;
    public readonly int $mask;

    /** @var array<int, array<int, bool>> */
    public array $modules;

    private const ALNUM_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private function __construct(int $version, string $level, string $mode, int $mask, array $modules)
    {
        $this->version = $version;
        $this->level = strtoupper($level);
        $this->mode = $mode;
        $this->mask = $mask;
        $this->size = $version * 4 + 17;
        $this->modules = $modules;
    }

    public static function encode(string $text, string $level = 'M', int $minVersion = 1): self
    {
        $level = strtoupper($level);
        if (!in_array($level, Tables::LEVELS, true)) {
            throw new \InvalidArgumentException('Niveau de correction inconnu : ' . $level);
        }

        if ($text === '') {
            throw new \InvalidArgumentException('Le contenu du QR code est vide.');
        }

        $mode = self::detectMode($text);
        $version = self::chooseVersion($text, $mode, $level, $minVersion);
        $codewords = self::buildCodewords($text, $mode, $version, $level);

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $score = Penalty::score(MatrixBuilder::build($version, $level, $mask, $codewords));
            if ($score < $bestPenalty) {
                $bestPenalty = $score;
                $bestMask = $mask;
            }
        }

        $grid = MatrixBuilder::build($version, $level, $bestMask, $codewords);
        $modules = array_map(
            static fn (array $row): array => array_map(static fn ($v): bool => (bool) $v, $row),
            $grid
        );

        return new self($version, $level, $mode, $bestMask, $modules);
    }

    public function isDark(int $row, int $col): bool
    {
        return $this->modules[$row][$col];
    }

    /** @return array<int, array<int, bool>> */
    public function matrix(): array
    {
        return $this->modules;
    }

    /** Nombre total de mots de code (donnees + correction) pour la version choisie. */
    public function codewordCount(): int
    {
        [$g1, $d1, $g2, $d2, $ec] = Tables::RS_BLOCKS[$this->version][self::levelIndex($this->level)];

        return $g1 * ($d1 + $ec) + $g2 * ($d2 + $ec);
    }

    // ------------------------------------------------------------------

    private static function detectMode(string $text): string
    {
        if ($text !== '' && ctype_digit($text)) {
            return 'numeric';
        }

        for ($i = 0; $i < strlen($text); $i++) {
            if (strpos(self::ALNUM_CHARS, $text[$i]) === false) {
                return 'byte';
            }
        }

        return 'alphanumeric';
    }

    private static function countBits(string $mode, int $version): int
    {
        $group = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        $index = ['numeric' => 0, 'alphanumeric' => 1, 'byte' => 2][$mode];

        return Tables::COUNT_BITS[$group][$index];
    }

    private static function dataBits(string $mode, string $text): int
    {
        $len = strlen($text);

        if ($mode === 'numeric') {
            $groups = intdiv($len, 3);
            $rest = $len % 3;

            return 10 * $groups + ($rest === 2 ? 7 : ($rest === 1 ? 4 : 0));
        }

        if ($mode === 'alphanumeric') {
            return 11 * intdiv($len, 2) + 6 * ($len % 2);
        }

        return 8 * $len;
    }

    private static function levelIndex(string $level): int
    {
        return array_search($level, Tables::LEVELS, true);
    }

    private static function capacityBits(int $version, string $level): int
    {
        [$g1, $d1, $g2, $d2] = Tables::RS_BLOCKS[$version][self::levelIndex($level)];

        return 8 * ($g1 * $d1 + $g2 * $d2);
    }

    private static function chooseVersion(string $text, string $mode, string $level, int $minVersion): int
    {
        for ($version = max(1, $minVersion); $version <= 40; $version++) {
            $needed = 4 + self::countBits($mode, $version) + self::dataBits($mode, $text);
            if ($needed <= self::capacityBits($version, $level)) {
                return $version;
            }
        }

        throw new \RuntimeException('Donnee trop longue pour un QR code (version 40 depassee).');
    }

    /** @return list<int> */
    private static function buildCodewords(string $text, string $mode, int $version, string $level): array
    {
        $buffer = new BitBuffer();
        $buffer->put(['numeric' => 1, 'alphanumeric' => 2, 'byte' => 4][$mode], 4);
        $buffer->put(strlen($text), self::countBits($mode, $version));

        if ($mode === 'numeric') {
            for ($i = 0; $i < strlen($text); $i += 3) {
                $chunk = substr($text, $i, min(3, strlen($text) - $i));
                $buffer->put((int) $chunk, [1 => 4, 2 => 7, 3 => 10][strlen($chunk)]);
            }
        } elseif ($mode === 'alphanumeric') {
            $chars = str_split($text);
            for ($i = 0; $i + 1 < count($chars); $i += 2) {
                $buffer->put(self::alnumValue($chars[$i]) * 45 + self::alnumValue($chars[$i + 1]), 11);
            }
            if (count($chars) % 2 === 1) {
                $buffer->put(self::alnumValue($chars[count($chars) - 1]), 6);
            }
        } else {
            for ($i = 0; $i < strlen($text); $i++) {
                $buffer->put(ord($text[$i]), 8);
            }
        }

        [$g1, $d1, $g2, $d2, $ecPerBlock] = Tables::RS_BLOCKS[$version][self::levelIndex($level)];
        $capacity = $g1 * $d1 + $g2 * $d2;

        $remaining = 8 * $capacity - $buffer->bitLength();
        $buffer->put(0, min(4, $remaining));
        $buffer->padToByteBoundary();

        $data = $buffer->bytes();
        $padIndex = 0;
        while (count($data) < $capacity) {
            $data[] = $padIndex % 2 === 0 ? 0xEC : 0x11;
            $padIndex++;
        }

        $blocks = [];
        $cursor = 0;
        for ($i = 0; $i < $g1; $i++) {
            $blocks[] = array_slice($data, $cursor, $d1);
            $cursor += $d1;
        }
        for ($i = 0; $i < $g2; $i++) {
            $blocks[] = array_slice($data, $cursor, $d2);
            $cursor += $d2;
        }

        $parity = array_map(static fn (array $block): array => ReedSolomon::encode($block, $ecPerBlock), $blocks);

        // Interlevement : d'abord les donnees, puis les mots de correction.
        $result = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($parity as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    private static function alnumValue(string $char): int
    {
        $pos = strpos(self::ALNUM_CHARS, $char);
        if ($pos === false) {
            throw new \RuntimeException('Caractere hors mode alphanumerique : ' . $char);
        }

        return $pos;
    }
}
