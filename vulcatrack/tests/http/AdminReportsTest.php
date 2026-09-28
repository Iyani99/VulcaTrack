<?php
/**
 * End-to-end HTTP tests for the admin Sales Reports page (admin/reports.php).
 *
 * Covers: admin guard + actor separation, the active nav entry, the range
 * label, the two summary cards, the Daily Sales and Items Sold tables (values
 * from the stored / frozen amounts), item-name escaping, the From / To filter
 * (From only, To only, a range, one exact day), malformed values being ignored,
 * the From-after-To message, the empty-range zeros + message, the Clear link,
 * no mutation forms, and clean output + server log.
 *
 * Sales and their lines are inserted directly with fixed July 2001 dates; card
 * values are only asserted for closed 2001 ranges so live development sales
 * never affect them. Every row seeded here is deleted afterwards, FK-safe.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('sales reports: guards, cards, daily + items tables, escaping, date filters, empty and error states', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'RP' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'reports-password-123';

    $custEmail = TestDb::email('rp-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} Customer", $custEmail, '09170003333', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $adminEmail = TestDb::email('rp-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $item = function (string $name, string $type, string $price, ?int $stock) use ($pdo): int {
        $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity) VALUES (?,?,?,?)')
            ->execute([$name, $type, $price, $stock]);
        return (int) $pdo->lastInsertId();
    };
    $valveName = "{$tag} Valve <img src=x onerror=alert(1)>";
    $patchName = "{$tag} Patch & Seal";
    $valve = $item($valveName, 'product', '45.50', 50);
    $patch = $item($patchName, 'service', '150.00', null);

    $sale = function (string $date, string $total, array $lines) use ($pdo, $adminId): void {
        $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)')
            ->execute([$adminId, $date, $total]);
        $saleId = (int) $pdo->lastInsertId();
        foreach ($lines as [$itemId, $qty, $unit, $subtotal]) {
            $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
                ->execute([$saleId, $itemId, $qty, $unit, $subtotal]);
        }
    };
    $sale('2001-07-01 09:00:00', '286.50', [[$valve, 3, '45.50', '136.50'], [$patch, 1, '150.00', '150.00']]);
    $sale('2001-07-01 23:59:59', '300.00', [[$patch, 2, '150.00', '300.00']]);
    $sale('2001-07-02 00:00:00', '45.50',  [[$valve, 1, '45.50', '45.50']]);
    // Current catalog prices changed after the sales — the report must not use them.
    $pdo->prepare('UPDATE items SET price = ? WHERE item_id IN (?, ?)')->execute(['999.99', $valve, $patch]);

    $server = new HttpServer(8692);
    $cleanup = function () use ($pdo, $custId, $adminId, $tag): void {
        $pdo->prepare('DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };
    // Whitespace-collapsed page, so table rows can be matched as one string.
    $flat = fn (string $html): string => preg_replace('/\s+/', ' ', $html);
    $card = fn (string $value): string => '<p class="card__num">' . $value . '</p>';
    $dayRow = fn (string $day, int $n, string $total): string =>
        "<td>{$day}</td> <td class=\"num\">{$n}</td> <td class=\"num\">&#8369;{$total}</td>";
    $itemRow = fn (string $name, int $qty, string $revenue): string =>
        '<td>' . e($name) . "</td> <td class=\"num\">{$qty}</td> <td class=\"num\">&#8369;{$revenue}</td>";

    $URL = '/vulcatrack/admin/reports.php';

    try {
        $server->start();

        // ================= guards / actor separation =================
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'unauthenticated visitors are redirected');
        assert_contains('/admin/login.php', (string) $r['location']);

        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'a signed-in customer cannot open Reports');
        assert_contains('/admin/login.php', (string) $r['location']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        // ================= default: all recorded sales =================
        $r = $server->request($URL);
        assert_same(200, $r['status']);
        $html = $r['body'];
        $assertClean($html, 'reports');
        assert_contains('<h1>Sales Reports</h1>', $html);
        assert_contains(' class="is-active">Reports</a>', $html, 'the Reports nav entry is active');
        assert_contains('Range: <strong>All recorded sales</strong>', $html, 'the default range is stated');
        assert_not_contains('>Clear</a>', $html, 'no Clear link without a filter');
        assert_contains($dayRow('2001-07-01', 2, '586.50'), $flat($html), 'the default range includes the seeded days');
        assert_not_contains('method="post" action="/vulcatrack/admin/reports.php"', $html, 'Reports has no mutation forms');

        // ================= a closed range: cards + both tables =================
        $html = $server->request($URL . '?from=2001-07-01&to=2001-07-31')['body'];
        $assertClean($html, 'reports range');
        $f = $flat($html);
        assert_contains('Range: <strong>2001-07-01 to 2001-07-31</strong>', $html);
        assert_contains('<p class="card__label">Transactions</p> ' . $card('3'), $f, 'Transactions card');
        assert_contains('<p class="card__label">Total Sales</p> ' . $card('&#8369;632.00'), $f, 'Total Sales card');
        assert_true(strpos($f, $dayRow('2001-07-02', 1, '45.50')) !== false
            && strpos($f, $dayRow('2001-07-02', 1, '45.50')) < strpos($f, $dayRow('2001-07-01', 2, '586.50')),
            'daily rows: 23:59:59 stays on its day, 00:00:00 starts the next; newest day first');
        assert_true(strpos($f, $itemRow($valveName, 4, '182.00')) !== false
            && strpos($f, $itemRow($valveName, 4, '182.00')) < strpos($f, $itemRow($patchName, 3, '450.00')),
            'items sold: frozen revenue, most units first');
        assert_not_contains('999.99', $html, 'the current catalog price is never used');
        assert_not_contains('<img src=x', $html, 'item names are escaped');
        assert_not_contains('>Type<', $html, 'no item-type column');
        assert_contains('href="/vulcatrack/admin/reports.php">Clear</a>', $html, 'Clear returns to the unfiltered report');
        assert_contains('type="date" name="from" value="2001-07-01"', $html);

        // ================= one exact day =================
        $f = $flat($server->request($URL . '?from=2001-07-01&to=2001-07-01')['body']);
        assert_contains('Range: <strong>2001-07-01</strong>', $f);
        assert_contains($card('2'), $f);
        assert_contains($card('&#8369;586.50'), $f);
        assert_not_contains('<td>2001-07-02</td>', $f);
        assert_contains($itemRow($patchName, 3, '450.00'), $f);
        assert_contains($itemRow($valveName, 3, '136.50'), $f);

        // ================= From only / To only (row checks; open ends may include dev sales) =================
        $f = $flat($server->request($URL . '?from=2001-07-02')['body']);
        assert_contains('Range: <strong>From 2001-07-02</strong>', $f);
        assert_contains($dayRow('2001-07-02', 1, '45.50'), $f);
        assert_not_contains('<td>2001-07-01</td>', $f);

        $f = $flat($server->request($URL . '?to=2001-07-01')['body']);
        assert_contains('Range: <strong>Up to 2001-07-01</strong>', $f);
        assert_contains($dayRow('2001-07-01', 2, '586.50'), $f);
        assert_not_contains('<td>2001-07-02</td>', $f);

        // ================= malformed values are ignored =================
        foreach (['?from=2001-02-31', '?to=abc', '?from[]=2001-07-01', "?from=2001-07-01'%20OR%201=1", '?to=2001-07-01%0A'] as $qs) {
            $r = $server->request($URL . $qs);
            assert_same(200, $r['status'], "'{$qs}' still loads");
            $assertClean($r['body'], "reports {$qs}");
            assert_contains('Range: <strong>All recorded sales</strong>', $r['body'], "'{$qs}' is treated as no filter");
            assert_not_contains('>Clear</a>', $r['body']);
        }

        // ================= From after To =================
        $r = $server->request($URL . '?from=2001-07-31&to=2001-07-01');
        assert_same(200, $r['status']);
        $assertClean($r['body'], 'reports from after to');
        assert_contains('The From date cannot be later than the To date.', $r['body']);
        assert_not_contains('card__num', $r['body'], 'no report figures for an impossible range');
        assert_not_contains('<table', $r['body']);
        assert_contains('>Clear</a>', $r['body']);

        // ================= empty range: zeros + message =================
        $r = $server->request($URL . '?from=2001-01-01&to=2001-01-31');
        assert_same(200, $r['status']);
        $f = $flat($r['body']);
        assert_contains($card('0'), $f, '0 transactions');
        assert_contains($card('&#8369;0.00'), $f, '₱0.00 total');
        assert_same(2, substr_count($f, 'No sales in this date range.'), 'both tables show the empty message');
        assert_not_contains('<table', $f);
        assert_contains('>Clear</a>', $f);

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
