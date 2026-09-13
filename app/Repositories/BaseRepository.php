<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

abstract class BaseRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<int|string,mixed> $params */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $data */
    protected function insertRow(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->execute($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    protected function updateRow(string $table, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));

        return $this->execute("UPDATE {$table} SET {$set} WHERE id = ?", [...array_values($data), $id]);
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }
}
