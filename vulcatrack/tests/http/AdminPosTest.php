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
        // every cart change carries the context it was rendered for ("" = ordinary sale; Phase 7.3d stale-tab guard)
        $post  = fn (array $fields) => $server->request($POS, $fields + ['_csrf' => $token(), 'expected_rescue_id' => ''], true)['body'];
        $qtyOf = function (string $html, int $itemId): ?int {
            return preg_match('/name="qty\[' . $itemId . '\]"\s+value="(\d+)"/', $html, $m) ? (int) $m[1] : null;
        };
        // Submit the sale exactly as the rendered form would (optionally overriding fields).
        $checkout = function (string $cash, array $override = []) use ($page, $server, $POS) {
            $html = $page();
            preg_match_all('/name="qty\[(\d+)\]"\s+value="(\d+)"/', $html, $m, PREG_SET_ORDER);
            $fields = ['_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'cash_tendered' => $cash, 'expected_rescue_id' => ''];
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
        // Phase 7.3c layout: catalogue pane (item cards that wrap on phones, so no Add
        // control hides behind a scroll strip) beside the Current sale panel.
        assert_contains('<section class="pos-catalog" id="items"', $html, 'catalogue pane');
        assert_same(2, substr_count($html, '<ul class="pos-grid">'), 'each catalogue group uses the wrapping card grid');
        assert_true(strpos($html, 'id="pos-group-service"') < strpos($html, 'id="pos-group-product"'), 'Services Offered comes before Products');
        assert_true(preg_match('#<section class="pos-catalog-group" aria-labelledby="pos-group-service">(.*?)</section>#s', $html, $serviceGroup) === 1);
        assert_true(preg_match('#<section class="pos-catalog-group" aria-labelledby="pos-group-product">(.*?)</section>#s', $html, $productGroup) === 1);
        assert_contains("{$tag} Patching", $serviceGroup[1], 'service result belongs under Services Offered');
        assert_not_contains("{$tag} Tire Valve", $serviceGroup[1]);
        assert_contains("{$tag} Tire Valve", $productGroup[1], 'product result belongs under Products');
        assert_not_contains("{$tag} Patching", $productGroup[1]);
        assert_not_contains('stock--na', $serviceGroup[1], 'services do not show a meaningless stock indicator');
        assert_contains('stock stock--ok', $productGroup[1], 'products retain stock status');
        assert_contains('<p class="pos-item__price">&#8369;150</p>', $productGroup[1], 'whole-peso catalogue price is compact');
        assert_contains('<p class="pos-item__price">&#8369;200.50</p>', $serviceGroup[1], 'nonzero centavos stay visible');
        assert_contains('<section class="pos-sale" id="sale"', $html, 'Current sale panel');
        assert_not_contains('pos-sale--added', $html, 'initial POS load has no cart-add entrance');
        assert_not_contains('pos-item--product', $html, 'product-specific card treatment is removed');
        assert_not_contains('pos-item--service', $html, 'service-specific card treatment is removed');
        assert_true(strpos($html, 'id="items"') < strpos($html, 'id="sale"'), 'catalogue first, then the sale (desktop left/right, stacked order)');
        // each card keeps the same add form: POST action=add + item_id + quantity, never nested
        assert_contains('<input type="hidden" name="item_id" value="' . $P . '">', $html);
        assert_same(0, preg_match('#<form[^>]*>(?:(?!</form>).)*<form#s', $html), 'no nested forms');
        // type chips are plain links carrying the real ?type= values (search kept)
        $filtered = $page('?q=' . urlencode($tag) . '&type=service');
        assert_contains('href="/vulcatrack/admin/pos.php?q=' . urlencode($tag) . '&amp;type=product"', $filtered, 'the Products chip keeps the search');
        assert_contains('<a class="chip" href="/vulcatrack/admin/pos.php">All</a>', $filtered, 'All clears both search and type');
        assert_contains('aria-current="true">Services</a>', $filtered, 'the chosen type is marked current');
        assert_not_contains('class="pos-clear"', $filtered, 'the redundant Clear link is absent');
        assert_contains('<input type="hidden" name="type" value="service">', $filtered, 'searching keeps the chosen type');
        assert_contains("{$tag} Patching", $filtered);
        assert_not_contains("{$tag} Tire Valve", $filtered, 'the type filter still filters');
        assert_contains('id="pos-group-service"', $filtered);
        assert_not_contains('id="pos-group-product"', $filtered, 'Services filter has no empty Products heading');
        $filtered = $page('?q=' . urlencode($tag) . '&type=product');
        assert_contains("{$tag} Tire Valve", $filtered);
        assert_not_contains("{$tag} Patching", $filtered);
        assert_contains('id="pos-group-product"', $filtered);
        assert_not_contains('id="pos-group-service"', $filtered, 'Products filter has no empty Services heading');
        $searched = $page('?q=' . urlencode($tag));
        assert_contains("{$tag} Patching", $searched, 'All search includes matching services');
        assert_contains("{$tag} Tire Valve", $searched, 'All search includes matching products');
        assert_true(strpos($searched, 'id="pos-group-service"') < strpos($searched, 'id="pos-group-product"'), 'All search keeps service-first grouping');
        $searched = $page('?q=' . urlencode($tag . ' Patching'));
        assert_contains('id="pos-group-service"', $searched);
        assert_not_contains('id="pos-group-product"', $searched, 'All search omits empty Products group');
        $searched = $page('?q=' . urlencode($tag . ' Tire Valve'));
        assert_contains('id="pos-group-product"', $searched);
        assert_not_contains('id="pos-group-service"', $searched, 'All search omits empty Services group');
        assert_contains('No items yet', $html);
        assert_contains('Walk-in', $html, 'blank customer = walk-in');
        $assertClean($html, 'pos (empty)');

        // ================= POST-only + CSRF =================
        $page('?_action=add&item_id=' . $P . '&quantity=1');
        assert_contains('No items yet', $page(), 'a GET never changes the cart');

        $r = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1', '_csrf' => 'bogus'], true);
        assert_contains('session expired', strtolower($r['body']));
        assert_contains('No items yet', $r['body'], 'a bad-CSRF add did nothing');
        assert_not_contains('pos-sale--added', $r['body'], 'a refused add has no cart motion');

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
        assert_contains('<section class="pos-sale pos-sale--added"', $body, 'successful add marks only Current sale for entrance');
        assert_not_contains('pos-sale--added', $page(), 'cart-add entrance is consumed after one render');
        assert_same(3, $qtyOf($body, $P), 'adding the same item again merges into one line');
        assert_same(1, preg_match_all('/name="qty\[' . $P . '\]"/', $body), 'exactly one cart line for the item');
        assert_contains('Not enough stock', $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '8']), 'cart + new qty checked against stock');
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1', 'q' => $tag, 'type' => 'service']);
        $r = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $H, 'quantity' => '1', 'q' => $tag, 'type' => 'product', '_csrf' => $token(), 'expected_rescue_id' => '']);
        assert_same(302, $r['status'], 'cart changes redirect (PRG)');
        assert_contains('q=' . $tag, (string) $r['location'], 'the item search is kept after adding');
        assert_contains('type=product', (string) $r['location']);

        $body = $post(['_action' => 'update', "qty[{$P}]" => '4', "qty[{$S}]" => '1', "qty[{$H}]" => '1', 'qty[99999999]' => '5']);
        assert_contains('Quantities updated', $body);
        assert_not_contains('pos-sale--added', $body, 'quantity updates do not replay add motion');
        assert_same(4, $qtyOf($body, $P));
        assert_null($qtyOf($body, 99999999), 'unknown keys in the update are ignored');
        $body = $post(['_action' => 'update', "qty[{$P}]" => '0', "qty[{$S}]" => '1', "qty[{$H}]" => '1']);
        assert_contains('Each quantity must be a whole number', $body);
        assert_same(4, $qtyOf($body, $P), 'a bad update changes nothing (all-or-nothing)');

        $body = $post(['_action' => 'remove', 'item_id' => (string) $H]);
        assert_null($qtyOf($body, $H), 'removed from the cart');
        assert_same(4, $qtyOf($body, $P), 'other lines untouched');
        assert_contains('&#8369;800.50', $body, 'total = 4 x 150.00 + 200.50, shown from DB prices');
        assert_contains('<span class="pos-total__value">&#8369;800.50</span>', $body, 'the server total is the large figure beside the cash box');
        // Form semantics of the sale panel (Phase 7.3c): quantities, expected_total and
        // Complete sale live in #pos-sale, whose FIRST own submit button is "Update
        // quantities" (Enter in a quantity box); Remove buttons belong to #pos-remove.
        assert_true(preg_match('#<form id="pos-sale"(.*?)</form>#s', $body, $saleForm) === 1, 'the sale form');
        assert_contains('name="expected_total" value="80050"', $saleForm[1]);
        assert_contains('name="qty[' . $P . ']"', $saleForm[1]);
        assert_contains('value="checkout" class="btnlink pos-complete">Complete sale</button>', $saleForm[1], 'Complete sale is the red primary action of the sale form');
        preg_match_all('#<button type="submit"(?![^>]*form="pos-remove")[^>]*>#', $saleForm[1], $ownButtons);
        assert_contains('value="update"', $ownButtons[0][0] ?? '', 'Update quantities is the first submit button of the sale form');
        assert_same(2, substr_count($saleForm[1], 'form="pos-remove"'), 'one Remove per line, owned by #pos-remove');
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
            '_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'cash_tendered' => '1000', 'expected_rescue_id' => '',
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
        assert_contains('&#8369;1,000', $body, 'cash received uses grouped whole pesos');
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
        // Decision 54: cash received / change live only on the one-time POS card above (from the
        // session); the stored document — even opened straight from that card — never has them.
        assert_not_contains('&#8369;1,000', $summary['body'], 'no cash received on the Transaction Summary');
        assert_not_contains('&#8369;179.50', $summary['body'], 'no change on the Transaction Summary');
        assert_not_contains('Cash received', $summary['body']);
        assert_not_contains('Cash received', $page(), 'nor on the POS once the one-time card has been shown');
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
        assert_contains('<dd class="pos-change-final">&#8369;0</dd>', $page(), 'exact cash → zero change');
        $walkIn = $q('SELECT customer_id, total_amount FROM sales WHERE admin_id = ? ORDER BY sale_id DESC LIMIT 1', [$adminId])->fetch(\PDO::FETCH_ASSOC);
        assert_null($walkIn['customer_id'], 'walk-in sale stores customer_id NULL');
        assert_same('200.50', $walkIn['total_amount']);

        // ================= cancel sale / empty checkout =================
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        $body = $post(['_action' => 'clear']);
        assert_contains('Sale cancelled', $body);
        assert_contains('No items yet', $body);
        assert_contains('no items yet', $server->request($POS, ['_action' => 'checkout', '_csrf' => $token(), 'cash_tendered' => '100', 'expected_rescue_id' => ''])['body']);
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
