<?php

declare(strict_types=1);

use App\Core\Env;

/** Help Desk / Ticketsystem. Fachliche Stammdaten (Status, SLA, Kategorien) liegen in der Datenbank. */
return [
    'enabled' => Env::bool('HELPDESK_ENABLED', true),
    // Präfix der Ticketnummer: PREFIX-JJJJ-NNNNNN
    'ticket_prefix' => strtoupper(trim((string) Env::get('HELPDESK_TICKET_PREFIX', 'HD'))) ?: 'HD',
    // Standardschwellen in Prozent der SLA-Zeit, wenn eine SLA-Regel keine eigenen Werte definiert
    'sla_warning_percent' => max(1, min(99, Env::int('HELPDESK_SLA_WARNING_PERCENT', 75))),
    'sla_escalation_percent' => max(1, min(100, Env::int('HELPDESK_ESCALATION_PERCENT', 90))),
    // Scheduler-Job (SLA-Prüfung, Eskalation, automatisches Schließen)
    'escalation_enabled' => Env::bool('HELPDESK_ESCALATION_ENABLED', true),
    'escalation_interval_minutes' => max(0, Env::int('HELPDESK_ESCALATION_INTERVAL_MINUTES', 5)),
    // Gelöste Tickets nach n Tagen ohne Rückmeldung automatisch schließen (0 = aus)
    'auto_close_days' => max(0, Env::int('HELPDESK_AUTO_CLOSE_DAYS', 7)),
    // Melder dürfen gelöste/geschlossene Tickets innerhalb dieser Frist selbst wieder öffnen (0 = nie)
    'reopen_days' => max(0, Env::int('HELPDESK_REOPEN_DAYS', 14)),
    // E-Mail-Benachrichtigungen (nutzen MailClient / Container "mail")
    'notifications_enabled' => Env::bool('HELPDESK_NOTIFICATION_ENABLED', true),
    // Zusätzliche Empfängeradresse für neue Tickets (z. B. Sammelpostfach); leer = keine
    'notification_inbox' => trim((string) Env::get('HELPDESK_NOTIFICATION_INBOX', '')),
    'list_per_page' => 25,
];
