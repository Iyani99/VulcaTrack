<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\RestockRepository;
use VulcaTrack\Service\RestockService;
use VulcaTrack\Service\SaleService;

test('Product restocks commit stock and attributed receipts; invalid attempts leave both unchanged', function () {
    $pdo = test_pdo();
    $tag = 'RST' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute([$tag . ' Admin', TestDb::email('restock'), 'unused-test-hash']);
    $adminId = (int) $pdo->lastInsertId();
    $items = new ItemRepository($pdo);
    $product = $items->create($tag . ' Product', 'product', null, 100, 5, null);
    $service = $items->create($tag . ' Service', 'service', null, 100, null, null);
    $restock = new RestockService($pdo);
    $history = new RestockRepository($pdo);
    try {
        assert_same([], $history->forItem($product), 'opening stock has no invented receipts');
        $id = $restock->record($product, 3, '  Local supplier  ', $adminId, '  First receipt  ');
        assert_true($id > 0);
        assert_same(8, $items->findById($product)['stock_quantity']);
        $rows = $history->forItem($product);
        assert_count(1, $rows);
        assert_same($id, (int) $rows[0]['restock_id']);
        assert_same(3, (int) $rows[0]['quantity']);
        assert_same(5, (int) $rows[0]['stock_before']);
        assert_same(8, (int) $rows[0]['stock_after']);
        assert_same('Local supplier', $rows[0]['supplier_name']);
        assert_same('First receipt', $rows[0]['note']);
        assert_same($adminId, (int) $rows[0]['admin_id']);
        assert_same($tag . ' Admin', $rows[0]['admin_name']);
        assert_not_null($rows[0]['restocked_at']);

        $restock->record($product, 2, '', $adminId);
        assert_same(10, $items->findById($product)['stock_quantity']);
        assert_null($history->forItem($product)[0]['supplier_name']);
        assert_null($history->forItem($product)[0]['note']);
        assert_same(8, (int) $history->forItem($product)[0]['stock_before']);
        assert_same(10, (int) $history->forItem($product)[0]['stock_after']);
        assert_count(2, $history->forItem($product));

        $sale = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId,
            'lines' => [['item_id' => $product, 'quantity' => 2]],
        ]);
        assert_same(8, $items->findById($product)['stock_quantity']);
        assert_count(2, $history->forItem($product), 'sale writes no receipt');
        assert_same(10, (int) $history->forItem($product)[0]['stock_after'], 'historical stock remains the receipt-time value');

        foreach ([0, -1, 2147483648] as $bad) {
            assert_throws(fn () => $restock->record($product, $bad, null, $adminId), \InvalidArgumentException::class);
        }
        assert_throws(fn () => $restock->record($service, 1, null, $adminId), \InvalidArgumentException::class);
        assert_throws(fn () => $restock->record(999999999, 1, null, $adminId), \InvalidArgumentException::class);
        assert_throws(fn () => $restock->record($product, 1, str_repeat('x', 151), $adminId), \InvalidArgumentException::class);
        assert_throws(fn () => $restock->record($product, 1, null, $adminId, str_repeat('x', 501)), \InvalidArgumentException::class);
        assert_throws(fn () => $restock->record($product, 2147483647, null, $adminId), \InvalidArgumentException::class,
            'capacity', 'INT stock overflow is refused');
        assert_throws(fn () => $restock->record($product, 1, null, 999999999), \PDOException::class,
            '', 'invalid Admin FK fails after stock increment, then rolls back');
        assert_same(8, $items->findById($product)['stock_quantity'], 'failure rolled stock back');
        assert_count(2, $history->forItem($product), 'failure inserted no receipt');
        assert_same(10, (int) $history->forItem($product)[0]['stock_after']);

        $items->setActive($product, false);
        assert_throws(fn () => $restock->record($product, 1, null, $adminId), \InvalidArgumentException::class,
            'Reactivate', 'archived Product cannot receive stock or a receipt');
        assert_same(8, $items->findById($product)['stock_quantity']);
        assert_count(2, $history->forItem($product));
        $items->setActive($product, true);
        $restock->record($product, 1, null, $adminId);
        assert_same(9, $items->findById($product)['stock_quantity'], 'reactivated Product can restock');
        assert_count(3, $history->forItem($product));
        assert_same(8, (int) $history->forItem($product)[0]['stock_before']);
        assert_same(9, (int) $history->forItem($product)[0]['stock_after']);

        assert_throws(fn () => $items->update($product, $tag . ' Product', 'product', null, 100, 99, null),
            \InvalidArgumentException::class, 'cannot be changed', 'repository blocks direct replacement');
        assert_same(9, $items->findById($product)['stock_quantity']);
    } finally {
        if (isset($sale)) {
            $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$sale['sale_id']]);
            $pdo->prepare('DELETE FROM sales WHERE sale_id = ?')->execute([$sale['sale_id']]);
        }
        $pdo->prepare('DELETE FROM inventory_restocks WHERE item_id = ?')->execute([$product]);
        $pdo->prepare('DELETE FROM items WHERE item_id IN (?, ?)')->execute([$product, $service]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
