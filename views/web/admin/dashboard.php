<?php
/** @var array<string,mixed> $m */
/** @var string $role */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n = static fn($v): string => number_format((int)$v, 0, ',', '.');
$cron = $m['cron'] ?? ['failed' => 0, 'overdue' => 0];
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin"><strong>Übersicht</strong></a>
    <a href="/admin/analytics">Analytics</a>
    <a href="/admin/users">User</a>
    <a href="/admin/rides">Fahrten</a>
    <a href="/admin/review">Review</a>
    <a href="/admin/game">Health</a>
    <a href="/admin/cron">Cron</a>
    <a href="/admin/audit">Audit</a>
    <a href="/admin/roles">Rollen</a>
</nav>

<section class="card">
    <h1><?= t('Übersicht') ?></h1>
    <p class="muted"><?= t('Rolle') ?>: <span class="badge"><?= $e($role) ?></span></p>
</section>

<?php
$tile = static function (string $label, string $value, ?string $href = null, ?string $tone = null) use ($e): string {
    $inner = '<div class="muted">' . $e($label) . '</div><div style="font-size:1.8rem;font-weight:700'
        . ($tone ? ';color:' . $tone : '') . '">' . $e($value) . '</div>';
    $card = '<div class="card" style="flex:1;min-width:9rem">' . $inner . '</div>';
    return $href ? '<a href="' . $e($href) . '" style="flex:1;min-width:9rem;text-decoration:none">' . $card . '</a>' : $card;
};
?>
<section class="card">
    <h2><?= t('Aktivität') ?></h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <?= $tile(t('Fahren gerade'), $n($m['active_now'])) ?>
        <?= $tile(t('Fahrten heute'), $n($m['rides_today'])) ?>
        <?= $tile(t('Fahrten 7T'), $n($m['rides_7d'])) ?>
        <?= $tile(t('Signups heute'), $n($m['signups_today'])) ?>
        <?= $tile(t('Signups 7T'), $n($m['signups_7d'])) ?>
    </div>
</section>

<section class="card">
    <h2><?= t('Aktive Fahrer (Uploads)') ?></h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <?= $tile('DAU', $n($m['dau'])) ?>
        <?= $tile('WAU', $n($m['wau'])) ?>
        <?= $tile('MAU', $n($m['mau'])) ?>
        <?= $tile(t('User gesamt'), $n($m['users_total'])) ?>
    </div>
</section>

<section class="card">
    <h2><?= t('Betrieb & Integrität') ?></h2>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <?= $tile(t('Ingest-Queue'), $n($m['ingest_queue']), '/admin/cron') ?>
        <?= $tile(t('Ingest-Fehler 24h'), $n($m['ingest_failed_24h']), '/admin/cron', (int)$m['ingest_failed_24h'] > 0 ? 'var(--err,#c0392b)' : null) ?>
        <?= $tile(t('Cron fehlgeschlagen'), $n($cron['failed']), '/admin/cron', (int)$cron['failed'] > 0 ? 'var(--err,#c0392b)' : null) ?>
        <?= $tile(t('Cron überfällig'), $n($cron['overdue']), '/admin/cron', (int)$cron['overdue'] > 0 ? 'var(--err,#c0392b)' : null) ?>
        <?= $tile(t('Offene Reports'), $n($m['reports_open']), '/admin/review', (int)$m['reports_open'] > 0 ? 'var(--err,#c0392b)' : null) ?>
        <?= $tile(t('Gebannt'), $n($m['banned'])) ?>
    </div>
</section>
