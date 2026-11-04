<section>
    <h1>Mes pages</h1>

    <p class="note">
        Comptes : 10 creations de badge par jour.
        Aujourd'hui : <?= (int) $remaining ?> restante<?= $remaining === 1 ? '' : 's' ?>.
        Une page porte un seul badge, et chaque enregistrement de son design consomme une
        creation. Supprimer ne rembourse pas le quota.
    </p>

    <h2>Creer une page de contact</h2>

    <!-- Le slug est l identite de la page : ce que le QR encode. Un slug deja pris est refuse,
         il n est pas remplace par un autre : sinon la page existerait sous deux adresses et on
         ne saurait plus laquelle le QR imprime designe. -->
    <form method="post" action="<?= attr(url('pages')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

        <label>Titre<input type="text" name="title" required maxlength="80"></label>
        <label>Slug (facultatif)<input type="text" name="slug" pattern="[a-z0-9]*"></label>
        <label>Telephone<input type="text" name="phone" maxlength="40"></label>
        <label>Email de contact<input type="email" name="email_contact" maxlength="120"></label>
        <label>Note<textarea name="note" maxlength="500"></textarea></label>

        <button type="submit">Creer</button>
    </form>

    <h2>Pages et badges</h2>

    <?php if ($pages === []): ?>
        <p class="note">Aucune page.</p>
    <?php endif; ?>

    <?php if ($pages !== []): ?>
        <!-- Une page = un badge : l onglet est la page, pas la creation. Le contenu ne depend
             pas du script : les onglets sont des liens vers une ancre et tous les panneaux sont
             rendus, donc sans JavaScript la page reste une liste. Le script ajoute les roles
             d onglets et masque le panneau non choisi, il ne remplace rien. -->
        <div class="tabs" data-tabs>
            <div class="tablist">
                <?php foreach ($pages as $page): ?>
                    <a class="tab" id="tab-<?= attr((string) $page['slug']) ?>" href="#page-<?= attr((string) $page['slug']) ?>">
                        <?= e($page['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php foreach ($pages as $page): ?>
                <section class="panel" id="page-<?= attr((string) $page['slug']) ?>">
                    <p class="note">
                        <a href="<?= attr(url('p/' . $page['slug'])) ?>">/<code><?= e($page['slug']) ?></code></a>
                    </p>

                    <img class="qr" src="<?= attr(url('qr/' . $page['slug'] . '.png')) ?>"
                         alt="QR de la page <?= attr((string) $page['slug']) ?>" width="120" height="120">

                    <p class="note">
                        <?php if ($page['badge_id'] === null): ?>
                            Pas de badge sur cette page.
                        <?php else: ?>
                            badge <?= (int) $page['badge_id'] ?> -
                            <?= e((string) $page['style']) ?> -
                            bicolore <?= $page['bicolor'] ? 'oui' : 'non' ?> -
                            <?= (string) $page['dimensions']['width'] ?> x <?= (string) $page['dimensions']['height'] ?> mm,
                            epaisseur <?= (string) $page['dimensions']['thickness'] ?> mm,
                            profondeur <?= (string) $page['dimensions']['depth'] ?> mm -
                            <a class="button" href="<?= attr(url('badges/' . $page['badge_id'] . '/stl')) ?>">STL</a>
                            <a class="button" href="<?= attr(url('badges/' . $page['badge_id'] . '/3mf')) ?>">3MF</a>
                        <?php endif; ?>
                    </p>

                    <!-- Le filigrane est une regle de la feuille (style-src 'self' interdit le
                         style dans la vue) : il ne peut pas etre pose par la balise, donc la carte
                         le porte par une classe. C est une decoration, le contenu reste lu dans
                         l ordre. -->
                    <form class="card watermark" method="post" action="<?= attr(url('badges')) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">
                        <input type="hidden" name="slug" value="<?= attr((string) $page['slug']) ?>">

                        <label>
                            Style
                            <select name="style">
                                <option value="engraved"<?= $page['style'] === 'engraved' ? ' selected' : '' ?>>Grave (monochrome)</option>
                                <option value="raised"<?= $page['style'] === 'raised' ? ' selected' : '' ?>>Relief</option>
                            </select>
                        </label>

                        <label><input type="checkbox" name="bicolor" value="1"<?= $page['bicolor'] ? ' checked' : '' ?>> Bicolore (2 volumes + 3MF)</label>
                        <label>Largeur mm<input type="number" name="width" value="<?= attr((string) $page['dimensions']['width']) ?>" min="20" max="60" step="0.5"></label>
                        <label>Hauteur mm<input type="number" name="height" value="<?= attr((string) $page['dimensions']['height']) ?>" min="20" max="70" step="0.5"></label>
                        <label>Epaisseur mm<input type="number" name="thickness" value="<?= attr((string) $page['dimensions']['thickness']) ?>" min="1" max="6" step="0.1"></label>
                        <label>Profondeur mm<input type="number" name="depth" value="<?= attr((string) $page['dimensions']['depth']) ?>" min="0.4" max="2" step="0.1"></label>

                        <button type="submit"><?= $page['badge_id'] === null ? 'Creer le badge' : 'Enregistrer le design' ?></button>
                    </form>

                    <form method="post" action="<?= attr(url('p/' . $page['slug'])) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

                        <label>Titre<input type="text" name="title" value="<?= e($page['title']) ?>" required></label>
                        <label>Telephone<input type="text" name="phone" value="<?= e($page['phone'] ?? '') ?>"></label>
                        <label>Email<input type="email" name="email_contact" value="<?= e($page['email_contact'] ?? '') ?>"></label>
                        <label>Note<textarea name="note"><?= e($page['note'] ?? '') ?></textarea></label>
                        <button type="submit">Enregistrer la page</button>
                    </form>

                    <form method="post" action="<?= attr(url('p/' . $page['slug'] . '/delete')) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">

                        <label><input type="checkbox" name="confirm" value="1" required> Confirmer la suppression</label>
                        <button type="submit">Supprimer la page et son badge</button>
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
