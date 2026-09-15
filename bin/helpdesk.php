#!/usr/bin/env php
<?php
/**
 * Help-Desk-Wartungsjobs per Kommandozeile (manuell oder per Cron/Scheduler).
 *
 * Aufruf: php bin/helpdesk.php <befehl> [--quiet] [--limit=N]
 *   process  SLA-Zustände neu bewerten (Warnung/Verletzung), Eskalationen ausführen,
 *            gelöste Tickets nach HELPDESK_AUTO_CLOSE_DAYS automatisch schließen.
 *   mail     E-Mail-Eingang abholen (IMAP oder .eml-Verzeichnis) und Nachrichten als neues Ticket
 *            bzw. als Kommentar (Antwort per Ticketnummer/In-Reply-To) verarbeiten.
 *
 * Exit-Code 0 bei Erfolg, 1 bei Fehler, 2 wenn das Modul bzw. der E-Mail-Eingang deaktiviert ist.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\ApplicationFactory;
use App\Core\Config;
use App\Services\Helpdesk\HelpdeskSchedulerService;
use App\Services\Helpdesk\TicketMailIngestionService;

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$limit = null;
$command = 'process';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif (!str_starts_with($arg, '--')) {
        $command = $arg;
    }
}

if (!in_array($command, ['process', 'mail'], true)) {
    fwrite(STDERR, "Unbekannter Befehl „{$command}“. Verfügbar: process [--quiet] | mail [--quiet] [--limit=N]\n");
    exit(1);
}

$container = ApplicationFactory::container(dirname(__DIR__));
$config = $container->get(Config::class);
if (!(bool) $config->get('helpdesk.enabled', true)) {
    if (!$quiet) {
        fwrite(STDERR, "Help Desk ist deaktiviert (HELPDESK_ENABLED=false).\n");
    }
    exit(2);
}

if ($command === 'mail') {
    $ingestion = $container->get(TicketMailIngestionService::class);
    if (!$ingestion->enabled()) {
        if (!$quiet) {
            fwrite(STDERR, "E-Mail-Eingang ist deaktiviert (HELPDESK_MAIL_ENABLED=false).\n");
        }
        exit(2);
    }
    try {
        $result = $ingestion->pull($limit);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fehler beim E-Mail-Eingang: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!$quiet) {
        printf(
            "OK (%s): %d Nachrichten, %d neue Tickets, %d Kommentare, %d ignoriert, %d fehlgeschlagen.\n",
            $result['source'],
            $result['fetched'],
            $result['created'],
            $result['comments'],
            $result['ignored'],
            $result['failed']
        );
        foreach ($result['items'] as $item) {
            printf("  [%s] %s%s%s\n", $item['action'], $item['uid'], $item['ticket'] !== null ? ' → ' . $item['ticket'] : '', $item['detail'] !== null ? ' – ' . $item['detail'] : '');
        }
    }
    exit($result['failed'] > 0 && $result['created'] + $result['comments'] + $result['ignored'] === 0 && $result['fetched'] > 0 ? 1 : 0);
}

try {
    $summary = $container->get(HelpdeskSchedulerService::class)->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    printf(
        "OK: %d Tickets geprüft, %d Warnungen, %d SLA-Verletzungen, %d Eskalationen, %d automatisch geschlossen.\n",
        $summary['checked'] ?? 0,
        $summary['warnings'] ?? 0,
        $summary['breaches'] ?? 0,
        $summary['escalated'] ?? 0,
        $summary['auto_closed'] ?? 0
    );
}
exit(0);
