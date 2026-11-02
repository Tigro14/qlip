<?php

declare(strict_types=1);

namespace Qlip\Services;

use Qlip\Support\Sendmail;
use Qlip\Support\Smtp;

/**
 * Envoi d'email. Cinq transports :
 *
 * - outbox : le message est ecrit dans var/outbox (un .eml par envoi). C'est le
 *   transport par defaut, et le seul qui permet de lire le lien sans serveur mail ;
 * - log : journalise seulement ;
 * - null : ne fait rien ;
 * - smtp : envoi reel, par le client ecrit a la main (Qlip\Support\Smtp, TLS sur le
 *   port 465). Pas de copie dans l outbox : le journal suffit, et une copie contiendrait
 *   des adresses envoyees a des tiers.
 * - local : remise au MTA de la machine (Qlip\Support\Sendmail), sans authentification
 *   ni TLS : la confiance vient de la machine. Pas de copie dans l outbox non plus.
 *
 * Chaque envoi est journalise dans var/log/mail.log, avec le resultat.
 */
final class Mailer
{
    private string $transport;
    private string $outbox;
    private string $log;

    /** @param array<string, mixed> $smtp */
    private array $smtp;

    /** @param array<string, mixed> $sendmail */
    private array $sendmail;

    /**
     * @param array<string, mixed> $smtp
     * @param array<string, mixed> $sendmail
     */
    public function __construct(string $transport, string $varPath, array $smtp = [], array $sendmail = [])
    {
        $this->transport = $transport;
        $this->outbox = $varPath . '/outbox';
        $this->log = $varPath . '/log';
        $this->smtp = $smtp;
        $this->sendmail = $sendmail;

        foreach ([$this->outbox, $this->log] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }

    /**
     * @return array{ok: bool, path: string, message: string, error: string}
     */
    public function send(string $to, string $subject, string $text): array
    {
        $from = $this->sender();
        $message = $this->build($to, $subject, $text, $from);

        $result = ['ok' => true, 'path' => $this->log . '/mail.log', 'message' => $message, 'error' => ''];

        if ($this->transport === 'null') {
            $this->journal($to, $subject, 'nul');

            return $result;
        }

        if ($this->transport === 'log') {
            $this->journal($to, $subject, 'journalise');

            return $result;
        }

        if ($this->transport === 'local') {
            $sent = (new Sendmail($this->sendmail))->send($to, $message);

            $this->journal($to, $subject, $sent['ok'] ? 'confie au MTA local' : 'erreur : ' . $sent['error']);

            return [
                'ok' => $sent['ok'],
                'path' => $this->log . '/mail.log',
                'message' => $message,
                'error' => $sent['error'],
            ];
        }

        if ($this->transport === 'smtp') {
            $sent = (new Smtp($this->smtp))->send($to, $message);

            $this->journal($to, $subject, $sent['ok'] ? 'envoye' : 'erreur : ' . $sent['error']);

            return [
                'ok' => $sent['ok'],
                'path' => $this->log . '/mail.log',
                'message' => $message,
                'error' => $sent['error'],
            ];
        }

        $path = $this->outbox . '/' . date('Ymd-His') . '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($to)), 0, 40) . '.eml';
        file_put_contents($path, $message);

        $this->journal($to, $subject, 'ecrit dans l outbox');

        return ['ok' => true, 'path' => $path, 'message' => $message, 'error' => ''];
    }

    public function transport(): string
    {
        return $this->transport;
    }

    /** Adresse d envoi, telle qu elle est ecrite dans l en-tete From. */
    private function sender(): string
    {
        foreach ([$this->smtp['from'] ?? '', $this->sendmail['from'] ?? ''] as $candidate) {
            if ((string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return 'Qlip <noreply@qlip.test>';
    }

    /** Le message, tel qu il part sur la ligne : tout en CRLF. */
    private function build(string $to, string $subject, string $text, string $from): string
    {
        $body = str_replace("\n", "\r\n", str_replace(["\r\n", "\r", "\n"], "\n", $text));

        return $this->header('To', $to) . "\r\n"
            . $this->header('From', $from) . "\r\n"
            . $this->header('Subject', $subject) . "\r\n"
            . $this->header('Date', date('r')) . "\r\n"
            . $this->header('Content-Type', 'text/plain; charset=utf-8') . "\r\n"
            . "\r\n"
            . $body;
    }

    /** Un en-tete d email ne recoit pas de retour a la ligne. */
    private function header(string $name, string $value): string
    {
        return $name . ': ' . str_replace(["\r", "\n"], '', $value);
    }

    private function journal(string $to, string $subject, string $status): void
    {
        file_put_contents($this->log . '/mail.log', now() . "\t" . $to . "\t" . $subject . "\t" . $status . "\n", FILE_APPEND);
    }
}
