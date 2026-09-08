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
