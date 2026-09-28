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
 *
 * Phase 7.3d-b — Rescue sales (sales.service_request_id): eligible statuses
 * (accepted / completed), the Rescue's customer is authoritative, one sale per
 * Rescue (friendly pre-check + the UNIQUE key, and only that key's duplicate is
 * translated), rollback of a linked sale, the request row is never modified,
 * the request-row lock, the current-read pre-check, and reject-vs-sale races.
 * Rescue rows are seeded by the fixture and deleted after their linked sales
 * (the FK is ON DELETE RESTRICT).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Service\SaleException;
use VulcaTrack\Service\SaleService;
use VulcaTrack\Support\Money;

/** Seeds throwaway rows for one test and tears them all down afterwards. */
final class SalesFixture
{
    /** @var array<int,int> */ public array $adminIds = [];
    /** @var array<int,int> */ public array $customerIds = [];
    /** @var array<int,int> */ public array $itemIds = [];
    /** @var array<int,int> */ public array $requestIds = [];
    /** @var array<int,int> */ public array $vehicleIds = [];
    /** @var array<int,int> */ public array $tiremanIds = [];

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

    public function tireman(): int
    {
        $this->pdo->prepare('INSERT INTO tiremen (name, contact_number) VALUES (?, ?)')->execute(['Fixture Tireman', '0918 000 0000']);
        $id = (int) $this->pdo->lastInsertId();
        $this->tiremanIds[] = $id;
        return $id;
    }

    /**
     * A Rescue request for $customerId (on a fresh vehicle) in $status, last
     * handled by $handlerAdminId, with a fixed old updated_at so any later
     * change to the row is observable.
     */
    public function rescue(int $customerId, string $status, ?int $handlerAdminId = null, ?int $tiremanId = null): int
    {
        $this->pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?, ?)')
            ->execute([$customerId, 'SFX-' . (count($this->vehicleIds) + 1)]);
        $vehicleId = (int) $this->pdo->lastInsertId();
        $this->vehicleIds[] = $vehicleId;

        $this->pdo->prepare(
            "INSERT INTO service_requests
                 (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
             VALUES (?, ?, ?, ?, 'Fixture flat tire', 14.95, 120.89, 12, ?, '2001-01-01 00:00:00')"
        )->execute([$customerId, $vehicleId, $handlerAdminId, $tiremanId, $status]);
        $id = (int) $this->pdo->lastInsertId();
        $this->requestIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> the raw service_requests row */
    public function rescueRow(int $requestId): array
    {
        return $this->pdo->query('SELECT * FROM service_requests WHERE request_id = ' . $requestId)->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> ids of the sales linked to a request */
    public function salesLinkedTo(int $requestId): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT sale_id FROM sales WHERE service_request_id = ' . $requestId
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> the raw sales row */
    public function saleRow(int $saleId): array
    {
        return $this->pdo->query('SELECT * FROM sales WHERE sale_id = ' . $saleId)->fetch(\PDO::FETCH_ASSOC);
    }

    /** Sales recorded by this fixture's admins, plus any sale linked to its Rescue requests. */
    private function saleIdsForThisFixture(): array
    {
        $where = [];
        if ($this->adminIds !== []) {
            $where[] = 'admin_id IN (' . implode(',', array_map('intval', $this->adminIds)) . ')';
        }
        if ($this->requestIds !== []) {
            $where[] = 'service_request_id IN (' . implode(',', array_map('intval', $this->requestIds)) . ')';
        }
        if ($where === []) {
            return [];
        }
        return array_map('intval', $this->pdo->query('SELECT sale_id FROM sales WHERE ' . implode(' OR ', $where))->fetchAll(\PDO::FETCH_COLUMN));
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
        // sales.service_request_id is ON DELETE RESTRICT: linked sales go first (above).
        $this->deleteByIds('service_requests', 'request_id', $this->requestIds);
        $this->deleteByIds('vehicles', 'vehicle_id', $this->vehicleIds);
        $this->deleteByIds('tiremen', 'tireman_id', $this->tiremanIds);
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

    public function createSale(int $adminId, ?int $customerId, string $saleDate, int $totalCentavos, ?int $serviceRequestId = null): int
    {
        if ($this->throwOnCreateSale) {
            throw new \RuntimeException('injected sales header insertion failure');
        }
        return parent::createSale($adminId, $customerId, $saleDate, $totalCentavos, $serviceRequestId);
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

/**
 * SaleRepository whose "already has a sale?" pre-check always answers "no" —
 * simulates losing the race to another admin, so the INSERT meets the real
 * UNIQUE key (uq_sales_service_request) in the database.
 */
final class BlindPrecheckSaleRepository extends SaleRepository
{
    public function lockSaleIdForServiceRequest(int $serviceRequestId): ?int
    {
        return null;
    }
}

/** SaleRepository whose createSale() fails with a chosen duplicate-key (1062) PDOException. */
final class DuplicateKeySaleRepository extends SaleRepository
{
    public function __construct(\PDO $pdo, private string $keyName)
    {
        parent::__construct($pdo);
    }

    public function createSale(int $adminId, ?int $customerId, string $saleDate, int $totalCentavos, ?int $serviceRequestId = null): int
    {
        $message = "Duplicate entry '42' for key '{$this->keyName}'";
        $e = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 {$message}");
        $e->errorInfo = ['23000', 1062, $message];
        throw $e;
    }
}

/** ItemRepository that runs a probe just before each item row is locked (lock-order checks). */
final class LockProbeItemRepository extends ItemRepository
{
    public ?\Closure $beforeLock = null;

    public function lockForUpdate(int $itemId): ?array
    {
        if ($this->beforeLock !== null) {
            ($this->beforeLock)($itemId);
        }
        return parent::lockForUpdate($itemId);
    }
}

/** SaleRepository that runs a probe just before the "already has a sale?" check (lock-order checks). */
final class LockProbeSaleRepository extends SaleRepository
{
    public ?\Closure $beforePrecheck = null;

    public function lockSaleIdForServiceRequest(int $serviceRequestId): ?int
    {
        if ($this->beforePrecheck !== null) {
            ($this->beforePrecheck)($serviceRequestId);
        }
        return parent::lockSaleIdForServiceRequest($serviceRequestId);
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

// --- stale-display guard: expected_total_centavos (POS UI) ------------------

test('expected_total_centavos that matches the authoritative total lets the sale commit', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $tube  = $fx->product('Guard Tube', '250.00', 5);
        $patch = $fx->service('Guard Patch', '120.50');

        $sale = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines'    => [['item_id' => $tube, 'quantity' => 2], ['item_id' => $patch, 'quantity' => 1]],
            'expected_total_centavos' => '62050',   // the POS posts it as a form string
        ]);
        assert_same(62050, $sale['total_centavos']);
        assert_same(3, $fx->stockOf($tube));
        assert_same(1, $fx->saleCount());
    });
});

test('a stale expected_total_centavos (price changed after display) rolls the whole sale back', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $tube = $fx->product('Stale Tube', '250.00', 5);
        $shownTotal = 50000;                       // 2 x 250.00 as the cashier saw it
        $fx->setPrice($tube, '275.00');            // price edited in Inventory meanwhile

        assert_throws(function () use ($pdo, $adminId, $tube, $shownTotal) {
            (new SaleService($pdo))->checkout([
                'admin_id' => $adminId,
                'lines'    => [['item_id' => $tube, 'quantity' => 2]],
                'expected_total_centavos' => $shownTotal,
            ]);
        }, SaleException::class, '550.00');

        assert_same(0, $fx->saleCount(), 'no sales row');
        assert_same(0, $fx->saleItemCount(), 'no sale_items rows');
        assert_same(5, $fx->stockOf($tube), 'stock untouched');
        assert_false($pdo->inTransaction(), 'transaction closed');
    });
});

test('a malformed expected_total_centavos is rejected before anything is written', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $tube = $fx->product('Malformed Tube', '10.00', 5);
        foreach (['-1', '12.50', 'abc', 10.5, Money::MAX_CENTAVOS + 1] as $bad) {
            assert_throws(function () use ($pdo, $adminId, $tube, $bad) {
                (new SaleService($pdo))->checkout([
                    'admin_id' => $adminId,
                    'lines'    => [['item_id' => $tube, 'quantity' => 1]],
                    'expected_total_centavos' => $bad,
                ]);
            }, SaleException::class, 'expected_total_centavos');
        }
        assert_same(0, $fx->saleCount());
        assert_same(5, $fx->stockOf($tube));
    });
});

// --- Rescue sales: sales.service_request_id (Phase 7.3d-b) -------------------

/** A second raw connection to the same database (autocommit), for concurrency checks. */
function sale_test_other_connection(): \PDO
{
    $cfg = $GLOBALS['vulcatrack_config']['db'];
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
    return new \PDO($dsn, $cfg['user'], $cfg['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
}

test('ordinary sales (walk-in or registered customer) still store service_request_id = NULL; stock rules unchanged', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $custId = $fx->customer();
        $tube  = $fx->product('Ordinary Tube', '250.00', 10);
        $patch = $fx->service('Ordinary Patch', '120.00');
        $lines = [['item_id' => $tube, 'quantity' => 1], ['item_id' => $patch, 'quantity' => 1]];

        $walkIn = (new SaleService($pdo))->checkout(['admin_id' => $adminId, 'lines' => $lines]);
        $linked = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId, 'customer_id' => $custId, 'lines' => $lines,
            'service_request_id' => '',                     // blank = no Rescue, like an absent key
        ]);

        $w = $fx->saleRow($walkIn['sale_id']);
        assert_null($w['customer_id'], 'walk-in');
        assert_null($w['service_request_id'], 'an ordinary walk-in sale is not linked to a Rescue');
        $l = $fx->saleRow($linked['sale_id']);
        assert_same($custId, (int) $l['customer_id'], 'registered customer');
        assert_null($l['service_request_id'], 'an ordinary registered-customer sale is not linked to a Rescue');
        assert_same(8, $fx->stockOf($tube), 'product stock deducted once per sale');
        assert_null($fx->stockOf($patch), 'service stock never touched');
    });
});

test('a Rescue sale for an ACCEPTED request is linked, recorded for the Rescue customer, with normal stock rules', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin('Rescue Cashier');
        $handler = $fx->admin('Rescue Handler');
        $custId  = $fx->customer('Rescue Customer');
        $rid     = $fx->rescue($custId, 'accepted', $handler, $fx->tireman());
        $tube    = $fx->product('Rescue Tube', '250.00', 5);
        $labor   = $fx->service('Rescue Vulcanizing', '150.00');

        $result = (new SaleService($pdo))->checkout([
            'admin_id'                => $cashier,
            'customer_id'             => (string) $custId,     // the POS posts form strings
            'lines'                   => [['item_id' => $tube, 'quantity' => 1], ['item_id' => $labor, 'quantity' => 1]],
            'expected_total_centavos' => 40000,
            'service_request_id'      => (string) $rid,
        ]);

        $sale = $fx->saleRow($result['sale_id']);
        assert_same($rid, (int) $sale['service_request_id'], 'the sale is linked to the Rescue');
        assert_same($custId, (int) $sale['customer_id'], "the Rescue's customer is recorded");
        assert_same($cashier, (int) $sale['admin_id'], 'recorded by the cashier, not by the Rescue handler');
        assert_same('400.00', $sale['total_amount']);
        assert_same([$result['sale_id']], $fx->salesLinkedTo($rid));
        assert_same(4, $fx->stockOf($tube), 'the product used on the Rescue is deducted');
        assert_null($fx->stockOf($labor), 'the Rescue service is not');
    });
});

test('recording a Rescue sale never changes the request: status, handling admin, Tireman and updated_at stay as they were', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin('Cashier');
        $handler = $fx->admin('Handler');
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $handler, $fx->tireman());
        $labor   = $fx->service('Independent Labor', '100.00');
        $before  = $fx->rescueRow($rid);

        (new SaleService($pdo))->checkout([
            'admin_id' => $cashier, 'customer_id' => $custId,
            'lines' => [['item_id' => $labor, 'quantity' => 1]], 'service_request_id' => $rid,
        ]);

        $after = $fx->rescueRow($rid);
        assert_same('accepted', $after['status'], 'recording a sale does not complete the Rescue');
        assert_same($handler, (int) $after['admin_id'], 'admin_id stays the last admin who changed status/assignment');
        assert_same('2001-01-01 00:00:00', $after['updated_at'], 'updated_at untouched');
        assert_same($before, $after, 'the whole request row is unchanged');
    });
});

test('a COMPLETED request can still have its sale recorded late, and stays completed', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $handler = $fx->admin('Closer');
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'completed', $handler, $fx->tireman());
        $labor   = $fx->service('Late Labor', '180.00');
        $before  = $fx->rescueRow($rid);

        $result = (new SaleService($pdo))->checkout([
            'admin_id' => $cashier, 'customer_id' => $custId,
            'lines' => [['item_id' => $labor, 'quantity' => 1]], 'service_request_id' => $rid,
        ]);

        assert_same($rid, (int) $fx->saleRow($result['sale_id'])['service_request_id']);
        assert_same($before, $fx->rescueRow($rid), 'still completed, nothing on the request changed');
    });
});

test('a sale is refused for a pending, rejected, unknown or malformed Rescue request; nothing is written', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $handler = $fx->admin('Handler');
        $custId  = $fx->customer();
        $tube    = $fx->product('Refused Tube', '250.00', 5);
        $sell = fn ($rid) => (new SaleService($pdo))->checkout([
            'admin_id' => $cashier, 'customer_id' => $custId,
            'lines' => [['item_id' => $tube, 'quantity' => 1]], 'service_request_id' => $rid,
        ]);

        foreach (['pending' => null, 'rejected' => $handler] as $status => $admin) {
            $rid = $fx->rescue($custId, $status, $admin);
            $before = $fx->rescueRow($rid);
            assert_throws(fn () => $sell($rid), SaleException::class, "request #{$rid} is {$status}", "a {$status} request");
            assert_same($before, $fx->rescueRow($rid), "the {$status} request is untouched");
            assert_same([], $fx->salesLinkedTo($rid));
        }

        assert_throws(fn () => $sell(2147483646), SaleException::class, 'was not found', 'an unknown request id');
        foreach (['abc', '0', '-3', '1.5', 2.0] as $bad) {
            assert_throws(fn () => $sell($bad), SaleException::class, 'service_request_id must be', 'malformed id ' . var_export($bad, true));
        }

        assert_false($pdo->inTransaction());
        assert_same(0, $fx->saleCount(), 'no sale was recorded');
        assert_same(0, $fx->saleItemCount());
        assert_same(5, $fx->stockOf($tube), 'no stock was deducted');
    });
});

test("a Rescue sale must be for the Rescue's customer: walk-in or another customer is refused, never swapped", function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $owner   = $fx->customer('Rescue Owner');
        $other   = $fx->customer('Someone Else');
        $rid     = $fx->rescue($owner, 'accepted', $fx->admin('Handler'));
        $tube    = $fx->product('Customer Tube', '250.00', 5);
        $sell = fn ($customerId) => (new SaleService($pdo))->checkout([
            'admin_id' => $cashier, 'customer_id' => $customerId,
            'lines' => [['item_id' => $tube, 'quantity' => 1]], 'service_request_id' => $rid,
        ]);

        assert_throws(fn () => $sell(null), SaleException::class, 'cannot be recorded as a walk-in', 'walk-in (null)');
        assert_throws(fn () => $sell(''), SaleException::class, 'cannot be recorded as a walk-in', 'walk-in (blank)');
        assert_throws(fn () => $sell($other), SaleException::class, 'is not the customer of Rescue request', 'another customer');
        assert_same(0, $fx->saleCount(), 'a mismatched customer records nothing');
        assert_same(5, $fx->stockOf($tube));
        assert_same([], $fx->salesLinkedTo($rid));

        $ok = $sell($owner);
        assert_same($owner, (int) $fx->saleRow($ok['sale_id'])['customer_id'], 'the matching customer succeeds');
    });
});

test('a second sale for the same Rescue is refused with a clear message (friendly pre-check)', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $fx->admin('Handler'));
        $tube    = $fx->product('Once Tube', '250.00', 5);
        $sell = fn () => (new SaleService($pdo))->checkout([
            'admin_id' => $cashier, 'customer_id' => $custId,
            'lines' => [['item_id' => $tube, 'quantity' => 1]], 'service_request_id' => $rid,
        ]);

        $first = $sell();
        assert_throws($sell, SaleException::class, "This Rescue already has a recorded sale (Sale #{$first['sale_id']}).");
        assert_same([$first['sale_id']], $fx->salesLinkedTo($rid), 'still exactly one sale for the Rescue');
        assert_same(1, $fx->saleItemCount(), 'the refused attempt added no lines');
        assert_same(4, $fx->stockOf($tube), 'stock deducted once, by the first sale only');
    });
});

test('if the pre-check misses (lost race), the UNIQUE key refuses the second sale and it is reported as the same clear error', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $fx->admin('Handler'));
        $tube    = $fx->product('Race Tube', '250.00', 5);
        $request = [
            'admin_id' => $cashier, 'customer_id' => $custId,
            'lines' => [['item_id' => $tube, 'quantity' => 1]], 'service_request_id' => $rid,
        ];
        $first = (new SaleService($pdo))->checkout($request);

        $caught = null;
        try {
            (new SaleService($pdo, new BlindPrecheckSaleRepository($pdo)))->checkout($request);
        } catch (SaleException $e) {
            $caught = $e;
        }
        assert_not_null($caught, 'the database refused the duplicate and it surfaced as a SaleException');
        assert_same('This Rescue already has a recorded sale.', $caught->getMessage());
        $db = $caught->getPrevious();
        assert_true($db instanceof \PDOException, 'the real database error is kept as the previous exception');
        assert_same(1062, (int) $db->errorInfo[1], 'duplicate-key error from MariaDB');
        assert_contains("'uq_sales_service_request'", (string) $db->errorInfo[2], 'on the Rescue link key');

        assert_false($pdo->inTransaction(), 'rolled back');
        assert_same([$first['sale_id']], $fx->salesLinkedTo($rid));
        assert_same(1, $fx->saleItemCount(), 'no lines from the refused attempt');
        assert_same(4, $fx->stockOf($tube), 'the refused attempt deducted nothing');
    });
});

test('only a duplicate on uq_sales_service_request becomes the Rescue message; any other duplicate-key error is left alone', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $fx->admin('Handler'));
        $labor   = $fx->service('Key Labor', '100.00');
        $sell = function (string $key, ?int $requestId) use ($pdo, $cashier, $custId, $labor) {
            (new SaleService($pdo, new DuplicateKeySaleRepository($pdo, $key)))->checkout([
                'admin_id' => $cashier, 'customer_id' => $custId,
                'lines' => [['item_id' => $labor, 'quantity' => 1]], 'service_request_id' => $requestId,
            ]);
        };

        assert_throws(fn () => $sell('uq_sales_service_request', $rid), SaleException::class, 'already has a recorded sale', 'MariaDB key name');
        assert_throws(fn () => $sell('sales.uq_sales_service_request', $rid), SaleException::class, 'already has a recorded sale', 'MySQL 8 key name (table-qualified)');

        foreach (['PRIMARY', 'uq_sales_service_request_old', 'uq_other'] as $otherKey) {
            assert_throws(fn () => $sell($otherKey, $rid), \PDOException::class, $otherKey, "a duplicate on '{$otherKey}' stays a database error");
        }
        // An ordinary sale (no Rescue) is never reported as "this Rescue already has a sale".
        assert_throws(fn () => $sell('uq_sales_service_request', null), \PDOException::class, null, 'no Rescue context: not translated');

        assert_false($pdo->inTransaction());
        assert_same(0, $fx->saleCount());
    });
});

test('a failure after the linked sale row is written rolls back the sale, its lines, the stock and the Rescue link', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $fx->admin('Handler'), $fx->tireman());
        $tube    = $fx->product('Rollback Tube', '250.00', 5);
        $labor   = $fx->service('Rollback Labor', '150.00');
        $before  = $fx->rescueRow($rid);
        $request = [
            'admin_id' => $cashier, 'customer_id' => $custId, 'service_request_id' => $rid,
            'lines' => [['item_id' => $tube, 'quantity' => 2], ['item_id' => $labor, 'quantity' => 1]],
        ];

        $sales = new FailingSaleRepository($pdo);
        $sales->throwOnAddSaleItemCall = 2;   // the header (with the link) and line 1 are written, line 2 fails
        assert_throws(fn () => (new SaleService($pdo, $sales))->checkout($request), \RuntimeException::class);

        assert_false($pdo->inTransaction());
        assert_same([], $fx->salesLinkedTo($rid), 'no sale linked to the Rescue survives');
        assert_same(0, $fx->saleCount(), 'no sales row');
        assert_same(0, $fx->saleItemCount(), 'no sale_items');
        assert_same(5, $fx->stockOf($tube), 'stock unchanged');
        assert_same($before, $fx->rescueRow($rid), 'the request itself was never modified');

        // Nothing was left "taken": the Rescue can still get its one sale.
        $retry = (new SaleService($pdo))->checkout($request);
        assert_same([$retry['sale_id']], $fx->salesLinkedTo($rid));
        assert_same(3, $fx->stockOf($tube));
    });
});

test('ServiceRequestRepository::lockForUpdate returns id / customer / status and holds the row until the transaction ends', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $custId = $fx->customer();
        $rid = $fx->rescue($custId, 'accepted', $fx->admin());
        $repo = new ServiceRequestRepository($pdo);
        $other = sale_test_other_connection();

        $pdo->beginTransaction();
        try {
            assert_same(['request_id' => $rid, 'customer_id' => $custId, 'status' => 'accepted'], $repo->lockForUpdate($rid));
            assert_null($repo->lockForUpdate(2147483646), 'unknown request');

            $other->beginTransaction();
            assert_throws(
                fn () => $other->query('SELECT status FROM service_requests WHERE request_id = ' . $rid . ' FOR UPDATE NOWAIT'),
                \PDOException::class,
                null,
                'the request row is locked by the Rescue-sale transaction'
            );
            $other->rollBack();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $other = null;
        }
        assert_same('2001-01-01 00:00:00', $fx->rescueRow($rid)['updated_at'], 'locking is read-only');
    });
});

test('Rescue-sale lock order: the request row is locked before any item row, and the items before the "already has a sale" check', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $fx->admin('Handler'));
        $tube    = $fx->product('Order Tube', '250.00', 5);
        $labor   = $fx->service('Order Labor', '150.00');
        $other   = sale_test_other_connection();

        // Is this row locked by the checkout right now? (NOWAIT fails at once with 1205 if so.)
        $locked = function (string $table, string $pk, int $id) use ($other): bool {
            $other->beginTransaction();
            try {
                $other->query("SELECT {$pk} FROM {$table} WHERE {$pk} = {$id} FOR UPDATE NOWAIT")->fetchAll();
                return false;
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
                    throw $e;
                }
                return true;
            } finally {
                $other->rollBack();
            }
        };

        $seen  = [];
        $items = new LockProbeItemRepository($pdo);
        $items->beforeLock = function (int $itemId) use (&$seen, $locked, $rid) {
            $seen[] = "item {$itemId}: request locked=" . var_export($locked('service_requests', 'request_id', $rid), true);
        };
        $sales = new LockProbeSaleRepository($pdo);
        $sales->beforePrecheck = function () use (&$seen, $locked, $tube, $labor) {
            $seen[] = 'pre-check: items locked=' . var_export($locked('items', 'item_id', $tube) && $locked('items', 'item_id', $labor), true);
        };

        (new SaleService($pdo, $sales, $items))->checkout([
            'admin_id' => $cashier, 'customer_id' => $custId, 'service_request_id' => $rid,
            'lines' => [['item_id' => $tube, 'quantity' => 1], ['item_id' => $labor, 'quantity' => 1]],
        ]);
        $other = null;

        assert_same([
            "item {$tube}: request locked=true",
            "item {$labor}: request locked=true",
            'pre-check: items locked=true',
        ], $seen, 'request row -> item rows (ascending) -> sales pre-check');
    });
});

test('the "already has a sale" pre-check is a current read: it sees a sale another connection committed after the snapshot', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $adminId = $fx->admin();
        $custId = $fx->customer();
        $rid = $fx->rescue($custId, 'accepted', $adminId);
        $other = sale_test_other_connection();

        assert_same('REPEATABLE-READ', (string) $pdo->query('SELECT @@SESSION.tx_isolation')->fetchColumn(),
            'precondition: plain reads inside a transaction use one snapshot');
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();   // the transaction's snapshot is taken here

            $other->prepare("INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount)
                             VALUES (?, ?, ?, '2001-01-01 10:00:00', '1.00')")->execute([$custId, $rid, $adminId]);
            $committedId = (int) $other->lastInsertId();

            $plain = $pdo->query('SELECT sale_id FROM sales WHERE service_request_id = ' . $rid)->fetchColumn();
            assert_false($plain, 'a plain SELECT still sees the old snapshot (this is why it is not used)');
            assert_same($committedId, (new SaleRepository($pdo))->lockSaleIdForServiceRequest($rid),
                'the locking read sees the sale committed a moment ago');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $other = null;
        }
    });
});

test('a reject arriving while a Rescue sale is being recorded waits for it, then is refused; completion still works', function () {
    with_sales_fixture(function (\PDO $pdo, SalesFixture $fx) {
        $cashier = $fx->admin();
        $handler = $fx->admin('Handler');
        $custId  = $fx->customer();
        $rid     = $fx->rescue($custId, 'accepted', $handler, $fx->tireman());
        $other   = sale_test_other_connection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $otherRequests = new ServiceRequestRepository($other);

        // What SaleService does mid-checkout: request row locked, linked sale row written, not yet committed.
        $pdo->beginTransaction();
        try {
            (new ServiceRequestRepository($pdo))->lockForUpdate($rid);
            (new SaleRepository($pdo))->createSale($cashier, $custId, '2001-01-01 10:00:00', 100, $rid);

            assert_throws(fn () => $otherRequests->reject($rid, 'accepted', $handler), \PDOException::class, 'Lock wait timeout',
                'the reject cannot slip in while the sale is being recorded');
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        assert_false($otherRequests->reject($rid, 'accepted', $handler), 'once the sale is committed, reject is refused');
        assert_same('accepted', $fx->rescueRow($rid)['status']);
        assert_true($otherRequests->complete($rid, $handler), 'a linked sale does not block completion');
        assert_same('completed', $fx->rescueRow($rid)['status']);
        $other = null;
    });
});
