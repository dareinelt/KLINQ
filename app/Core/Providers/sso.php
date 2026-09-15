<?php

declare(strict_types=1);

use App\Controllers\SsoController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Core\View;
use App\Security\CurrentUser;
use App\Security\WindowsIdentity;
use App\Services\Ldap\LdapClientInterface;
use App\Services\Sso\KerberosSetupService;

return static function (Container $c): void {
    $c->singleton(WindowsIdentity::class, static fn (Container $c): WindowsIdentity => new WindowsIdentity($c->get(Config::class)));
    $c->singleton(KerberosSetupService::class, static fn (Container $c): KerberosSetupService => new KerberosSetupService(
        $c->get(Config::class),
        $c->get(LdapClientInterface::class),
        $c->get(Logger::class)
    ));
    $c->singleton(SsoController::class, static fn (Container $c): SsoController => new SsoController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(WindowsIdentity::class)
    ));
};
