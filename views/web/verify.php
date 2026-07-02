<?php
/** @var ?string $status */
/** @var ?string $message */
?>
<section class="card">
    <h1><?= t('E-Mail-Bestätigung') ?></h1>
    <?php if ($status === 'success'): ?>
        <div class="alert alert-success"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a href="/dashboard"><?= t('Weiter zum Dashboard') ?></a></p>
    <?php else: ?>
        <div class="alert alert-error"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a href="/login"><?= t('Zum Login') ?></a></p>
    <?php endif; ?>
</section>
