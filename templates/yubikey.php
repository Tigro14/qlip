<section>
    <h1>YubiKey</h1>

    <p class="note">
        La page d'administration est protegee par une credential WebAuthn.
        Connecte-toi d'abord avec le mot de passe, puis presente la YubiKey ; ou
        utilise directement une credential residente (la YubiKey choisit le compte).
        La premiere fois : enregistre la cle, puis presente-la (l'enregistrement se fait
        avec la session, pas avec une assertion).
    </p>

    <?php if ($user === null): ?>
        <form method="post" action="<?= attr(url('login')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">
            <label>Email<input type="email" name="email" required autocomplete="email"></label>
            <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Connexion</button>
        </form>
    <?php else: ?>
        <p class="note">Connecte : <strong><?= e($user['email']) ?></strong></p>
    <?php endif; ?>

    <div data-webauthn
         data-csrf="<?= e($csrf ?? '') ?>"
         data-rp="<?= e($rpId ?? '') ?>"
         data-base="<?= attr(url('')) ?>"
         data-user="<?= e(json_field($user === null ? null : ['id' => user_handle((int) $user['id']), 'name' => $user['email'], 'displayName' => $user['email']])) ?>">
        <?php if ($user !== null && (int) ($user['is_admin'] ?? 0) === 1): ?>
            <button type="button" data-action="register">Enregistrer la YubiKey</button>
        <?php endif; ?>

        <button type="button" data-action="assert">Presente la YubiKey</button>

        <?php if ($credentials !== []): ?>
            <ul class="note">
                <?php foreach ($credentials as $credential): ?>
                    <li><code><?= e($credential['credential_id']) ?></code> &mdash;
                        compteur <?= (int) $credential['sign_count'] ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="note">Aucune credential enregistree pour ce compte.</p>
        <?php endif; ?>

        <p class="result"></p>
    </div>
</section>
