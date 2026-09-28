<?php
/**
 * End-to-end HTTP tests for the admin Sales History page (admin/sales.php).
 *
 * Covers: admin guard + actor separation, the newest-first list (sale no.,
 * date/time, cashier, customer or Walk-in, stored total, link to the existing
 * Transaction Summary), output escaping, the From / To filter (From only, To
 * only, a range, one exact day), malformed filter values being ignored, the
 * From-after-To message, the filtered empty state + Clear link, the active nav
 * entry, and clean output + server log.
 *
 * Sales are inserted directly with fixed 2001 dates so the results do not
 * depend on live development sales or today's date; every row seeded here is
 * deleted afterwards in FK-safe order.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('sales history: guards, newest-first list, walk-in/customer, escaping, date filters, empty states', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'SH' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'history-password-123';

    $custEmail = TestDb::email('sh-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} <b>Ana</b> & Co", $custEmail, '09170002222', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $adminEmail = TestDb::email('sh-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier <i>Jo</i>", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $sale = function (?int $customerId, string $date, string $total) use ($pdo, $adminId): int {
        $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (?,?,?,?)')
            ->execute([$customerId, $adminId, $date, $total]);
        return (int) $pdo->lastInsertId();
    };
    $early   = $sale($custId, '2001-03-15 10:00:00', '286.50');
    $walkIn  = $sale(null,    '2001-05-20 08:30:00', '1234.05');
    $late    = $sale(null,    '2001-06-10 23:59:59', '300.00');
    $nextDay = $sale($custId, '2001-06-11 00:00:00', '45.00');

    $server = new HttpServer(8691);
    $cleanup = function () use ($pdo, $custId, $adminId): void {
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };
    // Each seeded sale is identified by its View link to the Transaction Summary.
    $link = fn (int $id): string => '/vulcatrack/admin/transaction-summary.php?id=' . $id . '"';
    $shows = function (string $html, array $in, array $out, string $where) use ($link): void {
        foreach ($in as $id) {
            assert_contains($link($id), $html, "{$where}: sale {$id} should be listed");
        }
        foreach ($out as $id) {
            assert_not_contains($link($id), $html, "{$where}: sale {$id} should not be listed");
        }
    };

    $URL = '/vulcatrack/admin/sales.php';

    try {
        $server->start();

        // ================= guards / actor separation =================
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'unauthenticated visitors are redirected');
        assert_contains('/admin/login.php', (string) $r['location']);

        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'a signed-in customer cannot open Sales History');
        assert_contains('/admin/login.php', (string) $r['location']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        // ================= default: all sales, newest first =================
        $r = $server->request($URL);
        assert_same(200, $r['status']);
        $html = $r['body'];
        $assertClean($html, 'sales history');
        assert_contains('<h1>Sales History</h1>', $html);
        assert_contains(' class="is-active">Sales History</a>', $html, 'the Sales History nav entry is active');
        $shows($html, [$early, $walkIn, $late, $nextDay], [], 'unfiltered');
        assert_true(strpos($html, $link($nextDay)) < strpos($html, $link($late))
            && strpos($html, $link($late)) < strpos($html, $link($walkIn))
            && strpos($html, $link($walkIn)) < strpos($html, $link($early)), 'rows are newest first');
        assert_not_contains('>Clear</a>', $html, 'no Clear link without a filter');
        assert_contains('type="date" name="from" value=""', $html);
        assert_contains('type="date" name="to" value=""', $html);

        // row contents: sale no., date/time, cashier, customer / walk-in, stored total
        assert_contains('<td>' . $early . '</td>', $html, 'sale number');
        assert_contains('2001-03-15 10:00:00', $html, 'recorded sale date/time');
        assert_contains(e("{$tag} Cashier <i>Jo</i>"), $html, 'cashier name, escaped');
        assert_contains(e("{$tag} <b>Ana</b> & Co"), $html, 'linked customer name, escaped');
        assert_not_contains('<i>Jo</i>', $html);
        assert_not_contains('<b>Ana</b>', $html);
        assert_contains('<td>Walk-in</td>', $html, 'a sale without a customer shows Walk-in');
        assert_contains('&#8369;286.50', $html, 'stored total');
        assert_contains('&#8369;1234.05', $html, 'stored total, no thousands separator (existing convention)');

        // ================= From only =================
        $html = $server->request($URL . '?from=2001-05-20')['body'];
        $assertClean($html, 'from only');
        $shows($html, [$walkIn, $late, $nextDay], [$early], 'from only');
        assert_contains('type="date" name="from" value="2001-05-20"', $html, 'the From value is kept in the form');
        assert_contains('>Clear</a>', $html, 'a Clear link while filtered');
        assert_contains('href="/vulcatrack/admin/sales.php">Clear</a>', $html, 'Clear returns to the unfiltered page');

        // ================= To only =================
        $html = $server->request($URL . '?to=2001-05-20')['body'];
        $shows($html, [$early, $walkIn], [$late, $nextDay], 'to only');

        // ================= range + one exact day =================
        $html = $server->request($URL . '?from=2001-04-01&to=2001-06-10')['body'];
        $shows($html, [$walkIn, $late], [$early, $nextDay], 'range');
        assert_contains('2 sales.', $html, 'count line');

        $html = $server->request($URL . '?from=2001-06-10&to=2001-06-10')['body'];
        $shows($html, [$late], [$early, $walkIn, $nextDay], 'From = To (23:59:59 in, next-day 00:00:00 out)');
        assert_contains('1 sale.', $html, 'singular count line');

        // ================= malformed values are ignored =================
        foreach (['?from=2001-02-31', '?to=abc', '?from[]=2001-05-20', "?from=2001-05-20'%20OR%201=1", '?from=2001-05-20%0A', '?to=0000-00-00'] as $qs) {
            $r = $server->request($URL . $qs);
            assert_same(200, $r['status'], "'{$qs}' still loads");
            $assertClean($r['body'], "sales history {$qs}");
            $shows($r['body'], [$early, $walkIn, $late, $nextDay], [], "'{$qs}' is treated as no filter");
            assert_not_contains('>Clear</a>', $r['body'], "'{$qs}' is not an active filter");
        }

        // ================= From after To =================
        $r = $server->request($URL . '?from=2001-06-10&to=2001-03-01');
        assert_same(200, $r['status']);
        $assertClean($r['body'], 'from after to');
        assert_contains('The From date cannot be later than the To date.', $r['body']);
        assert_not_contains('<table', $r['body'], 'no list for an impossible range');
        assert_not_contains('No sales in this date range.', $r['body'], 'not presented as an ordinary empty result');
        assert_contains('>Clear</a>', $r['body']);

        // ================= filtered empty state =================
        $r = $server->request($URL . '?from=2001-01-01&to=2001-01-31');
        assert_same(200, $r['status']);
        assert_contains('No sales in this date range.', $r['body']);
        assert_not_contains('<table', $r['body']);
        assert_contains('>Clear</a>', $r['body']);

        // ================= read-only: no forms that change anything =================
        $html = $server->request($URL)['body'];
        assert_not_contains('method="post" action="/vulcatrack/admin/sales.php"', $html, 'Sales History has no mutation forms');

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
