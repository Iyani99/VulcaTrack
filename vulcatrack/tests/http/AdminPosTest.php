<?php
/**
 * End-to-end HTTP tests for the Phase 5 POS UI (admin/pos.php): the session
 * cart, optional customer linking, cash tender / change, and checkout through
 * SaleService.
 *
 * SaleService's transaction rules are covered in depth by
 * integration/SaleServiceTest; this file checks what only shows up across real
 * requests: guards and actor separation, CSRF and POST-only cart changes, the
 * cart surviving in the session across requests and failed checkouts, server-
 * side quantity / tender checks, the stale-price guard, walk-in vs linked
 * sales, product-only stock deduction, output escaping, and clean output.
 *
 * Throwaway admin / customer / items are seeded via PDO; every sale recorded
 * by the throwaway admin is deleted afterwards, FK-safe order.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin POS: guards, session cart, customer link, tender checks, stale-price guard, checkout', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'POS' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'pos-password-123';

    $custEmail = TestDb::email('pos-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} Linked Customer", $custEmail, '09175551234', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $adminEmail = TestDb::email('pos-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $mk = function (string $name, string $type, string $price, ?int $stock, int $active = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO items (item_name, item_type, category, price, stock_quantity, is_active) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $type, 'POS test', $price, $stock, $active]);
        return (int) $pdo->lastInsertId();
    };
    $P = $mk("{$tag} Tire Valve", 'product', '150.00', 10);
    $S = $mk("{$tag} Patching", 'service', '200.50', null);
    $I = $mk("{$tag} Retired Item", 'product', '99.00', 5, 0);
    $mk("{$tag} Empty Shelf", 'product', '50.00', 0);
    $H = $mk("{$tag} <script>alert(1)</script> Cap", 'product', '10.00', 5);

    $q = function (string $sql, array $args = []) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s;
    };
    $stockOf   = fn (int $id) => $q('SELECT stock_quantity FROM items WHERE item_id = ?', [$id])->fetchColumn();
    $saleCount = fn () => (int) $q('SELECT COUNT(*) FROM sales WHERE admin_id = ?', [$adminId])->fetchColumn();

    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8685);
    $cleanup = function () use ($q, $custId, $adminId, $tag): void {
        $q('DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.admin_id = ?', [$adminId]);
        $q('DELETE FROM sales WHERE admin_id = ?', [$adminId]);
        $q('DELETE FROM items WHERE item_name LIKE ?', [$tag . '%']);
        $q('DELETE FROM customers WHERE customer_id = ?', [$custId]);
        $q('DELETE FROM admins WHERE admin_id = ?', [$adminId]);
    };

    $POS = '/vulcatrack/admin/pos.php';

    try {
        $server->start();

        // ================= guards / actor separation =================
        $r = $server->request($POS);
        assert_same(302, $r['status'], 'unauthenticated GET redirects');
        assert_contains('/admin/login.php', (string) $r['location']);
        $r = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1', '_csrf' => 'x']);
        assert_same(302, $r['status'], 'unauthenticated POST redirects');
        assert_contains('/admin/login.php', (string) $r['location']);

        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($POS);
        assert_same(302, $r['status'], 'a customer cannot open the POS');
        assert_contains('/admin/login.php', (string) $r['location']);
        $r = $server->request($POS, ['_action' => 'checkout', '_csrf' => 'x']);
        assert_contains('/admin/login.php', (string) $r['location'], 'a customer cannot check out');
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        $page  = fn (string $qs = '') => $server->request($POS . $qs)['body'];
        $token = fn () => HttpServer::csrfToken($page());
        $post  = fn (array $fields) => $server->request($POS, $fields + ['_csrf' => $token()], true)['body'];
        $qtyOf = function (string $html, int $itemId): ?int {
            return preg_match('/name="qty\[' . $itemId . '\]"\s+value="(\d+)"/', $html, $m) ? (int) $m[1] : null;
        };
        // Submit the sale exactly as the rendered form would (optionally overriding fields).
        $checkout = function (string $cash, array $override = []) use ($page, $server, $POS) {
            $html = $page();
            preg_match_all('/name="qty\[(\d+)\]"\s+value="(\d+)"/', $html, $m, PREG_SET_ORDER);
            $fields = ['_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'cash_tendered' => $cash];
            if (preg_match('/name="expected_total" value="(\d+)"/', $html, $e)) {
                $fields['expected_total'] = $e[1];
            }
            foreach ($m as [, $id, $qty]) {
                $fields["qty[{$id}]"] = $qty;
            }
            return $server->request($POS, array_merge($fields, $override));
        };

        // ================= initial render =================
        $html = $page();
        assert_contains('Point of Sale', $html);
        assert_contains("{$tag} Tire Valve", $html, 'active product listed');
        assert_contains("{$tag} Patching", $html, 'active service listed');
        assert_not_contains("{$tag} Retired Item", $html, 'inactive item not offered');
        assert_contains('Out of stock', $html, 'a zero-stock product cannot be added');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'item names are escaped');
        assert_not_contains('<script>alert(1)</script>', $html);
        assert_contains('class="table-scroll"', $html, 'wide tables scroll in their own box on phones');
        assert_contains('No items yet', $html);
        assert_contains('Walk-in', $html, 'blank customer = walk-in');
        $assertClean($html, 'pos (empty)');

        // ================= POST-only + CSRF =================
        $page('?_action=add&item_id=' . $P . '&quantity=1');
        assert_contains('No items yet', $page(), 'a GET never changes the cart');

        $r = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1', '_csrf' => 'bogus'], true);
        assert_contains('session expired', strtolower($r['body']));
        assert_contains('No items yet', $r['body'], 'a bad-CSRF add did nothing');

        // ================= add: server-side validation =================
        assert_contains('not available for sale', $post(['_action' => 'add', 'item_id' => (string) $I, 'quantity' => '1']), 'inactive item refused');
        assert_contains('not available for sale', $post(['_action' => 'add', 'item_id' => '99999999', 'quantity' => '1']), 'unknown item refused');
        foreach (['0', '-1', 'abc', '1.5', '10000', ''] as $bad) {
            $body = $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => $bad]);
            assert_contains('Quantity must be a whole number', $body, "quantity '{$bad}' refused");
        }
        assert_contains('Not enough stock', $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '11']), 'more than stock refused');
        assert_contains('No items yet', $page(), 'nothing was added by the refused requests');

        // ================= add / merge / update / remove =================
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '2']);
        $body = $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        assert_same(3, $qtyOf($body, $P), 'adding the same item again merges into one line');
        assert_same(1, preg_match_all('/name="qty\[' . $P . '\]"/', $body), 'exactly one cart line for the item');
        assert_contains('Not enough stock', $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '8']), 'cart + new qty checked against stock');
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1', 'q' => $tag, 'type' => 'service']);
        $r = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $H, 'quantity' => '1', 'q' => $tag, 'type' => 'product', '_csrf' => $token()]);
        assert_same(302, $r['status'], 'cart changes redirect (PRG)');
        assert_contains('q=' . $tag, (string) $r['location'], 'the item search is kept after adding');
        assert_contains('type=product', (string) $r['location']);

        $body = $post(['_action' => 'update', "qty[{$P}]" => '4', "qty[{$S}]" => '1', "qty[{$H}]" => '1', 'qty[99999999]' => '5']);
        assert_contains('Quantities updated', $body);
        assert_same(4, $qtyOf($body, $P));
        assert_null($qtyOf($body, 99999999), 'unknown keys in the update are ignored');
        $body = $post(['_action' => 'update', "qty[{$P}]" => '0', "qty[{$S}]" => '1', "qty[{$H}]" => '1']);
        assert_contains('Each quantity must be a whole number', $body);
        assert_same(4, $qtyOf($body, $P), 'a bad update changes nothing (all-or-nothing)');

        $body = $post(['_action' => 'remove', 'item_id' => (string) $H]);
        assert_null($qtyOf($body, $H), 'removed from the cart');
        assert_same(4, $qtyOf($body, $P), 'other lines untouched');
        assert_contains('&#8369;800.50', $body, 'total = 4 x 150.00 + 200.50, shown from DB prices');
        assert_contains('Total due: &#8369;800.50', $body, 'the total is repeated beside the cash box (visible on phones)');
        $assertClean($body, 'pos (cart)');

        // ================= optional customer linking =================
        $body = $page('?cq=' . urlencode('5551234'));
        assert_contains("{$tag} Linked Customer", $body, 'customer found by contact number');
        assert_contains('value="link_customer"', $body);
        assert_contains('does not create accounts', $page('?cq=' . urlencode($tag . 'nobody')), 'no match → stays walk-in, no inline registration');
        assert_contains('could not be found', $post(['_action' => 'link_customer', 'customer_id' => '99999999']), 'arbitrary customer id refused');
        $body = $post(['_action' => 'link_customer', 'customer_id' => (string) $custId]);
        assert_contains('Linked customer', $body);
        assert_contains('Make walk-in', $body);

        // ================= checkout failures keep the cart; nothing recorded =================
        $r = $checkout('500');
        assert_same(200, $r['status']);
        assert_contains('is less than the total', $r['body'], 'insufficient cash refused by the server');
        assert_contains('value="500"', $r['body'], 'the typed cash amount is kept');
        assert_same(4, $qtyOf($r['body'], $P), 'cart preserved');
        assert_contains('Enter the cash received', $checkout('abc')['body'], 'malformed cash refused');
        assert_contains('Enter the cash received', $checkout('100.555')['body'], 'more than two decimals refused');
        assert_contains('not saved', $checkout('1000', ["qty[{$P}]" => '5'])['body'], 'unsaved quantity edits never slip into a sale');
        assert_contains('another window', $checkout('1000', ["qty[{$S}]" => null])['body'], 'a form that does not match the cart is refused');
        assert_same(0, $saleCount(), 'no sale recorded by any failed checkout');
        assert_same(10, (int) $stockOf($P), 'no stock deducted');

        // stale price: the cashier's screen says 800.50, then Inventory changes the price
        $html = $page();
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        assert_same('80050', $e[1] ?? null, 'the displayed total is carried in integer centavos');
        $q('UPDATE items SET price = ? WHERE item_id = ?', ['155.00', $P]);
        $r = $server->request($POS, [
            '_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'cash_tendered' => '1000',
            'expected_total' => $e[1], "qty[{$P}]" => '4', "qty[{$S}]" => '1',
        ]);
        assert_contains('total changed to ₱820.50', $r['body'], 'stale display refused; the new DB total is shown');
        assert_contains('Nothing was recorded', $r['body']);
        assert_same(0, $saleCount());
        assert_same(10, (int) $stockOf($P));
        assert_contains('&#8369;820.50', $page(), 'the re-rendered cart shows the new authoritative total');

        // client-supplied prices/totals are ignored: tampering with expected_total only makes the sale fail
        assert_contains('total changed', $checkout('1000', ['expected_total' => '100'])['body']);
        assert_same(0, $saleCount());

        // stock fell below the cart after it was built (e.g. another sale)
        $q('UPDATE items SET stock_quantity = 2 WHERE item_id = ?', [$P]);
        assert_contains('Only 2 in stock', $page(), 'the cart flags the shortfall');
        $r = $checkout('1000');
        assert_contains('Not enough stock', $r['body'], 'SaleService refuses the oversell');
        assert_same(0, $saleCount(), 'no partial sale');
        assert_same(2, (int) $stockOf($P), 'stock untouched');
        $q('UPDATE items SET stock_quantity = 10 WHERE item_id = ?', [$P]);

        // ================= successful linked checkout =================
        $r = $checkout('1000');
        assert_same(302, $r['status'], 'a completed sale redirects (PRG — a refresh cannot re-submit it)');
        $body = $page();
        assert_contains('recorded', $body);
        assert_contains('&#8369;820.50', $body, 'total');
        assert_contains('&#8369;1000.00', $body, 'cash received');
        assert_contains('&#8369;179.50', $body, 'change = 1000.00 - 820.50, integer centavos');
        assert_contains("{$tag} Linked Customer", $body);
        assert_contains('No items yet', $body, 'the cart is cleared after the sale');
        assert_contains('<strong>Walk-in</strong>', $body, 'the next sale starts as walk-in');
        $assertClean($body, 'pos (after sale)');
        assert_not_contains('recorded</h2>', $page(), 'the sale summary is shown once');

        $sale = $q('SELECT * FROM sales WHERE admin_id = ?', [$adminId])->fetch(\PDO::FETCH_ASSOC);
        assert_same(1, $saleCount());
        $summaryLink = '/vulcatrack/admin/transaction-summary.php?id=' . (int) $sale['sale_id'];
        assert_contains('href="' . $summaryLink . '"', $body, 'the success card links to this sale\'s Transaction Summary');
        $summary = $server->request($summaryLink);
        assert_same(200, $summary['status']);
        assert_contains('Transaction Summary', $summary['body']);
        assert_contains('&#8369;820.50', $summary['body'], 'the linked document shows the recorded total');
        assert_same($custId, (int) $sale['customer_id'], 'linked customer recorded');
        assert_same('820.50', $sale['total_amount']);
        $lines = $q('SELECT item_id, quantity, unit_price, subtotal FROM sale_items WHERE sale_id = ? ORDER BY item_id', [$sale['sale_id']])->fetchAll(\PDO::FETCH_ASSOC);
        assert_same([
            ['item_id' => $P, 'quantity' => 4, 'unit_price' => '155.00', 'subtotal' => '620.00'],
            ['item_id' => $S, 'quantity' => 1, 'unit_price' => '200.50', 'subtotal' => '200.50'],
        ], array_map(fn ($l) => ['item_id' => (int) $l['item_id'], 'quantity' => (int) $l['quantity'], 'unit_price' => $l['unit_price'], 'subtotal' => $l['subtotal']], $lines));
        assert_same(6, (int) $stockOf($P), 'product stock deducted');
        assert_null($stockOf($S), 'service stock untouched (still NULL)');
        $cols = $q('SHOW COLUMNS FROM sales')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['cash_tendered', 'change_amount', 'tender'] as $absent) {
            assert_false(in_array($absent, $cols, true), 'cash tender / change are never persisted');
        }

        // ================= walk-in checkout, exact cash =================
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1']);
        assert_same(302, $checkout('200.50')['status']);
        assert_contains('&#8369;0.00', $page(), 'exact cash → zero change');
        $walkIn = $q('SELECT customer_id, total_amount FROM sales WHERE admin_id = ? ORDER BY sale_id DESC LIMIT 1', [$adminId])->fetch(\PDO::FETCH_ASSOC);
        assert_null($walkIn['customer_id'], 'walk-in sale stores customer_id NULL');
        assert_same('200.50', $walkIn['total_amount']);

        // ================= cancel sale / empty checkout =================
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        $body = $post(['_action' => 'clear']);
        assert_contains('Sale cancelled', $body);
        assert_contains('No items yet', $body);
        assert_contains('no items yet', $server->request($POS, ['_action' => 'checkout', '_csrf' => $token(), 'cash_tendered' => '100'])['body']);
        assert_same(2, $saleCount(), 'exactly the two completed sales exist');

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'checkout failed'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
