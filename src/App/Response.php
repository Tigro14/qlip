<?php

declare(strict_types=1);

namespace Qlip\App;

final class Response
{
    private string $body;
    private int $status;

    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** @param array<string, string> $headers */
    public static function file(string $body, string $name, string $type, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . self::safeName($name) . '"',
        ] + $headers);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        // Un en-tete ne contient pas de retour a la ligne : sans ce filtre, une valeur
        // reprise de la requete dans Location permettrait d'ajouter des en-tetes.
        return new self('', $status, ['Location' => substr(str_replace(["\r", "\n"], '', $location), 0, 512)]);
    }

    /**
     * Nom de fichier pour Content-Disposition : un en-tete, donc ni guillemet, ni
     * retour a la ligne, ni octet de controle. Le nom vient d'un identifiant en base,
     * mais on ne suppose pas.
     */
    private static function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

        return $clean === '' ? 'fichier' : substr($clean, 0, 120);
    }

    /** En-tetes de securite, posees sur toutes les reponses de l'app. */
    public function withSecurityHeaders(): self
    {
        $this->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @param array<string, string> $headers */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        // Les en-tetes de securite s'appliquent a toutes les reponses de l'app, pas
        // seulement aux pages HTML : un JSON rendu par erreur serait sniffé sinon.
        $this->withSecurityHeaders();

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
