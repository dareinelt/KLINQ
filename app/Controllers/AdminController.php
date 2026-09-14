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
            ['permission' => 'users.manage', 'href' => '/admin/users', 'icon' => 'users', 'title' => 'Benutzer', 'text' => 'Lokale Konten anlegen, Rollen zuweisen, Konten deaktivieren und Passwörter zurücksetzen.'],
            ['permission' => 'settings.manage', 'href' => '/admin/labels', 'icon' => 'print', 'title' => 'Etikettenlayout', 'text' => 'Firmenname, Logo, Maße, QR-Position und Schriftgrößen der Inventaretiketten.'],
            ['permission' => 'employees.sync', 'href' => '/admin/ad-sync', 'icon' => 'refresh', 'title' => 'AD-Synchronisation', 'text' => 'Mitarbeiter aus dem Active Directory abgleichen, Läufe und Protokolle einsehen.'],
            ['permission' => 'handover.template', 'href' => '/admin/handover-template', 'icon' => 'signature', 'title' => 'Übergabeprotokoll-Vorlage', 'text' => 'Aufbau des Übergabeprotokolls im Baukasten anpassen: Texte, Mitarbeiterfelder, Spalten, Bestätigungen und Unterschrift.'],
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
