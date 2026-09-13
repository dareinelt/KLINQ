<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null;
$presetManufacturer = $isNew ? ($_GET['manufacturer_id'] ?? '') : '';
$selectedType = form_value($row, 'asset_type_id');
?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="/articles"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form">
    <?php if (!$manufacturers): ?>
        <div class="alert alert-warning"><?= icon('warning') ?> Es ist noch kein aktiver Hersteller vorhanden. <a href="/manufacturers/new">Zuerst Hersteller anlegen</a>.</div>
    <?php endif; ?>
    <form method="post" action="<?= e($isNew ? '/articles' : '/articles/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="f-manufacturer">Hersteller *</label>
                <select id="f-manufacturer" name="manufacturer_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected(form_value($row, 'manufacturer_id', $presetManufacturer), $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('manufacturer_id') ?>
            </div>
            <div class="form-group">
                <label for="f-type">Assettyp *</label>
                <select id="f-type" name="asset_type_id" required data-category-filter>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($assetTypes as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($selectedType, $t['id']) ?>><?= e($t['name']) ?> (<?= e($t['inventory_prefix']) ?>)</option><?php endforeach; ?>
                </select>
                <?= field_error('asset_type_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Bezeichnung *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="200" placeholder="z. B. ThinkPad T14 Gen 5">
                <?= field_error('name') ?>
            </div>
            <div class="form-group">
                <label for="f-number">Artikelnummer</label>
                <input id="f-number" name="article_number" value="<?= e(form_value($row, 'article_number')) ?>" maxlength="100" class="mono">
                <?= field_error('article_number') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="f-category">Kategorie</label>
            <select id="f-category" name="asset_category_id">
                <option value="">Keine</option>
                <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" data-type="<?= (int) $cat['asset_type_id'] ?>"<?= selected(form_value($row, 'asset_category_id'), $cat['id']) ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('asset_category_id') ?>
            <span class="form-hint">Nur Kategorien des gewählten Assettyps.</span>
        </div>
        <div class="form-group">
            <label for="f-desc">Beschreibung</label>
            <textarea id="f-desc" name="description" rows="3"><?= e(form_value($row, 'description')) ?></textarea>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="/articles">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/category-filter.js']; include __DIR__ . '/../partials/app_layout.php'; ?>
