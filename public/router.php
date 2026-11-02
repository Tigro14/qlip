<?php

declare(strict_types=1);

/**
 * Serveur PHP integre (developpement et tests) : les fichiers existants sont servis
 * directement, le reste passe par le front controller. Lance par bin/serve.sh et par
 * tests/http_test.py. En production, Apache ou nginx fait la meme repartition : les
 * fichiers reels sont servis par le serveur web, le reste va a public/index.php.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
