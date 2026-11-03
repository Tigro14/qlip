<section>
    <h1>Nouveau mot de passe</h1>

    <?php if (!empty($error)): ?>
        <p class="flash flash-err"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="note">
        Lien usage unique : une fois le mot de passe change, les autres liens du compte sont
        fermes. Un mot de passe trop court ne consomme pas le lien, le formulaire peut etre
        repris.
    </p>

    <form method="post" action="<?= attr(url('password/reset/' . $token)) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>
            Nouveau mot de passe
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>

        <button type="submit">Enregistrer</button>
    </form>

    <p>
        <a href="<?= attr(url('login')) ?>">Connexion</a>
    </p>
</section>
