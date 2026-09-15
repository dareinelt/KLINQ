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
    // Domain für Message-IDs ausgehender Ticket-Mails (Threading); leer = aus Absenderadresse/APP_URL ableiten
    'mail_domain' => trim((string) Env::get('HELPDESK_MAIL_DOMAIN', '')),
    // E-Mail-Eingang: Postfach per IMAP abholen und als Ticket bzw. Kommentar verarbeiten
    'mail' => [
        'enabled' => Env::bool('HELPDESK_MAIL_ENABLED', false),
        // imap (produktiv) oder file (Entwicklung/Tests: .eml-Dateien aus mail.file_path)
        'driver' => strtolower(trim((string) Env::get('HELPDESK_MAIL_DRIVER', 'imap'))) ?: 'imap',
        'file_path' => trim((string) Env::get('HELPDESK_MAIL_FILE_PATH', 'storage/mail-inbox')),
        'host' => trim((string) Env::get('HELPDESK_IMAP_HOST', '')),
        'port' => max(1, Env::int('HELPDESK_IMAP_PORT', 993)),
        // ssl (Port 993), starttls (Port 143) oder none
        'encryption' => strtolower(trim((string) Env::get('HELPDESK_IMAP_ENCRYPTION', 'ssl'))) ?: 'ssl',
        'username' => (string) Env::get('HELPDESK_IMAP_USERNAME', ''),
        'password' => (string) Env::get('HELPDESK_IMAP_PASSWORD', ''),
        'mailbox' => trim((string) Env::get('HELPDESK_IMAP_MAILBOX', 'INBOX')) ?: 'INBOX',
        // Verarbeitete Nachrichten in diesen Ordner verschieben; leer = nur als gelesen markieren
        'processed_mailbox' => trim((string) Env::get('HELPDESK_IMAP_PROCESSED_MAILBOX', '')),
        'timeout' => max(3, Env::int('HELPDESK_IMAP_TIMEOUT', 15)),
        'verify_peer' => Env::bool('HELPDESK_IMAP_VERIFY_PEER', true),
        // Nachrichten je Lauf (Schutz vor Überlast bei großen Postfächern)
        'batch_size' => max(1, min(500, Env::int('HELPDESK_MAIL_BATCH_SIZE', 50))),
        'interval_minutes' => max(0, Env::int('HELPDESK_MAIL_INTERVAL_MINUTES', 2)),
        // Benutzerkonto, unter dem Tickets/Kommentare aus E-Mails angelegt werden (benötigt helpdesk.create)
        'system_user' => trim((string) Env::get('HELPDESK_MAIL_SYSTEM_USER', 'admin')) ?: 'admin',
        // Unbekannte Absender (kein Mitarbeiter/Benutzer mit dieser Adresse) dürfen Tickets eröffnen
        'allow_unknown_senders' => Env::bool('HELPDESK_MAIL_ALLOW_UNKNOWN_SENDERS', true),
        // Standard-Tickettyp (Code) für Tickets aus E-Mails
        'default_type' => trim((string) Env::get('HELPDESK_MAIL_DEFAULT_TYPE', 'incident')) ?: 'incident',
    ],
    'list_per_page' => 25,
];
