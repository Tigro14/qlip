<section class="hero">
    <h1>Qlip</h1>

    <!-- Image decorative : l alt decrit ce que l on voit, il ne remplace pas le texte du
         paragraphe. Le fichier est un asset servi par le serveur web, il doit etre commit.
         Les dimensions ecrites ici sont la taille d affichage ; le fichier fait 440 x 358,
         soit 2 fois, pour un ecran en densite 2. Elles donnent le bon ratio avant le
         chargement, sinon la page saute a l affichage de l image. -->
    <img class="illustration" src="<?= attr(url('assets/img/key.png')) ?>"
         alt="Porte-cles imprimable, rendu en trois dimensions" width="220" height="179">

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
