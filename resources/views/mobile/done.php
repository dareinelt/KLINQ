<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isCheckout = $movement['type'] === 'checkout';
$open = $movement['status'] === 'open';
$inv = rawurlencode($movement['inventory_number']);
?>
<div class="card m-done<?= $open ? ' is-warning' : '' ?>">
    <div class="m-done-icon"><?= icon($open ? 'clock' : 'check') ?></div>
    <h2><?= $isCheckout ? 'Entnahme' : 'Rückgabe' ?> <?= $open ? 'gespeichert (offen)' : 'abgeschlossen' ?></h2>
    <p class="text-muted"><span class="mono font-semibold"><?= e($movement['inventory_number']) ?></span><?= $movement['employee_name'] ? ($isCheckout ? ' → ' : ' von ') . e($movement['employee_name']) : '' ?><br><?= fmt_datetime($movement['movement_at']) ?></p>
    <?php if ($open): ?>
        <p class="text-warning mb-0"><?= $missing ? 'Fehlt noch: <strong>' . e(implode(', ', $missing)) . '</strong>. ' : '' ?>Der Vorgang steht in der Liste „Offene Vorgänge“ und wird dort vervollständigt.</p>
    <?php else: ?>
        <p class="mb-0">Neuer Status: <?= badge($movement['asset_status_name'], $movement['asset_status_color']) ?><?= $movement['to_location_path'] ? ' · ' . e($movement['to_location_path']) : '' ?></p>
    <?php endif; ?>
</div>
<div class="m-actions">
    <a class="btn btn-primary btn-xl btn-block" href="/m"><?= icon('qr') ?> Nächstes Asset scannen</a>
    <div class="m-actions-row">
        <a class="btn btn-secondary" href="/m/asset/<?= $inv ?>"><?= icon('box') ?> Asset</a>
        <?php if ($can('movements.view')): ?><a class="btn btn-secondary" href="/movements/<?= (int) $movement['id'] ?>"><?= icon('pen') ?> Vorgang</a><?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/mobile_layout.php'; ?>
