<section>
    <h1>Inscription</h1>

    <?php if (!empty($error)): ?>
        <p class="flash flash-err"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= attr(url('register')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>
            Email
            <input type="email" name="email" required autocomplete="email">
        </label>

        <label>
            Mot de passe
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>

        <button type="submit">Creer le compte</button>
    </form>

    <p class="note">
        Un email de verification est envoye. Le transport est
        <code><?= e(env('QLIP_MAIL_TRANSPORT', 'outbox')) ?></code> : sans SMTP,
        le message est écrit dans <code>var/outbox</code> et journalise dans
        <code>var/log/mail.log</code>.
    </p>
</section>
