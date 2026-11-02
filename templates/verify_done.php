<section>
    <h1>Compte active</h1>

    <p>
        L'adresse <strong><?= e($user['email'] ?? '') ?></strong> est verifiee.
        Tu peux creer des pages de contact et des badges.
    </p>

    <p>
        <a class="button" href="<?= attr(url('dashboard')) ?>">Mes pages</a>
    </p>
</section>
