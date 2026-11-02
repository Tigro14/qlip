<section class="public-page">
    <h1><?= e($page['title']) ?></h1>

    <img class="qr" src="<?= e($qrUrl) ?>" alt="QR code" width="220" height="220">

    <p class="note">
        Le QR encode l'URL de cette page. Il est aussi disponible en STL (grave ou
        en relief) et en 3MF bicolore, module par module.
    </p>

    <?php if (($page['phone'] ?? '') !== ''): ?>
        <p><strong>Telephone :</strong> <?= e($page['phone']) ?></p>
    <?php endif; ?>

    <?php if (($page['email_contact'] ?? '') !== ''): ?>
        <p><strong>Email :</strong> <a href="mailto:<?= e($page['email_contact']) ?>"><?= e($page['email_contact']) ?></a></p>
    <?php endif; ?>

    <?php if (($page['note'] ?? '') !== ''): ?>
        <p><?= nl2br(e($page['note'])) ?></p>
    <?php endif; ?>

    <p class="note">
        Imprimer ce QR en porte-cles : <a href="<?= url('dashboard') ?>">creer un badge</a>
        (STL monochrome grave ou en relief, ou 3MF bicolore).
    </p>
</section>
