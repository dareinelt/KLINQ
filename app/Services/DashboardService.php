<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Kennzahlen und Aktivitäten für das Dashboard (reine Lesezugriffe). */
final class DashboardService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,int> */
    public function stats(): array
    {
        return [
            'assets_total' => $this->count('SELECT COUNT(*) FROM assets'),
            'assets_in_stock' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'in_stock'"),
            'assets_issued' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'issued'"),
            'assets_defective' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code IN ('defective','repair')"),
            'open_checkouts' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'checkout' AND status = 'open'"),
            'open_returns' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'return' AND status = 'open'"),
            'returns_overdue' => $this->count("SELECT COUNT(*) FROM assets WHERE expected_return_at IS NOT NULL AND expected_return_at < CURDATE() AND status_id IN (SELECT id FROM asset_statuses WHERE code IN ('issued','return_expected'))"),
            'movements_today' => $this->count("SELECT COUNT(*) FROM movements WHERE movement_date = '" . date('Y-m-d') . "' AND status <> 'cancelled'"),
            'employees_active' => $this->count('SELECT COUNT(*) FROM employees WHERE is_active = 1'),
            'orders_open' => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_delivered')"),
            'licenses_expiring' => $this->count('SELECT COUNT(*) FROM licenses WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)'),
        ];
    }

    /** @return array<string,int> */
    public function openCounts(): array
    {
        return [
            'checkouts' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'checkout' AND status = 'open'"),
            'returns' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'return' AND status = 'open'"),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentMovements(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.type, m.status, m.movement_at, m.created_by_name AS user_name, a.inventory_number, a.name AS asset_name,
                    e.display_name AS employee_name
             FROM movements m
             JOIN assets a ON a.id = m.asset_id
             LEFT JOIN employees e ON e.id = m.employee_id
             ORDER BY m.movement_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentAssetHistory(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.id, h.event_type, h.field, h.old_value, h.new_value, h.note, h.actor_name AS user_name, h.created_at, a.inventory_number, a.name AS asset_name
             FROM asset_history h
             JOIN assets a ON a.id = h.asset_id
             ORDER BY h.created_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function returnsDueSoon(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.expected_return_at, e.display_name AS employee_name
             FROM assets a
             LEFT JOIN employees e ON e.id = a.employee_id
             WHERE a.expected_return_at IS NOT NULL
               AND a.status_id IN (SELECT id FROM asset_statuses WHERE code IN (\'issued\',\'return_expected\'))
             ORDER BY a.expected_return_at ASC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
