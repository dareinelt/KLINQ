<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        return $this->insertRow('audit_logs', $data);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(
            "SELECT * FROM audit_logs {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function forObject(string $type, int $id, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT * FROM audit_logs WHERE object_type = ? AND object_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$type, $id]
        );
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(username LIKE ? OR action LIKE ? OR object_type LIKE ? OR object_label LIKE ?)';
            $like = $this->like((string) $filters['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['object_type'])) {
            $clauses[] = 'object_type = ?';
            $params[] = $filters['object_type'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
