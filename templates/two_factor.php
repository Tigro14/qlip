<section>
    <h1>Second facteur</h1>

    <p class="note">
        Mot de passe accepte pour <strong><?= e($pending['email'] ?? '') ?></strong>.
        La session n'est pas encore acceptee : la cle doit prouver sa possession.
        Une ceremonie refusee est comptee sur le meme verou que le mot de passe.
    </p>

    <?php if ($locked > time()): ?>
        <p class="flash flash-err">Trop de tentatives : attends <?= (int) ($locked - time()) ?> secondes.</p>
    <?php endif; ?>

    <?php if ($credentials === []): ?>
        <p class="note">
            Aucune credential pour ce compte :
            <a href="<?= attr(url('login')) ?>">reprendre la connexion</a>.
        </p>
    <?php else: ?>
        <div data-webauthn
             data-csrf="<?= e($csrf ?? '') ?>"
             data-rp="<?= e($rpId ?? '') ?>"
             data-base="<?= attr(url('')) ?>"
             data-assert="<?= attr(url('login/2fa')) ?>">
            <button type="button" data-action="assert">Presente la cle</button>

            <ul class="note">
                <?php foreach ($credentials as $credential): ?>
                    <li><code><?= e($credential['credential_id']) ?></code> &mdash;
                        politique <?= e($credential['policy'] ?? '') ?> &mdash;
                        compteur <?= (int) $credential['sign_count'] ?></li>
                <?php endforeach; ?>
            </ul>

            <p class="result"></p>
        </div>

        <p class="note">
            La cle est demandee a chaque connexion tant que le compte a une credential.
            Si elle est perdue, il n'y a pas d'echappement : le lien de reinitialisation
            prouve le controle de la boite, mais ne supprime pas la credential.
        </p>
    <?php endif; ?>
</section>
