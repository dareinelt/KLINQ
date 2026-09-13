<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p class="text-muted mb-0">Willkommen, <?= e($user['display_name'] ?? $user['username'] ?? '') ?>. Überblick über Bestand und Vorgänge.</p>
    </div>
    <?php if ($can('movements.checkout')): ?>
    <div class="page-actions">
        <a class="btn btn-primary" href="/m"><?= icon('qr') ?> Mobile Erfassung</a>
    </div>
    <?php endif; ?>
</div>

<section class="grid grid-4" aria-label="Kennzahlen">
    <?php if ($can('assets.view')): ?>
    <a class="card stat-card" href="/assets">
        <span class="stat-value"><?= (int) $stats['assets_total'] ?></span>
        <span class="stat-label">Assets gesamt</span>
    </a>
    <a class="card stat-card is-success" href="/assets?status=in_stock">
        <span class="stat-value"><?= (int) $stats['assets_in_stock'] ?></span>
        <span class="stat-label">Im Lager</span>
    </a>
    <a class="card stat-card is-info" href="/assets?status=issued">
        <span class="stat-value"><?= (int) $stats['assets_issued'] ?></span>
        <span class="stat-label">Ausgegeben</span>
    </a>
    <a class="card stat-card <?= $stats['assets_defective'] > 0 ? 'is-warning' : '' ?>" href="/assets?status=defective">
        <span class="stat-value"><?= (int) $stats['assets_defective'] ?></span>
        <span class="stat-label">Defekt / Reparatur</span>
    </a>
    <?php endif; ?>
    <?php if ($can('movements.view')): ?>
    <a class="card stat-card <?= $stats['open_checkouts'] > 0 ? 'is-warning' : '' ?>" href="/movements/open?type=checkout">
        <span class="stat-value"><?= (int) $stats['open_checkouts'] ?></span>
        <span class="stat-label">Offene Entnahmen</span>
    </a>
    <a class="card stat-card <?= $stats['open_returns'] > 0 ? 'is-warning' : '' ?>" href="/movements/open?type=return">
        <span class="stat-value"><?= (int) $stats['open_returns'] ?></span>
        <span class="stat-label">Offene Retouren</span>
    </a>
    <a class="card stat-card <?= $stats['returns_overdue'] > 0 ? 'is-danger' : '' ?>" href="/assets?overdue=1">
        <span class="stat-value"><?= (int) $stats['returns_overdue'] ?></span>
        <span class="stat-label">Rückgabe überfällig</span>
    </a>
    <a class="card stat-card" href="/movements?date=<?= date('Y-m-d') ?>">
        <span class="stat-value"><?= (int) $stats['movements_today'] ?></span>
        <span class="stat-label">Bewegungen heute</span>
    </a>
    <?php endif; ?>
    <?php if ($can('orders.view')): ?>
    <a class="card stat-card" href="/orders?status=open">
        <span class="stat-value"><?= (int) $stats['orders_open'] ?></span>
        <span class="stat-label">Offene Bestellungen</span>
    </a>
    <?php endif; ?>
    <?php if ($can('licenses.view')): ?>
    <a class="card stat-card <?= $stats['licenses_expiring'] > 0 ? 'is-warning' : '' ?>" href="/licenses?expiring=60">
        <span class="stat-value"><?= (int) $stats['licenses_expiring'] ?></span>
        <span class="stat-label">Lizenzen laufen ab (60 Tage)</span>
    </a>
    <?php endif; ?>
    <?php if ($can('employees.view')): ?>
    <a class="card stat-card" href="/employees">
        <span class="stat-value"><?= (int) $stats['employees_active'] ?></span>
        <span class="stat-label">Aktive Mitarbeiter</span>
    </a>
    <?php endif; ?>
</section>

<?php if ($can('movements.checkout') || $can('assets.manage')): ?>
<section class="card mt-4">
    <div class="card-header"><h2>Schnellzugriff</h2></div>
    <div class="quick-actions">
        <?php if ($can('movements.checkout')): ?>
            <a class="btn btn-secondary btn-lg" href="/m/scan"><?= icon('qr') ?> Scannen</a>
            <a class="btn btn-secondary btn-lg" href="/m/checkout"><?= icon('checkout') ?> Entnahme</a>
            <a class="btn btn-secondary btn-lg" href="/m/return"><?= icon('return') ?> Retoure</a>
        <?php endif; ?>
        <?php if ($can('assets.manage')): ?>
            <a class="btn btn-secondary btn-lg" href="/assets/new"><?= icon('plus') ?> Asset anlegen</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<div class="dashboard-grid mt-4">
    <?php if ($can('movements.view')): ?>
    <section class="card card-flush col-span-6">
        <div class="card-header"><h2>Letzte Bewegungen</h2><a class="btn btn-link btn-sm" href="/movements">Alle</a></div>
        <?php if (!$recentMovements): ?>
            <div class="table-empty">Noch keine Bewegungen erfasst.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Zeit</th><th>Typ</th><th>Asset</th><th>Mitarbeiter</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentMovements as $m): ?>
                <tr class="is-clickable" data-href="/movements/<?= (int) $m['id'] ?>">
                    <td class="nowrap"><?= fmt_datetime($m['movement_at']) ?></td>
                    <td><?= $m['type'] === 'checkout' ? badge('Entnahme', 'info') : badge('Retoure', 'success') ?></td>
                    <td><strong><?= e($m['inventory_number']) ?></strong><br><span class="text-muted text-sm"><?= e($m['asset_name'] ?? '') ?></span></td>
                    <td><?= e($m['employee_name'] ?? '–') ?></td>
                    <td><?= $m['status'] === 'open' ? badge('Offen', 'warning') : ($m['status'] === 'cancelled' ? badge('Storniert', 'neutral') : badge('Abgeschlossen', 'success')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($can('movements.view')): ?>
    <section class="card card-flush col-span-6">
        <div class="card-header"><h2>Anstehende Rückgaben</h2></div>
        <?php if (!$returnsDueSoon): ?>
            <div class="table-empty">Keine Rückgaben geplant.</div>
        <?php else: ?>
        <ul class="task-list" style="padding: 0 var(--space-4);">
            <?php foreach ($returnsDueSoon as $a): $overdue = $a['expected_return_at'] < date('Y-m-d'); ?>
                <li>
                    <div>
                        <div class="task-title"><a href="/assets/<?= (int) $a['id'] ?>"><?= e($a['inventory_number']) ?></a> <?= e($a['name'] ?? '') ?></div>
                        <div class="task-meta"><?= e($a['employee_name'] ?? '–') ?> · fällig <?= fmt_date($a['expected_return_at']) ?></div>
                    </div>
                    <div class="task-actions"><?= $overdue ? badge('Überfällig', 'danger') : badge('Geplant', 'info') ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($can('assets.view')): ?>
    <section class="card card-flush col-span-12">
        <div class="card-header"><h2>Letzte Änderungen an Assets</h2></div>
        <?php if (!$recentHistory): ?>
            <div class="table-empty">Noch keine Änderungen protokolliert.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Zeit</th><th>Asset</th><th>Ereignis</th><th>Änderung</th><th>Benutzer</th></tr></thead>
            <tbody>
            <?php foreach ($recentHistory as $h): ?>
                <tr>
                    <td class="nowrap"><?= fmt_datetime($h['created_at']) ?></td>
                    <td><a href="/assets?q=<?= e(urlencode($h['inventory_number'])) ?>"><?= e($h['inventory_number']) ?></a></td>
                    <td><?= e($h['event_type']) ?></td>
                    <td><?php if ($h['field']): ?><span class="text-muted"><?= e($h['field']) ?>:</span> <?= e($h['old_value'] ?? '–') ?> → <?= e($h['new_value'] ?? '–') ?><?php else: ?><?= e($h['note'] ?? '') ?><?php endif; ?></td>
                    <td><?= e($h['user_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
