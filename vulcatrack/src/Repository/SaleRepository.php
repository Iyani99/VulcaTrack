<?php

namespace VulcaTrack\Repository;

use PDO;
use VulcaTrack\Support\Money;

/**
 * Data access for the `sales` and `sale_items` tables. Prepared statements only.
 *
 * This class ONLY reads and writes sale rows. It does not open, commit or roll
 * back a transaction, decide prices or totals, validate quantities, or decide
 * whether a line consumes stock — SaleService owns all of that and calls these
 * methods on the same PDO connection, inside the one checkout transaction.
 *
 * Money: callers pass amounts as INTEGER CENTAVOS (Decision 55); this class
 * formats them to the DECIMAL(10,2) columns via Money::format() and converts
 * the stored decimal strings back to centavos on the way out.
 *
 * `sale_items.unit_price` is written once, at sale time, from the item's price
 * at that moment — a frozen historical snapshot, independent of `items.price`
 * afterwards (Decision 17).
 *
 * Not `final`: the mock-library-free test harness subclasses this to simulate a
 * mid-transaction insert failure (SaleServiceTest). Application code always uses
 * SaleRepository directly.
 */
class SaleRepository
{
    /**
     * Largest value the approved `sale_items.quantity` column (a signed MySQL
     * `INT`) can store. The DB runs in a non-strict SQL mode, so a larger value
     * would be silently clamped on INSERT rather than rejected — SaleService
     * refuses it up front so a quantity is never quietly corrupted.
     */
    public const MAX_QUANTITY = 2147483647;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Insert the `sales` header. $saleDate is a server-controlled
     * 'Y-m-d H:i:s' string (Decision 35 — no DB default, no backdating).
     * $customerId is null for a walk-in sale (Decision 14).
     */
    public function createSale(int $adminId, ?int $customerId, string $saleDate, int $totalCentavos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sales (customer_id, admin_id, sale_date, total_amount)
             VALUES (:customer_id, :admin_id, :sale_date, :total)'
        );
        $stmt->execute([
            ':customer_id' => $customerId,
            ':admin_id'    => $adminId,
            ':sale_date'   => $saleDate,
            ':total'       => Money::format($totalCentavos),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert one `sale_items` line. $unitPriceCentavos is the item's price
     * frozen at sale time; $subtotalCentavos must equal quantity * unit price.
     */
    public function addSaleItem(
        int $saleId,
        int $itemId,
        int $quantity,
        int $unitPriceCentavos,
        int $subtotalCentavos
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal)
             VALUES (:sale_id, :item_id, :quantity, :unit_price, :subtotal)'
        );
        $stmt->execute([
            ':sale_id'    => $saleId,
            ':item_id'    => $itemId,
            ':quantity'   => $quantity,
            ':unit_price' => Money::format($unitPriceCentavos),
            ':subtotal'   => Money::format($subtotalCentavos),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Sale header for the printable HTML receipt (Decision 50): shop-independent
     * fields plus the recording admin's name and the linked customer's name
     * (null → the receipt prints "Walk-in"). Null when the sale id is unknown.
     *
     * @return array<string,mixed>|null
     */
    public function findSaleForReceipt(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.sale_id, s.customer_id, s.admin_id, s.sale_date, s.total_amount, s.created_at,
                    a.full_name AS admin_name,
                    c.full_name AS customer_name
             FROM sales s
             JOIN admins a ON a.admin_id = s.admin_id
             LEFT JOIN customers c ON c.customer_id = s.customer_id
             WHERE s.sale_id = ? LIMIT 1'
        );
        $stmt->execute([$saleId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['total_amount_centavos'] = Money::toCentavos((string) $row['total_amount']);

        return $row;
    }

    /**
     * The line items of a sale, oldest first, each joined to its item for the
     * display name and type. `unit_price` / `subtotal` are the frozen sale-time
     * values; `*_centavos` are added for exact arithmetic.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSaleItems(int $saleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT si.sale_item_id, si.item_id, si.quantity, si.unit_price, si.subtotal,
                    i.item_name, i.item_type
             FROM sale_items si
             JOIN items i ON i.item_id = si.item_id
             WHERE si.sale_id = ?
             ORDER BY si.sale_item_id ASC'
        );
        $stmt->execute([$saleId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as $i => $row) {
            $rows[$i]['quantity']            = (int) $row['quantity'];
            $rows[$i]['unit_price_centavos'] = Money::toCentavos((string) $row['unit_price']);
            $rows[$i]['subtotal_centavos']   = Money::toCentavos((string) $row['subtotal']);
        }

        return $rows;
    }
}
