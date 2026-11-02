<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Qr\QrCode;
use Qlip\Qr\Tables;

$texts = [
    'https://qlip.test/p/ab12cd',
    'http://localhost:8080/p/xk9',
    'QR de test : porte-cles Qlip',
    'HELLO WORLD',
    '012345678901234567890123456789',
    'https://qlip.example.org/p/qlip-demo-0001?v=1&s=contact&sacré',
    'A',
    '123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890',
    str_repeat('QLIP', 120),
];

foreach ($texts as $text) {
    foreach (Tables::LEVELS as $level) {
        $qr = QrCode::encode($text, $level);

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
