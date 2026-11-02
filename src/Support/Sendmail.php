<?php

declare(strict_types=1);

namespace Qlip\Support;

/**
 * Transport local : le message est remis au MTA de la machine (/usr/sbin/sendmail).
 *
 * Pas de dependance, pas de TLS, pas d authentification : la confiance vient de la machine
 * elle-meme, et il n y a rien a authentifier. Les arguments sont donnes en liste, jamais dans
 * une chaine de commande : une adresse ne peut pas devenir une option ni une commande. Le
 * message est ecrit sur stdin, avec ses en-tetes (le MTA lit To: pour trouver le destinataire,
 * d ou -t), et -i empeche un point seul d etre lu comme la fin de l entree.
 */
final class Sendmail
{
    private string $path;
    private string $from;
    private int $timeout;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->path = (string) ($config['path'] ?? '/usr/sbin/sendmail');
        $this->from = (string) ($config['from'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 15);
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function send(string $to, string $message): array
    {
        // Une adresse ne recoit pas de retour a la ligne : ce serait de l injection dans la
        // commande, et -t lit les en-tetes pour choisir le destinataire.
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Adresse de destination invalide.', 'transcript' => []];
        }

        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Adresse d envoi invalide.', 'transcript' => []];
        }

        if (!is_executable($this->path)) {
            // Le chemin n est pas repris dans le message d erreur : il serait rendu sur la
            // page d inscription. Il est dans la configuration, et le journal garde le statut.
            return ['ok' => false, 'error' => 'MTA local absent ou non executable.', 'transcript' => []];
        }

        // 0 => 'r' : le pipe est lu par l enfant (son stdin), 1 et 2 => 'w' : l enfant y ecrit.
        // Le sens est vu du cote de l enfant, pas du parent.
        $process = proc_open(
            [$this->path, '-t', '-i', '-f', $this->from],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'MTA local : impossible de lancer ' . $this->path, 'transcript' => []];
        }

        fwrite($pipes[0], $message);
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], $this->timeout);
        stream_set_timeout($pipes[2], $this->timeout);

        $transcript = ['C: ' . $this->path . ' -t -i -f ' . $this->from];

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            $reply = trim($err) !== '' ? trim($err) : trim($out);

            $transcript[] = 'S: code ' . $code;

            return ['ok' => false, 'error' => 'MTA local : code ' . $code . ($reply !== '' ? ' (' . $reply . ')' : ''), 'transcript' => $transcript];
        }

        return ['ok' => true, 'error' => '', 'transcript' => $transcript];
    }
}
