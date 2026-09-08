<?php

namespace VulcaTrack\Service;

use PDO;
use Throwable;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

/**
 * Records one in-person sale, atomically (Phase 5).
 *
 * SaleService is the single owner of the checkout transaction. It:
 *   - validates the request (the caller may only choose item ids, quantities
 *     and an optional existing customer — never prices or totals);
 *   - opens ONE transaction;
 *   - reads every item FOR UPDATE, so the authoritative current price / type /
 *     stock / active-state come from the database and product rows are locked
 *     against a concurrent checkout;
 *   - freezes each line's unit price, computes subtotals and the sale total in
 *     integer centavos (no float arithmetic — Decision 55);
 *   - inserts `sales` + `sale_items`;
 *   - deducts stock for product lines only (services never touch stock —
 *     Decision 16);
 *   - commits, or on ANY failure rolls the whole transaction back so no partial
 *     sale is ever left behind.
 *
 * The repositories it uses share this same PDO connection and never open,
 * commit or roll back a transaction themselves.
 */
final class SaleService
{
    private SaleRepository $sales;
    private ItemRepository $items;
    private CustomerRepository $customers;
    private AdminRepository $admins;

    /**
     * $sales / $items are injectable so tests can exercise mid-transaction
     * failure; production callers pass just the PDO.
     */
    public function __construct(
        private PDO $pdo,
        ?SaleRepository $sales = null,
        ?ItemRepository $items = null
    ) {
        $this->sales     = $sales ?? new SaleRepository($pdo);
        $this->items     = $items ?? new ItemRepository($pdo);
        $this->customers = new CustomerRepository($pdo);
        $this->admins    = new AdminRepository($pdo);
    }

    /**
     * @param array $request {
     *   admin_id:    int,                                   // recording admin
     *   customer_id?: int|string|null,                      // absent / null / "" = walk-in
     *   lines:       array<array{item_id: int, quantity: int}>
     * }
     * `admin_id` MUST be taken from the authenticated Admin session by the
     * caller (the future POS endpoint) — it is the recording cashier and the
     * browser must never choose it. The service validates that the id still
     * refers to a real admin, but it cannot know whether the caller sourced it
     * correctly.
     *
     * Any other keys (a caller-supplied price, subtotal, total, item_type …)
     * are ignored — the server is authoritative for all of those.
     *
     * @return array{sale_id: int, total_centavos: int, sale_date: string}
     *
     * @throws SaleException if the sale is rejected (nothing was written)
     * @throws Throwable     on an unexpected failure (the transaction was rolled back)
     */
    public function checkout(array $request): array
    {
        $adminId    = $this->requirePositiveInt($request['admin_id'] ?? null, 'admin_id');
        $customerId = $this->normaliseCustomerId($request['customer_id'] ?? null);
        $wanted     = $this->normaliseLines($request['lines'] ?? null); // [item_id => quantity], low id first

        if ($this->pdo->inTransaction()) {
            // Exactly one owner of the checkout transaction — never nest it.
            throw new SaleException('SaleService owns the checkout transaction and cannot run inside another.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->admins->findById($adminId) === null) {
                throw new SaleException('The recording admin account is no longer valid — please sign in again.');
            }
            if ($customerId !== null && $this->customers->findById($customerId) === null) {
                throw new SaleException("Customer #{$customerId} was not found.");
            }

            $lines = [];
            $totalCentavos = 0;

            foreach ($wanted as $itemId => $quantity) {
                $item = $this->items->lockForUpdate((int) $itemId);
                if ($item === null) {
                    throw new SaleException("Item #{$itemId} does not exist.");
                }
                if ((int) $item['is_active'] !== 1) {
                    throw new SaleException("\"{$item['item_name']}\" is not available for sale.");
                }

                $unitCentavos = (int) $item['price_centavos'];

                // Bound the line BEFORE multiplying so unit_price x quantity can
                // never leave PHP's integer range. unit_price is already <=
                // Money::MAX_CENTAVOS (guaranteed by Money on the way in) and
                // quantity is already <= SaleRepository::MAX_QUANTITY.
                if ($unitCentavos > 0 && $quantity > intdiv(Money::MAX_CENTAVOS, $unitCentavos)) {
                    throw new SaleException(
                        "The line total for \"{$item['item_name']}\" is larger than this system can record."
                    );
                }
                $subtotalCentavos = $unitCentavos * $quantity;

                // Same idea for the running total: check with subtraction, not by
                // adding first and inspecting the (possibly overflowed) result.
                if ($subtotalCentavos > Money::MAX_CENTAVOS - $totalCentavos) {
                    throw new SaleException('The sale total is larger than this system can record.');
                }

                if ($item['item_type'] === 'product') {
                    $onHand = (int) $item['stock_quantity'];
                    if ($onHand < $quantity) {
                        throw new SaleException(
                            "Not enough stock for \"{$item['item_name']}\" "
                            . "(in stock: {$onHand}, requested: {$quantity})."
                        );
                    }
                }

                $lines[] = [
                    'item_id'           => (int) $itemId,
                    'item_type'         => (string) $item['item_type'],
                    'quantity'          => $quantity,
                    'unit_centavos'     => $unitCentavos,
                    'subtotal_centavos' => $subtotalCentavos,
                ];
                $totalCentavos += $subtotalCentavos;
            }

            $saleDate = date('Y-m-d H:i:s');
            $saleId   = $this->sales->createSale($adminId, $customerId, $saleDate, $totalCentavos);

            foreach ($lines as $line) {
                $this->sales->addSaleItem(
                    $saleId,
                    $line['item_id'],
                    $line['quantity'],
                    $line['unit_centavos'],
                    $line['subtotal_centavos']
                );
            }

            foreach ($lines as $line) {
                if ($line['item_type'] !== 'product') {
                    continue; // services never deduct stock (Decision 16)
                }
                if (!$this->items->decrementStock($line['item_id'], $line['quantity'])) {
                    // The locked row said there was enough; if the guarded update
                    // still changed nothing, the sale is not safe to keep.
                    throw new SaleException('Stock changed during checkout; the sale was not recorded.');
                }
            }

            $this->pdo->commit();

            return [
                'sale_id'        => $saleId,
                'total_centavos' => $totalCentavos,
                'sale_date'      => $saleDate,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable $rollbackFailure) {
                    // A failing rollback must not hide why the sale failed.
                }
            }
            throw $e;
        }
    }

    // --- request normalisation -------------------------------------------------

    private function requirePositiveInt($value, string $field): int
    {
        $n = $this->parseWholeNumber($value);
        if ($n === null || $n < 1) {
            throw new SaleException("{$field} must be a positive whole number.");
        }
        return $n;
    }

    /** null / "" / absent → walk-in; otherwise a positive id. */
    private function normaliseCustomerId($value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $n = $this->parseWholeNumber($value);
        if ($n === null || $n < 1) {
            throw new SaleException('customer_id must be a positive whole number or blank for a walk-in.');
        }
        return $n;
    }

    /**
     * Validate the requested lines and collapse duplicate item ids by summing
     * their quantities. Returns [item_id => quantity] ordered by item id
     * (a consistent lock order avoids deadlocks between concurrent checkouts).
     *
     * Every quantity — each supplied line AND the merged per-item total — must
     * fit `sale_items.quantity` (SaleRepository::MAX_QUANTITY). The running sum
     * is checked with subtraction before each add, so it can never overflow or
     * land outside the column's domain unnoticed.
     *
     * @return array<int,int>
     */
    private function normaliseLines($value): array
    {
        if (!is_array($value) || $value === []) {
            throw new SaleException('A sale needs at least one line item.');
        }

        $max = SaleRepository::MAX_QUANTITY;
        $merged = [];
        foreach ($value as $line) {
            if (!is_array($line)) {
                throw new SaleException('Each sale line must be an item id and a quantity.');
            }
            $itemId = $this->parseWholeNumber($line['item_id'] ?? null);
            if ($itemId === null || $itemId < 1) {
                throw new SaleException('Each sale line needs a valid item id.');
            }
            $quantity = $this->parseWholeNumber($line['quantity'] ?? null);
            if ($quantity === null || $quantity < 1) {
                throw new SaleException("Quantity for item #{$itemId} must be a whole number greater than zero.");
            }
            if ($quantity > $max) {
                throw new SaleException("Quantity for item #{$itemId} is too large to record.");
            }

            $runningForItem = $merged[$itemId] ?? 0;
            if ($quantity > $max - $runningForItem) {
                throw new SaleException("Total quantity for item #{$itemId} is too large to record.");
            }
            $merged[$itemId] = $runningForItem + $quantity;
        }

        ksort($merged);
        return $merged;
    }

    /**
     * A real int or a plain digit string ("5") → int. Rejects floats, "5.0",
     * "5x", "", null and negatives-with-text. Mirrors Validator::wholeNumber so
     * the POS page and the service agree on what a quantity is.
     */
    private function parseWholeNumber($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }
}
