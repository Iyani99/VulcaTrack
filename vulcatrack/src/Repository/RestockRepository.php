<?php

namespace VulcaTrack\Repository;

use PDO;

/** Receipt records for future Product restocks; no historical backfill. */
class RestockRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $itemId, int $quantity, int $stockBefore, int $stockAfter, ?string $supplier, ?string $note, int $adminId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_restocks (item_id, quantity, stock_before, stock_after, supplier_name, note, admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$itemId, $quantity, $stockBefore, $stockAfter, $supplier, $note, $adminId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function forItem(int $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.restock_id, r.quantity, r.stock_before, r.stock_after,
                    r.supplier_name, r.note, r.admin_id, r.restocked_at,
                    a.full_name AS admin_name
               FROM inventory_restocks r JOIN admins a ON a.admin_id = r.admin_id
              WHERE r.item_id = ? ORDER BY r.restocked_at DESC, r.restock_id DESC'
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }
}
