<?php

declare(strict_types=1);

namespace Qlip\App;

final class Request
{
    public string $method;
    public string $path;

    /** @var array<string, mixed> */
    public array $query;

    /** @var array<string, mixed> */
    public array $body;

    /** @var array<string, string> */
    public array $params = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    public function __construct(string $method, string $path, array $query = [], array $body = [])
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    /** @param array<string, mixed> $server @param array<string, mixed> $query @param array<string, mixed> $post */
    public static function fromGlobals(array $server, array $query, array $post): self
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $path = rawurldecode((string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

        $body = $post;
        $raw = file_get_contents('php://input');

        // Une assertion WebAuthn arrive en JSON : on la fusionne avec le POST.
        if ($raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $body = array_merge($body, $decoded);
            }
        }

        $request = new self($method, $path === '' ? '/' : $path, $query, $body);

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $request->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        return $request;
    }

    public function input(string $key, string $default = ''): string
    {
        // Les parametres d URL priment, puis le corps, puis la requete.
        $value = $this->params[$key] ?? $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? $value : (string) $value;
    }

    public function wantsJson(): bool
    {
        return ($this->query['ajax'] ?? '') === '1'
            || str_contains($this->header('accept', ''), 'json')
            || ($this->header('x-requested-with', '') === 'XMLHttpRequest');
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
}
