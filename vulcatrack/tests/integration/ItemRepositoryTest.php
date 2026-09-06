<?php
/**
 * Integration tests for VulcaTrack\Repository\ItemRepository against the live
 * `items` table. Every test runs inside a transaction that is rolled back, so
 * the database is left untouched.
 *
 * Locks in the Phase 5 foundation the Inventory UI and POS will build on:
 * the unified product/service model, integer-centavo prices, the
 * product-only stock split, soft delete, category retrieval and low-stock
 * detection.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Support\Money;

test('ItemRepository creates a product with stock + reorder and reads it back', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Tire Patch Kit', 'product', 'Supplies', Money::toCentavos('149.50'), 25, 5);
        assert_true($id > 0);

        $row = $repo->findById($id);
        assert_not_null($row);
        assert_same('Tire Patch Kit', $row['item_name']);
        assert_same('product', $row['item_type']);
        assert_same('Supplies', $row['category']);
        assert_same('149.50', $row['price']);            // raw DB decimal string
        assert_same(14950, $row['price_centavos']);      // added for arithmetic
        assert_same(25, $row['stock_quantity']);
        assert_same(5, $row['reorder_level']);
        assert_same(1, $row['is_active']);
        assert_not_null($row['created_at']);
        assert_not_null($row['updated_at']);
    });
});

test('ItemRepository creates a service with stock and reorder forced to NULL', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        // Pass numbers on purpose — a service must still store NULL / NULL.
        $id = $repo->create('Vulcanizing Service', 'service', 'Labor', Money::toCentavos('80'), 999, 10);

        $row = $repo->findById($id);
        assert_same('service', $row['item_type']);
        assert_null($row['stock_quantity'], 'a service never carries stock');
        assert_null($row['reorder_level'], 'a service never carries a reorder level');
        assert_same(8000, $row['price_centavos']);
    });
});

test('ItemRepository rejects a product without a usable stock quantity', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        assert_throws(
            fn () => $repo->create('No Stock Product', 'product', null, Money::toCentavos('10'), null, null),
            \InvalidArgumentException::class
        );
        assert_throws(
            fn () => $repo->create('Neg Stock Product', 'product', null, Money::toCentavos('10'), -1, null),
            \InvalidArgumentException::class
        );
        assert_throws(
            fn () => $repo->create('Neg Price Product', 'product', null, -100, 5, null),
            \InvalidArgumentException::class
        );
    });
});

test('ItemRepository rejects an item_type the schema does not allow', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        assert_throws(
            fn () => $repo->create('Weird', 'gadget', null, 100, null, null),
            \InvalidArgumentException::class
        );
    });
});

test('ItemRepository::update changes fields, bumps updated_at, and keeps the type split', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Old Name', 'product', 'Old Cat', Money::toCentavos('10.00'), 3, null);
        $before = $repo->findById($id);

        // Nudge the clock so a CURRENT_TIMESTAMP change is observable.
        $pdo->prepare('UPDATE items SET updated_at = :t WHERE item_id = :id')
            ->execute([':t' => '2000-01-01 00:00:00', ':id' => $id]);

        $repo->update($id, 'New Name', 'product', 'New Cat', Money::toCentavos('12.75'), 8, 2);
        $after = $repo->findById($id);

        assert_same('New Name', $after['item_name']);
        assert_same('New Cat', $after['category']);
        assert_same(1275, $after['price_centavos']);
        assert_same(8, $after['stock_quantity']);
        assert_same(2, $after['reorder_level']);
        assert_true($after['updated_at'] > '2000-01-01 00:00:00', 'updated_at is refreshed on update');
        assert_same($before['created_at'], $after['created_at'], 'created_at is untouched');

        // Convert the same product to a service — stock fields must clear.
        $repo->update($id, 'New Name', 'service', 'New Cat', Money::toCentavos('12.75'), 8, 2);
        $svc = $repo->findById($id);
        assert_null($svc['stock_quantity']);
        assert_null($svc['reorder_level']);
    });
});

test('ItemRepository::setActive soft-deletes and restores without losing the row', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $id = $repo->create('Toggle Me', 'product', null, 500, 1, null);

        $repo->setActive($id, false);
        assert_same(0, $repo->findById($id)['is_active'], 'row still exists, just inactive');

        $repo->setActive($id, true);
        assert_same(1, $repo->findById($id)['is_active']);
    });
});

test('ItemRepository::list filters by search, type and active flag', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $tag = 'ZZ' . substr(bin2hex(random_bytes(4)), 0, 8); // unique marker for this run

        $p1 = $repo->create("{$tag} Valve", 'product', 'Parts', 2500, 10, null);
        $p2 = $repo->create("{$tag} Inactive Part", 'product', 'Parts', 3000, 10, null);
        $s1 = $repo->create("{$tag} Balancing", 'service', 'Labor', 15000, null, null);
        $repo->setActive($p2, false);

        $mine = fn (array $rows) => array_values(array_filter(
            $rows,
            fn ($r) => strpos($r['item_name'], $tag) === 0
        ));

        $all = $mine($repo->list(['search' => $tag]));
        assert_count(3, $all);

        $products = $mine($repo->list(['search' => $tag, 'type' => 'product']));
        assert_count(2, $products);

        $services = $mine($repo->list(['search' => $tag, 'type' => 'service']));
        assert_count(1, $services);
        assert_same($s1, (int) $services[0]['item_id']);

        $activeOnly = $mine($repo->list(['search' => $tag, 'active' => true]));
        assert_count(2, $activeOnly);

        $inactiveOnly = $mine($repo->list(['search' => $tag, 'active' => false]));
        assert_count(1, $inactiveOnly);
        assert_same($p2, (int) $inactiveOnly[0]['item_id']);

        // active-first ordering
        $names = array_map(fn ($r) => $r['item_name'], $all);
        assert_same("{$tag} Inactive Part", end($names), 'the inactive row sorts last');
    });
});

test('ItemRepository::distinctCategories returns non-empty categories in use', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $catA = 'Cat_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $catB = 'Cat_' . substr(bin2hex(random_bytes(4)), 0, 6);

        $repo->create('Item A', 'product', $catA, 100, 1, null);
        $repo->create('Item B', 'product', $catB, 100, 1, null);
        $repo->create('Item C', 'service', '', 100, null, null);   // blank -> stored NULL
        $repo->create('Item D', 'service', null, 100, null, null);

        $cats = $repo->distinctCategories();
        assert_true(in_array($catA, $cats, true));
        assert_true(in_array($catB, $cats, true));
        assert_false(in_array('', $cats, true), 'blank categories are excluded');
    });
});

test('ItemRepository::lowStockProducts flags only active products at or below reorder level', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        $tag = 'LS' . substr(bin2hex(random_bytes(4)), 0, 8);

        $low      = $repo->create("{$tag} Low", 'product', null, 100, 2, 5);   // 2 <= 5  -> low
        $atLevel  = $repo->create("{$tag} At", 'product', null, 100, 5, 5);    // 5 <= 5  -> low
        $ok       = $repo->create("{$tag} OK", 'product', null, 100, 20, 5);   // 20 > 5  -> not
        $noThresh = $repo->create("{$tag} NoThresh", 'product', null, 100, 0, null); // no reorder_level -> not
        $svc      = $repo->create("{$tag} Svc", 'service', null, 100, null, null);   // service -> not
        $inactive = $repo->create("{$tag} Inactive", 'product', null, 100, 0, 5);
        $repo->setActive($inactive, false);                                          // inactive -> not

        $flagged = array_values(array_filter(
            $repo->lowStockProducts(),
            fn ($r) => strpos($r['item_name'], $tag) === 0
        ));
        $ids = array_map(fn ($r) => (int) $r['item_id'], $flagged);
        sort($ids);
        $expected = [$low, $atLevel];
        sort($expected);
        assert_same($expected, $ids);
    });
});

test('ItemRepository::findById returns null for an unknown id', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        assert_null((new ItemRepository($pdo))->findById(0));
        assert_null((new ItemRepository($pdo))->findById(999999999));
    });
});
