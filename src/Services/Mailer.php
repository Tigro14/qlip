<?php

declare(strict_types=1);

namespace Qlip\Services;

/**
 * Envoi d'email. Pas de SMTP dans ce projet : le transport par defaut écrit un
 * message dans var/outbox (un fichier .eml par envoi) et journalise dans
 * var/log/mail.log. Le transport "log" ne fait que journaliser.
 *
 * C'est volontaire : la verification d'email doit rester testable sans serveur
 * mail. Le lien de verification est egalement retourne par le service, ce qui
 * permet de l'afficher dans la page de confirmation en mode developpement.
 */
final class Mailer
{
    private string $transport;
    private string $outbox;
    private string $log;

    public function __construct(string $transport, string $varPath)
    {
        $this->transport = $transport;
        $this->outbox = $varPath . '/outbox';
        $this->log = $varPath . '/log';

        foreach ([$this->outbox, $this->log] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }

    /**
     * @return array{ok: bool, path: string, message: string}
     */
    public function send(string $to, string $subject, string $text): array
    {
        $message = "To: {$to}\n"
            . "From: Qlip <noreply@qlip.test>\n"
            . "Subject: {$subject}\n"
            . "Date: " . date('r') . "\n"
            . "Content-Type: text/plain; charset=utf-8\n\n"
            . $text;

        file_put_contents($this->log . '/mail.log', now() . "\t" . $to . "\t" . $subject . "\n", FILE_APPEND);

        if ($this->transport === 'log' || $this->transport === 'null') {
            return ['ok' => true, 'path' => $this->log . '/mail.log', 'message' => $message];
        }

        $path = $this->outbox . '/' . date('Ymd-His') . '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($to)), 0, 40) . '.eml';
        file_put_contents($path, $message);

        return ['ok' => true, 'path' => $path, 'message' => $message];
    }

    public function transport(): string
    {
        return $this->transport;
    }
}
