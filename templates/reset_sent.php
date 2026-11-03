<section>
    <h1>Reinitialisation demandee</h1>

    <?php if (!$ok): ?>
        <p class="flash flash-err">
            L'envoi a echoue : <?= e($error) ?>. Recomence la demande ou verifie la
            configuration du transport email.
        </p>
    <?php endif; ?>

    <p>
        Si cette adresse a un compte, un message a ete envoye a
        <strong><?= e($email) ?></strong> (transport : <code><?= e($transport) ?></code>).
        Le mot de passe ne change pas tant que le lien n'est pas utilise.
    </p>

    <?php if ($found && $transport === 'outbox'): ?>
        <p class="note">
            Fichier écrit : <code><?= e($path) ?></code>
        </p>
    <?php endif; ?>

    <?php if ($found): ?>
        <?php if ($transport === 'outbox' || $transport === 'log' || $transport === 'null'): ?>
            <p>
                <a class="button" href="<?= attr($link) ?>">Ouvrir le lien de reinitialisation</a>
            </p>

            <p class="note">
                Le lien est affiche ici seulement pour les transports de developpement : avec
                <code>outbox</code> cette page ne cache pas l'existence du compte. Pour un envoi
                reel (<code>smtp</code> ou <code>local</code>), il est seulement dans le message.
            </p>
        <?php else: ?>
            <p class="note">
                Le lien n'est pas affiche ici : il est seulement dans le message envoye.
                Sans lui, n'importe qui pourrait changer le mot de passe du compte.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <p class="note">
        Le lien est valable 24 h et ne peut etre utilise qu'une fois. Quand il sert, les
        autres liens du meme compte sont fermes.
    </p>

    <p>
        <a href="<?= attr(url('login')) ?>">Connexion</a>
    </p>
</section>
