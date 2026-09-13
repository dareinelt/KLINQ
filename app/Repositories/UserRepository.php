<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    private const SELECT = 'SELECT u.*, r.name AS role, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id';

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE u.username = ? LIMIT 1', [$username]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE u.id = ? LIMIT 1', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY u.username');
    }

    public function count(): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM users');
    }

    public function roleId(string $role): ?int
    {
        $id = $this->fetchValue('SELECT id FROM roles WHERE name = ?', [$role]);

        return $id === null ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('users', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('users', $id, $data);
    }

    public function recordLoginSuccess(int $id): void
    {
        $this->execute('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function recordLoginFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->execute(
            'UPDATE users SET failed_logins = failed_logins + 1,
                locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
             WHERE id = ?',
            [$maxAttempts, $lockMinutes, $id]
        );
    }
}
