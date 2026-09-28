<?php
/**
 * End-to-end HTTP tests for the Phase 7.1 inventory edit guards on
 * admin/item-edit.php:
 *
 * - a sold item shows its type as fixed, and a forged type change is refused
 *   without touching the type or stock;
 * - an unsold item keeps the Product / Service selector;
 * - a stale edit form (a real POS checkout happened after it was opened) is
 *   refused with a reload message and the newer stock is preserved, until the
 *   item is reloaded;
 * - a POST missing its expected state is refused; normal edits still work;
 *   guard, CSRF and escaping stay intact; clean output and server log.
 *
 * Seeded rows (items, the admin, sales + lines) are deleted afterwards, FK-safe.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Service\SaleService;

test('item edit integrity: sold-type lock, stale stock refusal, normal edits, guards', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'IE' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'integrity-password-123';

    $adminEmail = TestDb::email('ie-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Admin", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $mk = function (string $name, string $type, string $price, ?int $stock) use ($pdo): int {
        $pdo->prepare('INSERT INTO items (item_name, item_type, category, price, stock_quantity) VALUES (?,?,?,?,?)')
            ->execute([$name, $type, 'Parts', $price, $stock]);
        return (int) $pdo->lastInsertId();
    };
    $sold   = $mk("{$tag} Sold <b>Valve</b>", 'product', '45.50', 10);
    $unsold = $mk("{$tag} Unsold Cap", 'product', '10.00', 4);
    // One recorded line makes $sold "have sales" (stock untouched by this seed).
    $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)')
        ->execute([$adminId, '2001-09-01 10:00:00', '45.50']);
    $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
        ->execute([(int) $pdo->lastInsertId(), $sold, 1, '45.50', '45.50']);

    $row = fn (int $id) => $pdo->query('SELECT * FROM items WHERE item_id = ' . (int) $id)->fetch(\PDO::FETCH_ASSOC);

    $server = new HttpServer(8693);
    $cleanup = function () use ($pdo, $adminId, $tag): void {
        $pdo->prepare('DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };
    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };

    $EDIT = '/vulcatrack/admin/item-edit.php?id=';
    $LOCK = 'Item type cannot be changed after the item has recorded sales.';
    $STALE = 'This item changed (a sale may have been recorded). Reload the item and try again.';

    try {
        $server->start();

        // guard
        $r = $server->request($EDIT . $sold);
        assert_same(302, $r['status'], 'a guest cannot open item-edit');
        assert_contains('/admin/login.php', (string) $r['location']);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        // ================= sold item page: type fixed =================
        $page = $server->request($EDIT . $sold)['body'];
        $assertClean($page, 'sold item edit');
        assert_contains($LOCK, $page, 'the lock is explained');
        assert_contains('id="item_type_fixed" value="Product" readonly', $page, 'the type is shown read-only');
        assert_not_contains('<select id="item_type"', $page, 'no editable type selector');
        assert_contains('name="expected_type" value="product"', $page);
        assert_contains('name="expected_stock" value="10"', $page, 'the loaded stock travels with the form');
        assert_not_contains('permanently clears its stock', $page, 'no switch-to-service warning on a locked item');
        assert_contains(e("{$tag} Sold <b>Valve</b>"), $page, 'the name is escaped');
        assert_not_contains('<b>Valve</b>', $page);

        // ================= unsold item page: selector stays =================
        $page = $server->request($EDIT . $unsold)['body'];
        assert_contains('<select id="item_type" name="item_type">', $page);
        assert_not_contains($LOCK, $page);

        $post = function (int $id, array $fields) use ($server, $EDIT): array {
            $token = HttpServer::csrfToken($server->request($EDIT . $id)['body']);
            return $server->request($EDIT . $id, ['_csrf' => $token] + $fields);
        };
        $soldFields = [
            'item_name' => "{$tag} Sold <b>Valve</b>", 'item_type' => 'product', 'category' => 'Parts',
            'price' => '45.50', 'stock_quantity' => '10', 'reorder_level' => '',
            'expected_type' => 'product', 'expected_stock' => '10',
        ];

        // ================= forged type change on a sold item =================
        $r = $post($sold, ['item_type' => 'service'] + $soldFields);
        assert_same(200, $r['status'], 'the forged change re-renders the form');
        assert_contains($LOCK, $r['body']);
        $assertClean($r['body'], 'forged type change');
        assert_same('product', $row($sold)['item_type'], 'the type is unchanged');
        assert_same(10, (int) $row($sold)['stock_quantity'], 'the stock is not wiped');

        // ================= missing expected state is refused =================
        $noExpected = $soldFields;
        unset($noExpected['expected_type'], $noExpected['expected_stock']);
        $r = $post($sold, ['price' => '99.00'] + $noExpected);
        assert_same(200, $r['status']);
        assert_contains($STALE, $r['body'], 'a POST without expected state is not written blind');
        assert_same('45.50', $row($sold)['price']);

        // ================= stale form after a real POS checkout =================
        $staleToken = HttpServer::csrfToken($server->request($EDIT . $sold)['body']);   // form opened at stock 10
        (new SaleService($pdo))->checkout(['admin_id' => $adminId, 'lines' => [['item_id' => $sold, 'quantity' => 2]]]);
        assert_same(8, (int) $row($sold)['stock_quantity'], 'the POS sale deducted stock');

        $staleForm = ['_csrf' => $staleToken, 'price' => '50.00'] + $soldFields;          // still carries stock 10
        $r = $server->request($EDIT . $sold, $staleForm);
        assert_same(200, $r['status']);
        assert_contains($STALE, $r['body']);
        assert_contains('>Reload this item</a>', $r['body']);
        assert_contains('name="expected_stock" value="10"', $r['body'], 'the re-rendered form stays stale');
        $assertClean($r['body'], 'stale edit');
        assert_same(8, (int) $row($sold)['stock_quantity'], 'the newer stock is preserved');
        assert_same('45.50', $row($sold)['price'], 'nothing from the stale form was written');

        $r = $server->request($EDIT . $sold, ['_csrf' => HttpServer::csrfToken($r['body'])] + $staleForm);
        assert_contains($STALE, $r['body'], 'resubmitting the stale form is still refused');
        assert_same(8, (int) $row($sold)['stock_quantity']);

        // ================= reloaded: a normal edit of the sold item works =================
        assert_contains('name="expected_stock" value="8"', $server->request($EDIT . $sold)['body'], 'a reload picks up the new stock');
        $r = $post($sold, [
            'item_name' => "{$tag} Sold Valve v2", 'price' => '50.00', 'stock_quantity' => '8', 'reorder_level' => '3',
            'expected_stock' => '8',
        ] + $soldFields);
        assert_same(302, $r['status'], 'a fresh edit saves');
        assert_contains('saved=updated', (string) $r['location']);
        $now = $row($sold);
        assert_same("{$tag} Sold Valve v2", $now['item_name']);
        assert_same('50.00', $now['price']);
        assert_same(8, (int) $now['stock_quantity']);
        assert_same(3, (int) $now['reorder_level']);
        assert_same('product', $now['item_type']);

        // ================= unsold item can still change type =================
        $r = $post($unsold, [
            'item_name' => "{$tag} Unsold Cap", 'item_type' => 'service', 'category' => 'Labor', 'price' => '10.00',
            'expected_type' => 'product', 'expected_stock' => '4',
        ]);
        assert_same(302, $r['status'], 'an unsold product can become a service');
        assert_same('service', $row($unsold)['item_type']);
        assert_null($row($unsold)['stock_quantity']);

        // ================= CSRF still enforced =================
        $r = $server->request($EDIT . $sold, ['_csrf' => 'bogus', 'price' => '1.00'] + $soldFields);
        assert_same(200, $r['status']);
        assert_contains('session expired', strtolower($r['body']));
        assert_same('50.00', $row($sold)['price'], 'a bad-CSRF edit wrote nothing');

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
