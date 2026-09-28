<?php

namespace VulcaTrack\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
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
     * `INT`) can store. SaleService refuses a larger value up front with a clear
     * message; the strict session set in includes/db.php (Decision 64) is the
     * DB-level backstop that stops it from ever being silently clamped.
     */
    public const MAX_QUANTITY = 2147483647;

    /**
     * The UNIQUE key on `sales.service_request_id` (Phase 7.3d-a): at most one
     * sale per Rescue request. SaleService uses the name to recognise that
     * one duplicate-key error — and no other — as "already has a sale".
     */
    public const RESCUE_LINK_KEY = 'uq_sales_service_request';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Insert the `sales` header. $saleDate is a server-controlled
     * 'Y-m-d H:i:s' string (Decision 35 — no DB default, no backdating).
     * $customerId is null for a walk-in sale (Decision 14).
     * $serviceRequestId is the Rescue request the sale was recorded for, or
     * null for an ordinary POS sale (Phase 7.3d); SaleService has already
     * validated it — the UNIQUE key rejects a second sale for the same request.
     */
    public function createSale(
        int $adminId,
        ?int $customerId,
        string $saleDate,
        int $totalCentavos,
        ?int $serviceRequestId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount)
             VALUES (:customer_id, :service_request_id, :admin_id, :sale_date, :total)'
        );
        $stmt->execute([
            ':customer_id'        => $customerId,
            ':service_request_id' => $serviceRequestId,
            ':admin_id'           => $adminId,
            ':sale_date'          => $saleDate,
            ':total'              => Money::format($totalCentavos),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The id of the sale already recorded for a Rescue request, or null.
     *
     * A locking read (SELECT … FOR UPDATE), for use inside SaleService's
     * checkout transaction: it reads the latest committed rows, not the
     * transaction's older snapshot, so a sale another admin committed a moment
     * ago is seen. When there is no such sale InnoDB also locks the gap in
     * uq_sales_service_request, which other sales INSERTs may wait on until
     * this transaction ends — SaleService therefore calls it only after it has
     * locked the item rows (see there). The UNIQUE key remains the final guarantee.
     */
    public function lockSaleIdForServiceRequest(int $serviceRequestId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT sale_id FROM sales WHERE service_request_id = ? FOR UPDATE'
        );
        $stmt->execute([$serviceRequestId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The sale recorded for a Rescue request, for the admin Rescue detail
     * panel (Phase 7.3d): sale_id, sale_date, total_amount (+ _centavos) and
     * the recording admin's name. At most one exists (UNIQUE key). A plain
     * read — no locks. Null when none has been recorded.
     *
     * @return array{sale_id: int, sale_date: string, total_amount: string, total_amount_centavos: int, admin_name: string}|null
     */
    public function findForServiceRequest(int $serviceRequestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.sale_id, s.sale_date, s.total_amount, a.full_name AS admin_name
             FROM sales s
             JOIN admins a ON a.admin_id = s.admin_id
             WHERE s.service_request_id = ?
             LIMIT 1'
        );
        $stmt->execute([$serviceRequestId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'sale_id'               => (int) $row['sale_id'],
            'sale_date'             => (string) $row['sale_date'],
            'total_amount'          => (string) $row['total_amount'],
            'total_amount_centavos' => Money::toCentavos((string) $row['total_amount']),
            'admin_name'            => (string) $row['admin_name'],
        ];
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
     * (null → the receipt prints "Walk-in"), and service_request_id (the Rescue
     * request it was recorded for, or null). Null when the sale id is unknown.
     *
     * @return array<string,mixed>|null
     */
    public function findSaleForReceipt(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.sale_id, s.customer_id, s.service_request_id, s.admin_id, s.sale_date, s.total_amount, s.created_at,
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
     * Recorded sales for the admin Sales History list, newest first
     * (sale_date DESC, then sale_id DESC so equal timestamps stay in a fixed
     * order). One query: each sale joined to its recording admin and, for a
     * linked sale, its customer (customer_name null → "Walk-in"); plus
     * service_request_id (null = an ordinary POS sale, else its Rescue). Read-only —
     * sale_items and the current items.price are never touched; the stored
     * total_amount is the transaction total (+ total_amount_centavos).
     *
     * $from / $to are optional calendar days 'YYYY-MM-DD' (see isValidDay()),
     * both inclusive, applied to sale_date — the reporting date (Decision 35):
     * sale_date >= from 00:00:00 and sale_date < (to + 1 day) 00:00:00. A
     * from later than to simply matches nothing; the page reports that case
     * before calling here.
     *
     * @return array<int,array<string,mixed>>
     * @throws InvalidArgumentException when $from or $to is not a valid day
     */
    public function listForHistory(?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT s.sale_id, s.customer_id, s.service_request_id, s.admin_id, s.sale_date, s.total_amount,
                       a.full_name AS admin_name,
                       c.full_name AS customer_name
                FROM sales s
                JOIN admins a ON a.admin_id = s.admin_id
                LEFT JOIN customers c ON c.customer_id = s.customer_id';
        [$where, $params] = $this->saleDateRange($from, $to);
        $sql .= $where . ' ORDER BY s.sale_date DESC, s.sale_id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        foreach ($rows as $i => $row) {
            $rows[$i]['total_amount_centavos'] = Money::toCentavos((string) $row['total_amount']);
        }

        return $rows;
    }

    /*
     * Sales Reports (Decision 49) — aggregate reads over the recorded sales,
     * with the same optional From / To days as listForHistory(). Money is summed
     * in SQL over the stored DECIMAL columns (exact) and converted once to
     * integer centavos; nothing is recalculated from the current items.price.
     * (Money::toCentavos() caps at Money::MAX_CENTAVOS, ₱99,999,999.99 — far
     * beyond this shop's totals.)
     */

    /**
     * Transaction count and total sales (SUM of the stored sales.total_amount)
     * for the range. An empty range gives 0 / 0.
     *
     * @return array{count: int, total_centavos: int}
     * @throws InvalidArgumentException when $from or $to is not a valid day
     */
    public function summarize(?string $from = null, ?string $to = null): array
    {
        [$where, $params] = $this->saleDateRange($from, $to);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS sale_count, COALESCE(SUM(s.total_amount), 0) AS total
             FROM sales s' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'count'          => (int) $row['sale_count'],
            'total_centavos' => Money::toCentavos((string) $row['total']),
        ];
    }

    /**
     * One row per calendar day that has sales in the range, newest day first:
     * day ('YYYY-MM-DD'), transaction_count, total_centavos. Grouped on
     * DATE(sale_date) — sale_date is stored in the app's local timezone — while
     * the range filter still compares the raw sale_date. Days without sales
     * are not listed.
     *
     * @return array<int,array{day: string, transaction_count: int, total_centavos: int}>
     * @throws InvalidArgumentException when $from or $to is not a valid day
     */
    public function listDailyTotals(?string $from = null, ?string $to = null): array
    {
        [$where, $params] = $this->saleDateRange($from, $to);
        $stmt = $this->pdo->prepare(
            'SELECT DATE(s.sale_date) AS sale_day, COUNT(*) AS sale_count, SUM(s.total_amount) AS total
             FROM sales s' . $where . '
             GROUP BY DATE(s.sale_date)
             ORDER BY sale_day DESC'
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'day'               => (string) $row['sale_day'],
                'transaction_count' => (int) $row['sale_count'],
                'total_centavos'    => Money::toCentavos((string) $row['total']),
            ];
        }

        return $rows;
    }

    /**
     * One row per item sold in the range: item_id, item_name, quantity (units
     * sold) and revenue_centavos (SUM of the frozen sale_items.subtotal).
     * Grouped by item_id, so a renamed item stays one row; the name itself is
     * the item's CURRENT name (no name snapshot in the approved schema).
     * Inactive items are included — items are never hard-deleted. Ordered by
     * quantity, then revenue (both highest first), then item_id.
     *
     * @return array<int,array{item_id: int, item_name: string, quantity: int, revenue_centavos: int}>
     * @throws InvalidArgumentException when $from or $to is not a valid day
     */
    public function listItemTotals(?string $from = null, ?string $to = null): array
    {
        [$where, $params] = $this->saleDateRange($from, $to);
        $stmt = $this->pdo->prepare(
            'SELECT si.item_id, i.item_name, SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue
             FROM sale_items si
             JOIN sales s ON s.sale_id = si.sale_id
             JOIN items i ON i.item_id = si.item_id' . $where . '
             GROUP BY si.item_id, i.item_name
             ORDER BY qty_sold DESC, revenue DESC, si.item_id ASC'
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'item_id'          => (int) $row['item_id'],
                'item_name'        => (string) $row['item_name'],
                'quantity'         => (int) $row['qty_sold'],
                'revenue_centavos' => Money::toCentavos((string) $row['revenue']),
            ];
        }

        return $rows;
    }

    /**
     * The shared From / To filter on `sales s`.sale_date for Sales History and
     * Sales Reports: [' WHERE …' or '', bound values]. Both days are optional
     * and inclusive — sale_date >= from 00:00:00 and sale_date < (to + 1 day)
     * 00:00:00 — so the raw column is compared, never DATE(sale_date).
     *
     * @return array{0: string, 1: array<int,string>}
     * @throws InvalidArgumentException when $from or $to is not a valid day
     */
    private function saleDateRange(?string $from, ?string $to): array
    {
        $where  = [];
        $params = [];
        if ($from !== null) {
            if (!self::isValidDay($from)) {
                throw new InvalidArgumentException("Invalid from date: {$from}");
            }
            $where[]  = 's.sale_date >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            if (!self::isValidDay($to)) {
                throw new InvalidArgumentException("Invalid to date: {$to}");
            }
            // 9999-12-31 has no following DATETIME day; every sale is on or before it.
            if ($to !== '9999-12-31') {
                $where[]  = 's.sale_date < ?';
                $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * True for a real calendar day written exactly 'YYYY-MM-DD' within the
     * DATETIME year range (1000-9999) — the Sales History date-filter format
     * (what <input type="date"> submits). '2026-02-31' and '2026-9-8' are not.
     */
    public static function isValidDay(string $day): bool
    {
        return preg_match('/^([1-9]\d{3})-(\d{2})-(\d{2})$/D', $day, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
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
