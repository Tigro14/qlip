<section>
    <h1>Administration</h1>

    <p class="note">
        Session valide par WebAuthn (YubiKey) :
        <code><?= e($user['email']) ?></code>,
        verification a <code><?= e($sessionAt ?? '') ?></code>.
    </p>

    <h2>Credentials WebAuthn de ce compte</h2>

    <div data-webauthn
         data-csrf="<?= e($csrf ?? '') ?>"
         data-rp="<?= e($rpId ?? '') ?>"
         data-base="<?= attr(url('')) ?>"
         data-user="<?= e(json_field(['id' => (string) $user['id'], 'name' => $user['email'], 'displayName' => $user['email']])) ?>">

        <button type="button" data-action="register">Enregistrer une YubiKey</button>
        <button type="button" data-action="assert">Presente la YubiKey</button>
        <p class="result"></p>

        <?php if ($credentials === []): ?>
            <p class="note">Aucune credential enregistree.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>#</th><th>ID de credential</th><th>Type</th><th>Algo</th><th>Compteur</th><th>AAGUID</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($credentials as $credential): ?>
                        <tr>
                            <td><?= (int) $credential['id'] ?></td>
                            <td><code><?= e($credential['credential_id']) ?></code></td>
                            <td><?= e($credential['type'] ?? '-') ?></td>
                            <td><?= e($credential['alg'] ?? '-') ?></td>
                            <td><?= (int) $credential['sign_count'] ?></td>
                            <td><code><?= e($credential['aaguid'] ?? '-') ?></code></td>
                            <td><?= e($credential['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <h2>Comptes</h2>

    <table>
        <thead>
            <tr><th>#</th><th>Email</th><th>Admin</th><th>Verifie</th><th>Pages</th><th>Badges</th><th>Creé</th></tr>
        </thead>
        <tbody>
            <?php foreach ($users as $account): ?>
                <tr>
                    <td><?= (int) $account['id'] ?></td>
                    <td><?= e($account['email']) ?></td>
                    <td><?= (int) $account['is_admin'] === 1 ? 'oui' : 'non' ?></td>
                    <td><?= $account['verified_at'] === null ? 'non' : e($account['verified_at']) ?></td>
                    <td><?= (int) $account['pages'] ?></td>
                    <td><?= (int) $account['badges'] ?></td>
                    <td><?= e($account['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Pages de contact</h2>

    <table>
        <thead>
            <tr><th>#</th><th>Slug</th><th>Titre</th><th>Compte</th><th>Creé</th></tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $page): ?>
                <tr>
                    <td><?= (int) $page['id'] ?></td>
                    <td><a href="<?= attr(url('p/' . $page['slug'])) ?>"><code><?= e($page['slug']) ?></code></a></td>
                    <td><?= e($page['title']) ?></td>
                    <td><?= e($page['user_email'] ?? '') ?></td>
                    <td><?= e($page['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Badges</h2>

    <p class="note">
        Quota : <?= (int) $dailyLimit ?> creations par jour et par compte.
        Aujourd'hui, total sur la base : <?= (int) $usedToday ?>.
    </p>

    <table>
        <thead>
            <tr><th>#</th><th>Page</th><th>Compte</th><th>Style</th><th>Bicolore</th><th>Date</th><th>Fichiers</th></tr>
        </thead>
        <tbody>
            <?php foreach ($badges as $badge): ?>
                <tr>
                    <td><?= (int) $badge['id'] ?></td>
                    <td><code><?= e($badge['slug'] ?? '') ?></code></td>
                    <td><?= e($badge['user_email'] ?? '') ?></td>
                    <td><?= e($badge['style']) ?></td>
                    <td><?= (int) $badge['bicolor'] === 1 ? 'oui' : 'non' ?></td>
                    <td><?= e($badge['created_at']) ?></td>
                    <td>
                        <a href="<?= attr(url('badges/' . $badge['id'] . '/stl')) ?>">STL</a>
                        <a href="<?= attr(url('badges/' . $badge['id'] . '/3mf')) ?>">3MF</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Journal</h2>

    <table>
        <thead>
            <tr><th>#</th><th>Compte</th><th>Action</th><th>Detail</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php foreach ($audit as $entry): ?>
                <tr>
                    <td><?= (int) $entry['id'] ?></td>
                    <td><?= e($entry['user_email'] ?? '-') ?></td>
                    <td><code><?= e($entry['action']) ?></code></td>
                    <td><?= e($entry['detail'] ?? '') ?></td>
                    <td><?= e($entry['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
