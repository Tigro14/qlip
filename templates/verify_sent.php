<section>
    <h1>Verifie ton email</h1>

    <p>
        Un message a ete envoye a <strong><?= e($email) ?></strong>
        (transport : <code><?= e($transport) ?></code>).
        Le compte reste inactive tant que le lien n'est pas utilise.
    </p>

    <?php if (!$ok): ?>
        <p class="flash flash-err">
            L'envoi a echoue : <?= e($error) ?>. Le compte n'est pas active ;
            recomence l'inscription ou verifie la configuration du transport email.
        </p>
    <?php endif; ?>

    <?php if ($transport === 'outbox'): ?>
        <p class="note">
            Fichier écrit : <code><?= e($path) ?></code>
        </p>
    <?php endif; ?>

    <?php if ($transport === 'outbox' || $transport === 'log' || $transport === 'null'): ?>
        <p>
            <a class="button" href="<?= e($link) ?>">Ouvrir le lien de verification</a>
        </p>
    <?php else: ?>
        <p class="note">
            Le lien n est pas affiche ici : il est seulement dans le message envoye.
            Sans lui, n'importe qui pourrait activer le compte qu'il vient de creer.
        </p>
    <?php endif; ?>

    <p class="note">
        Le lien est valable 7 jours et ne peut etre utilise qu'une fois.
    </p>
</section>
