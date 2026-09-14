<?php

declare(strict_types=1);

/**
 * Rollen → Rechte. Rechte werden serverseitig in der AuthorizationMiddleware
 * (Route) und in Controllern/Services geprüft.
 *
 * Konvention: "<bereich>.view" / "<bereich>.manage".
 */
$all = [
    'dashboard.view',
    'assets.view', 'assets.manage', 'assets.retire',
    'labels.print',
    'movements.view', 'movements.checkout', 'movements.return', 'movements.complete',
    'employees.view', 'employees.manage', 'employees.sync',
    'locations.view', 'locations.manage',
    'costcenters.view', 'costcenters.manage',
    'manufacturers.view', 'manufacturers.manage',
    'articles.view', 'articles.manage',
    'suppliers.view', 'suppliers.manage',
    'orders.view', 'orders.manage', 'orders.receive',
    'documents.view', 'documents.manage',
    'licenses.view', 'licenses.manage',
    'reports.view', 'reports.export',
    'imports.manage',
    'audit.view',
    'settings.manage',
    'users.manage',
];

// Alle Leserechte – außer dem Audit-Log (enthält Benutzer- und IP-Daten; nur Admin und Assetmanagement)
$readOnly = array_values(array_filter($all, static fn (string $p): bool => str_ends_with($p, '.view') && $p !== 'audit.view'));

return [
    'all' => $all,
    'roles' => [
        'admin' => $all,
        'assetmanagement' => array_values(array_unique(array_merge($readOnly, [
            'assets.manage', 'assets.retire', 'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'employees.manage', 'employees.sync',
            'locations.manage', 'costcenters.manage',
            'manufacturers.manage', 'articles.manage',
            'documents.manage', 'licenses.manage',
            'reports.export', 'imports.manage', 'audit.view',
        ]))),
        'lager' => array_values(array_unique(array_merge($readOnly, [
            'labels.print',
            'movements.checkout', 'movements.return', 'movements.complete',
            'orders.receive', 'assets.manage', 'documents.manage',
        ]))),
        'einkauf' => array_values(array_unique(array_merge($readOnly, [
            'suppliers.manage', 'orders.manage', 'orders.receive',
            'manufacturers.manage', 'articles.manage', 'documents.manage',
            'licenses.manage', 'reports.export',
        ]))),
        'readonly' => $readOnly,
    ],
    'role_labels' => [
        'admin' => 'Administrator',
        'assetmanagement' => 'Assetmanagement',
        'lager' => 'Lager',
        'einkauf' => 'Einkauf',
        'readonly' => 'Nur lesen',
    ],
];
