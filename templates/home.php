<section>
    <h1>Qlip</h1>
    <p>
        Des QR codes editables qui pointent vers une mini page de contact
        (objet trouve, etiquette de sac, badge d'atelier), et le meme QR en
        porte-cles imprimable : STL monochrome grave ou en relief, ou 3MF
        bicolore avec deux volumes.
    </p>

    <ul>
        <li>Inscription par email verifie, 10 creations de badge par jour et par compte.</li>
        <li>Page d'administration protegee par YubiKey (WebAuthn/FIDO2).</li>
        <li>Base SQLite locale, aucun dependance Composer.</li>
    </ul>

    <p>
        <a class="button" href="<?= attr(url('register')) ?>">Creer un compte</a>
        <a class="button" href="<?= attr(url('login')) ?>">Connexion</a>
    </p>
</section>
