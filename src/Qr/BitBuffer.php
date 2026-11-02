<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Bitstream minimaliste : sert a construire la sequence de mots de code.
 */
final class BitBuffer
{
    /** @var list<int> */
    private array $bytes = [];

    private int $bitLength = 0;

    public function put(int $value, int $bits): void
    {
        for ($i = $bits - 1; $i >= 0; $i--) {
            $this->putBit(($value >> $i) & 1);
        }
    }

    public function putBit(int $bit): void
    {
        $byteIndex = intdiv($this->bitLength, 8);
        if ($byteIndex >= count($this->bytes)) {
            $this->bytes[] = 0;
        }

        if ($bit === 1) {
            $this->bytes[$byteIndex] |= 1 << (7 - ($this->bitLength % 8));
        }

        $this->bitLength++;
    }

    public function bitLength(): int
    {
        return $this->bitLength;
    }

    /** @return list<int> */
    public function bytes(): array
    {
        return $this->bytes;
    }

    public function padToByteBoundary(): void
    {
        $remainder = $this->bitLength % 8;
        if ($remainder !== 0) {
            $this->bitLength += 8 - $remainder;
        }
    }
}
