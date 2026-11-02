<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Qlip') ?> &middot; Qlip</title>
    <link rel="stylesheet" href="<?= attr(url('assets/app.css')) ?>">
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
</body>
</html>
