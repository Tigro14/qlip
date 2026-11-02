<?php

declare(strict_types=1);

namespace Qlip\App;

/**
 * Rendu de templates PHP. Un template est rendu dans layout.php sauf si la cle
 * "layout" vaut false (JSON, images, pages partielles).
 */
final class View
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->capture($template, $data);

        if (($data['layout'] ?? true) === false) {
            return $content;
        }

        return $this->capture('layout.php', $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $path = $this->directory . '/' . $template;

        if (!is_file($path)) {
            throw new \RuntimeException('Template manquant : ' . $template);
        }

        ob_start();
        extract($data, EXTR_SKIP);
        require $path;

        return (string) ob_get_clean();
    }
}
