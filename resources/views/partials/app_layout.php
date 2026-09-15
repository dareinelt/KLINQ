<?php
/**
 * App-Layout (Desktop): Sidebar + Topbar.
 * Erwartet: $innerContent, $title, $activeNav, $user, $can (callable), optional $scripts, $areaLabel
 */
require_once __DIR__ . '/helpers.php';

$user = $user ?? [];
$activeNav = $activeNav ?? '';
$appName = $appName ?? 'Assetverwaltung';
$can = $can ?? static fn (string $p): bool => false;
$roleLabels = $roleLabels ?? [];
$openCounts = $openCounts ?? ['checkouts' => 0, 'returns' => 0];
$helpdeskEnabled = $helpdeskEnabled ?? false;
$areaClass = match (true) {
    $activeNav === 'admin' => 'is-admin',
    str_starts_with($activeNav, 'helpdesk') => 'is-helpdesk',
    str_starts_with($activeNav, 'portal') => 'is-portal',
    default => '',
};

$navItem = static function (string $key, string $href, string $iconName, string $label, ?int $badge = null) use ($activeNav): string {
    $active = $activeNav === $key;
    $badgeHtml = $badge !== null && $badge > 0 ? '<span class="nav-badge">' . $badge . '</span>' : '';

    return '<a href="' . e($href) . '" class="' . ($active ? 'is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . icon($iconName) . '<span>' . e($label) . '</span>' . $badgeHtml . '</a>';
};

ob_start();
?>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="/dashboard">
            <span class="brand-logo" aria-hidden="true"><?= icon('box') ?></span>
            <span><?= e($appName) ?></span>
        </a>
        <nav class="sidebar-nav" aria-label="Hauptnavigation">
            <?= $navItem('dashboard', '/dashboard', 'home', 'Dashboard') ?>
            <?= $navItem('scan', '/m', 'qr', 'Scannen') ?>
            <?php if ($can('assets.view')): ?>
                <span class="sidebar-section-label">Bestand</span>
                <?= $navItem('assets', '/assets', 'laptop', 'Assets') ?>
            <?php endif; ?>
            <?php if ($can('movements.view')): ?>
                <?= $navItem('open-checkouts', '/movements/open', 'warning', 'Offene Vorgänge', ($openCounts['checkouts'] ?? 0) + ($openCounts['returns'] ?? 0)) ?>
                <?= $navItem('movements', '/movements', 'swap', 'Bewegungen') ?>
            <?php endif; ?>
            <?php if ($can('handover.view')): ?>
                <?= $navItem('handover', '/handover', 'signature', 'Übergabeprotokolle') ?>
            <?php endif; ?>
            <?php if ($can('licenses.view')): ?>
                <?= $navItem('licenses', '/licenses', 'key', 'Lizenzen') ?>
            <?php endif; ?>
            <?php if ($helpdeskEnabled && $can('helpdesk.view')): ?>
                <span class="sidebar-section-label">Help Desk</span>
                <?= $navItem('helpdesk', '/helpdesk', 'lifebuoy', 'Übersicht') ?>
                <?= $navItem('helpdesk-tickets', '/helpdesk/tickets', 'ticket', 'Tickets', $openCounts['tickets'] ?? 0) ?>
                <?php if ($can('knowledgebase.view')): ?><?= $navItem('helpdesk-knowledge', '/helpdesk/knowledge', 'book', 'Wissensdatenbank') ?><?php endif; ?>
                <?php if ($can('helpdesk.reports')): ?><?= $navItem('helpdesk-reports', '/helpdesk/reports', 'chart', 'Ticket-Berichte') ?><?php endif; ?>
                <?php if ($can('helpdesk.categories') || $can('helpdesk.admin')): ?><?= $navItem('helpdesk-admin', '/helpdesk/admin', 'settings', 'Help-Desk-Einstellungen') ?><?php endif; ?>
            <?php elseif ($helpdeskEnabled && $can('portal.view')): ?>
                <span class="sidebar-section-label">Support</span>
                <?= $navItem('portal', '/portal', 'lifebuoy', 'Meine Tickets', $openCounts['portal_tickets'] ?? 0) ?>
                <?php if ($can('knowledgebase.view')): ?><?= $navItem('portal-knowledge', '/portal/knowledge', 'book', 'Hilfe & Anleitungen') ?><?php endif; ?>
            <?php endif; ?>
            <?php if ($can('orders.view') || $can('suppliers.view')): ?>
                <span class="sidebar-section-label">Einkauf</span>
                <?php if ($can('orders.view')): ?><?= $navItem('orders', '/orders', 'cart', 'Bestellungen') ?><?php endif; ?>
                <?php if ($can('suppliers.view')): ?><?= $navItem('suppliers', '/suppliers', 'truck', 'Lieferanten') ?><?php endif; ?>
            <?php endif; ?>
            <span class="sidebar-section-label">Stammdaten</span>
            <?php if ($can('employees.view')): ?><?= $navItem('employees', '/employees', 'users', 'Mitarbeiter') ?><?php endif; ?>
            <?php if ($can('locations.view')): ?><?= $navItem('locations', '/locations', 'map', 'Standorte') ?><?php endif; ?>
            <?php if ($can('costcenters.view')): ?><?= $navItem('costcenters', '/cost-centers', 'hash', 'Kostenstellen') ?><?php endif; ?>
            <?php if ($can('manufacturers.view')): ?><?= $navItem('manufacturers', '/manufacturers', 'factory', 'Hersteller') ?><?php endif; ?>
            <?php if ($can('articles.view')): ?><?= $navItem('articles', '/articles', 'tag', 'Artikel') ?><?php endif; ?>
            <?php if ($can('reports.view') || $can('imports.manage') || $can('settings.manage') || $can('audit.view')): ?>
                <span class="sidebar-section-label">Auswertung &amp; System</span>
                <?php if ($can('reports.view')): ?><?= $navItem('reports', '/reports', 'chart', 'Berichte') ?><?php endif; ?>
                <?php if ($can('imports.manage')): ?><?= $navItem('imports', '/imports', 'import', 'Import') ?><?php endif; ?>
                <?php if ($can('audit.view')): ?><?= $navItem('audit', '/audit', 'shield', 'Audit-Log') ?><?php endif; ?>
                <?php if ($can('settings.manage')): ?><?= $navItem('admin', '/admin', 'settings', 'Administration') ?><?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-block">
                    <?= icon('logout') ?>
                    Abmelden
                </button>
            </form>
        </div>
    </aside>
    <header class="topbar">
        <button type="button" class="topbar-menu-toggle" id="sidebar-toggle" aria-label="Navigation öffnen" aria-expanded="false" aria-controls="sidebar">
            <?= icon('menu') ?>
        </button>
        <?php if (!empty($areaLabel)): ?>
            <span class="topbar-area-label <?= $areaClass ?>"><?= e($areaLabel) ?></span>
        <?php endif; ?>
        <div class="search-box topbar-search" id="global-search">
            <?= icon('search') ?>
            <label for="global-search-input" class="visually-hidden">Globale Suche</label>
            <input id="global-search-input" type="search" placeholder="Inventarnr., Seriennr., Mitarbeiter, Standort …" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="global-search-results" enterkeyhint="search">
            <ul class="search-results" id="global-search-results" hidden></ul>
        </div>
        <div class="topbar-spacer"></div>
        <span class="offline-indicator" id="offline-indicator" hidden><?= icon('offline') ?> Offline</span>
        <a class="topbar-user" href="/profile/password" title="Passwort ändern">
            <span class="topbar-user-name"><?= e($user['display_name'] ?? $user['username'] ?? '') ?> · <?= e($roleLabels[$user['role'] ?? ''] ?? ($user['role'] ?? '')) ?></span>
            <span class="avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['display_name'] ?? $user['username'] ?? '?'), 0, 1)) ?></span>
        </a>
    </header>
    <main class="main-content" id="main">
        <?php include __DIR__ . '/flash.php'; ?>
        <?= $innerContent ?? '' ?>
    </main>
</div>
<?php
unset($_SESSION['_old_input'], $_SESSION['_errors']);
$content = ob_get_clean();
$scripts = array_merge(['/js/search.js', '/js/pwa.js'], $scripts ?? []);
include __DIR__ . '/layout.php';
