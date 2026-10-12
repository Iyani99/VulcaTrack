<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Admin stock adjustment is Product-only, validated, attributed, replay-safe, and visible in inventory', function () {
    $pdo = test_pdo();
    $tag = 'HADJ' . bin2hex(random_bytes(4));
    $email = TestDb::email('http-adjust');
    $password = 'adjustment-test-password';
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute([$tag . ' Admin', $email, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity) VALUES (?,?,?,?)')
        ->execute([$tag . ' Product', 'product', '10.00', 4]);
    $product = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price) VALUES (?,?,?)')
        ->execute([$tag . ' Service', 'service', '10.00']);
    $service = (int) $pdo->lastInsertId();
    $stock = fn (): int => (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $product)->fetchColumn();
    $historyCount = fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments WHERE item_id = ' . $product)->fetchColumn();
    $base = '/vulcatrack/admin/adjust-stock.php?id=' . $product;
    $nonce = static function (string $html): ?string {
        return preg_match('/name="_adjustment_nonce" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : null;
    };
    $server = new HttpServer(8702);
    try {
        $server->start();
        assert_same(302, $server->request($base)['status'], 'guest cannot adjust stock');
        $login = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', [
            '_csrf' => HttpServer::csrfToken($login['body']), 'email' => $email, 'password' => $password,
        ]);
        assert_same(404, $server->request('/vulcatrack/admin/adjust-stock.php?id=' . $service)['status']);
        assert_same(404, $server->request('/vulcatrack/admin/adjust-stock.php?id=2147483647')['status']);
        $inventory = $server->request('/vulcatrack/admin/inventory.php?type=all')['body'];
        assert_contains('adjust-stock.php?id=' . $product, $inventory, 'Product has Adjust action');
        assert_not_contains('adjust-stock.php?id=' . $service, $inventory, 'Service has no Adjust action');
        assert_not_contains('restock.php?id=' . $service, $inventory, 'Service has no Restock action');
        assert_contains('inventory-actions-mobile', $inventory);
        $page = $server->request($base);
        assert_same(200, $page['status']);
        assert_contains('Current shop stock: 4', $page['body']);
        assert_contains('Use Restock for newly received stock.', $page['body']);
        assert_contains('data-adjust-preview', $page['body']);
        assert_contains('No adjustments recorded yet', $page['body']);
        $csrf = HttpServer::csrfToken($page['body']);
        $first = $nonce($page['body']);
        assert_not_null($first);
        $badCsrf = $server->request($base, [
            '_csrf' => 'bad', '_adjustment_nonce' => $first,
            'direction' => 'decrease', 'quantity' => '1', 'reason' => 'lost',
        ]);
        assert_same(200, $badCsrf['status']);
        assert_contains('session expired', strtolower($badCsrf['body']));
        assert_same($first, $nonce($badCsrf['body']), 'CSRF failure does not consume nonce');
        assert_same(4, $stock());
        $send = function (string $token, array $fields = []) use ($server, $base, $csrf): array {
            return $server->request($base, array_replace([
                '_csrf' => $csrf, '_adjustment_nonce' => $token,
                'direction' => 'decrease', 'quantity' => '1', 'reason' => 'lost', 'note' => '',
            ], $fields));
        };
        foreach ([
            ['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.5'],
            ['reason' => 'other'], ['reason' => 'made_up'], ['direction' => 'sideways'],
        ] as $bad) {
            $r = $send($first, $bad);
            assert_same(200, $r['status']);
            assert_same($first, $nonce($r['body']), 'validation leaves the nonce reusable');
            assert_same(4, $stock());
        }
        $r = $send($first, ['quantity' => '5']);
        assert_same(200, $r['status']);
        assert_contains('cannot make stock negative', $r['body']);
        assert_same(4, $stock());
        assert_same(0, $historyCount());
        $fresh = $nonce($r['body']);
        assert_not_null($fresh);
        assert_true($fresh !== $first, 'transaction attempt consumes nonce even on failure');
        $r = $send($fresh, ['quantity' => '2', 'reason' => 'other', 'note' => '  Physical count <check>  ']);
        assert_same(302, $r['status']);
        assert_contains('saved=1', (string) $r['location']);
        assert_same(2, $stock());
        assert_same(1, $historyCount());
        $again = $send($fresh, ['quantity' => '2']);
        assert_same(200, $again['status']);
        assert_contains('already submitted or has expired', $again['body']);
        assert_same(2, $stock());
        $view = $server->request($base)['body'];
        assert_contains('Physical count &lt;check&gt;', $view);
        assert_contains('data-label="Stock movement">4 &rarr; 2', $view);
        assert_contains('data-label="Recorded by">' . $tag . ' Admin', $view);
        assert_contains('data-label="Reason">Other', $view);
        assert_contains('data-label="Change"', $view);
        $newNonce = $nonce($view);
        $r = $send($newNonce, ['direction' => 'increase', 'quantity' => '3', 'reason' => 'count_correction']);
        assert_same(302, $r['status']);
        assert_same(5, $stock());
        assert_same(2, $historyCount());
        $pdo->prepare('UPDATE items SET is_active = 0 WHERE item_id = ?')->execute([$product]);
        $archivedPage = $server->request($base)['body'];
        assert_contains('Archived', $archivedPage);
        $r = $send($nonce($archivedPage), ['quantity' => '1', 'reason' => 'damaged']);
        assert_same(302, $r['status']);
        assert_same(4, $stock());
        assert_same(0, (int) $pdo->query('SELECT is_active FROM items WHERE item_id = ' . $product)->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM inventory_restocks WHERE item_id = ' . $product)->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM sale_items WHERE item_id = ' . $product)->fetchColumn());
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM inventory_adjustments WHERE item_id = ?')->execute([$product]);
        $pdo->prepare('DELETE FROM items WHERE item_id IN (?, ?)')->execute([$product, $service]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
