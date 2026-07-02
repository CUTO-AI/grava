<?php
/** @var bool $verified */
/** @var array<string,list<string>> $errors */
/** @var array{title:string,description:string,visibility:string,tags:string} $values */
/** @var string $_csrf */

$err = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) { return ''; }
    return '<span class="field-error">' . htmlspecialchars((string)$errors[$field][0], ENT_QUOTES, 'UTF-8') . '</span>';
};
?>
<section class="card">
    <h1><?= t('Neue Route hochladen') ?></h1>

    <?php if (!$verified): ?>
        <div class="alert alert-warn">
            <p><strong><?= t('E-Mail noch nicht bestätigt.') ?></strong></p>
            <p>
                <?= t('Bitte klick auf den Link in der Bestätigungs-E-Mail, die wir nach der
                Registrierung verschickt haben. Erst danach kannst du Routen
                hochladen.') ?>
            </p>
            <p class="muted">
                <?= t('Keine Mail bekommen? Schau im Spam-Ordner nach oder fordere im Dashboard
                eine neue an.') ?>
            </p>
            <p>
                <a href="/dashboard" class="btn-secondary"><?= t('Zurück zum Dashboard') ?></a>
            </p>
        </div>
    <?php else: ?>
        <p class="muted">
            <?= t('Lade eine GPX- oder GeoJSON-Datei hoch. Höhenmeter, Distanz und
            Bounding-Box werden automatisch berechnet.') ?>
        </p>

        <form method="post" action="/routes" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <?= t('Titel') ?>
                <input type="text" name="title" maxlength="140" required
                       value="<?= htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8') ?>">
                <?= $err('title') ?>
            </label>

            <label>
                <?= t('Beschreibung') ?> <span class="muted">(<?= t('optional') ?>)</span>
                <textarea name="description" rows="4" maxlength="8000"><?= htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <?= $err('description') ?>
            </label>

            <label>
                <?= t('Sichtbarkeit') ?>
                <select name="visibility">
                    <?php foreach (['private' => t('Privat'), 'unlisted' => t('Mit Link teilbar'), 'public' => t('Öffentlich (später)')] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $values['visibility'] === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('visibility') ?>
            </label>

            <label>
                <?= t('Tags') ?> <span class="muted">(<?= t('kommagetrennt, optional') ?>)</span>
                <input type="text" name="tags" placeholder="gravel, alps, family"
                       value="<?= htmlspecialchars($values['tags'], ENT_QUOTES, 'UTF-8') ?>">
                <?= $err('tags') ?>
            </label>

            <label>
                <?= t('Track-Datei') ?>
                <input type="file" name="payload" accept=".gpx,.geojson,application/gpx+xml,application/geo+json" required>
                <?= $err('payload') ?>
            </label>

            <div class="form-actions">
                <button type="submit"><?= t('Hochladen') ?></button>
                <a href="/routes" class="btn-link"><?= t('Abbrechen') ?></a>
            </div>
        </form>
    <?php endif; ?>
</section>
