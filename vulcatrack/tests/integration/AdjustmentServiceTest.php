<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\AdjustmentRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\RestockRepository;
use VulcaTrack\Service\AdjustmentService;
use VulcaTrack\Service\RestockService;
use VulcaTrack\Service\SaleService;

test('manual Product corrections record attributed before/after stock atomically and remain separate from Restocks', function () {
    $pdo = test_pdo();
    $tag = 'ADJ' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute([$tag . ' Admin', TestDb::email('adj'), 'test-hash']);
    $adminId = (int) $pdo->lastInsertId();
    $items = new ItemRepository($pdo);
    $product = $items->create($tag . ' Product', 'product', null, 100, 5, null);
    $service = $items->create($tag . ' Service', 'service', null, 100, null, null);
    $adjust = new AdjustmentService($pdo);
    $history = new AdjustmentRepository($pdo);
    $restocks = new RestockRepository($pdo);
    $saleCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    try {
        assert_same([], $history->forItem($product), 'opening stock has no invented adjustments');
        $id = $adjust->record($product, 'decrease', 2, 'lost', null, $adminId);
        assert_same(3, $items->findById($product)['stock_quantity']);
        $rows = $history->forItem($product);
        assert_count(1, $rows);
        assert_same($id, (int) $rows[0]['adjustment_id']);
        assert_same(5, (int) $rows[0]['stock_before']);
        assert_same(3, (int) $rows[0]['stock_after']);
        assert_same('lost', $rows[0]['reason']);
        assert_same($adminId, (int) $rows[0]['admin_id']);
        assert_same($tag . ' Admin', $rows[0]['admin_name']);
        assert_not_null($rows[0]['adjusted_at']);
        assert_null($rows[0]['note']);
        assert_false($items->update($product, $tag . ' Stale edit', 'product', null, 100, 3, null,
            ['item_type' => 'product', 'stock_quantity' => 5]), 'a pre-adjustment Item Edit cannot overwrite newer stock');
        assert_same($tag . ' Product', $items->findById($product)['item_name']);
        $adjust->record($product, 'increase', 4, 'count_correction', '  Found excess  ', $adminId);
        assert_same(7, $items->findById($product)['stock_quantity']);
        assert_same('Found excess', $history->forItem($product)[0]['note']);
        assert_same(3, (int) $history->forItem($product)[0]['stock_before']);
        assert_same(7, (int) $history->forItem($product)[0]['stock_after']);
        assert_same([], $restocks->forItem($product), 'adjustment is not a receipt');
        assert_same($saleCountBefore, (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(), 'adjustment is not a Sale');

        foreach ([
            fn () => $adjust->record($product, 'decrease', 8, 'damaged', null, $adminId),
            fn () => $adjust->record($service, 'decrease', 1, 'lost', null, $adminId),
            fn () => $adjust->record(2147483647, 'decrease', 1, 'lost', null, $adminId),
            fn () => $adjust->record($product, 'increase', 1, 'other', null, $adminId),
            fn () => $adjust->record($product, 'increase', 1, 'bad', null, $adminId),
            fn () => $adjust->record($product, 'increase', 0, 'lost', null, $adminId),
            fn () => $adjust->record($product, 'increase', 2147483647, 'count_correction', null, $adminId),
        ] as $invalid) {
            assert_throws($invalid, \InvalidArgumentException::class);
        }
        assert_throws(fn () => $adjust->record($product, 'increase', 1, 'other', 'why', 2147483647), \PDOException::class,
            '', 'failed history insert must roll the stock update back');
        assert_same(7, $items->findById($product)['stock_quantity']);
        assert_count(2, $history->forItem($product));

        $items->setActive($product, false);
        $adjust->record($product, 'decrease', 1, 'damaged', null, $adminId);
        assert_same(6, $items->findById($product)['stock_quantity']);
        assert_same(0, $items->findById($product)['is_active'], 'archived Product is not reactivated');
        assert_throws(fn () => (new RestockService($pdo))->record($product, 1, null, $adminId), \InvalidArgumentException::class);
        assert_count(3, $history->forItem($product));
        assert_same([], $restocks->forItem($product));
        $items->setActive($product, true);
        (new RestockService($pdo))->record($product, 1, null, $adminId);
        assert_same(7, $items->findById($product)['stock_quantity']);
        assert_count(1, $restocks->forItem($product));
        assert_count(3, $history->forItem($product), 'routine Restock does not become an adjustment');
        $sale = (new SaleService($pdo))->checkout([
            'admin_id' => $adminId, 'lines' => [['item_id' => $product, 'quantity' => 1]],
        ]);
        assert_same(6, $items->findById($product)['stock_quantity']);
        assert_count(3, $history->forItem($product), 'POS deduction does not become an adjustment');
        assert_count(1, $restocks->forItem($product));
    } finally {
        if (isset($sale)) {
            $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$sale['sale_id']]);
            $pdo->prepare('DELETE FROM sales WHERE sale_id = ?')->execute([$sale['sale_id']]);
        }
        $pdo->prepare('DELETE FROM inventory_restocks WHERE item_id = ?')->execute([$product]);
        $pdo->prepare('DELETE FROM inventory_adjustments WHERE item_id = ?')->execute([$product]);
        $pdo->prepare('DELETE FROM items WHERE item_id IN (?, ?)')->execute([$product, $service]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
