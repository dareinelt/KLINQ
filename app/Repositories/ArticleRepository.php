<?php

declare(strict_types=1);

namespace App\Repositories;

final class ArticleRepository extends BaseRepository
{
    private const SELECT = 'SELECT a.*, m.name AS manufacturer_name, t.name AS asset_type_name, t.code AS asset_type_code,
                                   c.name AS category_name,
                                   (SELECT COUNT(*) FROM assets s WHERE s.article_id = a.id) AS asset_count
                            FROM articles a
                            JOIN manufacturers m ON m.id = a.manufacturer_id
                            JOIN asset_types t ON t.id = a.asset_type_id
                            LEFT JOIN asset_categories c ON c.id = a.asset_category_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $filters q, manufacturer_id, asset_type_id, active
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY m.name, a.name LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(?int $assetTypeId = null): array
    {
        $sql = 'SELECT a.id, a.name, a.article_number, a.asset_type_id, a.asset_category_id, a.manufacturer_id, m.name AS manufacturer_name
                FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id WHERE a.is_active = 1';
        $params = [];
        if ($assetTypeId !== null) {
            $sql .= ' AND a.asset_type_id = :type';
            $params['type'] = $assetTypeId;
        }

        return $this->fetchAll($sql . ' ORDER BY m.name, a.name', $params);
    }

    /** @return array<string,mixed>|null */
    public function findDuplicate(int $manufacturerId, string $name, ?int $excludeId = null): ?array
    {
        $params = ['m' => $manufacturerId, 'n' => $name];
        $sql = 'SELECT id, name FROM articles WHERE manufacturer_id = :m AND LOWER(name) = LOWER(:n)';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('articles', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('articles', $id, $data);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(a.name LIKE :q OR a.article_number LIKE :q OR m.name LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (!empty($filters['manufacturer_id'])) {
            $clauses[] = 'a.manufacturer_id = :manufacturer_id';
            $params['manufacturer_id'] = (int) $filters['manufacturer_id'];
        }
        if (!empty($filters['asset_type_id'])) {
            $clauses[] = 'a.asset_type_id = :asset_type_id';
            $params['asset_type_id'] = (int) $filters['asset_type_id'];
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 'a.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
