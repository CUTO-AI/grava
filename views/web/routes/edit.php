<?php
/** @var array<string,mixed> $route */
/** @var array<string,list<string>> $errors */
/** @var array{title:string,description:string,visibility:string,tags:string} $values */
/** @var string $_csrf */

$id = (string)$route['id'];

$err = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) { return ''; }
    return '<span class="field-error">' . htmlspecialchars((string)$errors[$field][0], ENT_QUOTES, 'UTF-8') . '</span>';
};
?>
<section class="card">
    <h1><?= t('Route bearbeiten') ?></h1>
    <p class="muted">
        <?= t('Geometrie ist je Version unveränderlich — eine neue Geometrie liefert die
        App über einen erneuten Upload mit derselben') ?> <code>client_route_uuid</code>.
        <?= t('Hier passt du nur Metadaten an.') ?>
    </p>

    <form method="post" action="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/update" novalidate>
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
                <?php foreach (['private' => 'Privat', 'unlisted' => 'Mit Link teilbar', 'public' => 'Öffentlich (später)'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $values['visibility'] === $val ? 'selected' : '' ?>>
                        <?= te($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('visibility') ?>
        </label>

        <label>
            <?= t('Tags') ?> <span class="muted">(<?= t('kommagetrennt') ?>)</span>
            <input type="text" name="tags" placeholder="gravel, alps"
                   value="<?= htmlspecialchars($values['tags'], ENT_QUOTES, 'UTF-8') ?>">
            <?= $err('tags') ?>
        </label>

        <div class="form-actions">
            <button type="submit"><?= t('Speichern') ?></button>
            <a href="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="btn-link"><?= t('Abbrechen') ?></a>
        </div>
    </form>
</section>
