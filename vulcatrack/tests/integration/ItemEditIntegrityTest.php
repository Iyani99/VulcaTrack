<?php
/**
 * Integration tests for the Phase 7.1 inventory edit guards in
 * ItemRepository::update() / hasSales():
 *
 * - an item with recorded sales keeps its item_type (unsold items may still
 *   switch between product and service);
 * - an edit carrying a stale expected type / stock is refused, so it cannot
 *   overwrite stock a sale has changed meanwhile;
 * - a save that matches but changes nothing is NOT mistaken for a stale one
 *   (MySQL reports 0 affected rows for it).
 *
 * Each test runs inside a transaction that is rolled back.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

/** Record one sale line for $itemId so the item "has sales". */
function integrity_sell(\PDO $pdo, int $itemId, int $quantity = 1): void
{
    $adminId = (new AdminRepository($pdo))->create('Integrity Cashier', TestDb::email('ie'), Password::hash('password123'));
    $sales = new SaleRepository($pdo);
    $saleId = $sales->createSale($adminId, null, '2001-09-01 10:00:00', 1000 * $quantity);
    $sales->addSaleItem($saleId, $itemId, $quantity, 1000, 1000 * $quantity);
}

test('ItemRepository::update lets an UNSOLD item switch between product and service', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Unsold Thing', 'product', null, 1000, 5, 2);
        assert_false($repo->hasSales($id));

        assert_true($repo->update($id, 'Unsold Thing', 'service', null, 1000, 5, 2,
            ['item_type' => 'product', 'stock_quantity' => 5]), 'product -> service is allowed while unsold');
        $row = $repo->findById($id);
        assert_same('service', $row['item_type']);
        assert_null($row['stock_quantity']);

        assert_true($repo->update($id, 'Unsold Thing', 'product', null, 1000, 3, null,
            ['item_type' => 'service', 'stock_quantity' => null]), 'service -> product is allowed with a stock value');
        $row = $repo->findById($id);
        assert_same('product', $row['item_type']);
        assert_same(3, $row['stock_quantity']);
    });
});

test('ItemRepository::update refuses a type change once the item has recorded sales', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $product = $repo->create('Sold Valve', 'product', 'Parts', 4550, 10, 3);
        $service = $repo->create('Sold Patch', 'service', 'Labor', 15000, null, null);
        integrity_sell($pdo, $product);
        integrity_sell($pdo, $service);
        assert_true($repo->hasSales($product));

        assert_throws(fn () => $repo->update($product, 'Sold Valve', 'service', 'Parts', 4550, 10, 3,
            ['item_type' => 'product', 'stock_quantity' => 10]),
            \InvalidArgumentException::class, 'cannot be changed after the item has recorded sales', 'sold product -> service');
        $row = $repo->findById($product);
        assert_same('product', $row['item_type'], 'the type is unchanged');
        assert_same(10, $row['stock_quantity'], 'the stock was not wiped');
        assert_same(3, $row['reorder_level']);

        assert_throws(fn () => $repo->update($service, 'Sold Patch', 'product', 'Labor', 15000, 5, null),
            \InvalidArgumentException::class, 'cannot be changed', 'sold service -> product (no expected state either)');
        assert_same('service', $repo->findById($service)['item_type']);
    });
});

test('ItemRepository::update still saves other fields on a sold item', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Sold Tube', 'product', 'Parts', 25000, 10, null);
        integrity_sell($pdo, $id);

        assert_true($repo->update($id, 'Sold Tube (renamed)', 'product', 'Tubes', Money::toCentavos('275.00'), 14, 4,
            ['item_type' => 'product', 'stock_quantity' => 10]));
        $row = $repo->findById($id);
        assert_same('Sold Tube (renamed)', $row['item_name']);
        assert_same('Tubes', $row['category']);
        assert_same(27500, $row['price_centavos']);
        assert_same(14, $row['stock_quantity'], 'a deliberate stock correction is still possible');
        assert_same(4, $row['reorder_level']);
        assert_same('product', $row['item_type']);
    });
});

test('ItemRepository::update refuses a stale edit so a sale\'s newer stock is kept', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Busy Valve', 'product', 'Parts', 4550, 10, null);
        $loaded = ['item_type' => 'product', 'stock_quantity' => 10];   // the edit form opens at stock 10

        // Meanwhile the POS sells 2 (same guarded decrement SaleService uses).
        assert_true($repo->decrementStock($id, 2));
        assert_same(8, $repo->findById($id)['stock_quantity']);

        // The stale form changes only the price but still carries stock 10.
        assert_false($repo->update($id, 'Busy Valve', 'product', 'Parts', 5000, 10, null, $loaded), 'stale edit refused');
        $row = $repo->findById($id);
        assert_same(8, $row['stock_quantity'], 'the sale\'s newer stock is not overwritten');
        assert_same(4550, $row['price_centavos'], 'nothing from the stale form was written');

        // A stale type is refused the same way.
        assert_false($repo->update($id, 'Busy Valve', 'product', 'Parts', 5000, 8, null,
            ['item_type' => 'service', 'stock_quantity' => 8]));

        // Reloaded (expected 8) the same edit goes through.
        assert_true($repo->update($id, 'Busy Valve', 'product', 'Parts', 5000, 8, null,
            ['item_type' => 'product', 'stock_quantity' => 8]), 'a fresh edit succeeds');
        assert_same(5000, $repo->findById($id)['price_centavos']);

        assert_false($repo->update(999999999, 'Ghost', 'product', null, 100, 1, null,
            ['item_type' => 'product', 'stock_quantity' => 1]), 'an unknown item is not "saved"');
    });
});

test('ItemRepository::update treats a matched save with no changes as success, not stale', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Steady Cap', 'product', 'Parts', 1500, 6, 2);
        $svc = $repo->create('Steady Service', 'service', null, 9000, null, null);

        // Pin the session clock so CURRENT_TIMESTAMP cannot move, and make the
        // row already hold it: an identical save then changes 0 rows.
        $pdo->exec('SET timestamp = 978307200');
        try {
            $pdo->exec('UPDATE items SET updated_at = CURRENT_TIMESTAMP WHERE item_id IN (' . $id . ', ' . $svc . ')');
            $probe = $pdo->prepare('UPDATE items SET item_name = item_name, updated_at = CURRENT_TIMESTAMP WHERE item_id = ?');
            $probe->execute([$id]);
            assert_same(0, $probe->rowCount(), 'precondition: MySQL reports 0 affected rows for an unchanged row');

            assert_true($repo->update($id, 'Steady Cap', 'product', 'Parts', 1500, 6, 2,
                ['item_type' => 'product', 'stock_quantity' => 6]), 'identical product save is a success');
            assert_true($repo->update($svc, 'Steady Service', 'service', '  ', 9000, null, null,
                ['item_type' => 'service', 'stock_quantity' => null]), 'identical service save (blank category = NULL) is a success');
            assert_true($repo->update($id, 'Steady Cap', 'product', 'Parts', 1500, 6, 2), 'also without expected state');

            // …while a stale identical-looking save is still refused.
            assert_false($repo->update($id, 'Steady Cap', 'product', 'Parts', 1600, 6, 2,
                ['item_type' => 'product', 'stock_quantity' => 7]));
        } finally {
            $pdo->exec('SET timestamp = DEFAULT');
        }
        assert_same(1500, $repo->findById($id)['price_centavos']);
    });
});
