<?php

declare(strict_types=1);

namespace Qlip\Qr;

/**
 * Rendus du QR code : SVG, PNG (encodeur maison, sans dependance a GD) et ASCII.
 */
final class Renderer
{
    /** Zone silencieuse obligatoire : 4 modules. */
    public const QUIET_ZONE = 4;

    public static function svg(
        QrCode $qr,
        int $modulePx = 8,
        int $quiet = self::QUIET_ZONE,
        string $dark = '#000000',
        string $light = '#ffffff'
    ): string {
        $size = $qr->size + 2 * $quiet;
        $pixels = $size * $modulePx;

        $parts = [
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges">',
                $pixels,
                $pixels,
                $pixels,
                $pixels
            ),
            sprintf('<rect width="%d" height="%d" fill="%s"/>', $pixels, $pixels, e($light)),
        ];

        for ($row = 0; $row < $qr->size; $row++) {
            for ($col = 0; $col < $qr->size; $col++) {
                if (!$qr->isDark($row, $col)) {
                    continue;
                }

                $parts[] = sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
                    ($col + $quiet) * $modulePx,
                    ($row + $quiet) * $modulePx,
                    $modulePx,
                    $modulePx,
                    e($dark)
                );
            }
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }

    /**
     * PNG 8 bits indexe, ecrit a la main (chunk par chunk) : fonctionne sans l'extension GD.
     */
    public static function png(QrCode $qr, int $modulePx = 4, int $quiet = self::QUIET_ZONE, array $palette = ['#000000', '#ffffff']): string
    {
        $size = $qr->size + 2 * $quiet;
        $indices = [];

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $dark = false;

                $r = $row - $quiet;
                $c = $col - $quiet;
                if ($r >= 0 && $r < $qr->size && $c >= 0 && $c < $qr->size) {
                    $dark = $qr->isDark($r, $c);
                }

                $indices[$row][$col] = $dark ? 0 : 1;
            }
        }

        // Upstream filter 0 (aucune filtration) sur chaque ligne.
        $raw = '';
        for ($row = 0; $row < $size; $row++) {
            $raw .= "\0" . implode('', array_map(static fn (int $i): string => chr($i), $indices[$row]));
        }

        $paletteBytes = '';
        foreach ($palette as $hex) {
            $rgb = hex2bin(str_replace('#', '', $hex));
            if ($rgb === false || strlen($rgb) !== 3) {
                throw new \InvalidArgumentException('Couleur palette invalide : ' . $hex);
            }
            $paletteBytes .= $rgb;
        }

        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('N2C5', $size, $size, 8, 3, 0, 0, 0);

        return $signature
            . self::chunk('IHDR', $ihdr)
            . self::chunk('PLTE', $paletteBytes)
            . self::chunk('IDAT', gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    public static function ascii(QrCode $qr, int $quiet = 2): string
    {
        $lines = [];

        for ($row = -$quiet; $row < $qr->size + $quiet; $row++) {
            $line = '';
            for ($col = -$quiet; $col < $qr->size + $quiet; $col++) {
                $line .= ($row >= 0 && $row < $qr->size && $col >= 0 && $col < $qr->size && $qr->isDark($row, $col)) ? '#' : ' ';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
