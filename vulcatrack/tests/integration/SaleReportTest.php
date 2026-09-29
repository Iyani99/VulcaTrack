<?php
/**
 * Integration tests for the Sales Reports reads on SaleRepository —
 * summarize(), listDailyTotals(), listItemTotals(), summarizeBySource(). Each test runs inside a
 * transaction that is rolled back, so the database is left untouched.
 *
 * Sales are seeded through createSale() / addSaleItem() with fixed 2001 dates
 * and every report call is limited to a 2001 range, so live development sales
 * and today's date never affect the results.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;

/**
 * Seed the July 2001 report fixture and return the item ids.
 *
 *   2001-07-01 09:00:00  Valve x3 (136.50) + Patch x1 (150.00) = 286.50
 *   2001-07-01 23:59:59  Patch x2 (300.00)                     = 300.00
 *   2001-07-02 00:00:00  Tire x1 (3000.00)                     = 3000.00
 *   2001-07-03 10:00:00  Valve x1 (45.50) + Patch x1 (150.00)  = 195.50
 *
 * @return array{valve: int, patch: int, tire: int}
 */
function report_fixture(\PDO $pdo): array
{
    $adminId = (new AdminRepository($pdo))->create('Report Cashier', TestDb::email('r'), Password::hash('password123'));
    $items = new ItemRepository($pdo);
    $valve = $items->create('Report Valve', 'product', null, 4550, 100, null);
    $patch = $items->create('Report Patch', 'service', null, 15000, null, null);
    $tire  = $items->create('Report Tire', 'product', null, 300000, 10, null);

    $repo = new SaleRepository($pdo);
    $sale = function (string $date, array $lines) use ($repo, $adminId): void {
        $total = 0;
        foreach ($lines as [, $qty, $price]) {
            $total += $qty * $price;
        }
        $saleId = $repo->createSale($adminId, null, $date, $total);
        foreach ($lines as [$itemId, $qty, $price]) {
            $repo->addSaleItem($saleId, $itemId, $qty, $price, $qty * $price);
        }
    };
    $sale('2001-07-01 09:00:00', [[$valve, 3, 4550], [$patch, 1, 15000]]);
    $sale('2001-07-01 23:59:59', [[$patch, 2, 15000]]);
    $sale('2001-07-02 00:00:00', [[$tire, 1, 300000]]);
    $sale('2001-07-03 10:00:00', [[$valve, 1, 4550], [$patch, 1, 15000]]);

    return ['valve' => $valve, 'patch' => $patch, 'tire' => $tire];
}

test('SaleRepository::summarize counts sales and sums the stored totals; an empty range is 0 / 0', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        report_fixture($pdo);
        $repo = new SaleRepository($pdo);

        assert_same(['count' => 4, 'total_centavos' => 378200], $repo->summarize('2001-07-01', '2001-07-31'));
        assert_same(['count' => 2, 'total_centavos' => 58650], $repo->summarize('2001-07-01', '2001-07-01'), 'From = To is one day');
        assert_same(['count' => 0, 'total_centavos' => 0], $repo->summarize('2001-01-01', '2001-01-31'), 'empty range');
    });
});

test('SaleRepository::listDailyTotals groups by calendar day of sale_date, newest day first', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        report_fixture($pdo);
        $repo = new SaleRepository($pdo);

        assert_same([
            ['day' => '2001-07-03', 'transaction_count' => 1, 'total_centavos' => 19550],
            ['day' => '2001-07-02', 'transaction_count' => 1, 'total_centavos' => 300000],
            ['day' => '2001-07-01', 'transaction_count' => 2, 'total_centavos' => 58650],
        ], $repo->listDailyTotals('2001-07-01', '2001-07-31'),
            'two sales on 07-01 (incl. 23:59:59) group together; 07-02 00:00:00 is the next day');

        assert_same([['day' => '2001-07-02', 'transaction_count' => 1, 'total_centavos' => 300000]],
            $repo->listDailyTotals('2001-07-02', '2001-07-02'), 'From = To keeps only that day');
        assert_same([], $repo->listDailyTotals('2001-01-01', '2001-01-31'), 'no rows for an empty range');
    });
});

test('SaleRepository::listItemTotals sums frozen quantities and subtotals per item, whatever the item is now', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = report_fixture($pdo);
        $repo = new SaleRepository($pdo);

        $expected = [
            // Patch and Valve both sold 4 — the higher revenue ranks first.
            ['item_id' => $ids['patch'], 'item_name' => 'Report Patch', 'quantity' => 4, 'revenue_centavos' => 60000],
            ['item_id' => $ids['valve'], 'item_name' => 'Report Valve', 'quantity' => 4, 'revenue_centavos' => 18200],
            ['item_id' => $ids['tire'],  'item_name' => 'Report Tire',  'quantity' => 1, 'revenue_centavos' => 300000],
        ];
        assert_same($expected, $repo->listItemTotals('2001-07-01', '2001-07-31'));

        // Later catalog changes: new price, new name, deactivated.
        $items = new ItemRepository($pdo);
        $items->update($ids['valve'], 'Report Valve (renamed)', 'product', null, 99999, 100, null);
        $items->setActive($ids['valve'], false);
        $pdo->prepare('UPDATE items SET price = ? WHERE item_id = ?')->execute(['175.00', $ids['patch']]);

        $expected[1]['item_name'] = 'Report Valve (renamed)';
        assert_same($expected, $repo->listItemTotals('2001-07-01', '2001-07-31'),
            'revenue stays the frozen subtotal; a renamed, inactive item is still one row (current name)');

        assert_same([
            ['item_id' => $ids['patch'], 'item_name' => 'Report Patch', 'quantity' => 1, 'revenue_centavos' => 15000],
            ['item_id' => $ids['valve'], 'item_name' => 'Report Valve (renamed)', 'quantity' => 1, 'revenue_centavos' => 4550],
        ], $repo->listItemTotals('2001-07-03', '2001-07-03'), 'From = To');
        assert_same([], $repo->listItemTotals('2001-01-01', '2001-01-31'), 'no rows for an empty range');
    });
});

test('SaleRepository::listItemTotals breaks a full tie on item_id', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('Tie Cashier', TestDb::email('r'), Password::hash('password123'));
        $items = new ItemRepository($pdo);
        $first  = $items->create('Tie Cap A', 'product', null, 1000, 10, null);
        $second = $items->create('Tie Cap B', 'product', null, 1000, 10, null);
        $repo = new SaleRepository($pdo);
        $saleId = $repo->createSale($adminId, null, '2001-08-01 12:00:00', 2000);
        $repo->addSaleItem($saleId, $second, 1, 1000, 1000);
        $repo->addSaleItem($saleId, $first, 1, 1000, 1000);

        $ids = array_column($repo->listItemTotals('2001-08-01', '2001-08-01'), 'item_id');
        assert_same([$first, $second], $ids, 'same quantity and revenue: lower item_id first');
    });
});

test('Sales Reports figures agree: summary total = sum of daily totals = sum of item revenue', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        report_fixture($pdo);
        $repo = new SaleRepository($pdo);

        foreach ([['2001-07-01', '2001-07-31'], ['2001-07-01', '2001-07-01'], ['2001-07-02', '2001-07-03'], ['2001-06-01', '2001-07-02']] as [$from, $to]) {
            $label   = "{$from}..{$to}";
            $summary = $repo->summarize($from, $to);
            $daily   = $repo->listDailyTotals($from, $to);
            $items   = $repo->listItemTotals($from, $to);
            assert_true($summary['count'] > 0, "the fixture has sales in {$label}");

            assert_same($summary['total_centavos'], array_sum(array_column($daily, 'total_centavos')), "daily totals add up {$label}");
            assert_same($summary['count'], array_sum(array_column($daily, 'transaction_count')), "daily counts add up {$label}");
            assert_same($summary['total_centavos'], array_sum(array_column($items, 'revenue_centavos')), "item revenue adds up {$label}");
        }
    });
});

test('SaleRepository::summarizeBySource splits In-shop (no Rescue) from Rescue sales; one-source, empty and ₱0 ranges', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        report_fixture($pdo); // four July 2001 in-shop sales, 3782.00 in all
        $adminId = (new AdminRepository($pdo))->create('Source Cashier', TestDb::email('r'), Password::hash('password123'));
        $custId  = (new CustomerRepository($pdo))->create('Source Customer', TestDb::email('c'), '0917', Password::hash('password123'));
        $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'SRC-1')")->execute([$custId]);
        $vehicleId = (int) $pdo->lastInsertId();
        $rescue = function () use ($pdo, $custId, $vehicleId): int {
            $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'flat', 'completed')")
                ->execute([$custId, $vehicleId]);
            return (int) $pdo->lastInsertId();
        };
        $repo = new SaleRepository($pdo);
        $repo->createSale($adminId, $custId, '2001-07-05 11:00:00', 120000, $rescue()); // Rescue, July
        $repo->createSale($adminId, $custId, '2001-08-10 11:00:00', 50000, $rescue());  // Rescue only, August
        $repo->createSale($adminId, $custId, '2001-09-01 08:00:00', 0);                  // a ₱0 in-shop sale (a free service), registered customer

        assert_same([
            'in_shop' => ['count' => 4, 'total_centavos' => 378200],
            'rescue'  => ['count' => 1, 'total_centavos' => 120000],
        ], $repo->summarizeBySource('2001-07-01', '2001-07-31'), 'both sources');
        assert_same([
            'in_shop' => ['count' => 0, 'total_centavos' => 0],
            'rescue'  => ['count' => 1, 'total_centavos' => 50000],
        ], $repo->summarizeBySource('2001-08-01', '2001-08-31'), 'only Rescue sales: in_shop is still present as 0 / 0');
        assert_same([
            'in_shop' => ['count' => 1, 'total_centavos' => 0],
            'rescue'  => ['count' => 0, 'total_centavos' => 0],
        ], $repo->summarizeBySource('2001-09-01', '2001-09-30'),
            'a ₱0 sale still counts; a registered customer alone does not make a sale "Rescue"');
        assert_same([
            'in_shop' => ['count' => 0, 'total_centavos' => 0],
            'rescue'  => ['count' => 0, 'total_centavos' => 0],
        ], $repo->summarizeBySource('2001-01-01', '2001-01-31'), 'empty range');

        // the split always adds up to the page's summary figures
        foreach ([['2001-07-01', '2001-07-31'], ['2001-07-01', '2001-09-30'], ['2001-08-01', '2001-08-31']] as [$from, $to]) {
            $by  = $repo->summarizeBySource($from, $to);
            $sum = $repo->summarize($from, $to);
            assert_same($sum['count'], $by['in_shop']['count'] + $by['rescue']['count'], "counts add up {$from}..{$to}");
            assert_same($sum['total_centavos'], $by['in_shop']['total_centavos'] + $by['rescue']['total_centavos'], "totals add up {$from}..{$to}");
        }
    });
});

test('SaleRepository report reads reject malformed dates like listForHistory', function () {
    $repo = new SaleRepository(test_pdo());
    foreach (['summarize', 'listDailyTotals', 'listItemTotals', 'summarizeBySource'] as $method) {
        foreach (['2026-02-31', "2026-09-28' OR '1'='1", '2026-9-8'] as $bad) {
            assert_throws(fn () => $repo->$method($bad, null), \InvalidArgumentException::class, null, "{$method}: malformed from");
            assert_throws(fn () => $repo->$method(null, $bad), \InvalidArgumentException::class, null, "{$method}: malformed to");
        }
    }
});
