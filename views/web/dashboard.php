<?php
/** @var array<string,mixed> $user */
/** @var string $_csrf */
$name = $user['display_name'] ?? null;
$greeting = $name !== null && $name !== '' ? $name : ($user['email'] ?? t('Fahrer*in'));

// L7: ISO-8601 lesbar formatieren. Wenn die intl-Extension geladen
// ist, nutzen wir sie für Locale-korrekte Ausgabe — sonst eine
// einfache, deutsche Standardform.
$createdAt = (string)($user['created_at'] ?? '');
$createdAtDisplay = $createdAt;
if ($createdAt !== '') {
    try {
        $dt = new DateTimeImmutable($createdAt);
        if (class_exists('IntlDateFormatter')) {
            $fmt = new IntlDateFormatter(
                'de-DE',
                IntlDateFormatter::LONG,
                IntlDateFormatter::SHORT,
                $dt->getTimezone(),
            );
            $formatted = $fmt->format($dt);
            if (is_string($formatted) && $formatted !== '') {
                $createdAtDisplay = $formatted;
            }
        } else {
            $months = [
                1=>'Januar', 2=>'Februar', 3=>'März',     4=>'April',
                5=>'Mai',    6=>'Juni',    7=>'Juli',     8=>'August',
                9=>'September', 10=>'Oktober', 11=>'November', 12=>'Dezember',
            ];
            $createdAtDisplay = sprintf(
                '%d. %s %d, %s Uhr',
                (int)$dt->format('j'),
                $months[(int)$dt->format('n')] ?? $dt->format('M'),
                (int)$dt->format('Y'),
                $dt->format('H:i'),
            );
        }
    } catch (Throwable) {
        // Fällt zurück auf den Rohstring, damit ein kaputter Datumswert
        // die Seite nicht killt.
    }
}
?>
<section class="card">
    <h1><?= t('Hallo') ?>, <?= htmlspecialchars((string)$greeting, ENT_QUOTES, 'UTF-8') ?>!</h1>
    <p><?= t('Willkommen im CYBERRIDE Dashboard.') ?></p>

    <dl class="profile">
        <dt><?= t('E-Mail') ?></dt>
        <dd><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            <?php if (empty($user['email_verified'])): ?>
                <span class="badge badge-warn"><?= t('nicht bestätigt') ?></span>
            <?php else: ?>
                <span class="badge badge-ok"><?= t('bestätigt') ?></span>
            <?php endif; ?>
        </dd>
        <dt><?= t('Profil-Handle') ?></dt>
        <dd>
            <?php $handle = (string)($user['public_handle'] ?? ''); if ($handle !== ''): ?>
                <a href="/u/<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></a>
            <?php else: ?>
                <span class="muted"><?= t('noch nicht gesetzt') ?></span> ·
                <a href="/settings/handle"><?= t('jetzt festlegen') ?></a>
            <?php endif; ?>
        </dd>
        <dt><?= t('Konto seit') ?></dt>
        <dd><?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?></dd>
        <dt><?= t('User-ID') ?></dt>
        <dd><code><?= htmlspecialchars((string)($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></dd>
    </dl>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-secondary"><?= t('Abmelden') ?></button>
    </form>
</section>

<section class="card">
    <h2><?= t('Funktionen &amp; Neuigkeiten') ?></h2>
    <p class="muted"><?= t('Was grava alles kann und was als Nächstes kommt.') ?></p>
    <p><a class="btn-primary" href="/features"><?= t('Funktionen &amp; Neuigkeiten ansehen') ?></a></p>
</section>
