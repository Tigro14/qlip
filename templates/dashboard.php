<section>
    <h1>Mes pages</h1>

    <p class="note">
        Comptes : 10 creations de badge par jour.
        Aujourd'hui : <?= (int) $remaining ?> restante<?= $remaining === 1 ? '' : 's' ?>.
        Supprimer ne rembourse pas le quota.
    </p>

    <h2>Creer une page de contact</h2>

    <form method="post" action="<?= attr(url('pages')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>Titre<input type="text" name="title" required maxlength="80"></label>
        <label>Slug (facultatif)<input type="text" name="slug" pattern="[a-z0-9]*"></label>
        <label>Telephone<input type="text" name="phone" maxlength="40"></label>
        <label>Email de contact<input type="email" name="email_contact" maxlength="120"></label>
        <label>Note<textarea name="note" maxlength="500"></textarea></label>

        <button type="submit">Creer</button>
    </form>

    <h2>Pages</h2>

    <?php if ($pages === []): ?>
        <p class="note">Aucune page.</p>
    <?php endif; ?>

    <?php foreach ($pages as $page): ?>
        <article class="card">
            <h3><a href="<?= attr(url('p/' . $page['slug'])) ?>"><?= e($page['title']) ?></a></h3>
            <p class="note">/<code><?= e($page['slug']) ?></code> &mdash;
                <img class="qr" src="<?= attr(url('qr/' . $page['slug'] . '.png')) ?>" alt="QR" width="120" height="120">
            </p>

            <form method="post" action="<?= attr(url('p/' . $page['slug'])) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">
                <label>Titre<input type="text" name="title" value="<?= e($page['title']) ?>" required></label>
                <label>Telephone<input type="text" name="phone" value="<?= e($page['phone'] ?? '') ?>"></label>
                <label>Email<input type="email" name="email_contact" value="<?= e($page['email_contact'] ?? '') ?>"></label>
                <label>Note<textarea name="note"><?= e($page['note'] ?? '') ?></textarea></label>
                <button type="submit">Enregistrer</button>
            </form>

            <form method="post" action="<?= attr(url('p/' . $page['slug'] . '/delete')) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

                <label><input type="checkbox" name="confirm" value="1"> Confirmer la suppression</label>
                <button type="submit">Supprimer la page</button>
            </form>
        </article>
    <?php endforeach; ?>

    <h2>Creer un badge</h2>

    <!-- Le filigrane est une regle de la feuille (style-src 'self' interdit le style dans
         la vue) : il ne peut pas etre pose par la balise, donc la carte le porte par une
         classe. C est une decoration, le contenu reste lu dans l ordre. -->
    <form class="card watermark" method="post" action="<?= attr(url('badges')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>
            Page
            <select name="slug" required>
                <?php foreach ($pages as $page): ?>
                    <option value="<?= e($page['slug']) ?>"><?= e($page['slug']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Style
            <select name="style">
                <option value="engraved">Grave (monochrome)</option>
                <option value="raised">Relief</option>
            </select>
        </label>

        <label><input type="checkbox" name="bicolor" value="1"> Bicolore (2 volumes + 3MF)</label>
        <label>Largeur mm<input type="number" name="width" value="34" min="20" max="60" step="0.5"></label>
        <label>Hauteur mm<input type="number" name="height" value="40" min="20" max="70" step="0.5"></label>
        <label>Epaisseur mm<input type="number" name="thickness" value="3" min="1" max="6" step="0.1"></label>
        <label>Profondeur mm<input type="number" name="depth" value="0.8" min="0.4" max="2" step="0.1"></label>

        <button type="submit">Creer le badge</button>
    </form>

    <h2>Creations</h2>

    <?php if ($badges === []): ?>
        <p class="note">Aucun badge.</p>
    <?php endif; ?>

    <?php if ($badges !== []): ?>
        <!-- Le contenu ne depend pas du script : les onglets sont des liens vers une ancre et
             tous les panneaux sont rendus, donc sans JavaScript la page reste une liste. Le
             script ajoute les roles d onglets et masque le panneau non choisi, il ne remplace
             rien. -->
        <div class="tabs" data-tabs>
            <div class="tablist">
                <?php foreach ($badges as $badge): ?>
                    <a class="tab" id="tab-<?= (int) $badge['id'] ?>" href="#badge-<?= (int) $badge['id'] ?>">
                        #<?= (int) $badge['id'] ?> <code><?= e($badge['slug'] ?? '') ?></code>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php foreach ($badges as $badge): ?>
                <section class="panel" id="badge-<?= (int) $badge['id'] ?>">
                    <img class="qr" src="<?= attr(url('qr/' . $badge['slug'] . '.png')) ?>"
                         alt="QR de la page <?= attr($badge['slug'] ?? '') ?>" width="120" height="120">

                    <p class="note">
                        <code><?= e($badge['slug'] ?? '') ?></code> -
                        <?= e($badge['style']) ?> -
                        bicolore <?= $badge['bicolor'] ? 'oui' : 'non' ?> -
                        <?= (float) ($badge['dimensions']['width'] ?? 0) ?> x <?= (float) ($badge['dimensions']['height'] ?? 0) ?> mm,
                        epaisseur <?= (float) ($badge['dimensions']['thickness'] ?? 0) ?> mm,
                        profondeur <?= (float) ($badge['dimensions']['depth'] ?? 0) ?> mm
                    </p>

                    <p>
                        <a class="button" href="<?= attr(url('badges/' . $badge['id'] . '/stl')) ?>">STL</a>
                        <a class="button" href="<?= attr(url('badges/' . $badge['id'] . '/3mf')) ?>">3MF</a>
                    </p>

                    <form method="post" action="<?= attr(url('badges/' . $badge['id'] . '/delete')) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

                        <label><input type="checkbox" name="confirm" value="1" required> Confirmer la suppression</label>
                        <button type="submit">Supprimer</button>
                    </form>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Second facteur (YubiKey)</h2>

    <p class="note">
        Quand le compte a une credential, la cle est demandee a chaque connexion en plus du
        mot de passe. L'enregistrement se fait sur une session deja confirmee par le mot de
        passe, et la politique stockee par la credential est 'discouraged' : une cle U2F
        (YubiKey NEO) ne sait pas faire la verification utilisateur, c'est le mot de passe
        qui prouve l'utilisateur. Une cle capable de la faire (YubiKey 5, passkey) doit
        etre enregistree depuis la page d'administration.
    </p>

    <div data-webauthn
         data-csrf="<?= e($csrf ?? '') ?>"
         data-rp="<?= e($rpId ?? '') ?>"
         data-base="<?= attr(url('')) ?>">
        <button type="button" data-action="register">Enregistrer la cle</button>

        <?php if ($credentials !== []): ?>
            <ul class="note">
                <?php foreach ($credentials as $credential): ?>
                    <li><code><?= e($credential['credential_id']) ?></code> &mdash;
                        politique <?= e($credential['policy'] ?? '') ?> &mdash;
                        compteur <?= (int) $credential['sign_count'] ?>

                        <form method="post" action="<?= attr(url('credentials/' . $credential['id'] . '/delete')) ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

                            <label>Mot de passe<input type="password" name="password" required></label>
                            <label><input type="checkbox" name="confirm" value="1" required> Confirmer</label>
                            <button type="submit">Oublier cette cle</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="note">
                Supprimer la cle est definitif : le compte se reconnecte avec le seul mot de passe.
                Elle est protegee par le mot de passe du compte parce que la cle ne peut plus prouver
                la possession au moment ou on l'enleve, et la session en cours perd la confirmation
                que cette cle lui avait apportee.
            </p>
        <?php else: ?>
            <p class="note">Aucune credential : la connexion se fait avec le seul mot de passe.</p>
        <?php endif; ?>

        <p class="result"></p>
    </div>

    <h2>Supprimer le compte</h2>

    <p class="note">
        Suppression definitive : le compte, ses pages, ses badges, ses credentials et ses
        jetons de verification partent. Rien ne revient, donc le mot de passe est demande.
    </p>

    <form method="post" action="<?= attr(url('account/delete')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>Mot de passe<input type="password" name="password" required></label>
        <label><input type="checkbox" name="confirm" value="1" required> Confirmer la suppression</label>

        <button type="submit">Supprimer le compte</button>
    </form>
</section>
