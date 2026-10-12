<?php

namespace VulcaTrack\Repository;

use PDO;

/** Append-only records of Admin manual Product stock corrections. */
class AdjustmentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $itemId, string $direction, int $quantity, string $reason, ?string $note,
        int $before, int $after, int $adminId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_adjustments
             (item_id, direction, quantity, reason, note, stock_before, stock_after, admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$itemId, $direction, $quantity, $reason, $note, $before, $after, $adminId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function forItem(int $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.adjustment_id, h.direction, h.quantity, h.reason, h.note,
                    h.stock_before, h.stock_after, h.admin_id, h.adjusted_at,
                    a.full_name AS admin_name
               FROM inventory_adjustments h JOIN admins a ON a.admin_id = h.admin_id
              WHERE h.item_id = ? ORDER BY h.adjusted_at DESC, h.adjustment_id DESC'
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }
}
