<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Qr\QrCode;
use Qlip\Qr\Tables;

$texts = array_filter(explode("\n", stream_get_contents(STDIN)), static fn (string $line): bool => $line !== '');

foreach ($texts as $text) {
    foreach (Tables::LEVELS as $level) {
        try {
            $qr = QrCode::encode($text, $level);
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage(), 'text' => $text, 'level' => $level]), PHP_EOL;
            continue;
        }

        $matrix = [];
        for ($row = 0; $row < $qr->size; $row++) {
            $line = '';
            for ($col = 0; $col < $qr->size; $col++) {
                $line .= $qr->isDark($row, $col) ? '1' : '0';
            }
            $matrix[] = $line;
        }

        echo json_encode([
            'text' => $text,
            'level' => $level,
            'mode' => $qr->mode,
            'version' => $qr->version,
            'mask' => $qr->mask,
            'matrix' => $matrix,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
}
