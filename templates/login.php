<section>
    <h1>Connexion</h1>

    <?php if (!empty($flash)): ?>
        <p class="flash flash-<?= e($flash['level'] ?? 'ok') ?>"><?= e($flash['message'] ?? '') ?></p>
    <?php endif; ?>

    <?php if ($locked > time()): ?>
        <p class="flash flash-err">Trop de tentatives : attends <?= (int) ($locked - time()) ?> secondes.</p>
    <?php endif; ?>

    <form method="post" action="<?= attr(url('login')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>
            Email
            <input type="email" name="email" required autocomplete="email">
        </label>

        <label>
            Mot de passe
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit">Entrer</button>
    </form>

    <p class="note">
        Le compte doit avoir verifie son email.
        <a href="<?= attr(url('password')) ?>">Mot de passe oublie ?</a>
        <?php if ($user === null): ?>
            <a href="<?= attr(url('register')) ?>">Inscription</a>
        <?php endif; ?>
    </p>
</section>
