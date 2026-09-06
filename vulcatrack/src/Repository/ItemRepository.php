<?php

namespace VulcaTrack\Repository;

use InvalidArgumentException;
use PDO;
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
 * (Decision 16). create() / update() enforce that split regardless of the
 * arguments passed.
 *
 * Removal is soft: setActive($id, false). Rows are never hard-deleted — an
 * inactive item stays visible on historical `sale_items` (Decisions 27-29).
 *
 * `items.updated_at` has no ON UPDATE clause, so every UPDATE sets it here.
 */
final class ItemRepository
{
    private const COLUMNS =
        'item_id, item_name, item_type, category, price, stock_quantity, reorder_level, is_active, created_at, updated_at';

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
        ?int $reorderLevel
    ): int {
        [$stock, $reorder] = $this->normaliseStockFields($itemType, $stockQuantity, $reorderLevel);

        $stmt = $this->pdo->prepare(
            'INSERT INTO items (item_name, item_type, category, price, stock_quantity, reorder_level)
             VALUES (:name, :type, :category, :price, :stock, :reorder)'
        );
        $stmt->execute([
            ':name'     => $name,
            ':type'     => $itemType,
            ':category' => $this->nullIfBlank($category),
            ':price'    => Money::format($this->assertNonNegativePrice($priceCentavos)),
            ':stock'    => $stock,
            ':reorder'  => $reorder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an item's editable fields. Does not touch `is_active` — use
     * setActive() for that.
     *
     * @throws InvalidArgumentException on a negative price or a product without stock
     */
    public function update(
        int $itemId,
        string $name,
        string $itemType,
        ?string $category,
        int $priceCentavos,
        ?int $stockQuantity,
        ?int $reorderLevel
    ): void {
        [$stock, $reorder] = $this->normaliseStockFields($itemType, $stockQuantity, $reorderLevel);

        $stmt = $this->pdo->prepare(
            'UPDATE items
                SET item_name = :name, item_type = :type, category = :category,
                    price = :price, stock_quantity = :stock, reorder_level = :reorder,
                    updated_at = CURRENT_TIMESTAMP
              WHERE item_id = :id'
        );
        $stmt->execute([
            ':name'     => $name,
            ':type'     => $itemType,
            ':category' => $this->nullIfBlank($category),
            ':price'    => Money::format($this->assertNonNegativePrice($priceCentavos)),
            ':stock'    => $stock,
            ':reorder'  => $reorder,
            ':id'       => $itemId,
        ]);
    }

    /** Activate (true) or soft-delete (false) an item. */
    public function setActive(int $itemId, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE items SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE item_id = :id'
        );
        $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':id'     => $itemId,
        ]);
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
        $row['is_active']      = (int) $row['is_active'];

        return $row;
    }
}
