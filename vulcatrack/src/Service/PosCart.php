<?php

namespace VulcaTrack\Service;

use VulcaTrack\Repository\ItemRepository;

/**
 * The temporary POS cart (Decision 57) — held in the admin's PHP session, never
 * in the database (no cart table).
 *
 * It stores only what identifies the cashier's intent:
 *   - lines:              [item_id => quantity], in the order items were added
 *   - customer_id:        an existing customer to link, or null for a walk-in
 *   - service_request_id: the Rescue request this sale is being recorded for
 *                         (Phase 7.3d), or null for an ordinary POS sale. While
 *                         it is set the customer is locked to that request's
 *                         customer; only clear() (cancel / completed sale) ends it.
 *
 * It never stores prices or totals. Anything shown on screen is re-read from
 * the database (describe()), and at checkout SaleService re-reads and locks
 * every item — the database stays authoritative (Decision 62).
 *
 * Limits (POS-layer guards, deliberately far below SaleService's column
 * ceilings): at most MAX_LINES distinct items and at most MAX_QUANTITY of one
 * item. They keep the session payload and request size small, and they keep
 * describe()'s arithmetic well inside PHP's integer range
 * (MAX_LINES × MAX_QUANTITY × Money::MAX_CENTAVOS ≈ 5e15 < PHP_INT_MAX), while
 * still being generous for a single counter sale in a vulcanizing shop.
 */
final class PosCart
{
    public const MAX_LINES    = 50;
    public const MAX_QUANTITY = 9999;

    private const KEY = 'pos_cart';

    /** @var array{lines: array<int,int>, customer_id: ?int, service_request_id: ?int} */
    private array $state;

    /**
     * @param array<string,mixed> $session usually $_SESSION (by reference)
     */
    public function __construct(array &$session)
    {
        $session[self::KEY] = self::sanitise($session[self::KEY] ?? null);
        $this->state = &$session[self::KEY];
    }

    /** @return array<int,int> [item_id => quantity], in the order added */
    public function lines(): array
    {
        return $this->state['lines'];
    }

    public function isEmpty(): bool
    {
        return $this->state['lines'] === [];
    }

    public function quantityOf(int $itemId): int
    {
        return $this->state['lines'][$itemId] ?? 0;
    }

    /** Lines in the shape SaleService::checkout() expects. */
    public function toSaleLines(): array
    {
        $out = [];
        foreach ($this->state['lines'] as $itemId => $quantity) {
            $out[] = ['item_id' => $itemId, 'quantity' => $quantity];
        }
        return $out;
    }

    /**
     * Add $quantity of an item. Adding an item that is already in the cart
     * increases that line (one line per item — deterministic, no duplicates).
     *
     * @return int the line's new quantity
     * @throws PosCartException
     */
    public function add(int $itemId, int $quantity): int
    {
        $this->assertItemId($itemId);
        $this->assertQuantity($quantity);

        $current = $this->quantityOf($itemId);
        if ($current === 0 && count($this->state['lines']) >= self::MAX_LINES) {
            throw new PosCartException('A sale can have at most ' . self::MAX_LINES . ' different items.');
        }
        if ($quantity > self::MAX_QUANTITY - $current) {
            throw new PosCartException('The quantity of one item cannot be more than ' . self::MAX_QUANTITY . '.');
        }

        $this->state['lines'][$itemId] = $current + $quantity;
        return $this->state['lines'][$itemId];
    }

    /**
     * Replace the quantity of an item already in the cart.
     *
     * @throws PosCartException
     */
    public function setQuantity(int $itemId, int $quantity): void
    {
        if (!isset($this->state['lines'][$itemId])) {
            throw new PosCartException('That item is no longer in the sale.');
        }
        $this->assertQuantity($quantity);
        $this->state['lines'][$itemId] = $quantity;
    }

    public function remove(int $itemId): void
    {
        unset($this->state['lines'][$itemId]);
    }

    public function customerId(): ?int
    {
        return $this->state['customer_id'];
    }

    /**
     * Link an existing customer (the caller has already looked it up), or null
     * for walk-in. Refused during a Rescue sale: its customer is the request's.
     */
    public function setCustomer(?int $customerId): void
    {
        if ($this->state['service_request_id'] !== null) {
            throw new PosCartException(
                "A Rescue sale is always recorded for the Rescue's customer. Cancel the sale to start a different one."
            );
        }
        if ($customerId !== null && $customerId < 1) {
            throw new PosCartException('That customer could not be linked.');
        }
        $this->state['customer_id'] = $customerId;
    }

    /** The Rescue request this sale is for, or null for an ordinary POS sale. */
    public function serviceRequestId(): ?int
    {
        return $this->state['service_request_id'];
    }

    /**
     * The sale's context as rendered into every POS form (expected_rescue_id):
     * "" for an ordinary sale, the request id for a Rescue sale.
     */
    public function contextToken(): string
    {
        return $this->state['service_request_id'] === null ? '' : (string) $this->state['service_request_id'];
    }

    /**
     * Stale-tab guard: was the submitted form rendered for the context the cart
     * is in NOW? All POS tabs share one session cart, so a page rendered for an
     * ordinary sale must not change a Rescue sale (or vice versa, or another
     * Rescue). Strict: only "" or a plain positive id is a valid token; a
     * missing, malformed or different value does not match.
     */
    public function matchesContext($submitted): bool
    {
        if (!is_string($submitted) || ($submitted !== '' && preg_match('/^[1-9]\d{0,9}$/D', $submitted) !== 1)) {
            return false;
        }
        return $submitted === $this->contextToken();
    }

    /**
     * Start recording a sale for a Rescue request (the caller has already
     * checked the request can have one). Only from a clean cart — no items, no
     * linked customer, no other Rescue — so an ordinary sale in progress is
     * never overwritten. Starting the Rescue that is already active changes
     * nothing (returns false); a fresh start returns true.
     *
     * @throws PosCartException when the cart is not clean
     */
    public function startRescue(int $requestId, int $customerId): bool
    {
        if ($requestId < 1 || $customerId < 1) {
            throw new PosCartException('That Rescue request cannot be used for a sale.');
        }
        if ($this->state['service_request_id'] === $requestId) {
            return false;
        }
        if ($this->state['service_request_id'] !== null || $this->state['lines'] !== [] || $this->state['customer_id'] !== null) {
            throw new PosCartException('Finish or cancel the current sale first.');
        }
        $this->state['service_request_id'] = $requestId;
        $this->state['customer_id'] = $customerId;
        return true;
    }

    /** Empty the cart, return to walk-in and leave any Rescue sale (after a completed or abandoned sale). */
    public function clear(): void
    {
        $this->state['lines'] = [];
        $this->state['customer_id'] = null;
        $this->state['service_request_id'] = null;
    }

    /**
     * The cart as it looks against the database RIGHT NOW — for display only.
     * Prices, names, types and stock come from ItemRepository, never from the
     * session. Each row carries a `problem` message when the line cannot be
     * sold as-is (item missing / deactivated / not enough stock); SaleService
     * performs the authoritative check again at checkout.
     *
     * @return array{rows: array<int,array<string,mixed>>, total_centavos: int, has_problems: bool}
     */
    public function describe(ItemRepository $items): array
    {
        $rows = [];
        $total = 0;
        $hasProblems = false;

        foreach ($this->state['lines'] as $itemId => $quantity) {
            $item = $items->findById($itemId);
            $problem = null;
            $unit = 0;

            if ($item === null) {
                $problem = 'This item no longer exists. Remove it from the sale.';
            } else {
                $unit = (int) $item['price_centavos'];
                if ((int) $item['is_active'] !== 1) {
                    $problem = 'This item has been deactivated. Remove it from the sale.';
                } elseif ($item['item_type'] === 'product' && (int) $item['stock_quantity'] < $quantity) {
                    $problem = 'Only ' . (int) $item['stock_quantity'] . ' in stock.';
                }
            }

            $subtotal = $unit * $quantity; // bounded by the cart limits — see class doc
            $total += $subtotal;
            $hasProblems = $hasProblems || $problem !== null;

            $rows[] = [
                'item_id'           => $itemId,
                'quantity'          => $quantity,
                'item_name'         => $item['item_name'] ?? ('Item #' . $itemId),
                'item_type'         => $item['item_type'] ?? null,
                'stock_quantity'    => $item['stock_quantity'] ?? null,
                'unit_centavos'     => $unit,
                'subtotal_centavos' => $subtotal,
                'problem'           => $problem,
            ];
        }

        return ['rows' => $rows, 'total_centavos' => $total, 'has_problems' => $hasProblems];
    }

    // --- internals ------------------------------------------------------------

    private function assertItemId(int $itemId): void
    {
        if ($itemId < 1) {
            throw new PosCartException('Choose a valid item.');
        }
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new PosCartException('Quantity must be a whole number from 1 to ' . self::MAX_QUANTITY . '.');
        }
    }

    /**
     * Rebuild a well-formed cart from whatever the session holds, dropping any
     * malformed entry. The session is server-side, so this is defence in depth,
     * not a trust boundary.
     *
     * @return array{lines: array<int,int>, customer_id: ?int, service_request_id: ?int}
     */
    private static function sanitise($raw): array
    {
        $clean = ['lines' => [], 'customer_id' => null, 'service_request_id' => null];
        if (!is_array($raw)) {
            return $clean;
        }
        if (is_array($raw['lines'] ?? null)) {
            foreach ($raw['lines'] as $itemId => $quantity) {
                if (count($clean['lines']) >= self::MAX_LINES) {
                    break;
                }
                if (is_int($itemId) && $itemId > 0 && is_int($quantity)
                    && $quantity >= 1 && $quantity <= self::MAX_QUANTITY) {
                    $clean['lines'][$itemId] = $quantity;
                }
            }
        }
        if (is_int($raw['customer_id'] ?? null) && $raw['customer_id'] > 0) {
            $clean['customer_id'] = $raw['customer_id'];
        }
        // A Rescue sale always has its customer; a Rescue id without one is dropped.
        if (is_int($raw['service_request_id'] ?? null) && $raw['service_request_id'] > 0 && $clean['customer_id'] !== null) {
            $clean['service_request_id'] = $raw['service_request_id'];
        }
        return $clean;
    }
}
