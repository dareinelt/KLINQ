<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

final class Permissions
{
    public function __construct(private readonly Config $config) {}

    /** @return array<int,string> */
    public function forRole(string $role): array
    {
        $roles = $this->config->get('permissions.roles', []);

        return $roles[$role] ?? [];
    }

    public function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, $this->forRole($role), true);
    }

    /** @return array<string,string> */
    public function roleLabels(): array
    {
        return $this->config->get('permissions.role_labels', []);
    }

    /** @return array<int,string> */
    public function roleNames(): array
    {
        return array_keys($this->config->get('permissions.roles', []));
    }

    public function roleExists(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }
}
