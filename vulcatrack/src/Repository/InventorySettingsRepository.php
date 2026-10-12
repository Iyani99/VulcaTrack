<?php

namespace VulcaTrack\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** The single persisted management setting used by Inventory Intelligence. */
final class InventorySettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{slow_moving_days:int,updated_at:string,updated_by_admin_id:?int,updated_by_admin_name:?string} */
    public function current(): array
    {
        $row = $this->pdo->query(
            'SELECT s.slow_moving_days, s.updated_at, s.updated_by_admin_id,
                    a.full_name AS updated_by_admin_name
               FROM inventory_settings s
               LEFT JOIN admins a ON a.admin_id = s.updated_by_admin_id
              WHERE s.setting_id = 1'
        )->fetch();
        if ($row === false) {
            throw new RuntimeException('Inventory settings row is missing.');
        }
        $row['slow_moving_days'] = (int) $row['slow_moving_days'];
        if ($row['slow_moving_days'] < 1 || $row['slow_moving_days'] > 365) {
            throw new RuntimeException('Inventory settings threshold is invalid.');
        }
        $row['updated_by_admin_id'] = $row['updated_by_admin_id'] === null
            ? null : (int) $row['updated_by_admin_id'];
        return $row;
    }

    /** True if a meaningful change was written; false for an unchanged value. */
    public function updateSlowMovingDays(int $days, int $adminId): bool
    {
        if ($days < 1 || $days > 365 || $adminId < 1) {
            throw new InvalidArgumentException('Slow-moving threshold must be 1–365 days and have a recording Admin.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE inventory_settings
                SET slow_moving_days = :days, updated_by_admin_id = :admin_id,
                    updated_at = CURRENT_TIMESTAMP
              WHERE setting_id = 1 AND slow_moving_days <> :current_days'
        );
        $stmt->execute([':days' => $days, ':admin_id' => $adminId, ':current_days' => $days]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $this->current(); // fail explicitly if the required row disappeared
        return false;
    }
}
