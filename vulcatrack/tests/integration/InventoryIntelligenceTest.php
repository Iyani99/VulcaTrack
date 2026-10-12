<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\InventorySettingsRepository;
use VulcaTrack\Repository\ItemRepository;

test('slow-moving Products use exact Sale and creation boundaries; Restock is context only', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $tag = 'INTEL' . bin2hex(random_bytes(3));
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute([$tag, TestDb::email('intel'), 'test-hash']);
        $adminId = (int) $pdo->lastInsertId();
        $mk = function (string $key, string $created, int $stock = 4, int $active = 1, string $type = 'product') use ($pdo, $tag): int {
            $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, is_active, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$tag . $key, $type, '10.00', $type === 'service' ? null : $stock, $active, $created]);
            return (int) $pdo->lastInsertId();
        };
        $old = '2026-07-01 12:00:00';
        $at = '2026-08-13 12:00:00';
        $before = '2026-08-13 11:59:59';
        $after = '2026-08-13 12:00:01';
        $asOf = '2026-10-12 12:00:00'; // 60-day default cutoff = $at
        $oldSale = $mk('oldSale', $old);
        $exactSale = $mk('exactSale', $old);
        $recentSale = $mk('recentSale', $old);
        $neverOld = $mk('neverOld', $at);
        $neverNew = $mk('neverNew', $after);
        $zero = $mk('zero', $old, 0);
        $archived = $mk('archived', $old, 4, 0);
        $service = $mk('service', $old, 0, 1, 'service');
        $reactivated = $mk('reactivated', $old, 3, 0);
        $pdo->prepare('UPDATE items SET is_active = 1 WHERE item_id = ?')->execute([$reactivated]);
        $sold = function (int $itemId, string $when) use ($pdo, $adminId): void {
            $pdo->prepare('INSERT INTO sales (admin_id, sale_date, total_amount) VALUES (?, ?, ?)')
                ->execute([$adminId, $when, '10.00']);
            $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
                ->execute([(int) $pdo->lastInsertId(), $itemId, 1, '10.00', '10.00']);
        };
        $sold($oldSale, $before);
        $sold($exactSale, $at);
        $sold($recentSale, $before);
        $sold($recentSale, $after);
        $sold($zero, $before);
        $sold($archived, $before);
        $pdo->prepare('INSERT INTO inventory_restocks (item_id, quantity, stock_before, stock_after, admin_id, restocked_at) VALUES (?,?,?,?,?,?)')
            ->execute([$oldSale, 1, 3, 4, $adminId, '2026-10-11 12:00:00']);

        $repo = new ItemRepository($pdo);
        $rows = $repo->slowMovingProducts($asOf);
        $byId = array_column($rows, null, 'item_id');
        assert_same([$reactivated, $neverOld, $oldSale], array_map('intval', array_keys($byId)), 'never sold sorts first by record date, then oldest recorded inactivity');
        assert_same($before, $byId[$oldSale]['last_sale_at']);
        assert_same('2026-10-11 12:00:00', $byId[$oldSale]['last_restock_at'], 'recent Restock does not reset qualification');
        assert_null($byId[$neverOld]['last_sale_at']);
        assert_null($byId[$neverOld]['last_restock_at'], 'missing history does not exclude the Product');
        foreach ([$exactSale, $recentSale, $neverNew, $zero, $archived, $service] as $excluded) {
            assert_false(isset($byId[$excluded]), 'recent, young, zero-stock, archived and Service records are excluded');
        }
        assert_true(isset(array_column($repo->slowMovingProducts('2026-10-12 12:00:01'), null, 'item_id')[$exactSale]), 'a Sale exactly one second before a later cutoff qualifies');
    });
});

test('inventory threshold accepts 1–365, records meaningful changes and ignores duplicates', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new InventorySettingsRepository($pdo);
        $initial = $repo->current();
        assert_same(60, $initial['slow_moving_days']);
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute(['Settings Admin', TestDb::email('setting'), 'test-hash']);
        $adminId = (int) $pdo->lastInsertId();
        assert_false($repo->updateSlowMovingDays(60, $adminId));
        assert_same($initial, $repo->current(), 'same value leaves attribution and timestamp untouched');
        foreach ([0, 366, -1] as $invalid) {
            assert_throws(fn () => $repo->updateSlowMovingDays($invalid, $adminId), \InvalidArgumentException::class);
        }
        assert_true($repo->updateSlowMovingDays(1, $adminId));
        assert_same(1, $repo->current()['slow_moving_days']);
        $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, created_at) VALUES (?,?,?,?,?)')
            ->execute(['Threshold fixture', 'product', '1.00', 1, '2026-08-01 12:00:00']);
        $itemId = (int) $pdo->lastInsertId();
        $items = new ItemRepository($pdo);
        $asOf = new \DateTimeImmutable('2026-10-12 12:00:00', new \DateTimeZone('Asia/Manila'));
        assert_true(isset(array_column($items->slowMovingProducts($asOf->format('Y-m-d H:i:s')), null, 'item_id')[$itemId]));
        assert_true($repo->updateSlowMovingDays(365, $adminId));
        assert_same($adminId, $repo->current()['updated_by_admin_id']);
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute(['Second Settings Admin', TestDb::email('setting2'), 'test-hash']);
        $secondAdminId = (int) $pdo->lastInsertId();
        assert_true($repo->updateSlowMovingDays(90, $secondAdminId), 'the later Admin write wins');
        assert_same($secondAdminId, $repo->current()['updated_by_admin_id']);
        assert_true($repo->updateSlowMovingDays(365, $adminId));
        assert_false(isset(array_column($items->slowMovingProducts($asOf->format('Y-m-d H:i:s')), null, 'item_id')[$itemId]));
        assert_throws(fn () => $repo->updateSlowMovingDays(60, 0), \InvalidArgumentException::class);
        $pdo->exec('DELETE FROM inventory_settings WHERE setting_id = 1');
        assert_throws(fn () => $repo->current(), \RuntimeException::class);
    });
});

test('an invalid persisted inventory threshold is unavailable', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $priorChecks = (int) $pdo->query('SELECT @@SESSION.check_constraint_checks')->fetchColumn();
        $pdo->exec('SET SESSION check_constraint_checks = OFF');
        try {
            $repo = new InventorySettingsRepository($pdo);
            foreach ([0, 366] as $invalid) {
                $pdo->prepare('UPDATE inventory_settings SET slow_moving_days = ? WHERE setting_id = 1')
                    ->execute([$invalid]);
                assert_throws(fn () => $repo->current(), \RuntimeException::class,
                    'Inventory settings threshold is invalid.');
            }
        } finally {
            $pdo->exec('SET SESSION check_constraint_checks = ' . $priorChecks);
        }
    });
});

test('Product overrides use their own Sale and never-sold windows while NULL follows the shop default', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute(['Override Admin', TestDb::email('override'), 'test-hash']);
        $adminId = (int) $pdo->lastInsertId();
        $items = new ItemRepository($pdo);
        $settings = new InventorySettingsRepository($pdo);
        $asOf = '2026-10-12 12:00:00';
        $nullSold = $items->create('Null sold', 'product', null, 100, 2, null);
        $shortSold = $items->create('Short sold', 'product', null, 100, 2, null, 30);
        $longSold = $items->create('Long sold', 'product', null, 100, 2, null, 90);
        $nullNever = $items->create('Null never', 'product', null, 100, 2, null);
        $shortNever = $items->create('Short never', 'product', null, 100, 2, null, 30);
        $longNever = $items->create('Long never', 'product', null, 100, 2, null, 90);
        $service = $items->create('No threshold service', 'service', null, 100, null, null);
        $pdo->prepare('UPDATE items SET created_at = ? WHERE item_id IN (?,?,?)')
            ->execute(['2026-08-20 12:00:00', $nullNever, $shortNever, $longNever]);
        foreach ([$nullSold, $shortSold, $longSold] as $id) {
            $pdo->prepare('INSERT INTO sales (admin_id, sale_date, total_amount) VALUES (?,?,?)')
                ->execute([$adminId, '2026-08-20 12:00:00', '1.00']);
            $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
                ->execute([(int) $pdo->lastInsertId(), $id, 1, '1.00', '1.00']);
        }
        $rows = array_column($items->slowMovingProducts($asOf), null, 'item_id');
        assert_false(isset($rows[$nullSold]));
        assert_false(isset($rows[$nullNever]));
        assert_true(isset($rows[$shortSold]));
        assert_true(isset($rows[$shortNever]));
        assert_same(30, $rows[$shortSold]['effective_days']);
        assert_same(30, $rows[$shortNever]['slow_moving_days_override']);
        assert_false(isset($rows[$longSold]));
        assert_false(isset($rows[$longNever]));
        assert_false(isset($rows[$service]));
        assert_null($items->findById($service)['slow_moving_days_override']);

        assert_true($settings->updateSlowMovingDays(20, $adminId));
        $rows = array_column($items->slowMovingProducts($asOf), null, 'item_id');
        assert_same(20, $rows[$nullSold]['effective_days']);
        assert_true(isset($rows[$nullNever]));
        assert_same(30, $rows[$shortSold]['effective_days'], 'explicit override does not follow the shop default');
        assert_false(isset($rows[$longSold]));
        $items->update($shortSold, 'Short sold', 'product', null, 100, 2, null, null, 1);
        assert_same(1, $items->findById($shortSold)['slow_moving_days_override']);
        $items->update($shortSold, 'Short sold', 'product', null, 100, 2, null, null, 365);
        assert_same(365, $items->findById($shortSold)['slow_moving_days_override']);
        foreach ([0, 366] as $bad) {
            assert_throws(fn () => $items->update($shortSold, 'Short sold', 'product', null, 100, 2, null, null, $bad), \InvalidArgumentException::class);
        }
        assert_throws(fn () => $items->create('Bad service', 'service', null, 100, null, null, 30), \InvalidArgumentException::class);
        assert_throws(fn () => $pdo->prepare('UPDATE items SET slow_moving_days_override = 0 WHERE item_id = ?')->execute([$shortSold]), \PDOException::class);
        assert_throws(fn () => $pdo->prepare('UPDATE items SET slow_moving_days_override = 366 WHERE item_id = ?')->execute([$shortSold]), \PDOException::class);
    });
});
