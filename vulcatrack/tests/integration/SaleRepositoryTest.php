<?php
/**
 * Integration tests for VulcaTrack\Repository\SaleRepository against the live
 * `sales` / `sale_items` tables. Each test runs inside a transaction that is
 * rolled back, so the database is left untouched.
 *
 * These cover the repository in isolation: it persists and reads sale rows and
 * nothing else. Transaction ownership, pricing, totals, stock and atomicity are
 * SaleService's job and are covered by SaleServiceTest.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

test('SaleRepository::createSale stores admin, walk-in customer, server date and total', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('Cashier', TestDb::email('a'), Password::hash('password123'));
        $repo = new SaleRepository($pdo);

        $saleId = $repo->createSale($adminId, null, '2026-09-08 14:30:00', 12550);
        assert_true($saleId > 0);

        $row = $repo->findSaleForReceipt($saleId);
        assert_not_null($row);
        assert_null($row['customer_id'], 'walk-in sale stores NULL customer_id');
        assert_null($row['customer_name'], 'no linked customer name for a walk-in');
        assert_same('Cashier', $row['admin_name']);
        assert_same('2026-09-08 14:30:00', $row['sale_date']);
        assert_same('125.50', $row['total_amount'], 'raw DB decimal string');
        assert_same(12550, $row['total_amount_centavos']);
    });
});

test('SaleRepository::createSale links an existing customer when given one', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('A', TestDb::email('a'), Password::hash('password123'));
        $custId  = (new CustomerRepository($pdo))->create('Reg Customer', TestDb::email('c'), '0917', Password::hash('password123'));

        $saleId = (new SaleRepository($pdo))->createSale($adminId, $custId, '2026-09-08 09:00:00', 5000);
        $row = (new SaleRepository($pdo))->findSaleForReceipt($saleId);

        assert_same($custId, (int) $row['customer_id']);
        assert_same('Reg Customer', $row['customer_name']);
    });
});

test('SaleRepository::createSale stores the Rescue link when given one (NULL by default); lockSaleIdForServiceRequest finds it', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('A', TestDb::email('a'), Password::hash('password123'));
        $custId  = (new CustomerRepository($pdo))->create('Rescue Customer', TestDb::email('c'), '0917', Password::hash('password123'));
        $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'SR-1')")->execute([$custId]);
        $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'flat', 'accepted')")
            ->execute([$custId, (int) $pdo->lastInsertId()]);
        $requestId = (int) $pdo->lastInsertId();
        $repo = new SaleRepository($pdo);

        assert_null($repo->lockSaleIdForServiceRequest($requestId), 'no sale for the request yet');

        $ordinary = $repo->createSale($adminId, null, '2026-09-28 09:00:00', 1000);   // existing 4-argument call
        $linked   = $repo->createSale($adminId, $custId, '2026-09-28 10:00:00', 2000, $requestId);
        $link = fn (int $saleId) => $pdo->query('SELECT service_request_id FROM sales WHERE sale_id = ' . $saleId)->fetchColumn();
        assert_null($link($ordinary), 'an ordinary sale is not linked');
        assert_same($requestId, (int) $link($linked), 'the Rescue sale stores its request');

        assert_same($linked, $repo->lockSaleIdForServiceRequest($requestId));
        assert_null($repo->lockSaleIdForServiceRequest(2147483646), 'unknown request');
    });
});

test('SaleRepository::findForServiceRequest returns the linked sale for the Rescue panel; history / receipt reads expose the link', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('Panel Cashier', TestDb::email('a'), Password::hash('password123'));
        $custId  = (new CustomerRepository($pdo))->create('Panel Customer', TestDb::email('c'), '0917', Password::hash('password123'));
        $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'SR-2')")->execute([$custId]);
        $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'flat', 'completed')")
            ->execute([$custId, (int) $pdo->lastInsertId()]);
        $requestId = (int) $pdo->lastInsertId();
        $repo = new SaleRepository($pdo);

        assert_null($repo->findForServiceRequest($requestId), 'no sale yet');
        $saleId = $repo->createSale($adminId, $custId, '2001-02-03 04:05:06', 31050, $requestId);
        $plain  = $repo->createSale($adminId, null, '2001-02-03 05:00:00', 100);

        assert_same([
            'sale_id' => $saleId, 'sale_date' => '2001-02-03 04:05:06', 'total_amount' => '310.50',
            'total_amount_centavos' => 31050, 'admin_name' => 'Panel Cashier',
        ], $repo->findForServiceRequest($requestId));

        assert_same($requestId, (int) $repo->findSaleForReceipt($saleId)['service_request_id']);
        assert_null($repo->findSaleForReceipt($plain)['service_request_id']);
        $hist = [];
        foreach ($repo->listForHistory('2001-02-03', '2001-02-03') as $row) {
            $hist[(int) $row['sale_id']] = $row['service_request_id'];
        }
        assert_same($requestId, (int) $hist[$saleId], 'Sales History can derive "Rescue #N"');
        assert_null($hist[$plain], 'and "In-shop" for an unlinked sale');
    });
});

test('SaleRepository::addSaleItem freezes unit price and stores the passed subtotal', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $adminId = (new AdminRepository($pdo))->create('A', TestDb::email('a'), Password::hash('password123'));
        $items = new ItemRepository($pdo);
        $productId = $items->create('Inner Tube', 'product', 'Parts', Money::toCentavos('250.00'), 10, null);
        $serviceId = $items->create('Patching', 'service', 'Labor', Money::toCentavos('120.00'), null, null);

        $sales = new SaleRepository($pdo);
        $saleId = $sales->createSale($adminId, null, '2026-09-08 10:00:00', Money::toCentavos('620.00'));
        $sales->addSaleItem($saleId, $productId, 2, Money::toCentavos('250.00'), Money::toCentavos('500.00'));
        $sales->addSaleItem($saleId, $serviceId, 1, Money::toCentavos('120.00'), Money::toCentavos('120.00'));

        // The current item price moves after the sale...
        $items->update($productId, 'Inner Tube', 'product', 'Parts', Money::toCentavos('999.00'), 10, null);

        // ...the recorded line is unchanged.
        $lines = $sales->listSaleItems($saleId);
        assert_count(2, $lines);
        assert_same('Inner Tube', $lines[0]['item_name']);
        assert_same(2, $lines[0]['quantity']);
        assert_same('250.00', $lines[0]['unit_price'], 'frozen unit price ignores the later items.price change');
        assert_same(25000, $lines[0]['unit_price_centavos']);
        assert_same(50000, $lines[0]['subtotal_centavos']);
        assert_same('service', $lines[1]['item_type']);
    });
});

test('SaleRepository::findSaleForReceipt returns null for an unknown sale', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        assert_null((new SaleRepository($pdo))->findSaleForReceipt(999999999));
        assert_count(0, (new SaleRepository($pdo))->listSaleItems(999999999));
    });
});
