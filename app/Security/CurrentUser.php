<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\ForbiddenException;

/**
 * Angemeldeter Benutzer aus der Session inkl. Rechteprüfung.
 */
final class CurrentUser
{
    private const SESSION_KEY = 'auth_user';

    public function __construct(private readonly Permissions $permissions) {}

    /** @param array<string,mixed> $user */
    public function login(array $user): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
            'role' => (string) $user['role'],
            'logged_in_at' => time(),
        ];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function isAuthenticated(): bool
    {
        return is_array($_SESSION[self::SESSION_KEY] ?? null);
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $user = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($user) ? $user : null;
    }

    public function id(): ?int
    {
        return $this->user()['id'] ?? null;
    }

    public function username(): string
    {
        return (string) ($this->user()['username'] ?? 'system');
    }

    public function displayName(): string
    {
        return (string) ($this->user()['display_name'] ?? $this->username());
    }

    public function role(): string
    {
        return (string) ($this->user()['role'] ?? '');
    }

    public function can(string $permission): bool
    {
        return $this->isAuthenticated() && $this->permissions->roleHas($this->role(), $permission);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new ForbiddenException('Keine Berechtigung: ' . $permission);
        }
    }

    /** @return array<int,string> */
    public function permissions(): array
    {
        return $this->isAuthenticated() ? $this->permissions->forRole($this->role()) : [];
    }
}
