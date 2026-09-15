# Help Desk – Ticketsystem, Serviceportal & Wissensdatenbank

Das Help-Desk-Modul ergänzt die Assetverwaltung um ein vollständiges IT-Ticketsystem: Störungen, Serviceanfragen, Änderungen und Probleme werden als **Tickets** mit Nummer, Status-Workflow, Priorität aus Auswirkung × Dringlichkeit, SLA-Fristen, Zuständigkeit (Agent/Gruppe/Vertretung), Kommentaren, Anhängen, Arbeitszeiten, Verknüpfungen zu Assets/Mitarbeitern und einer lückenlosen Historie geführt. Endanwender melden Anliegen über ein **Serviceportal**, Lösungen landen in einer **Wissensdatenbank**. Alles läuft im bestehenden Stack (PHP 8.4, MySQL, keine Frameworks, CSP-konform, jede Route mit Berechtigung, Audit-Log).

## Überblick

| Bereich | Pfad | Zielgruppe |
|---|---|---|
| Help-Desk-Dashboard | `/helpdesk` | Agenten, Leitung |
| Ticketliste, Detail, Anlage | `/helpdesk/tickets` | Agenten |
| Berichte & CSV-Export | `/helpdesk/reports` | Leitung |
| Wissensdatenbank (Pflege) | `/helpdesk/knowledge` | Agenten |
| Administration (Typen, Status, Prioritäten, Kategorien, SLA, Gruppen, Vorlagen, Regeln) | `/helpdesk/admin` | Help-Desk-Admin |
| Serviceportal (eigene Tickets, neue Anfrage, Wissensartikel) | `/portal` | alle Mitarbeitenden |
| JSON-Endpunkte (Suche, Vorschläge, Vorlagen, Assets des Melders) | `/api/helpdesk/…` | Oberfläche |

Der Modulschalter `HELPDESK_ENABLED=false` blendet Navigation, Dashboard-Kacheln, Ticket-Treffer in der globalen Suche und den Scheduler-Job aus; die Routen selbst bleiben durch ihre Berechtigungen geschützt.

### Bereichswechsel in der Oberfläche

Help Desk und Assetverwaltung sind zwei eigenständige Module mit je eigenem Dashboard (`/dashboard` bzw. `/helpdesk`, für reine Portalnutzer `/portal`). In der Topbar steht mittig zwischen Suchfeld und Benutzeranzeige ein Dropdown, über das der Bereich gewechselt wird; die Seitenleiste zeigt immer nur die Navigationseinträge des gewählten Moduls. Das Dropdown erscheint nur, wenn der Benutzer beide Bereiche betreten darf (`helpdesk.view` oder `portal.view` bei aktivem Modulschalter).

Modulzuordnung und Navigationseinträge stehen zentral in `app/Support/ModuleNavigation.php`; das Layout (`resources/views/partials/app_layout.php`) rendert sie nur noch. Das aktive Modul ergibt sich aus dem `activeNav`-Schlüssel der Seite (`helpdesk*`/`portal*` → Help Desk, sonst Assetverwaltung).

## Ticket-Lebenszyklus

### Nummer

`PREFIX-JJJJ-NNNNNN` (z. B. `HD-2025-000042`), Präfix aus `HELPDESK_TICKET_PREFIX`. Die Vergabe läuft über `ticket_sequences` unter `SELECT … FOR UPDATE` je Jahr – kollisionsfrei auch bei parallelen Anlagen.

### Status-Workflow

Status sind Stammdaten (`ticket_statuses`) mit einer **Kategorie**, die das Verhalten bestimmt:

| Kategorie | Standardstatus | Bedeutung |
|---|---|---|
| `new` | Neu | eingegangen, noch nicht angenommen |
| `open` | In Bearbeitung | Agent arbeitet; erste Reaktion zählt für die Reaktions-SLA |
| `pending` | Wartet auf Melder / Wartet auf Dritte | SLA-Uhr pausiert (`sla_paused_at`, `sla_paused_minutes`) |
| `resolved` | Gelöst | Lösung eingetragen; Melder kann bestätigen oder wieder öffnen |
| `closed` | Geschlossen | endgültig (manuell oder automatisch nach `HELPDESK_AUTO_CLOSE_DAYS`) |
| `cancelled` | Abgebrochen | mit Begründung |

Erlaubte Übergänge stehen je Status als Liste (`allowed_transitions`). Regeln:

- **Gelöst** erfordert einen Lösungstext, **Abgebrochen** eine Begründung.
- **Wieder öffnen** (`helpdesk.reopen`; im Portal innerhalb `HELPDESK_REOPEN_DAYS`) erhöht `reopen_count` und setzt die SLA-Uhr fort.
- Jede Änderung prüft die **Version** (optimistische Sperre, 409 bei Konflikt) und schreibt ein Ereignis in `ticket_events` sowie einen Audit-Eintrag.
- Zusammengeführte Tickets (`merged_into_ticket_id`) sind schreibgeschützt; Kommentare, Anhänge, Watcher und Asset-Verknüpfungen wandern ins Zielticket.

### Priorität, Auswirkung, Dringlichkeit

Die Priorität wird aus **Auswirkung** (1 = Unternehmen … 3 = Einzelperson) × **Dringlichkeit** (1 = sofort … 3 = niedrig) über eine 3×3-Matrix (`TicketPriorityMatrix`) vorgeschlagen; Agenten können sie überschreiben, Portalnutzer nicht (agentenspezifische Felder werden im Portal ignoriert).

### SLA

`ticket_slas` definieren je Priorität (optional je Typ/Kategorie) Reaktions- und Lösungszeit in Minuten, wahlweise nur innerhalb der **Servicezeiten** (Wochentage, Start/Ende) sowie eigene Warn-/Eskalationsschwellen in Prozent. Beim Anlegen und bei Prioritäts-/Kategoriewechsel werden `response_due_at`/`resolution_due_at` berechnet (`TicketSlaService`, Pausen werden herausgerechnet). Der Zustand (`ok → warning → breached`, `met` bei Erfüllung) wird laufend aktualisiert und in Liste, Detail (Fortschrittsbalken) und Dashboard angezeigt.

### Eskalation & Scheduler

`php bin/helpdesk.php process` (im Container automatisch alle `HELPDESK_ESCALATION_INTERVAL_MINUTES` Minuten über `docker/php/scheduler.sh`, alternativ Cron auf dem Host):

1. bewertet alle offenen Tickets neu (Warnung/Verletzung, Ereignis + Benachrichtigung einmalig je Stufe),
2. eskaliert verletzte Tickets (`escalation_level` +1, Meldung an Gruppenleitung/Sammelpostfach, Ereignis `escalated`),
3. schließt gelöste Tickets ohne Rückmeldung nach `HELPDESK_AUTO_CLOSE_DAYS` Tagen (Ereignis `closed`, Quelle `scheduler`).

Exit-Codes: `0` ok, `1` Fehler, `2` Modul deaktiviert.

## Ticketdetail (`/helpdesk/tickets/{id}`)

- **Kopf**: Nummer, Betreff, Status/Priorität/Typ, Melder (Mitarbeiter mit Abteilung, Standort, Telefon), betroffener Mitarbeiter, Zuständigkeit, Fälligkeiten, SLA-Balken.
- **Aktionen**: Status ändern (Dialog mit Lösung/Begründung), Übernehmen, Zuweisen (Agent/Gruppe/Vertretung), Zusammenführen, Beobachten, Löschen (`helpdesk.delete`).
- **Kommunikation**: öffentliche Kommentare (Melder sieht sie im Portal, E-Mail-Benachrichtigung) und **interne Notizen** (`helpdesk.internal_note`, nur Agenten). Für Agenten ist die **interne Notiz** vorausgewählt; öffentliche Antworten müssen bewusst gewählt werden. Anhänge je Kommentar oder direkt am Ticket, optional intern.
- **Arbeitszeiten** (`helpdesk.worklog`): Minuten, Tätigkeit, Datum; Summe im Kopf. Der Bereich ist – wie **Anhänge** – aufklappbar und standardmäßig geschlossen.
- **Verknüpfungen**: Assets (mit Vorschlag der dem Melder zugeordneten Geräte), Beziehungen zu anderen Tickets (`related`, `duplicate_of`, `parent_of`, `problem_of`, `change_for` – jeweils mit Gegenrichtung), Wissensartikel (Vorschläge nach Betreff/Kategorie, „Artikel aus Lösung erstellen“).
- **Tags** mit Autovervollständigung, **Watcher** (zusätzliche Empfänger).
- **Seitenleiste**: kompakte Infoboxen (SLA, Zuweisung, Details, Tags, Assets, Beziehungen, Beobachter, Wissensdatenbank); die Bearbeitungsformulare sind jeweils hinter einem aufklappbaren Abschnitt zusammengefasst.
- **Beschreibungstext**: nach dem Anlegen nur noch mit `helpdesk.admin` änderbar. Für alle anderen Bearbeiter ist das Feld im Bearbeitungsformular schreibgeschützt; ein übermittelter Wert wird serverseitig verworfen (`TicketService::canEditDescription()`). Ergänzungen gehören in Kommentare bzw. interne Notizen.
- **Historie**: alle Ereignisse chronologisch mit Feldänderungen (alt → neu).

### Liste (`/helpdesk/tickets`)

Ansichten *Meine*, *Meine Gruppe*, *Nicht zugewiesen*, *Alle offenen*, *SLA-kritisch*, *Gelöst*, *Alle*, dazu Volltext (Nummer, Betreff, Beschreibung, Melder), Filter nach Status, Priorität, Typ, Kategorie, Gruppe, Agent, Tag, Asset, Zeitraum; Sortierung nach Fälligkeit, Priorität, Aktualisierung, Nummer. CSV-Export (`helpdesk.export`) respektiert die aktiven Filter.

## Serviceportal (`/portal`)

Jeder angemeldete Benutzer mit `portal.view`/`portal.create` sieht ausschließlich **eigene** Tickets (als Melder oder betroffene Person, gematcht über `users.employee_id`). Neue Anfrage: Vorlage wählen (füllt Typ/Kategorie/Betreff/Beschreibung vor), Auswirkung/Dringlichkeit in Alltagssprache, eigene Geräte als Auswahl, Anhang. Im Detail: Kommentare schreiben, Anhänge hochladen, gelöste Tickets bestätigen oder innerhalb der Frist wieder öffnen. Interne Notizen und interne Anhänge sind für Portalnutzer unsichtbar (`TicketService::getVisible` filtert serverseitig).

## Wissensdatenbank

`knowledge_articles` mit Titel, Zusammenfassung, Inhalt (Markdown-light: Absätze, Listen, `**fett**`, Code), Kategorie, Tags, Sichtbarkeit (`internal` = nur Agenten, `public` = auch Portal), Status (`draft`/`published`/`archived`), Aufrufzähler. Artikel lassen sich mit Tickets verknüpfen (`knowledge_article_tickets`); die Suche schlägt beim Anlegen im Portal und im Ticketdetail passende Artikel vor.

## Automatisierung

- **Vorlagen** (`ticket_templates`): vorbelegte Felder für Portal und Agenten, optional auf Rollen/Portal beschränkt.
- **Regeln** (`ticket_rules`): bei Ereignis (`created`, `updated`, `status_changed`, `comment_added`, `sla_warning`, `sla_breached`) werden Bedingungen (Typ, Kategorie, Priorität, Betreff enthält, Melder-Abteilung, Quelle …) geprüft und Aktionen ausgeführt (Gruppe/Agent setzen, Priorität, Tags hinzufügen, SLA zuweisen, Benachrichtigen). Reihenfolge über `sort_order`, Stopp nach Treffer optional; jede Anwendung erscheint als Ereignis `rule_applied`.
- **Benachrichtigungen** (`ticket_notifications`): Anlage, Zuweisung, Kommentar, Statuswechsel, SLA-Warnung/-Verletzung, Eskalation – per E-Mail über den `mail`-Container an Melder, Agent, Gruppe, Watcher und optional `HELPDESK_NOTIFICATION_INBOX`. Ausgang wird mit Status/Fehler protokolliert und ist in der Administration einsehbar. `HELPDESK_NOTIFICATION_ENABLED=false` schaltet den Versand ab.
- **E-Mail-Eingang** (`TicketMailIngestionService`): siehe Abschnitt „E-Mail-Eingang (IMAP) & Threading“.

## E-Mail-Eingang (IMAP) & Threading

Der Help Desk kann ein Postfach abholen und eingehende Nachrichten automatisch Tickets zuordnen. Es wird **keine PHP-Erweiterung** (`ext-imap`) benötigt – der IMAP-Client (`app/Services/Helpdesk/Mail/ImapMailboxClient.php`) spricht das Protokoll direkt über TLS-Sockets.

### Ausgehende Nachrichten

Jede Benachrichtigung trägt Threading-Kopfzeilen, damit Antworten der Empfänger eindeutig zugeordnet werden können:

| Kopfzeile | Wert |
|---|---|
| `Message-ID` | `<ticket-{id}.{ereignis}.{zufall}@{HELPDESK_MAIL_DOMAIN}>` – wird in `ticket_notifications.message_id` protokolliert |
| `In-Reply-To` / `References` | `<ticket-{id}@{domain}>` (stabile Thread-Wurzel je Ticket) – Mailprogramme fassen alle Mails eines Tickets zu einer Konversation zusammen |
| `X-Ticket-Number` | Ticketnummer |
| `Auto-Submitted: auto-generated` | verhindert Antwortschleifen mit Abwesenheitsassistenten |

Der Betreff enthält weiterhin `[HD-JJJJ-NNNNNN]` als für Menschen lesbaren Fallback.

### Zuordnung eingehender Nachrichten

`TicketMailIngestionService::ingest()` versucht in dieser Reihenfolge, ein bestehendes Ticket zu finden (`ticket_inbound_mails.matched_by`):

1. **`reference`** – `In-Reply-To` und `References` (neueste zuerst) werden geprüft gegen
   - das eigene ID-Format `<ticket-{id}…@…>`,
   - protokollierte ausgehende Nachrichten (`ticket_notifications.message_id`),
   - Message-IDs früherer eingehender Mails (`tickets.mail_message_id`, `ticket_comments.mail_message_id`) – so wird auch „Antwort auf die eigene Erstmeldung“ richtig zugeordnet.
2. **`subject`** – Ticketnummer im Betreff (`PREFIX-JJJJ-NNNNNN`, Groß-/Kleinschreibung egal).
3. **`none`** – kein Treffer: neues Ticket (Typ `HELPDESK_MAIL_DEFAULT_TYPE`, Quelle `email`).

Verweise auf zusammengeführte Tickets werden zum Zielticket weitergeleitet. Verweise auf gelöschte/unbekannte Tickets führen zu einem neuen Ticket.

### Absender und Wirkung

- Die Absenderadresse wird gegen `users.email` (aktive Konten) und `employees.email` aufgelöst. Mitarbeiter werden Melder (`requester_employee_id`), Benutzer ohne Mitarbeiterdatensatz `requester_user_id`, unbekannte Adressen werden als externer Melder in `tickets.requester_email` geführt (abschaltbar über `HELPDESK_MAIL_ALLOW_UNKNOWN_SENDERS=false`).
- Kommentare aus E-Mails sind immer **öffentlich** (`source = email`) und werden dem tatsächlichen Absender zugeschrieben: Antworten des Melders beenden `waiting_user` (→ `in_progress`, SLA-Uhr läuft weiter) und öffnen gelöste Tickets wieder; Antworten von Agenten (Rolle mit `helpdesk.view`) zählen als Erstreaktion.
- Zitierte Vorgängernachrichten (Outlook-/Gmail-/Thunderbird-Trenner, `>`-Zeilen) und Signaturtrenner werden abgeschnitten; HTML-only-Mails werden in Text umgewandelt.
- Anhänge landen über `DocumentService` in der Dokumentenablage (Whitelist `ALLOWED_UPLOAD_EXTENSIONS`, MIME-Prüfung); abgelehnte Dateitypen werden protokolliert und übersprungen, die Nachricht wird trotzdem verarbeitet.
- **Ignoriert** werden Duplikate (gleiche `Message-ID`), automatische Nachrichten (`Auto-Submitted`, `X-Auto-Response-Suppress`, `Precedence: bulk/list`, `List-Unsubscribe`, Bounces/`multipart/report`, `mailer-daemon@`/`noreply@`) sowie Mails der eigenen Absenderadresse.
- Jede Nachricht wird in `ticket_inbound_mails` protokolliert (Aktion `created|comment|ignored|failed`, Zuordnungsart, Detail); Tickets/Kommentare erhalten Ereignisse mit `via = email`.

### Abholung

`php bin/helpdesk.php mail [--limit=N]` holt neue Nachrichten (`UNSEEN`, ohne Keyword `$HelpdeskFailed`) ab, verarbeitet sie und markiert sie als gelesen bzw. verschiebt sie nach `HELPDESK_IMAP_PROCESSED_MAILBOX`. Nicht verarbeitbare Nachrichten werden mit `$HelpdeskFailed` markiert und beim nächsten Lauf übersprungen. Im Container läuft der Job automatisch alle `HELPDESK_MAIL_INTERVAL_MINUTES` Minuten (`docker/php/scheduler.sh`), sobald `HELPDESK_MAIL_ENABLED=true`. Exit-Codes: `0` ok, `1` Fehler, `2` deaktiviert.

Für Entwicklung und Tests existiert der Treiber `file` (`HELPDESK_MAIL_DRIVER=file`): `.eml`-Dateien aus `HELPDESK_MAIL_FILE_PATH` werden verarbeitet und nach `processed/` bzw. `failed/` verschoben.

Voraussetzungen: Das Konto `HELPDESK_MAIL_SYSTEM_USER` (Standard `admin`) muss aktiv sein und `helpdesk.create` besitzen; unter ihm werden Anlage und Kommentare ausgeführt (Audit), der fachliche Autor bleibt der Absender.

## Rollen & Berechtigungen

| Rolle | Umfang |
|---|---|
| **helpdesk_agent** | Tickets sehen/anlegen/bearbeiten/zuweisen/kommentieren, interne Notizen, schließen/wieder öffnen, zusammenführen, Arbeitszeiten, Berichte, Wissensdatenbank pflegen; plus alle Leserechte der Assetverwaltung |
| **helpdesk_lead** | zusätzlich eskalieren, SLA-Regeln, Export, Kategorien, Vorlagen |
| **helpdesk_admin** | zusätzlich `helpdesk.admin` (Typen, Status, Prioritäten, Gruppen, Regeln, Benachrichtigungsprotokoll) und `documents.manage` |
| **assetmanagement / lager / einkauf** | Portal (eigene Tickets anlegen und verfolgen) und Wissensartikel lesen, kein Agentenbereich |
| **readonly** | bewusst nur `*.view` – sieht das Portal und öffentliche Artikel, kann aber keine Tickets anlegen |
| **admin** | alles |

Rechte: `helpdesk.view|create|update|assign|comment|internal_note|close|reopen|merge|worklog|delete|escalate|sla|export|categories|templates|reports|admin`, `portal.view|create`, `knowledgebase.view|manage`. Jede Route in `routes/modules/helpdesk.php` trägt genau eine davon; `RouteInventoryTest` prüft das.

## Konfiguration (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `HELPDESK_ENABLED` | `true` | Modul in Navigation, Dashboard, Suche und Scheduler aktiv |
| `HELPDESK_TICKET_PREFIX` | `HD` | Präfix der Ticketnummer |
| `HELPDESK_SLA_WARNING_PERCENT` | `75` | Warnschwelle in % der SLA-Zeit (Fallback je SLA-Regel) |
| `HELPDESK_ESCALATION_PERCENT` | `90` | Eskalationsschwelle in % |
| `HELPDESK_ESCALATION_ENABLED` | `true` | Scheduler-Job aktiv |
| `HELPDESK_ESCALATION_INTERVAL_MINUTES` | `5` | Intervall des Container-Schedulers (0 = aus) |
| `HELPDESK_AUTO_CLOSE_DAYS` | `7` | Gelöste Tickets automatisch schließen (0 = aus) |
| `HELPDESK_REOPEN_DAYS` | `14` | Frist für Wiedereröffnung durch Melder (0 = nie) |
| `HELPDESK_NOTIFICATION_ENABLED` | `true` | E-Mail-Versand |
| `HELPDESK_NOTIFICATION_INBOX` | leer | zusätzliches Sammelpostfach für neue Tickets/Eskalationen |
| `HELPDESK_MAIL_DOMAIN` | leer | Domain für `Message-ID`/Thread-Wurzel ausgehender Mails (leer = aus `MAIL_FROM_ADDRESS`/`APP_URL`) |
| `HELPDESK_MAIL_ENABLED` | `false` | E-Mail-Eingang aktiv |
| `HELPDESK_MAIL_DRIVER` | `imap` | `imap` oder `file` (Entwicklung) |
| `HELPDESK_MAIL_FILE_PATH` | `storage/mail-inbox` | Ordner mit `.eml`-Dateien für den `file`-Treiber |
| `HELPDESK_IMAP_HOST` / `HELPDESK_IMAP_PORT` | leer / `993` | IMAP-Server |
| `HELPDESK_IMAP_ENCRYPTION` | `ssl` | `ssl` (993), `starttls` (143) oder `none` |
| `HELPDESK_IMAP_USERNAME` / `HELPDESK_IMAP_PASSWORD` | leer | Zugangsdaten |
| `HELPDESK_IMAP_MAILBOX` | `INBOX` | abzuholender Ordner |
| `HELPDESK_IMAP_PROCESSED_MAILBOX` | leer | Zielordner für verarbeitete Mails (leer = nur als gelesen markieren) |
| `HELPDESK_IMAP_TIMEOUT` | `15` | Socket-Timeout in Sekunden |
| `HELPDESK_IMAP_VERIFY_PEER` | `true` | TLS-Zertifikat prüfen |
| `HELPDESK_MAIL_BATCH_SIZE` | `50` | Nachrichten je Lauf |
| `HELPDESK_MAIL_INTERVAL_MINUTES` | `2` | Intervall des Container-Schedulers (0 = aus) |
| `HELPDESK_MAIL_SYSTEM_USER` | `admin` | Konto, unter dem der Eingang Tickets anlegt (braucht `helpdesk.create`) |
| `HELPDESK_MAIL_ALLOW_UNKNOWN_SENDERS` | `true` | Unbekannte Absender dürfen Tickets eröffnen |
| `HELPDESK_MAIL_DEFAULT_TYPE` | `incident` | Tickettyp (Code) für Tickets aus E-Mails |

## Integration in die Assetverwaltung

- Asset-Detail zeigt die letzten Tickets des Geräts und einen Link „Ticket erstellen“ (`/helpdesk/tickets/new?asset_id=`).
- Mitarbeiterdaten (Abteilung, Standort, Kostenstelle, Telefon) werden beim Melder eingeblendet; die dem Melder zugeordneten Assets werden als Verknüpfung vorgeschlagen (`/api/helpdesk/employees/{id}/assets`).
- Globale Suche liefert Tickets (Agenten: alle, sonst nur eigene); Dashboard zeigt „Meine offenen Tickets“ bzw. „Meine Anfragen“ mit Badge in der Navigation.
- Anhänge nutzen die bestehende `documents`-Ablage (`entity_type = ticket`), Audit-Einträge das bestehende `audit_logs`.

## Technik

- **Migration** `database/migrations/008_helpdesk.sql` und `009_helpdesk_mail.sql` (Threading-Spalten, `ticket_inbound_mails`), **Seeder** `database/seeders/003_helpdesk_defaults.sql` (Typen, Status, Prioritäten, SLA-Standardregeln, Beispielkategorien, Vorlagen).
- **Code**: `app/Repositories/Ticket*Repository.php`, `KnowledgeBaseRepository.php`; `app/Services/Helpdesk/` (`TicketService`, `TicketWorkflowService`, `TicketSlaService`, `TicketPriorityMatrix`, `TicketNumberService`, `TicketMergeService`, `TicketNotificationService`, `TicketRuleService`/`TicketRuleEvaluator`, `TicketReportService`, `TicketMailIngestionService`, `KnowledgeBaseService`, `HelpdeskAdminService`, `HelpdeskSchedulerService`); `app/Services/Helpdesk/Mail/` (`MimeMessageParser`, `InboundMail`, `MailboxClientInterface`, `ImapMailboxClient`, `FileMailboxClient`); `app/Controllers/Helpdesk/`; Provider `app/Core/Providers/helpdesk.php`; Routen `routes/modules/helpdesk.php`; Views `resources/views/helpdesk/`; `public/js/helpdesk.js`, `public/css/pages/helpdesk.css`.
- **Tests**: `tests/Unit/HelpdeskLogicTest.php` (Prioritätsmatrix, SLA-Berechnung mit Servicezeiten, Workflow-Übergänge, Regel-Auswertung, Rechte), `tests/Unit/HelpdeskMailParserTest.php` (MIME-Parser, RFC 2047/2231, Zitat-Erkennung, Auto-Reply-Erkennung, Ticketnummer/Message-ID), `tests/Integration/TicketServiceIntegrationTest.php` (Anlage, Nummernvergabe, Status, Zuweisung, Kommentare, Sichtbarkeit im Portal, Merge, Konflikte), `tests/Integration/HelpdeskAutomationIntegrationTest.php` (Regeln, Scheduler: Warnung/Verletzung/Eskalation/Auto-Close, Benachrichtigungen, Wissensdatenbank), `tests/Integration/HelpdeskMailIngestionIntegrationTest.php` (Ticket aus E-Mail, Threading über Betreff/`In-Reply-To`/`References`, Statuslogik, Merge-Weiterleitung, Duplikate, Auto-Replies, Anhänge). Ausführen: `php tests/run.php --filter=Ticket` bzw. `--filter=Helpdesk`.

## Bekannte Einschränkungen

- Der IMAP-Client unterstützt `LOGIN` (Benutzername/Passwort), kein OAuth2/XOAUTH2; für Microsoft 365 wird ein App-Passwort bzw. ein Postfach mit Basic-Auth benötigt.
- Assets werden beim Anlegen mehrfach ausgewählt; nachträglich werden sie im Ticketdetail einzeln verknüpft/gelöst.
- `readonly` kann im Portal keine Tickets anlegen (Rolle bleibt bewusst reine Leserolle); Mitarbeitende brauchen eine Fachrolle oder eine Help-Desk-Rolle.
- Berichte basieren auf Live-Abfragen ohne Materialisierung; bei sehr großen Beständen empfiehlt sich ein Zeitraumfilter.

## Sinnvolle Erweiterungen

IMAP-Anmeldung per OAuth2 (Microsoft 365/Google), Kundenzufriedenheitsabfrage nach Schließung, Kanban-Ansicht je Gruppe, wiederkehrende Tickets/Wartungspläne, Push-Benachrichtigungen (PWA), SLA-Kalender mit Feiertagen, Zeitbuchung auf Kostenstellen, Chat-/Teams-Webhook-Benachrichtigungen.
