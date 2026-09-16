<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\HelpdeskMailboxController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $router->get('/admin', static fn (Request $r): Response => $c->get(AdminController::class)->index($r), 'settings.manage');

    // Help-Desk-Postfach: vollständige Konfiguration des E-Mail-Eingangs in der Anwendung
    $mailbox = static fn (): HelpdeskMailboxController => $c->get(HelpdeskMailboxController::class);
    $router->get('/admin/helpdesk-mail', static fn (Request $r): Response => $mailbox()->index($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail', static fn (Request $r): Response => $mailbox()->save($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail/test', static fn (Request $r): Response => $mailbox()->test($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail/run', static fn (Request $r): Response => $mailbox()->run($r), 'settings.manage');
};
