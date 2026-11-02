<section>
    <h1>Verifie ton email</h1>

    <p>
        Un message a ete envoye a <strong><?= e($email) ?></strong>
        (transport : <code><?= e($transport) ?></code>).
        Le compte reste inactive tant que le lien n'est pas utilise.
    </p>

    <?php if ($transport === 'outbox'): ?>
        <p class="note">
            Fichier écrit : <code><?= e($path) ?></code>
        </p>
    <?php endif; ?>

    <p>
        <a class="button" href="<?= e($link) ?>">Ouvrir le lien de verification</a>
    </p>

    <p class="note">
        Le lien est valable 7 jours et ne peut etre utilise qu'une fois.
    </p>
</section>
