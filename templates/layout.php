<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Qlip') ?> &middot; Qlip</title>
    <link rel="stylesheet" href="<?= attr(url('assets/app.css')) ?>">

    <!-- Le script du theme est charge ici, sans defer : il doit poser l attribut avant le
         premier paint. Un script defer s execute apres le parse, la page s afficherait en
         clair puis basculerait. La feuille ne peut pas le faire seule, elle ne connait pas le
         choix stocke, et la CSP interdit une regle dans la page. -->
    <script src="<?= attr(url('assets/theme.js')) ?>"></script>
</head>
<body>
<header class="bar">
    <a class="brand" href="<?= attr(url('')) ?>">Qlip</a>
    <nav>
        <a href="<?= attr(url('dashboard')) ?>">Mes pages</a>
        <?php if (($user['is_admin'] ?? 0) === 1): ?>
            <a href="<?= attr(url('admin')) ?>">Administration</a>
        <?php endif; ?>
        <?php if ($user !== null): ?>
            <form method="post" action="<?= attr(url('logout')) ?>" class="inline">
                <input type="hidden" name="csrf" value="<?= e($csrf ?? '') ?>">
                <button type="submit">Quitter</button>
            </form>
        <?php else: ?>
            <a href="<?= attr(url('login')) ?>">Connexion</a>
            <a href="<?= attr(url('register')) ?>">Inscription</a>
        <?php endif; ?>

        <!-- Le switch est le dernier element du nav, donc il est a droite. Il n envoie rien
             au serveur : il pose l attribut data-theme et le choix dans localStorage. -->
        <button type="button" class="secondary" data-theme-toggle aria-pressed="false">Theme</button>
    </nav>
</header>

<main>
    <?php if (!empty($flash)): ?>
        <p class="flash flash-<?= e($flash['level'] ?? 'ok') ?>"><?= e($flash['message'] ?? '') ?></p>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer>
    QR en PHP pur, STL ASCII et 3MF. YubiKey 2FA par WebAuthn, SQLite local.
</footer>

<!-- Le script est externe : script-src 'self' autorise un fichier du site, pas une balise
     inline. La ceremonie WebAuthn est dans app.js, donc sans cette ligne les boutons de la
     page YubiKey ne font rien. -->
<script src="<?= attr(url('assets/app.js')) ?>" defer></script>

<!-- Les onglets de badges sont un repli : le template rend tous les panneaux, le script ne
     fait que masquer ceux qui ne sont pas choisis. defer convient ici, le contenu est deja
     rendu quand il s execute. -->
<script src="<?= attr(url('assets/tabs.js')) ?>" defer></script>
</body>
</html>
