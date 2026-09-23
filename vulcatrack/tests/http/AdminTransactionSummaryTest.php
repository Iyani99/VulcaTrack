<?php
/**
 * End-to-end HTTP tests for the printable Transaction Summary
 * (admin/transaction-summary.php) — Decision 50's non-official sale document.
 *
 * Covers: admin guard + actor separation, strict sale-id validation (404 for
 * malformed / unknown ids, no error leakage), linked-customer vs walk-in,
 * the recorded sale date / cashier / quantities / frozen unit prices /
 * subtotals / total, historical correctness after the item's current price,
 * stock and active state change, output escaping, the print structure and the
 * non-official note, and clean output + server log.
 *
 * Sales are recorded through the real SaleService (committed), then every row
 * the throwaway admin created is deleted afterwards, FK-safe order.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Service\SaleService;

test('transaction summary: guards, id validation, frozen historical values, escaping, print structure', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'TXN' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'txn-password-123';

    $custEmail = TestDb::email('txn-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} <b>Ana</b> & Co", $custEmail, '09170001111', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $adminEmail = TestDb::email('txn-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier <i>Jo</i>", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $mk = function (string $name, string $type, string $price, ?int $stock) use ($pdo): int {
        $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity) VALUES (?,?,?,?)')
            ->execute([$name, $type, $price, $stock]);
        return (int) $pdo->lastInsertId();
    };
    $P = $mk("{$tag} Valve <img src=x onerror=alert(1)>", 'product', '45.50', 20);
    $S = $mk("{$tag} Hot Patch", 'service', '150.00', null);

    $q = function (string $sql, array $args = []) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s;
    };

    $server = new HttpServer(8686);
    $cleanup = function () use ($q, $custId, $adminId, $tag): void {
        $q('DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.admin_id = ?', [$adminId]);
        $q('DELETE FROM sales WHERE admin_id = ?', [$adminId]);
        $q('DELETE FROM items WHERE item_name LIKE ?', [$tag . '%']);
        $q('DELETE FROM customers WHERE customer_id = ?', [$custId]);
        $q('DELETE FROM admins WHERE admin_id = ?', [$adminId]);
    };

    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };

    $URL = '/vulcatrack/admin/transaction-summary.php';

    try {
        // Two real sales through the checkout service.
        $service = new SaleService($pdo);
        $linked = $service->checkout([
            'admin_id'    => $adminId,
            'customer_id' => $custId,
            'lines'       => [['item_id' => $P, 'quantity' => 3], ['item_id' => $S, 'quantity' => 1]],
        ]); // 3 x 45.50 + 150.00 = 286.50
        $walkIn = $service->checkout([
            'admin_id' => $adminId,
            'lines'    => [['item_id' => $S, 'quantity' => 2]],
        ]); // 300.00
        $saleDate = (string) $q('SELECT sale_date FROM sales WHERE sale_id = ?', [$linked['sale_id']])->fetchColumn();

        // Inventory changes AFTER the sale must not rewrite the document.
        $q('UPDATE items SET price = ?, stock_quantity = ?, is_active = 0 WHERE item_id = ?', ['99.99', 2, $P]);
        $q('UPDATE items SET price = ? WHERE item_id = ?', ['175.00', $S]);

        $server->start();
        $linkedUrl = $URL . '?id=' . $linked['sale_id'];

        // ================= guards / actor separation =================
        $r = $server->request($linkedUrl);
        assert_same(302, $r['status'], 'unauthenticated visitors are redirected');
        assert_contains('/admin/login.php', (string) $r['location']);

        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($linkedUrl);
        assert_same(302, $r['status'], 'a customer — even the linked one — cannot open the admin document');
        assert_contains('/admin/login.php', (string) $r['location']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        // ================= linked sale =================
        $r = $server->request($linkedUrl);
        assert_same(200, $r['status']);
        $html = $r['body'];
        $assertClean($html, 'transaction summary (linked)');

        assert_contains('Transaction Summary', $html);
        assert_contains('Gerald Tabayag Vulcanizing Shop', $html);
        assert_contains('504 San Jose St. Baliwag, Bulacan', $html, 'shop address from config/shop.php');
        assert_contains('<dd>' . $linked['sale_id'] . '</dd>', $html, 'sale number');
        assert_contains(e($saleDate), $html, 'recorded sale date/time');
        assert_contains(e("{$tag} Cashier <i>Jo</i>"), $html, 'cashier name, escaped');
        assert_contains(e("{$tag} <b>Ana</b> & Co"), $html, 'linked customer name, escaped');
        assert_not_contains('<b>Ana</b>', $html);
        assert_not_contains('<i>Jo</i>', $html);
        assert_contains(e("{$tag} Valve <img src=x onerror=alert(1)>"), $html, 'item name escaped');
        assert_not_contains('<img src=x', $html);

        // recorded quantities / frozen unit prices / subtotals / total
        assert_contains('<td class="num">3</td>', $html, 'quantity 3');
        assert_contains('&#8369;45.50', $html, 'frozen unit price of the product');
        assert_contains('&#8369;136.50', $html, 'recorded line subtotal 3 x 45.50');
        assert_contains('&#8369;150.00', $html, 'frozen unit price of the service');
        assert_contains('&#8369;286.50', $html, 'recorded total');
        assert_not_contains('99.99', $html, 'the current product price is never shown as the sale price');
        assert_not_contains('175.00', $html, 'the current service price is never shown as the sale price');
        assert_not_contains('Walk-in', $html);

        // non-official scope + print structure
        assert_contains('For transaction reference only. Not an official BIR invoice.', $html);
        assert_not_contains('official receipt', strtolower($html), 'never claims to be an Official Receipt');
        foreach (['TIN', 'VAT'] as $absent) {
            assert_not_contains('>' . $absent, $html, "no {$absent} field");
        }
        assert_contains('window.print()', $html, 'Print button');
        assert_contains('class="is-printdoc"', $html, 'print styles are scoped to this document');
        assert_contains('pagehead no-print', $html, 'screen-only controls are hidden when printing');
        assert_contains('Back to POS', $html);

        // ================= walk-in sale =================
        $html = $server->request($URL . '?id=' . $walkIn['sale_id'])['body'];
        assert_contains('<dd>Walk-in</dd>', $html, 'walk-in sale shows "Walk-in"');
        assert_contains('<td class="num">2</td>', $html);
        assert_contains('&#8369;300.00', $html, 'recorded subtotal/total at the sale-time service price');
        $assertClean($html, 'transaction summary (walk-in)');

        // ================= malformed / unknown ids =================
        foreach (['', '?id=', '?id=abc', '?id=0', '?id=-1', '?id=1.5', '?id=' . $linked['sale_id'] . 'x',
                  '?id=99999999999', '?id[]=' . $linked['sale_id'], '?id=2147483647'] as $qs) {
            $r = $server->request($URL . $qs);
            assert_same(404, $r['status'], "'{$qs}' is a clean 404");
            assert_contains('Sale not found', $r['body']);
            $assertClean($r['body'], "transaction summary {$qs}");
        }

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
