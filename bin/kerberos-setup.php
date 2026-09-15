#!/usr/bin/env php
<?php
/**
 * Kerberos-Einrichtung des Webservers (Keytab, krb5.conf, Apache-Konfiguration).
 *
 * Wird beim Containerstart aus dem Entrypoint aufgerufen, kann aber auch manuell laufen:
 *   php bin/kerberos-setup.php [--quiet]
 * Exit-Code 0 bei Erfolg, 1 bei Fehler, 2 wenn Windows-SSO deaktiviert ist.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\ApplicationFactory;
use App\Services\Sso\KerberosSetupService;

$options = getopt('', ['quiet']);
$quiet = isset($options['quiet']);
$container = ApplicationFactory::container(dirname(__DIR__));
$setup = $container->get(KerberosSetupService::class);

try {
    $result = $setup->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result['status'] === 'disabled') {
    if (!$quiet) {
        fwrite(STDERR, $result['message'] . "\n");
    }
    exit(2);
}
if ($result['status'] !== 'success') {
    fwrite(STDERR, 'Kerberos-Einrichtung fehlgeschlagen: ' . $result['message'] . "\n");
    exit(1);
}
if (!$quiet) {
    echo 'OK: ' . $result['message'] . "\n";
}
exit(0);
