<?php
/**
 * Integration tests for VulcaTrack\Service\SaleService — the atomic in-person
 * checkout the POS UI will call.
 *
 * SaleService owns its own transaction (begin / commit / rollback), so these
 * tests CANNOT use the shared BEGIN/ROLLBACK isolation. Instead each test seeds
 * its own admin / customer / items through SalesFixture and deletes them (and
 * any sale rows attributed to its admins) in a finally block, FK-safe order.
 *
 * Coverage: authoritative DB pricing, frozen unit_price, server-side totals,
 * integer-centavo money, product-only stock deduction, quantity validation,
 * walk-in vs linked customer, overselling protection (SELECT ... FOR UPDATE),
 * and full rollback on any mid-checkout failure.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Service\SaleException;
use VulcaTrack\Service\SaleService;
use VulcaTrack\Support\Money;

/** Seeds throwaway rows for one test and tears them all down afterwards. */
final class SalesFixture
{
    /** @var array<int,int> */ public array $adminIds = [];
    /** @var array<int,int> */ public array $customerIds = [];
    /** @var array<int,int> */ public array $itemIds = [];

    public function __construct(private \PDO $pdo)
    {
    }

    public function admin(string $name = 'Fixture Cashier'): int
    {
        $id = (new AdminRepository($this->pdo))->create($name, TestDb::email('sfx-admin'), Password::hash('password123'));
        $this->adminIds[] = $id;
        return $id;
    }

    public function customer(string $name = 'Fixture Customer'): int
    {
        $id = (new CustomerRepository($this->pdo))->create($name, TestDb::email('sfx-cust'), '09170000000', Password::hash('password123'));
        $this->customerIds[] = $id;
        return $id;
    }

    public function product(string $name, string $price, int $stock, ?int $reorder = null): int
    {
        $id = (new ItemRepository($this->pdo))->create($name, 'product', 'Parts', Money::toCentavos($price), $stock, $reorder);
        $this->itemIds[] = $id;
        return $id;
    }

    public function service(string $name, string $price): int
    {
        $id = (new ItemRepository($this->pdo))->create($name, 'service', 'Labor', Money::toCentavos($price), null, null);
        $this->itemIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> */
    public function item(int $itemId): array
    {
        return (new ItemRepository($this->pdo))->findById($itemId);
    }

    public function stockOf(int $itemId): ?int
    {
        return $this->item($itemId)['stock_quantity'];
    }

    public function deactivate(int $itemId): void
    {
        (new ItemRepository($this->pdo))->setActive($itemId, false);
    }

    public function setPrice(int $itemId, string $price): void
    {
        $row = $this->item($itemId);
        (new ItemRepository($this->pdo))->update(
            $itemId,
            $row['item_name'],
            $row['item_type'],
            $row['category'],
            Money::toCentavos($price),
            $row['stock_quantity'],
            $row['reorder_level']
        );
    }

    private function saleIdsForThisFixture(): array
    {
        if ($this->adminIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $this->adminIds));
        return array_map('intval', $this->pdo->query("SELECT sale_id FROM sales WHERE admin_id IN ({$in})")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function saleCount(): int
    {
        return count($this->saleIdsForThisFixture());
    }

    public function saleItemCount(): int
    {
        $saleIds = $this->saleIdsForThisFixture();
        if ($saleIds === []) {
            return 0;
        }
        $in = implode(',', $saleIds);
        return (int) $this->pdo->query("SELECT COUNT(*) FROM sale_items WHERE sale_id IN ({$in})")->fetchColumn();
    }

    public function cleanup(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $saleIds = $this->saleIdsForThisFixture();
        if ($saleIds !== []) {
            $in = implode(',', $saleIds);
            $this->pdo->exec("DELETE FROM sale_items WHERE sale_id IN ({$in})");
            $this->pdo->exec("DELETE FROM sales WHERE sale_id IN ({$in})");
        }
        $this->deleteByIds('items', 'item_id', $this->itemIds);
        $this->deleteByIds('customers', 'customer_id', $this->customerIds);
        $this->deleteByIds('admins', 'admin_id', $this->adminIds);
    }

    private function deleteByIds(string $table, string $pk, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $this->pdo->exec("DELETE FROM {$table} WHERE {$pk} IN ({$in})");
    }
}

/** SaleRepository that throws on a chosen write (fault injection). */
final class FailingSaleRepository extends SaleRepository
{
    public bool $throwOnCreateSale = false;
    public ?int $throwOnAddSaleItemCall = null;
    private int $calls = 0;

    public function createSale(int $adminId, ?int $customerId, string $saleDate, int $totalCentavos): int
    {
        if ($this->throwOnCreateSale) {
            throw new \RuntimeException('injected sales header insertion failure');
        }
        return parent::createSale($adminId, $customerId, $saleDate, $totalCentavos);
    }

    public function addSaleItem(int $saleId, int $itemId, int $quantity, int $unitPriceCentavos, int $subtotalCentavos): int
    {
        $this->calls++;
        if ($this->throwOnAddSaleItemCall !== null && $this->calls === $this->throwOnAddSaleItemCall) {
            throw new \RuntimeException('injected sale_items insertion failure');
        }
        return parent::addSaleItem($saleId, $itemId, $quantity, $unitPriceCentavos, $subtotalCentavos);
    }
}

/** ItemRepository that throws on the Nth decrementStock() call (fault injection). */
final class FailingItemRepository extends ItemRepository
{
    public ?int $throwOnDecrementCall = null;
    private int $calls = 0;

    public function decrementStock(int $itemId, int $quantity): bool
    {
        $this->calls++;
        if ($this->throwOnDecrementCall !== null && $this->calls === $this->throwOnDecrementCall) {
            throw new \RuntimeException('injected stock-deduction failure');
        }
        return parent::decrementStock($itemId, $quantity);
    }
}

/** Run $body with a fixture, always cleaning up. */
function with_sales_fixture(\Closure $body): void
{
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    $fx = new SalesFixture($pdo);
    try {
        $body($pdo, $fx);
    } finally {
        $fx->cleanup();
    }
}

// --- happy paths ------------------------------------------------------------

test('SaleService records a product-only sale: sales + sale_items committed, stock reduced', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $tube = $fx->product('Inner Tube', '250.00', 10);
        $valve = $fx->product('Valve', '35.50', 40);

        $before = time();
        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $tube, 'quantity' => 2],
                ['item_id' => $valve, 'quantity' => 3],
            ],
        ]);

        assert_true($result['sale_id'] > 0);
        assert_same(2 * 25000 + 3 * 3550, $result['total_centavos']);   // 50000 + 10650
        assert_true(is_int($result['total_centavos']));
        assert_true(abs(strtotime($result['sale_date']) - $before) <= 5, 'sale_date is server-set to ~now');

        $header = (new SaleRepository($pdo))->findSaleForReceipt($result['sale_id']);
        assert_same('606.50', $header['total_amount'], 'server-calculated total persisted exactly');
        assert_same($result['sale_date'], $header['sale_date']);

        $lines = (new SaleRepository($pdo))->listSaleItems($result['sale_id']);
        assert_count(2, $lines);

        assert_same(8, $fx->stockOf($tube), '10 - 2');
        assert_same(37, $fx->stockOf($valve), '40 - 3');
    });
});

test('SaleService records a service-only sale and never touches stock', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $patch = $fx->service('Tyre Patching', '120.00');
        $balance = $fx->service('Wheel Balancing', '150.00');

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $patch, 'quantity' => 1],
                ['item_id' => $balance, 'quantity' => 2],
            ],
        ]);

        assert_same(12000 + 2 * 15000, $result['total_centavos']);
        assert_null($fx->stockOf($patch), 'a service still carries no stock column');
        assert_null($fx->stockOf($balance));
        assert_count(2, (new SaleRepository($pdo))->listSaleItems($result['sale_id']));
    });
});

test('SaleService records a mixed product + service sale correctly', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $tube = $fx->product('Inner Tube', '250.00', 5);
        $labour = $fx->service('Fitting', '80.00');

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $tube, 'quantity' => 1],
                ['item_id' => $labour, 'quantity' => 1],
            ],
        ]);

        assert_same(25000 + 8000, $result['total_centavos']);
        assert_same(4, $fx->stockOf($tube), 'product line deducted');
        assert_null($fx->stockOf($labour), 'service line not deducted');
    });
});

test('a walk-in sale stores customer_id = NULL; a linked existing customer is recorded', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Sealant', '75.00', 20);

        $walkIn = (new SaleService($pdo))->checkout([
            'admin_id'    => $adminId,
            'customer_id' => null,
            'lines'       => [['item_id' => $item, 'quantity' => 1]],
        ]);
        $walkInHeader = (new SaleRepository($pdo))->findSaleForReceipt($walkIn['sale_id']);
        assert_null($walkInHeader['customer_id'], 'NULL customer for a walk-in (Decision 14)');

        $custId = $fx->customer('Linked Buyer');
        $linked = (new SaleService($pdo))->checkout([
            'admin_id'    => $adminId,
            'customer_id' => $custId,
            'lines'       => [['item_id' => $item, 'quantity' => 1]],
        ]);
        $linkedHeader = (new SaleRepository($pdo))->findSaleForReceipt($linked['sale_id']);
        assert_same($custId, (int) $linkedHeader['customer_id']);
        assert_same('Linked Buyer', $linkedHeader['customer_name']);

        // blank string is treated as walk-in, not an error
        $blank = (new SaleService($pdo))->checkout([
            'admin_id'    => $adminId,
            'customer_id' => '',
            'lines'       => [['item_id' => $item, 'quantity' => 1]],
        ]);
        assert_null((new SaleRepository($pdo))->findSaleForReceipt($blank['sale_id'])['customer_id']);
    });
});

// --- authoritative pricing -------------------------------------------------

test('SaleService uses the authoritative DB price and ignores any caller-supplied price', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Priced Part', '500.00', 10);

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [[
                'item_id'    => $item,
                'quantity'   => 2,
                // hostile extras — all must be ignored
                'unit_price' => '0.01',
                'price'      => 1,
                'subtotal'   => '0.02',
                'item_type'  => 'service',
            ]],
            'total_amount' => '0.02',
        ]);

        assert_same(100000, $result['total_centavos'], '2 x 500.00 from the DB, not the caller');
        $lines = (new SaleRepository($pdo))->listSaleItems($result['sale_id']);
        assert_same('500.00', $lines[0]['unit_price']);
        assert_same(8, $fx->stockOf($item), 'treated as a product despite item_type=service in the request');
    });
});

test('sale_items.unit_price is frozen: a later items.price change does not rewrite history', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Repriced Tyre', '500.00', 10);

        $sale = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [['item_id' => $item, 'quantity' => 1]],
        ]);

        $fx->setPrice($item, '550.00');
        assert_same(55000, $fx->item($item)['price_centavos'], 'current price moved');

        $lines = (new SaleRepository($pdo))->listSaleItems($sale['sale_id']);
        assert_same('500.00', $lines[0]['unit_price'], 'historical unit price unchanged');
        assert_same(50000, $lines[0]['subtotal_centavos']);

        $header = (new SaleRepository($pdo))->findSaleForReceipt($sale['sale_id']);
        assert_same('500.00', $header['total_amount'], 'historical total unchanged');
    });
});

test('money stays exact with integer centavos - no float drift', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        // 0.10 * 3 and 19.99 * 7 are the classic float-error cases.
        $dime = $fx->product('Ten Centavo Washer', '0.10', 100);
        $odd = $fx->product('Odd Price Part', '19.99', 100);

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $dime, 'quantity' => 3],
                ['item_id' => $odd, 'quantity' => 7],
            ],
        ]);

        assert_same(30 + 13993, $result['total_centavos']);            // 0.30 + 139.93
        assert_same('140.23', (new SaleRepository($pdo))->findSaleForReceipt($result['sale_id'])['total_amount']);
    });
});

// --- quantity / item validation (all reject, nothing written) --------------

test('SaleService rejects bad quantities, unknown items and inactive items without writing anything', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $ok = $fx->product('Good Part', '10.00', 5);
        $inactive = $fx->product('Retired Part', '10.00', 5);
        $fx->deactivate($inactive);
        $service = new SaleService($pdo);

        $reject = function (array $lines, string $because) use ($service, $adminId) {
            assert_throws(
                fn () => $service->checkout(['admin_id' => $adminId, 'lines' => $lines]),
                SaleException::class,
                null,
                $because
            );
        };

        $reject([['item_id' => $ok, 'quantity' => 0]], 'zero quantity');
        $reject([['item_id' => $ok, 'quantity' => -2]], 'negative quantity');
        $reject([['item_id' => $ok, 'quantity' => '2.5']], 'non-integer quantity');
        $reject([['item_id' => $ok, 'quantity' => 'lots']], 'non-numeric quantity');
        $reject([['item_id' => 999999999, 'quantity' => 1]], 'unknown item');
        $reject([['item_id' => $inactive, 'quantity' => 1]], 'inactive item');
        $reject([], 'no lines at all');

        assert_same(0, $fx->saleCount(), 'not one sale row was written');
        assert_same(0, $fx->saleItemCount());
        assert_same(5, $fx->stockOf($ok), 'stock untouched by any rejected attempt');
    });
});

test('a quantity larger than the sale_items column can hold is rejected cleanly (no DB clamp)', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Free Sample', '0.00', 1000000000);   // 0.00 so the money guard is not what trips
        $overMax = (string) (SaleRepository::MAX_QUANTITY + 1);
        $huge = '999999999999999999999999';                        // saturates to PHP_INT_MAX on cast

        foreach ([$overMax, $huge] as $q) {
            assert_throws(
                fn () => (new SaleService($pdo))->checkout([
                    'admin_id' => $adminId,
                    'lines'    => [['item_id' => $item, 'quantity' => $q]],
                ]),
                SaleException::class,
                'too large to record'
            );
        }
        assert_same(0, $fx->saleCount(), 'no sales row for an unpersistable quantity');
        assert_same(0, $fx->saleItemCount(), 'no sale_items row');
        assert_same(1000000000, $fx->stockOf($item), 'stock untouched');
    });
});

test('duplicate lines whose merged quantity exceeds the column maximum are rejected cleanly', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Free Sample', '0.00', 2000000000);
        $each = (string) (intdiv(SaleRepository::MAX_QUANTITY, 2) + 1);   // each OK alone; sum > MAX_QUANTITY

        assert_throws(
            fn () => (new SaleService($pdo))->checkout([
                'admin_id' => $adminId,
                'lines'    => [
                    ['item_id' => $item, 'quantity' => $each],
                    ['item_id' => $item, 'quantity' => $each],
                ],
            ]),
            SaleException::class,
            'Total quantity'
        );
        assert_same(0, $fx->saleCount());
        assert_same(0, $fx->saleItemCount());
        assert_same(2000000000, $fx->stockOf($item), 'stock untouched');
    });
});

test('a line whose price x quantity would exceed the money ceiling is rejected before it can overflow', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        // 50,000.00 x 300,000 = 15,000,000,000.00 -- well past DECIMAL(10,2) / MAX_CENTAVOS.
        $pricey = $fx->product('Pricey Rig', '50000.00', 500000);

        assert_throws(
            fn () => (new SaleService($pdo))->checkout([
                'admin_id' => $adminId,
                'lines'    => [['item_id' => $pricey, 'quantity' => 300000]],
            ]),
            SaleException::class,
            'larger than this system can record'
        );
        assert_same(0, $fx->saleCount());
        assert_same(500000, $fx->stockOf($pricey), 'stock untouched');
    });
});

test('a multi-line sale whose running total would exceed the money ceiling is rejected', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        // Each line is fine on its own (60,000,000.00) but two together pass MAX_CENTAVOS (99,999,999.99).
        $a = $fx->product('Big Ticket A', '99999999.99', 10);
        $b = $fx->product('Big Ticket B', '99999999.99', 10);

        assert_throws(
            fn () => (new SaleService($pdo))->checkout([
                'admin_id' => $adminId,
                'lines'    => [
                    ['item_id' => $a, 'quantity' => 1],
                    ['item_id' => $b, 'quantity' => 1],
                ],
            ]),
            SaleException::class,
            'sale total is larger'
        );
        assert_same(0, $fx->saleCount());
        assert_same(10, $fx->stockOf($a), 'stock untouched');
        assert_same(10, $fx->stockOf($b));
    });
});

test('SaleService rejects a sale linked to a non-existent customer', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Part', '10.00', 5);

        assert_throws(
            fn () => (new SaleService($pdo))->checkout([
                'admin_id'    => $adminId,
                'customer_id' => 999999999,
                'lines'       => [['item_id' => $item, 'quantity' => 1]],
            ]),
            SaleException::class
        );
        assert_same(0, $fx->saleCount());
        assert_same(5, $fx->stockOf($item));
    });
});

test('SaleService merges duplicate item lines and validates the combined quantity against stock', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Bundled Part', '30.00', 10);

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $item, 'quantity' => 2],
                ['item_id' => $item, 'quantity' => 3],
            ],
        ]);

        $lines = (new SaleRepository($pdo))->listSaleItems($result['sale_id']);
        assert_count(1, $lines, 'duplicates collapsed to one line');
        assert_same(5, $lines[0]['quantity']);
        assert_same(15000, $result['total_centavos']);
        assert_same(5, $fx->stockOf($item), '10 - (2 + 3), deducted once');
    });
});

// --- stock / overselling -------------------------------------------------

test('insufficient product stock rejects the whole sale; a prior sale correctly lowers the ceiling', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $part = $fx->product('Scarce Part', '40.00', 3);
        $freebie = $fx->service('Advice', '0.00');
        $service = new SaleService($pdo);

        // Asking for more than exists rejects everything, including the service line.
        assert_throws(
            fn () => $service->checkout([
                'admin_id' => $adminId,
                'lines'    => [
                    ['item_id' => $part, 'quantity' => 4],
                    ['item_id' => $freebie, 'quantity' => 1],
                ],
            ]),
            SaleException::class,
            null,
            'over-stock request rejected'
        );
        assert_same(0, $fx->saleCount(), 'no partial sale');
        assert_same(3, $fx->stockOf($part), 'stock untouched');

        // Sell 2, leaving 1.
        $service->checkout(['admin_id' => $adminId, 'lines' => [['item_id' => $part, 'quantity' => 2]]]);
        assert_same(1, $fx->stockOf($part));

        // Now 2 is too many.
        assert_throws(
            fn () => $service->checkout(['admin_id' => $adminId, 'lines' => [['item_id' => $part, 'quantity' => 2]]]),
            SaleException::class
        );
        assert_same(1, $fx->stockOf($part));

        // Exactly 1 succeeds and drives stock to 0 (never negative).
        $service->checkout(['admin_id' => $adminId, 'lines' => [['item_id' => $part, 'quantity' => 1]]]);
        assert_same(0, $fx->stockOf($part));
    });
});

test('ItemRepository::decrementStock is a guarded, non-negative, product-only update', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $repo = new ItemRepository($pdo);
        $product = $fx->product('Guarded Part', '10.00', 5);
        $service = $fx->service('Guarded Service', '10.00');

        assert_false($repo->decrementStock($product, 6), 'cannot go negative');
        assert_same(5, $fx->stockOf($product), 'a refused decrement changes nothing');

        assert_true($repo->decrementStock($product, 5));
        assert_same(0, $fx->stockOf($product));

        assert_false($repo->decrementStock($service, 1), 'services are never decremented');
        assert_false($repo->decrementStock(999999999, 1), 'unknown item');
    });
});

test('lockForUpdate takes a row lock a second connection cannot bypass (FOR UPDATE)', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $item = $fx->product('Locked Part', '10.00', 5);

        $cfg = $GLOBALS['vulcatrack_config']['db'];
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
        $other = new \PDO($dsn, $cfg['user'], $cfg['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->beginTransaction();
        try {
            (new ItemRepository($pdo))->lockForUpdate($item);   // hold the lock

            $other->beginTransaction();
            assert_throws(
                fn () => $other->query("SELECT stock_quantity FROM items WHERE item_id = " . (int) $item . " FOR UPDATE NOWAIT"),
                \PDOException::class,
                null,
                'the row is locked by the first transaction'
            );
            $other->rollBack();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $other = null;
        }
    });
});

// --- atomicity / rollback ------------------------------------------------

test('a failure inserting the sale header rolls everything back', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Part', '10.00', 5);

        $sales = new FailingSaleRepository($pdo);
        $sales->throwOnCreateSale = true;

        assert_throws(
            fn () => (new SaleService($pdo, $sales))->checkout([
                'admin_id' => $adminId,
                'lines'    => [['item_id' => $item, 'quantity' => 1]],
            ]),
            \RuntimeException::class
        );
        assert_false($pdo->inTransaction(), 'the transaction was closed');
        assert_same(0, $fx->saleCount(), 'no sales row');
        assert_same(0, $fx->saleItemCount());
        assert_same(5, $fx->stockOf($item), 'stock unchanged');
    });
});

test('a sale recorded against a since-deleted admin is rejected cleanly, not as a raw PDO error', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Part', '10.00', 5);
        // Simulate the admin row vanishing after the session was established.
        $pdo->exec('DELETE FROM admins WHERE admin_id = ' . (int) $adminId);

        assert_throws(
            fn () => (new SaleService($pdo))->checkout([
                'admin_id' => $adminId,
                'lines'    => [['item_id' => $item, 'quantity' => 1]],
            ]),
            SaleException::class,
            'no longer valid'
        );
        assert_false($pdo->inTransaction());
        assert_same(0, $fx->saleCount(), 'no sale written');
        assert_same(5, $fx->stockOf($item), 'stock unchanged');
    });
});

test('a failure inserting a sale_items line rolls back the sale, the lines and the stock', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $a = $fx->product('Part A', '10.00', 5);
        $b = $fx->product('Part B', '20.00', 5);

        $sales = new FailingSaleRepository($pdo);
        $sales->throwOnAddSaleItemCall = 2;   // second line blows up

        assert_throws(
            fn () => (new SaleService($pdo, $sales))->checkout([
                'admin_id' => $adminId,
                'lines'    => [
                    ['item_id' => $a, 'quantity' => 1],
                    ['item_id' => $b, 'quantity' => 1],
                ],
            ]),
            \RuntimeException::class
        );

        assert_false($pdo->inTransaction());
        assert_same(0, $fx->saleCount(), 'failed sale leaves no sales row');
        assert_same(0, $fx->saleItemCount(), 'failed sale leaves no sale_items');
        assert_same(5, $fx->stockOf($a), 'stock unchanged');
        assert_same(5, $fx->stockOf($b));
    });
});

test('a failure during stock deduction restores an earlier line already deducted', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $a = $fx->product('Part A', '10.00', 5);
        $b = $fx->product('Part B', '20.00', 5);

        $items = new FailingItemRepository($pdo);
        $items->throwOnDecrementCall = 2;   // first product deducts, second throws

        assert_throws(
            fn () => (new SaleService($pdo, null, $items))->checkout([
                'admin_id' => $adminId,
                'lines'    => [
                    ['item_id' => $a, 'quantity' => 2],
                    ['item_id' => $b, 'quantity' => 1],
                ],
            ]),
            \RuntimeException::class
        );

        assert_false($pdo->inTransaction());
        assert_same(0, $fx->saleCount());
        assert_same(0, $fx->saleItemCount());
        assert_same(5, $fx->stockOf($a), 'the earlier deduction was rolled back');
        assert_same(5, $fx->stockOf($b));
    });
});

test('checkout refuses to run inside a caller-opened transaction', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $item = $fx->product('Part', '10.00', 5);

        $pdo->beginTransaction();
        try {
            assert_throws(
                fn () => (new SaleService($pdo))->checkout([
                    'admin_id' => $adminId,
                    'lines'    => [['item_id' => $item, 'quantity' => 1]],
                ]),
                SaleException::class,
                'owns the checkout transaction'
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    });
});

// --- receipt read path (Decision 49/50) ---------------------------------

test('SaleRepository receipt reads expose everything the printable receipt needs', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin('Jamie Cruz');
        $tube = $fx->product('Inner Tube', '250.00', 10);
        $patch = $fx->service('Patching', '120.00');

        $sale = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [
                ['item_id' => $tube, 'quantity' => 2],
                ['item_id' => $patch, 'quantity' => 1],
            ],
        ]);

        $header = (new SaleRepository($pdo))->findSaleForReceipt($sale['sale_id']);
        assert_same('Jamie Cruz', $header['admin_name']);
        assert_null($header['customer_name'], 'prints as "Walk-in"');
        assert_same(62000, $header['total_amount_centavos']);
        assert_not_null($header['sale_date']);

        $lines = (new SaleRepository($pdo))->listSaleItems($sale['sale_id']);
        assert_same('Inner Tube', $lines[0]['item_name']);
        assert_same(2, $lines[0]['quantity']);
        assert_same(25000, $lines[0]['unit_price_centavos']);
        assert_same(50000, $lines[0]['subtotal_centavos']);
        assert_same('Patching', $lines[1]['item_name']);
        assert_same('service', $lines[1]['item_type']);
    });
});
