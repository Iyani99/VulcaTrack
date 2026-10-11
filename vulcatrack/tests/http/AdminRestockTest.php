<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Admin restock page guards receipts, validates input, and shows committed history', function () {
    $pdo = test_pdo();
    $tag = 'HRST' . bin2hex(random_bytes(4));
    $email = TestDb::email('http-restock');
    $password = 'restock-test-password';
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute([$tag . ' Admin', $email, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity) VALUES (?, ?, ?, ?)')
        ->execute([$tag . ' Product', 'product', '10.00', 4]);
    $product = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity) VALUES (?, ?, ?, ?)')
        ->execute([$tag . ' Other Product', 'product', '10.00', 0]);
    $otherProduct = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price) VALUES (?, ?, ?)')
        ->execute([$tag . ' Service', 'service', '10.00']);
    $service = (int) $pdo->lastInsertId();
    $stock = fn () => (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $product)->fetchColumn();
    $count = fn () => (int) $pdo->query('SELECT COUNT(*) FROM inventory_restocks WHERE item_id = ' . $product)->fetchColumn();
    $base = '/vulcatrack/admin/restock.php?id=' . $product;
    $otherBase = '/vulcatrack/admin/restock.php?id=' . $otherProduct;
    $nonce = static function (string $html): ?string {
        return preg_match('/name="_restock_nonce" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : null;
    };
    $server = new HttpServer(8696);
    try {
        $server->start();
        $r = $server->request($base);
        assert_same(302, $r['status'], 'guest redirected');
        assert_contains('/admin/login.php', (string) $r['location']);
        assert_same(4, $stock());

        $login = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', [
            '_csrf' => HttpServer::csrfToken($login['body']), 'email' => $email, 'password' => $password,
        ]);
        $page = $server->request($base);
        assert_same(200, $page['status']);
        assert_contains($tag . ' Product', $page['body']);
        assert_contains('Current shop stock: 4', $page['body']);
        assert_contains('No restocks recorded yet', $page['body']);
        assert_contains('name="note"', $page['body']);
        assert_contains('data-restock-form', $page['body']);
        assert_contains("button.textContent = 'Recording...'", $page['body']);
        $firstNonce = $nonce($page['body']);
        assert_not_null($firstNonce);
        $secondPage = $server->request($base);
        $secondNonce = $nonce($secondPage['body']);
        assert_not_null($secondNonce);
        assert_true($firstNonce !== $secondNonce, 'a second tab has its own token');
        $otherPage = $server->request($otherBase);
        $otherNonce = $nonce($otherPage['body']);
        assert_not_null($otherNonce);
        assert_true($firstNonce !== $otherNonce);
        assert_same(404, $server->request('/vulcatrack/admin/restock.php?id=' . $service)['status']);
        assert_same(404, $server->request('/vulcatrack/admin/restock.php?id=999999999')['status']);

        $token = HttpServer::csrfToken($page['body']);
        $r = $server->request($base, ['_csrf' => 'bad', '_restock_nonce' => $firstNonce, 'quantity' => '3']);
        assert_same(200, $r['status']);
        assert_contains('session expired', strtolower($r['body']));
        assert_same($firstNonce, $nonce($r['body']), 'CSRF failure does not consume the form token');
        assert_same(4, $stock());
        assert_same(0, $count());

        $r = $server->request($base, ['_csrf' => $token, 'quantity' => '3']);
        assert_same(200, $r['status']);
        assert_contains('already submitted or has expired', $r['body'], 'missing token is refused');
        assert_same(4, $stock());
        assert_same(0, $count());
        $r = $server->request($otherBase, ['_csrf' => $token, '_restock_nonce' => $firstNonce, 'quantity' => '3']);
        assert_same(200, $r['status']);
        assert_contains('already submitted or has expired', $r['body'], 'token is bound to Product ID');
        assert_same(0, (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $otherProduct)->fetchColumn());

        foreach (['0', '-1', '1.5'] as $bad) {
            $r = $server->request($base, ['_csrf' => $token, '_restock_nonce' => $firstNonce, 'quantity' => $bad]);
            assert_same(200, $r['status']);
            assert_contains('whole number greater than zero', $r['body']);
            assert_same($firstNonce, $nonce($r['body']), 'validation failure leaves token reusable');
            assert_same(4, $stock());
            assert_same(0, $count());
        }
        $r = $server->request($base, ['_csrf' => $token, '_restock_nonce' => $firstNonce, 'quantity' => '2147483648']);
        assert_same(200, $r['status']);
        assert_same(4, $stock());
        assert_same(0, $count());
        assert_true($nonce($r['body']) !== $firstNonce, 'a transaction attempt consumes its token even on failure');
        $firstNonce = $nonce($r['body']);
        assert_not_null($firstNonce);

        $r = $server->request($base, ['_csrf' => $token, '_restock_nonce' => $firstNonce, 'quantity' => '3', 'note' => str_repeat('x', 501)]);
        assert_same(200, $r['status']);
        assert_contains('Note / Description is too long', $r['body']);
        assert_same(4, $stock());
        assert_same(0, $count());

        $r = $server->request($base, [
            '_csrf' => $token, '_restock_nonce' => $firstNonce,
            'quantity' => '3', 'supplier_name' => '<Parts & Co>', 'note' => '<Special & urgent>',
        ]);
        assert_same(302, $r['status']);
        assert_contains('saved=1', (string) $r['location']);
        assert_same(7, $stock());
        assert_same(1, $count());
        $replay = $server->request($base, [
            '_csrf' => $token, '_restock_nonce' => $firstNonce,
            'quantity' => '3', 'supplier_name' => '<Parts & Co>', 'note' => '<Special & urgent>',
        ]);
        assert_same(200, $replay['status']);
        assert_contains('already submitted or has expired', $replay['body']);
        assert_not_null($nonce($replay['body']));
        assert_true($nonce($replay['body']) !== $firstNonce, 'replay receives a fresh form token');
        assert_same(7, $stock(), 'replay did not increase stock twice');
        assert_same(1, $count(), 'replay did not create a second receipt');
        $r = $server->request($base . '&saved=1');
        assert_contains('Restock recorded.', $r['body']);
        assert_contains('Current shop stock: 7', $r['body']);
        assert_contains('&lt;Parts &amp; Co&gt;', $r['body']);
        assert_contains('4 &rarr; 7', $r['body']);
        assert_contains('<summary>View note</summary>', $r['body']);
        assert_contains('&lt;Special &amp; urgent&gt;', $r['body']);
        assert_contains($tag . ' Admin', $r['body']);
        assert_contains('datatable mobile-records', $r['body']);
        assert_not_contains('<Parts & Co>', $r['body']);
        assert_not_contains('<Special & urgent>', $r['body']);

        $token = HttpServer::csrfToken($r['body']);
        $r = $server->request($base, ['_csrf' => $token, '_restock_nonce' => $secondNonce, 'quantity' => '2']);
        assert_same(302, $r['status'], 'supplier is optional');
        assert_same(9, $stock());
        assert_same(2, $count());
        $supplier = $pdo->query('SELECT supplier_name FROM inventory_restocks WHERE item_id = ' . $product
            . ' ORDER BY restock_id DESC LIMIT 1')->fetchColumn();
        assert_null($supplier);
        $receipt = $pdo->query('SELECT stock_before, stock_after, note FROM inventory_restocks WHERE item_id = ' . $product
            . ' ORDER BY restock_id DESC LIMIT 1')->fetch();
        assert_same(7, (int) $receipt['stock_before']);
        assert_same(9, (int) $receipt['stock_after']);
        assert_null($receipt['note']);

        $pdo->prepare('UPDATE items SET is_active = 0 WHERE item_id = ?')->execute([$otherProduct]);
        $archivedGet = $server->request($otherBase);
        assert_same(409, $archivedGet['status']);
        assert_contains('Reactivate this product before restocking it.', $archivedGet['body']);
        $archivedPost = $server->request($otherBase, [
            '_csrf' => $token, '_restock_nonce' => $otherNonce, 'quantity' => '3',
        ]);
        assert_same(409, $archivedPost['status'], 'direct POST cannot bypass archive rule');
        assert_same(0, (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $otherProduct)->fetchColumn());
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM inventory_restocks WHERE item_id = ' . $otherProduct)->fetchColumn());
        $inventory = $server->request('/vulcatrack/admin/inventory.php?type=all&status=all&q=' . $tag);
        assert_contains('restock.php?id=' . $product, $inventory['body'], 'active Product still offers Restock');
        assert_not_contains('restock.php?id=' . $otherProduct, $inventory['body'], 'archived Product omits Restock');
        $archivedEdit = $server->request('/vulcatrack/admin/item-edit.php?id=' . $otherProduct);
        assert_not_contains('restock.php?id=' . $otherProduct, $archivedEdit['body']);
        assert_contains('Reactivate this product before recording a restock.', $archivedEdit['body']);

        $pdo->prepare('UPDATE items SET is_active = 1 WHERE item_id = ?')->execute([$otherProduct]);
        $reactivatedPage = $server->request($otherBase);
        assert_same(200, $reactivatedPage['status']);
        $r = $server->request($otherBase, [
            '_csrf' => HttpServer::csrfToken($reactivatedPage['body']),
            '_restock_nonce' => $nonce($reactivatedPage['body']), 'quantity' => '3',
        ]);
        assert_same(302, $r['status'], 'reactivated Product can Restock');
        assert_same(3, (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $otherProduct)->fetchColumn());
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM inventory_restocks WHERE item_id = ' . $otherProduct)->fetchColumn());

        $edit = $server->request('/vulcatrack/admin/item-edit.php?id=' . $product);
        assert_not_contains('name="stock_quantity"', $edit['body'], 'edit form cannot replace stock');
        assert_contains('value="9" readonly', $edit['body']);
        assert_contains('restock.php?id=' . $product, $edit['body']);
        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $log);
        }
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM inventory_restocks WHERE item_id IN (?, ?)')->execute([$product, $otherProduct]);
        $pdo->prepare('DELETE FROM items WHERE item_id IN (?, ?, ?)')->execute([$product, $otherProduct, $service]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
