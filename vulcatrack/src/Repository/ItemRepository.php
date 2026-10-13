<?php

namespace VulcaTrack\Repository;

use InvalidArgumentException;
use PDO;
use Throwable;
use VulcaTrack\Support\Money;

/**
 * Data access for the unified `items` table — products AND services in one
 * table, distinguished by `item_type` (Decision 15). Prepared statements only.
 *
 * Money: callers pass and receive prices as INTEGER CENTAVOS (Decision 55).
 * This class converts to/from the DECIMAL(10,2) column via Money. The raw
 * `price` field in a returned row stays the DB decimal string ("99.95");
 * `price_centavos` is added alongside it for arithmetic.
 *
 * Stock: products carry `stock_quantity` (>= 0) and an optional
 * `reorder_level`; services carry neither (both NULL) and never deduct stock
 * (Decision 16). create() accepts opening stock; later increases use restocks.
 *
 * Removal is soft: setActive($id, false). Rows are never hard-deleted — an
 * inactive item stays visible on historical `sale_items` (Decisions 27-29).
 *
 * `items.updated_at` has no ON UPDATE clause, so every UPDATE sets it here.
 *
 * Not `final`: the mock-library-free test harness subclasses this to simulate a
 * database failure during checkout (SaleServiceTest). Application code always
 * uses ItemRepository directly.
 */
class ItemRepository
{
    private const COLUMNS =
        'item_id, item_name, item_type, category, price, stock_quantity, reorder_level, slow_moving_days_override, is_active, created_at, updated_at';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM items WHERE item_id = ? LIMIT 1'
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * List items with optional filters. All filters are optional; an empty
     * filter set returns every item, active first then by name.
     *
     * @param array{
     *     search?: string,
     *     type?: string,          // 'product' | 'service'
     *     active?: bool,          // true = active only, false = inactive only
     *     low_stock_only?: bool   // active products at or below reorder_level
     * } $filters
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            // Native prepares (ATTR_EMULATE_PREPARES => false) need one placeholder per use.
            $like = '%' . $this->escapeLike(trim((string) $filters['search'])) . '%';
            $where[] = '(item_name LIKE :search_name OR category LIKE :search_cat)';
            $params[':search_name'] = $like;
            $params[':search_cat'] = $like;
        }

        if (isset($filters['type']) && in_array($filters['type'], ['product', 'service'], true)) {
            $where[] = 'item_type = :type';
            $params[':type'] = $filters['type'];
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $where[] = 'is_active = :active';
            $params[':active'] = $filters['active'] ? 1 : 0;
        }

        if (!empty($filters['low_stock_only'])) {
            $where[] = "item_type = 'product' AND is_active = 1
                        AND stock_quantity IS NOT NULL AND reorder_level IS NOT NULL
                        AND stock_quantity <= reorder_level";
        }

        $sql = 'SELECT ' . self::COLUMNS . ' FROM items';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY is_active DESC, item_name ASC, item_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Active products whose stock has reached the reorder level. Convenience
     * wrapper over list(['low_stock_only' => true]).
     *
     * @return array<int,array<string,mixed>>
     */
    public function lowStockProducts(): array
    {
        return $this->list(['low_stock_only' => true]);
    }

    /**
     * Current active, stocked Products without a Sale in their effective window.
     * Never-sold Products receive a record-creation observation grace.
     * Restocks are context only and never reset Sales inactivity.
     *
     * @return array<int,array{item_id:int,item_name:string,stock_quantity:int,created_at:string,last_sale_at:?string,last_restock_at:?string,effective_days:int,slow_moving_days_override:?int}>
     */
    public function slowMovingProducts(string $asOf): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.item_id, i.item_name, i.stock_quantity, i.created_at,
                    i.slow_moving_days_override,
                    COALESCE(i.slow_moving_days_override, cfg.slow_moving_days) AS effective_days,
                    sold.last_sale_at, received.last_restock_at
               FROM items i
               JOIN inventory_settings cfg ON cfg.setting_id = 1
               LEFT JOIN (
                 SELECT si.item_id, MAX(s.sale_date) AS last_sale_at
                   FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                  GROUP BY si.item_id
               ) sold ON sold.item_id = i.item_id
               LEFT JOIN (
                 SELECT item_id, MAX(restocked_at) AS last_restock_at
                   FROM inventory_restocks GROUP BY item_id
               ) received ON received.item_id = i.item_id
              WHERE i.item_type = 'product' AND i.is_active = 1
                AND i.stock_quantity > 0
                AND (sold.last_sale_at < DATE_SUB(CAST(:sold_as_of AS DATETIME),
                      INTERVAL COALESCE(i.slow_moving_days_override, cfg.slow_moving_days) DAY)
                     OR (sold.last_sale_at IS NULL AND i.created_at <= DATE_SUB(CAST(:created_as_of AS DATETIME),
                      INTERVAL COALESCE(i.slow_moving_days_override, cfg.slow_moving_days) DAY)))
              ORDER BY sold.last_sale_at IS NULL DESC,
                       COALESCE(sold.last_sale_at, i.created_at) ASC,
                       i.item_name ASC, i.item_id ASC"
        );
        $stmt->execute([':sold_as_of' => $asOf, ':created_as_of' => $asOf]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['item_id'] = (int) $row['item_id'];
            $row['stock_quantity'] = (int) $row['stock_quantity'];
            $row['effective_days'] = (int) $row['effective_days'];
            $row['slow_moving_days_override'] = $row['slow_moving_days_override'] === null
                ? null : (int) $row['slow_moving_days_override'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Distinct non-empty categories already in use — for a datalist / suggestion
     * list (Decision 52). No category table.
     *
     * @return array<int,string>
     */
    public function distinctCategories(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT category FROM items
             WHERE category IS NOT NULL AND category <> ''
             ORDER BY category ASC"
        );

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Insert an item. $priceCentavos is integer centavos; stock/reorder are
     * ignored for a service and required (stock) for a product.
     *
     * @throws InvalidArgumentException on a negative price or a product without stock
     */
    public function create(
        string $name,
        string $itemType,
        ?string $category,
        int $priceCentavos,
        ?int $stockQuantity,
        ?int $reorderLevel,
        ?int $slowMovingDaysOverride = null
    ): int {
        [$stock, $reorder] = $this->normaliseStockFields($itemType, $stockQuantity, $reorderLevel);
        $override = $this->normaliseSlowMovingOverride($itemType, $slowMovingDaysOverride);

        $stmt = $this->pdo->prepare(
            'INSERT INTO items (item_name, item_type, category, price, stock_quantity, reorder_level, slow_moving_days_override)
             VALUES (:name, :type, :category, :price, :stock, :reorder, :override_days)'
        );
        $stmt->execute([
            ':name'     => $name,
            ':type'     => $itemType,
            ':category' => $this->nullIfBlank($category),
            ':price'    => Money::format($this->assertNonNegativePrice($priceCentavos)),
            ':stock'    => $stock,
            ':reorder'  => $reorder,
            ':override_days' => $override,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an item's editable fields. Does not touch `is_active` — use
     * setActive() for that.
     *
     * Two integrity guards (Phase 7.1):
     *
     * - **Type lock.** Once an item appears on a recorded sale (`sale_items`)
     *   its item_type is fixed: a different $itemType is refused. Unsold items
     *   may still switch between product and service. The rule is checked up
     *   front AND repeated inside the UPDATE, so a sale recorded in between
     *   cannot slip a type change through.
     *
     * - **Stale edit guard.** $expected carries the item_type and
     *   stock_quantity the edit form was loaded with. The row is only written
     *   while it still has those values, so a stale form can never put back a
     *   stock level that a POS sale has since reduced. Pass null to skip this
     *   guard (internal callers that just read the current row).
     *
     * Returns false — writing nothing — when the item is unknown or no longer
     * matches $expected. MySQL counts only *changed* rows, so a save that
     * matched but changed nothing also reports 0; that case is told apart by
     * re-reading the row, and counts as success when the row already holds
     * exactly the submitted values.
     *
     * @param array{item_type: string, stock_quantity: ?int}|null $expected
     * @throws InvalidArgumentException on a negative price, a product without
     *         stock, or a type change on an item that has recorded sales
     */
    public function update(
        int $itemId,
        string $name,
        string $itemType,
        ?string $category,
        int $priceCentavos,
        ?int $stockQuantity,
        ?int $reorderLevel,
        ?array $expected = null,
        ?int $slowMovingDaysOverride = null
    ): bool {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            // Serialize price/type edits with promotion creation and checkout.
            $result = $this->updateLocked($itemId, $name, $itemType, $category,
                $priceCentavos, $stockQuantity, $reorderLevel, $expected, $slowMovingDaysOverride);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    private function updateLocked(int $itemId, string $name, string $itemType, ?string $category,
        int $priceCentavos, ?int $stockQuantity, ?int $reorderLevel, ?array $expected,
        ?int $slowMovingDaysOverride): bool
    {
        [$stock, $reorder] = $this->normaliseStockFields($itemType, $stockQuantity, $reorderLevel);
        $override = $this->normaliseSlowMovingOverride($itemType, $slowMovingDaysOverride);
        $category = $this->nullIfBlank($category);
        $price    = Money::format($this->assertNonNegativePrice($priceCentavos));

        $current = $this->lockForUpdate($itemId);
        if ($current === null) {
            return false;
        }
        if ($current['item_type'] === 'product') {
            $openPromotion = (new ProductPromotionRepository($this->pdo))
                ->findOpenForItem($itemId, date('Y-m-d'), true);
            if ($openPromotion !== null && ($itemType !== 'product'
                || $priceCentavos <= (int) $openPromotion['sale_price_centavos'])) {
                throw new InvalidArgumentException(
                    'Cancel the open promotion before changing Product type or setting regular price at or below its sale price.'
                );
            }
        }
        if ($itemType !== $current['item_type'] && $this->hasSales($itemId)) {
            throw new InvalidArgumentException('Item type cannot be changed after the item has recorded sales.');
        }
        if ($current['item_type'] === 'product' && $itemType === 'product'
            && $stock !== $current['stock_quantity']) {
            throw new InvalidArgumentException('Existing Product stock cannot be changed in Item Edit.');
        }
        if ($current['item_type'] === 'product' && $itemType === 'service'
            && $current['stock_quantity'] !== 0) {
            throw new InvalidArgumentException('A Product with stock cannot become a Service.');
        }
        if ($current['item_type'] === 'service' && $itemType === 'product' && $stock !== 0) {
            throw new InvalidArgumentException('A converted Product must start with zero stock.');
        }

        $sql = "UPDATE items
                   SET item_name = :name, item_type = :type, category = :category,
                       price = :price, stock_quantity = :stock, reorder_level = :reorder,
                       slow_moving_days_override = :override_days,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE item_id = :id
                   AND (item_type = :same_type
                        OR NOT EXISTS (SELECT 1 FROM sale_items si WHERE si.item_id = :sold_id))
                   AND (item_type <> 'product' OR :type_for_stock <> 'product' OR stock_quantity = :stock_guard)
                   AND (item_type <> 'product' OR :type_from_product <> 'service' OR stock_quantity = 0)
                   AND (item_type <> 'service' OR :type_from_service <> 'product' OR :new_stock_from_service = 0)";
        $params = [
            ':name'      => $name,
            ':type'      => $itemType,
            ':category'  => $category,
            ':price'     => $price,
            ':stock'     => $stock,
            ':reorder'   => $reorder,
            ':override_days' => $override,
            ':id'        => $itemId,
            ':same_type' => $itemType,
            ':sold_id'   => $itemId,
            ':stock_guard' => $stock,
            ':type_for_stock' => $itemType,
            ':type_from_product' => $itemType,
            ':type_from_service' => $itemType,
            ':new_stock_from_service' => $stock,
        ];
        if ($expected !== null) {
            $sql .= ' AND item_type = :expected_type AND stock_quantity <=> :expected_stock';
            $params[':expected_type']  = $expected['item_type'];
            $params[':expected_stock'] = $expected['stock_quantity'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        // 0 rows: stale / locked (nothing written), or matched with nothing to change.
        $now = $this->lockForUpdate($itemId);

        return $now !== null
            && ($expected === null || ($now['item_type'] === $expected['item_type']
                && $now['stock_quantity'] === $expected['stock_quantity']))
            && $now['item_name'] === $name
            && $now['item_type'] === $itemType
            && $now['category'] === $category
            && $now['price_centavos'] === $priceCentavos
            && $now['stock_quantity'] === $stock
            && $now['reorder_level'] === $reorder
            && $now['slow_moving_days_override'] === $override;
    }

    /** True once the item appears on at least one recorded sale line. */
    public function hasSales(int $itemId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sale_items WHERE item_id = ? LIMIT 1');
        $stmt->execute([$itemId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Activate (true) or soft-delete (false) an item. */
    public function setActive(int $itemId, bool $active): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $item = $this->lockForUpdate($itemId);
            if ($item !== null) {
                if (!$active && $item['item_type'] === 'product'
                    && (new ProductPromotionRepository($this->pdo))
                        ->findOpenForItem($itemId, date('Y-m-d'), true) !== null) {
                    throw new InvalidArgumentException('Cancel the open promotion before deactivating this Product.');
                }
                $stmt = $this->pdo->prepare(
                    'UPDATE items SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE item_id = :id'
                );
                $stmt->execute([':active' => $active ? 1 : 0, ':id' => $itemId]);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    // --- Phase 5: POS checkout support -------------------------------------
    //
    // These two are used ONLY by SaleService, inside its checkout transaction.
    // They stay here so all SQL against `items` lives in one class; the policy
    // (which rows to lock, when to deduct, product-only) belongs to SaleService.

    /**
     * Read one item FOR UPDATE — the authoritative price / type / stock / active
     * state, with the row locked for the rest of the caller's transaction so a
     * concurrent checkout cannot consume the same stock (no overselling).
     *
     * Same shape as findById(); only meaningful inside an open transaction.
     *
     * @return array<string,mixed>|null
     */
    public function lockForUpdate(int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM items WHERE item_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Deduct product stock in a single guarded statement: the row is only
     * touched when it is a product with enough stock, so it can never go
     * negative even if a caller passes a bad quantity. Call inside the same
     * transaction as lockForUpdate().
     *
     * @return bool true when exactly one product row was decremented
     */
    public function decrementStock(int $itemId, int $quantity): bool
    {
        if ($quantity <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE items
                SET stock_quantity = stock_quantity - ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE item_id = ?
                AND item_type = 'product'
                AND stock_quantity IS NOT NULL
                AND stock_quantity >= ?"
        );
        $stmt->execute([$quantity, $itemId, $quantity]);

        return $stmt->rowCount() === 1;
    }

    /** Guarded increase, used only inside the RestockService transaction. */
    public function incrementStock(int $itemId, int $quantity): bool
    {
        if ($quantity <= 0 || $quantity > 2147483647) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE items SET stock_quantity = stock_quantity + ?, updated_at = CURRENT_TIMESTAMP
              WHERE item_id = ? AND item_type = 'product' AND is_active = 1
                AND stock_quantity IS NOT NULL AND stock_quantity >= 0
                AND stock_quantity <= ?"
        );
        $stmt->execute([$quantity, $itemId, 2147483647 - $quantity]);
        return $stmt->rowCount() === 1;
    }

    /** Apply an audited correction after the caller locks the Product row. */
    public function setAdjustedStock(int $itemId, int $before, int $after): bool
    {
        if ($before < 0 || $after < 0 || $after > 2147483647 || $before === $after) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE items SET stock_quantity = :after, updated_at = CURRENT_TIMESTAMP
              WHERE item_id = :id AND item_type = 'product' AND stock_quantity = :before"
        );
        $stmt->execute([':after' => $after, ':id' => $itemId, ':before' => $before]);
        return $stmt->rowCount() === 1;
    }

    // --- internals ----------------------------------------------------------

    /**
     * Enforce the product/service split. A service never carries stock; a
     * product must have a non-negative stock quantity and a non-negative
     * (or null) reorder level.
     *
     * @return array{0:?int,1:?int} [stock_quantity, reorder_level] to store
     */
    private function normaliseStockFields(string $itemType, ?int $stock, ?int $reorder): array
    {
        if ($itemType === 'service') {
            return [null, null];
        }
        if ($itemType === 'product') {
            if ($stock === null || $stock < 0) {
                throw new InvalidArgumentException('A product requires a stock quantity of zero or more.');
            }
            if ($reorder !== null && $reorder < 0) {
                throw new InvalidArgumentException('A reorder level cannot be negative.');
            }
            return [$stock, $reorder];
        }
        throw new InvalidArgumentException("Unknown item_type: {$itemType}");
    }

    private function normaliseSlowMovingOverride(string $itemType, ?int $days): ?int
    {
        if ($days === null) {
            return null;
        }
        if ($itemType !== 'product' || $days < 1 || $days > 365) {
            throw new InvalidArgumentException('A Product slow-moving override must be 1–365 days.');
        }
        return $days;
    }

    private function assertNonNegativePrice(int $priceCentavos): int
    {
        if ($priceCentavos < 0) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }
        return $priceCentavos;
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Add a `price_centavos` integer alongside the raw decimal `price` string.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['price_centavos'] = Money::toCentavos((string) $row['price']);
        $row['stock_quantity'] = $row['stock_quantity'] === null ? null : (int) $row['stock_quantity'];
        $row['reorder_level']  = $row['reorder_level'] === null ? null : (int) $row['reorder_level'];
        $row['slow_moving_days_override'] = $row['slow_moving_days_override'] === null
            ? null : (int) $row['slow_moving_days_override'];
        $row['is_active']      = (int) $row['is_active'];

        return $row;
    }
}
