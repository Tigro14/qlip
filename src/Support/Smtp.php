<?php

declare(strict_types=1);

namespace Qlip\Support;

/**
 * Client SMTP ecrit a la main : pas de dependance, pas de librairie.
 *
 * Seulement ce qu il faut pour envoyer un message : connexion TLS (port 465), EHLO,
 * AUTH LOGIN, MAIL FROM, RCPT TO, DATA, QUIT. La verification du certificat est
 * toujours active : un mot de passe ne doit pas partir vers un serveur non identifie.
 * Le mot de passe n est jamais ecrit dans le transcript ni dans le journal.
 */
final class Smtp
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $hello;
    private string $cafile;
    private int $timeout;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->host = (string) ($config['host'] ?? '');
        $this->port = (int) ($config['port'] ?? 465);
        $this->user = (string) ($config['user'] ?? '');
        $this->pass = (string) ($config['pass'] ?? '');
        $this->from = (string) ($config['from'] ?? $this->user);
        $this->hello = (string) ($config['hello'] ?? 'localhost');
        $this->cafile = (string) ($config['cafile'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 15);
    }

    /**
     * Le message est la version wire : en-tetes, ligne vide, corps, le tout en CRLF
     * (RFC 5322).
     *
     * @return array{ok: bool, error: string, transcript: list<string>}
     */
    public function send(string $to, string $message): array
    {
        $transcript = [];

        // Une adresse ne recoit pas de retour a la ligne : ce serait de l injection dans
        // la commande SMTP.
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Adresse de destination invalide.', 'transcript' => $transcript];
        }

        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Adresse d envoi invalide.', 'transcript' => $transcript];
        }

        if ($this->host === '' || $this->user === '' || $this->pass === '') {
            return ['ok' => false, 'error' => 'Configuration SMTP incomplete : host, user et pass sont requis.', 'transcript' => $transcript];
        }

        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];

        if ($this->cafile !== '') {
            $options['cafile'] = $this->cafile;
        }

        $socket = @stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $options])
        );

        if ($socket === false) {
            return ['ok' => false, 'error' => 'Connexion TLS refusee : ' . $errstr, 'transcript' => $transcript];
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->dialog($socket, $to, $message, $transcript);

            return ['ok' => true, 'error' => '', 'transcript' => $transcript];
        } catch (\Throwable $problem) {
            return ['ok' => false, 'error' => $problem->getMessage(), 'transcript' => $transcript];
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<string> $transcript
     */
    private function dialog($socket, string $to, string $message, array &$transcript): void
    {
        $this->expect($socket, 220, $transcript);

        $capabilities = $this->command($socket, 'EHLO ' . $this->hello, 250, $transcript);

        if (stripos($capabilities, 'AUTH LOGIN') === false) {
            throw new \RuntimeException('Le serveur n annonce pas AUTH LOGIN.');
        }

        $this->command($socket, 'AUTH LOGIN', 334, $transcript);

        // Le serveur repond 334 (le libelle est en base64) pour l identifiant, puis pour
        // le mot de passe, puis 235. Le transcript ne doit pas repeter le mot de passe.
        $this->write($socket, base64_encode($this->user), '> ' . base64_encode($this->user), $transcript);
        $this->expect($socket, 334, $transcript);

        $this->write($socket, base64_encode($this->pass), '> ****', $transcript);
        $this->expect($socket, 235, $transcript);

        $this->command($socket, 'MAIL FROM:<' . $this->from . '>', 250, $transcript);
        $this->command($socket, 'RCPT TO:<' . $to . '>', 250, $transcript);
        $this->command($socket, 'DATA', 354, $transcript);

        foreach (explode("\r\n", $message) as $line) {
            // Un point en debut de ligne est double : sinon le serveur croit que le corps
            // est termine.
            $wire = $line !== '' && $line[0] === '.' ? '.' . $line : $line;

            fwrite($socket, $wire . "\r\n");
            $transcript[] = 'B: ' . $wire;
        }

        fwrite($socket, ".\r\n");
        $transcript[] = 'B: .';

        $this->expect($socket, 250, $transcript);
        $this->command($socket, 'QUIT', 221, $transcript);
    }

    /**
     * @param resource $socket
     * @param list<string> $transcript
     */
    private function write($socket, string $wire, string $label, array &$transcript): void
    {
        fwrite($socket, $wire . "\r\n");
        $transcript[] = 'C: ' . $label;
    }

    /**
     * @param resource $socket
     * @param list<string> $transcript
     */
    private function command($socket, string $wire, int $code, array &$transcript): string
    {
        $this->write($socket, $wire, $wire, $transcript);

        return $this->expect($socket, $code, $transcript);
    }

    /**
     * Une reponse peut etre multiligne (250-xxx puis 250 xxx) : on lit jusqu a la ligne
     * qui porte le code attendu.
     *
     * @param resource $socket
     * @param list<string> $transcript
     */
    private function expect($socket, int $code, array &$transcript): string
    {
        $lines = [];

        while (true) {
            $line = fgets($socket, 1024);

            if ($line === false) {
                throw new \RuntimeException('Serveur SMTP : reponse interrompue.');
            }

            if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                throw new \RuntimeException('Serveur SMTP : timeout en attente de reponse.');
            }

            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (strlen($line) < 4 || $line[3] !== '-' || (int) substr($line, 0, 3) !== $code) {
                break;
            }
        }

        $reply = implode(' ', $lines);

        if ((int) substr($reply, 0, 3) !== $code) {
            throw new \RuntimeException('Serveur SMTP : reponse ' . substr($reply, 0, 3) . ', ' . $code . ' attendue (' . $reply . ').');
        }

        $transcript[] = 'S: ' . $reply;

        return $reply;
    }
}
