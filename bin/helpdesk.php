#!/usr/bin/env php
<?php
/**
 * Help-Desk-Wartungsjob per Kommandozeile (manuell oder per Cron/Scheduler).
 *
 * Aufruf: php bin/helpdesk.php process [--quiet]
 *   process  SLA-Zustände neu bewerten (Warnung/Verletzung), Eskalationen ausführen,
 *            gelöste Tickets nach HELPDESK_AUTO_CLOSE_DAYS automatisch schließen.
 *
 * Exit-Code 0 bei Erfolg, 1 bei Fehler, 2 wenn das Modul deaktiviert ist (HELPDESK_ENABLED=false).
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\ApplicationFactory;
use App\Core\Config;
use App\Services\Helpdesk\HelpdeskSchedulerService;

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$command = 'process';
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        $command = $arg;
        break;
    }
}

if ($command !== 'process') {
    fwrite(STDERR, "Unbekannter Befehl „{$command}“. Verfügbar: process [--quiet]\n");
    exit(1);
}

$container = ApplicationFactory::container(dirname(__DIR__));
if (!(bool) $container->get(Config::class)->get('helpdesk.enabled', true)) {
    if (!$quiet) {
        fwrite(STDERR, "Help Desk ist deaktiviert (HELPDESK_ENABLED=false).\n");
    }
    exit(2);
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
