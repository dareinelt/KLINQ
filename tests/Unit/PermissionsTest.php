<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Env;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Exceptions\ForbiddenException;
use Tests\Support\TestCase;

final class PermissionsTest extends TestCase
{
    private Permissions $permissions;

    protected function setUp(): void
    {
        $this->permissions = new Permissions(new Config(dirname(__DIR__, 2) . '/config'));
    }

    public function testAdminHasEverything(): void
    {
        $this->assertTrue($this->permissions->roleHas('admin', 'settings.manage'));
        $this->assertTrue($this->permissions->roleHas('admin', 'assets.manage'));
    }

    public function testReadonlyHasOnlyViewPermissions(): void
    {
        foreach ($this->permissions->forRole('readonly') as $permission) {
            $this->assertTrue(str_ends_with($permission, '.view'), "readonly darf {$permission} nicht haben");
        }
        $this->assertFalse($this->permissions->roleHas('readonly', 'assets.manage'));
        $this->assertFalse($this->permissions->roleHas('readonly', 'audit.view'), 'Audit-Log enthält Benutzer-/IP-Daten');
        $this->assertFalse($this->permissions->roleHas('lager', 'audit.view'));
        $this->assertTrue($this->permissions->roleHas('assetmanagement', 'audit.view'));
    }

    public function testLagerCanCheckoutButNotManageSettings(): void
    {
        $this->assertTrue($this->permissions->roleHas('lager', 'movements.checkout'));
        $this->assertFalse($this->permissions->roleHas('lager', 'settings.manage'));
    }

    public function testEinkaufManagesOrdersOnly(): void
    {
        $this->assertTrue($this->permissions->roleHas('einkauf', 'orders.manage'));
        $this->assertFalse($this->permissions->roleHas('einkauf', 'movements.checkout'));
    }

    public function testUnknownRoleHasNoPermissions(): void
    {
        $this->assertSame([], $this->permissions->forRole('does-not-exist'));
        $this->assertFalse($this->permissions->roleExists('does-not-exist'));
    }

    public function testCurrentUserRequireThrowsForbidden(): void
    {
        $user = new CurrentUser($this->permissions);
        $this->assertFalse($user->isAuthenticated());
        $user->login(['id' => 1, 'username' => 'ro', 'display_name' => 'RO', 'role' => 'readonly']);
        $this->assertTrue($user->can('assets.view'));
        $this->assertThrows(ForbiddenException::class, static fn () => $user->require('assets.manage'));
        $user->logout();
        $this->assertFalse($user->isAuthenticated());
    }
}
