<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class AdminController extends BaseController
{
    public function index(Request $request): Response
    {
        $areas = [
            ['permission' => 'settings.manage', 'href' => '/admin/labels', 'icon' => 'print', 'title' => 'Etikettenlayout', 'text' => 'Firmenname, Logo, Maße, QR-Position und Schriftgrößen der Inventaretiketten.'],
            ['permission' => 'employees.sync', 'href' => '/admin/ad-sync', 'icon' => 'refresh', 'title' => 'AD-Synchronisation', 'text' => 'Mitarbeiter aus dem Active Directory abgleichen, Läufe und Protokolle einsehen.'],
            ['permission' => 'audit.view', 'href' => '/audit', 'icon' => 'shield', 'title' => 'Audit-Log', 'text' => 'Wer hat wann was geändert – vollständiges Änderungsprotokoll.'],
        ];

        return $this->render('admin.index', [
            'title' => 'Administration',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'areas' => array_values(array_filter($areas, fn (array $a): bool => $this->currentUser->can($a['permission']))),
        ]);
    }
}
