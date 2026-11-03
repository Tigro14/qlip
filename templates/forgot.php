<section>
    <h1>Mot de passe oublie</h1>

    <?php if (!empty($flash)): ?>
        <p class="flash flash-<?= e($flash['level'] ?? 'ok') ?>"><?= e($flash['message'] ?? '') ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="flash flash-err"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (($locked ?? 0) > time()): ?>
        <p class="flash flash-err">Trop de demandes : attends <?= (int) (($locked ?? 0) - time()) ?> secondes.</p>
    <?php endif; ?>

    <form method="post" action="<?= attr(url('password')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>
            Email
            <input type="email" name="email" required autocomplete="email">
        </label>

        <button type="submit">Envoyer le lien</button>
    </form>

    <p class="note">
        La page ne dit pas si l'adresse a un compte : la reponse est la meme dans les deux
        cas, sinon ce formulaire servirait a dresser la liste des comptes.
    </p>

    <p class="note">
        5 demandes maximum par heure et par navigateur. Le lien est envoye a l'adresse du
        compte, valable 24 h, usage unique.
    </p>

    <p>
        <a href="<?= attr(url('login')) ?>">Connexion</a>
        <?php if ($user === null): ?>
            &middot; <a href="<?= attr(url('register')) ?>">Inscription</a>
        <?php endif; ?>
    </p>
</section>
