<?php

namespace VulcaTrack\Repository;

use PDO;
use VulcaTrack\Support\Money;

/** Immutable promotion terms, attributed cancellation, and locked consumption. */
final class ProductPromotionRepository
{
    private const COLUMNS = 'promotion_id, item_id, sale_price, regular_price_snapshot, starts_on, ends_on,
        unit_limit, units_consumed, note, created_by_admin_id, created_at, cancelled_at, cancelled_by_admin_id';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $promotionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_promotions WHERE promotion_id = ?');
        $stmt->execute([$promotionId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** Caller locks the Product first. Current locking read avoids stale snapshots. */
    public function lockById(int $promotionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_promotions WHERE promotion_id = ? FOR UPDATE');
        $stmt->execute([$promotionId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** Scheduled or Active; caller may already hold the Product lock. */
    public function findOpenForItem(int $itemId, string $today, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_promotions
            WHERE item_id = ? AND cancelled_at IS NULL AND (ends_on IS NULL OR ends_on >= ?)
              AND (unit_limit IS NULL OR units_consumed < unit_limit)
            ORDER BY promotion_id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$itemId, $today]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        $rows = $this->pdo->query('SELECT p.*, i.item_name, i.price AS current_regular_price,
                   i.stock_quantity, i.is_active, a.full_name AS created_by_name,
                   ca.full_name AS cancelled_by_name
            FROM product_promotions p JOIN items i ON i.item_id = p.item_id
            JOIN admins a ON a.admin_id = p.created_by_admin_id
            LEFT JOIN admins ca ON ca.admin_id = p.cancelled_by_admin_id
            ORDER BY p.created_at DESC, p.promotion_id DESC')->fetchAll();
        return array_map([$this, 'hydrate'], $rows);
    }

    /** Active rows indexed by Product id for the in-shop POS render. */
    public function activeByItem(string $today): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM product_promotions
            WHERE cancelled_at IS NULL AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)
              AND (unit_limit IS NULL OR units_consumed < unit_limit)
            ORDER BY promotion_id DESC');
        $stmt->execute([$today, $today]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $promotion = $this->hydrate($row);
            $result[$promotion['item_id']] = $result[$promotion['item_id']] ?? $promotion;
        }
        return $result;
    }

    public function create(int $itemId, int $salePrice, int $regularPrice, string $startsOn,
        ?string $endsOn, ?int $unitLimit, ?string $note, int $adminId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_promotions
            (item_id, sale_price, regular_price_snapshot, starts_on, ends_on, unit_limit, note, created_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$itemId, Money::format($salePrice), Money::format($regularPrice),
            $startsOn, $endsOn, $unitLimit, $note, $adminId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function cancel(int $promotionId, int $adminId, string $at): bool
    {
        $stmt = $this->pdo->prepare('UPDATE product_promotions
            SET cancelled_at = ?, cancelled_by_admin_id = ?
            WHERE promotion_id = ? AND cancelled_at IS NULL');
        $stmt->execute([$at, $adminId, $promotionId]);
        return $stmt->rowCount() === 1;
    }

    /** Guarded, atomic counter increment inside SaleService's transaction. */
    public function consume(int $promotionId, int $quantity, string $today): bool
    {
        if ($quantity < 1) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE product_promotions
            SET units_consumed = units_consumed + :quantity
            WHERE promotion_id = :id AND cancelled_at IS NULL
              AND starts_on <= :today_start AND (ends_on IS NULL OR ends_on >= :today_end)
              AND units_consumed <= :maximum
              AND (unit_limit IS NULL OR units_consumed <= unit_limit - :limit_quantity)');
        $stmt->execute([
            ':quantity' => $quantity, ':id' => $promotionId,
            ':today_start' => $today, ':today_end' => $today,
            ':maximum' => 2147483647 - $quantity, ':limit_quantity' => $quantity,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Durable audit quantity; compare with units_consumed when inspecting a promotion. */
    public function recordedUnits(int $promotionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE promotion_id = ?');
        $stmt->execute([$promotionId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        foreach (['promotion_id', 'item_id', 'units_consumed', 'created_by_admin_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['unit_limit'] = $row['unit_limit'] === null ? null : (int) $row['unit_limit'];
        $row['cancelled_by_admin_id'] = $row['cancelled_by_admin_id'] === null
            ? null : (int) $row['cancelled_by_admin_id'];
        $row['sale_price_centavos'] = Money::toCentavos((string) $row['sale_price']);
        $row['regular_price_snapshot_centavos'] = Money::toCentavos((string) $row['regular_price_snapshot']);
        if (isset($row['current_regular_price'])) {
            $row['current_regular_price_centavos'] = Money::toCentavos((string) $row['current_regular_price']);
        }
        return $row;
    }
}
