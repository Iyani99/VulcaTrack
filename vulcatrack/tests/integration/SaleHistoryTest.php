<?php
/**
 * Integration tests for SaleRepository::listForHistory() / isValidDay() — the
 * read behind the admin Sales History page. Each test runs inside a transaction
 * that is rolled back, so the database is left untouched.
 *
 * Sales are seeded straight through createSale() with fixed 2001 dates, and the
 * results are narrowed to the admins seeded here, so live development sales
 * and today's date never affect the outcome.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\SaleRepository;

/** The sale ids in $rows that belong to $adminId, in the order returned. */
function history_ids(array $rows, int $adminId): array
{
    $ids = [];
    foreach ($rows as $row) {
        if ((int) $row['admin_id'] === $adminId) {
            $ids[] = (int) $row['sale_id'];
        }
    }
    return $ids;
}

test('SaleRepository::listForHistory orders newest first by sale_date, then sale_id', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('History Cashier', TestDb::email('h'), Password::hash('password123'));
        $repo = new SaleRepository($pdo);

        $a = $repo->createSale($adminId, null, '2001-03-10 09:00:00', 1000);
        $b = $repo->createSale($adminId, null, '2001-03-12 15:00:00', 2000);
        $c = $repo->createSale($adminId, null, '2001-03-12 15:00:00', 3000); // same timestamp, later id
        $d = $repo->createSale($adminId, null, '2001-03-11 00:00:00', 4000);

        $expected = [$c, $b, $d, $a];
        assert_same($expected, history_ids($repo->listForHistory(), $adminId), 'unfiltered: sale_date DESC, sale_id DESC');
        assert_same($expected, history_ids($repo->listForHistory('2001-03-01', '2001-03-31'), $adminId), 'filtered: same order');
    });
});

test('SaleRepository::listForHistory returns cashier, customer or walk-in, and the stored total', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('Cashier Jo', TestDb::email('h'), Password::hash('password123'));
        $custId  = (new CustomerRepository($pdo))->create('Reg Ana', TestDb::email('c'), '0917', Password::hash('password123'));
        $repo = new SaleRepository($pdo);

        $linked = $repo->createSale($adminId, $custId, '2001-04-01 10:00:00', 28650);
        $walkIn = $repo->createSale($adminId, null, '2001-04-01 11:00:00', 123405);

        $rows = [];
        foreach ($repo->listForHistory('2001-04-01', '2001-04-01') as $row) {
            $rows[(int) $row['sale_id']] = $row;
        }
        assert_true(isset($rows[$linked], $rows[$walkIn]), 'both seeded sales are listed');

        assert_same('Cashier Jo', $rows[$linked]['admin_name']);
        assert_same($custId, (int) $rows[$linked]['customer_id']);
        assert_same('Reg Ana', $rows[$linked]['customer_name']);
        assert_same('2001-04-01 10:00:00', $rows[$linked]['sale_date']);
        assert_same('286.50', $rows[$linked]['total_amount'], 'raw stored decimal');
        assert_same(28650, $rows[$linked]['total_amount_centavos']);

        assert_null($rows[$walkIn]['customer_id'], 'walk-in sale has no customer');
        assert_null($rows[$walkIn]['customer_name'], 'walk-in sale has no customer name (shown as "Walk-in")');
        assert_same('Cashier Jo', $rows[$walkIn]['admin_name']);
        assert_same(123405, $rows[$walkIn]['total_amount_centavos']);
    });
});

test('SaleRepository::listForHistory date filters are inclusive calendar days on sale_date', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('Boundary Cashier', TestDb::email('h'), Password::hash('password123'));
        $repo = new SaleRepository($pdo);

        $before   = $repo->createSale($adminId, null, '2001-06-09 23:59:59', 100);
        $dayStart = $repo->createSale($adminId, null, '2001-06-10 00:00:00', 100);
        $dayEnd   = $repo->createSale($adminId, null, '2001-06-10 23:59:59', 100);
        $nextDay  = $repo->createSale($adminId, null, '2001-06-11 00:00:00', 100);

        assert_same([$dayEnd, $dayStart], history_ids($repo->listForHistory('2001-06-10', '2001-06-10'), $adminId),
            'From = To is exactly one day: 00:00:00 and 23:59:59 in, the neighbours out');
        assert_same([$nextDay, $dayEnd, $dayStart], history_ids($repo->listForHistory('2001-06-10', null), $adminId),
            'From only: from 00:00:00 is included, the day before is not');
        assert_same([$dayEnd, $dayStart, $before], history_ids($repo->listForHistory(null, '2001-06-10'), $adminId),
            'To only: to 23:59:59 is included, to+1 00:00:00 is not');
        assert_same([], history_ids($repo->listForHistory('2001-06-11', '2001-06-10'), $adminId), 'From after To matches nothing');
        assert_same([$nextDay, $dayEnd, $dayStart, $before], history_ids($repo->listForHistory('1000-01-01', '9999-12-31'), $adminId),
            'the widest valid range still works (no day after 9999-12-31)');
    });
});

test('SaleRepository::isValidDay and listForHistory reject anything but a real YYYY-MM-DD day', function () {
    foreach (['2026-09-28', '2024-02-29', '1000-01-01', '9999-12-31'] as $ok) {
        assert_true(SaleRepository::isValidDay($ok), "{$ok} is a valid day");
    }
    $bad = ['', '2026-02-31', '2023-02-29', '2026-13-01', '2026-00-10', '2026-9-8', '20260928', '0999-12-31',
            '2026-09-28 00:00:00', ' 2026-09-28', "2026-09-28\n", "2026-09-28' OR '1'='1", '28/09/2026'];
    foreach ($bad as $value) {
        assert_false(SaleRepository::isValidDay($value), var_export($value, true) . ' is not a valid day');
    }

    $pdo = test_pdo();
    $repo = new SaleRepository($pdo);
    foreach (['2026-02-31', "2026-09-28' OR '1'='1", ''] as $value) {
        assert_throws(fn () => $repo->listForHistory($value, null), \InvalidArgumentException::class, null, 'malformed from date');
        assert_throws(fn () => $repo->listForHistory(null, $value), \InvalidArgumentException::class, null, 'malformed to date');
    }
});
