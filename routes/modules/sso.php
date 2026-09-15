<?php

declare(strict_types=1);

use App\Controllers\SsoController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Windows-SSO (Kerberos). /sso/pruefung ist im Webserver als einzige URL Kerberos-geschützt
 * (siehe docker/php/entrypoint.sh → bin/kerberos-setup.php); /sso/abbruch dient als
 * ErrorDocument, wenn der Browser kein Ticket liefert. Beide Routen sind bewusst ohne
 * Anmeldung erreichbar und ändern keine Daten.
 */
return static function (Router $router, Container $c): void {
    $sso = static fn (): SsoController => $c->get(SsoController::class);

    $router->get('/sso/pruefung', static fn (Request $r): Response => $sso()->probe($r), null, true);
    $router->get('/sso/abbruch', static fn (Request $r): Response => $sso()->cancelled($r), null, true);
};
