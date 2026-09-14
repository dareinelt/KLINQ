<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $router->get('/admin', static fn (Request $r): Response => $c->get(AdminController::class)->index($r), 'settings.manage');
};
