<?php

declare(strict_types=1);

namespace Qlip\Support;

/**
 * Ecriteur ZIP minimal (methode "store", sans dependance exterieure).
 *
 * Suffisant pour un conteneur .3MF : quelques fichiers XML, la compression
 * n'est pas necessaire pour un fichier de quelques dizaines de ko.
 */
final class ZipWriter
{
    /** @var array<string, string> chemin => contenu */
    private array $entries = [];

    public function add(string $path, string $contents): void
    {
        $this->entries[$path] = $contents;
    }

    public function contents(): string
    {
        $local = '';
        $central = '';

        // Horodatage fige : un meme badge produit toujours le meme archive.
        $time = self::dosTime(0, 0, 0);
        $date = self::dosDate(1980, 1, 1);

        foreach ($this->entries as $path => $contents) {
            $name = self::normalize($path);
            $crc = crc32($contents);
            $length = strlen($contents);

            $offset = strlen($local);

            $local .= pack('V', 0x04034b50)
                . pack('v5', 20, 0, 0, $time, $date)
                . pack('V3', $crc, $length, $length)
                . pack('v2', strlen($name), 0)
                . $name
                . $contents;

            $central .= pack('V', 0x02014b50)
                . pack('v6', 20, 20, 0, 0, $time, $date)
                . pack('V3', $crc, $length, $length)
                . pack('v5', strlen($name), 0, 0, 0, 0)
                . pack('V', 0)
                . pack('V', $offset)
                . $name;
        }

        $count = count($this->entries);

        $eocd = pack('V', 0x06054b50)
            . pack('v2', 0, 0)
            . pack('v2', $count, $count)
            . pack('V2', strlen($central), strlen($local))
            . pack('v', 0);

        return $local . $central . $eocd;
    }

    private static function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function dosTime(int $hour, int $minute, int $second): int
    {
        return ($hour << 11) | ($minute << 5) | intdiv($second, 2);
    }

    private static function dosDate(int $year, int $month, int $day): int
    {
        return (($year - 1980) << 9) | ($month << 5) | $day;
    }
}
