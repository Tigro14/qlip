<?php

declare(strict_types=1);

namespace Qlip\App;

/**
 * Configuration, par variables d'environnement (QLIP_*).
 */
final class Config
{
    public string $dbPath;
    public string $varPath;
    public string $baseUrl;
    public string $rpId;

    /** @var list<string> */
    public array $origins;

    public string $mailTransport;
    public int $dailyLimit;

    /** @var array<string, mixed> */
    public array $smtp;

    public function __construct(array $values = [])
    {
        $root = \env('QLIP_ROOT') ?: dirname(__DIR__, 2);

        $this->dbPath = $values['db_path'] ?? (\env('QLIP_DB') ?: $root . '/data/qlip.sqlite');
        $this->varPath = $values['var_path'] ?? (\env('QLIP_VAR') ?: $root . '/var');
        $this->baseUrl = rtrim($values['base_url'] ?? (\env('QLIP_BASE_URL') ?: 'http://localhost:8080'), '/');
        $this->rpId = $values['rp_id'] ?? (\env('QLIP_RP_ID') ?: parse_url($this->baseUrl, PHP_URL_HOST) ?: 'localhost');

        $origin = \env('QLIP_ORIGINS');

        if (isset($values['origins'])) {
            $this->origins = array_map('trim', (array) $values['origins']);
        } else {
            $this->origins = $origin !== ''
                ? array_map('trim', explode(',', $origin))
                : [$this->baseUrl];
        }

        $this->mailTransport = $values['mail_transport'] ?? (\env('QLIP_MAIL_TRANSPORT') ?: 'outbox');
        $this->dailyLimit = (int) ($values['daily_limit'] ?? (\env('QLIP_DAILY_LIMIT') ?: 10));

        // Transport smtp : le mot de passe vient de l'environnement, jamais d'un fichier du
        // depot. Le port 465 est en TLS implicite, donc la verification du certificat est
        // active (voir Qlip\Support\Smtp).
        $this->smtp = [
            'host' => $values['smtp_host'] ?? \env('QLIP_SMTP_HOST'),
            'port' => (int) ($values['smtp_port'] ?? (\env('QLIP_SMTP_PORT') ?: 465)),
            'user' => $values['smtp_user'] ?? \env('QLIP_SMTP_USER'),
            'pass' => $values['smtp_pass'] ?? \env('QLIP_SMTP_PASS'),
            'from' => $values['smtp_from'] ?? (\env('QLIP_SMTP_FROM') ?: \env('QLIP_SMTP_USER')),
            'cafile' => $values['smtp_cafile'] ?? \env('QLIP_SMTP_CAFILE'),
            'hello' => $values['smtp_hello'] ?? (\env('QLIP_SMTP_HELLO') ?: 'localhost'),
            'timeout' => (int) ($values['smtp_timeout'] ?? 15),
        ];
    }

    public function rootsPem(): string
    {
        $path = \env('QLIP_ATTESTATION_ROOTS');

        if ($path === '' || !is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }
}
