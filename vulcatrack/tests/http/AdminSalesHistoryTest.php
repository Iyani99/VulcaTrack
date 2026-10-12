<?php
/** End-to-end checks for the operational Sales History screen. */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Sales History: guarded filters, summary, paging, old Reports links and empty states', function () {
    $pdo = test_pdo();
    $tag = 'SH' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'history-password-123';
    $custEmail = TestDb::email('sh-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} <b>Ana</b>", $custEmail, '09170002222', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();
    $adminEmail = TestDb::email('sh-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier <i>Jo</i>", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'SH-1')")->execute([$custId]);
    $vehicleId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'flat', 'accepted')")
        ->execute([$custId, $vehicleId]);
    $rescueId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, is_active) VALUES (?,?,?,?,0)')
        ->execute([$tag . ' Archived', 'product', '999.00', 0]);
    $itemId = (int) $pdo->lastInsertId();
    $ids = [];
    for ($i = 0; $i < 27; $i++) {
        $rescue = $i === 26;
        $pdo->prepare('INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount) VALUES (?,?,?,?,?)')
            ->execute([$rescue ? $custId : null, $rescue ? $rescueId : null, $adminId,
                $i === 0 ? '2001-06-10 00:00:00' : '2001-06-10 23:59:59', $rescue ? '5.00' : '1.00']);
        $ids[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
        ->execute([$ids[0], $itemId, 1, '1.00', '1.00']);
    $pdo->prepare("UPDATE service_requests SET status = 'completed' WHERE request_id = ?")->execute([$rescueId]);
    $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)')
        ->execute([$adminId, '2001-06-11 00:00:00', '2.00']);
    $nextDay = (int) $pdo->lastInsertId();
    $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
    $quickIds = [];
    foreach ([0, 1, 6, 29, 30] as $daysAgo) {
        $date = $today->modify("-{$daysAgo} days")->format('Y-m-d') . ' 12:00:00';
        $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)')
            ->execute([$adminId, $date, '1.00']);
        $quickIds[$daysAgo] = (int) $pdo->lastInsertId();
    }

    $server = new HttpServer(8691);
    $url = '/vulcatrack/admin/sales.php';
    $link = static fn (int $id): string => '/vulcatrack/admin/transaction-summary.php?id=' . $id . '"';
    try {
        $server->start();
        assert_same(302, $server->request($url)['status'], 'guest cannot read Sales History');
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']),
            'email' => $custEmail, 'password' => $password]);
        assert_same(302, $server->request($url)['status'], 'customer cannot read Sales History');
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);
        $login = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($login['body']),
            'email' => $adminEmail, 'password' => $password]);

        $default = $server->request($url);
        assert_same(200, $default['status']);
        assert_contains('>Today</strong>', $default['body'], 'Today is the visible normal default');
        assert_contains($today->format('F j, Y'), $default['body'], 'active range is readable');
        assert_not_contains('<select name="period">', $default['body'], 'period choices are direct controls');
        foreach (['Today', 'Yesterday', '7 Days', '30 Days', 'Exact Day', 'Custom'] as $label) {
            assert_contains('>' . $label . '</a>', $default['body'], $label . ' has a period control');
        }
        assert_contains('data-sales-period="today" class="is-active" aria-current="true"', $default['body']);
        assert_contains('href="/vulcatrack/admin/sales.php?period=yesterday"', $default['body']);
        assert_contains('href="/vulcatrack/admin/sales.php?period=7d"', $default['body']);
        assert_contains('href="/vulcatrack/admin/sales.php?period=30d"', $default['body']);
        assert_contains('sales-history__dates--exact', $default['body'], 'Exact Day form is available');
        assert_contains('sales-history__dates--custom', $default['body'], 'Custom form is available');
        assert_not_contains('>Apply</button>', $default['body'], 'generic Apply button is gone');
        assert_contains('Show day</button>', $default['body'], 'no-JS Exact Day fallback');
        assert_contains('Show range</button>', $default['body'], 'no-JS Custom fallback');
        assert_contains($link($quickIds[0]), $default['body'], 'Today includes current Manila day');
        assert_not_contains($link($quickIds[1]), $default['body'], 'Today excludes Yesterday');
        assert_not_contains($link($ids[0]), $default['body'], 'historical sales are not in Today');
        assert_not_contains('Reset to Today', $default['body'], 'reset is redundant in the normal default');
        $yesterday = $server->request($url . '?period=yesterday')['body'];
        assert_contains('>Yesterday</strong>', $yesterday);
        assert_contains($today->modify('-1 day')->format('F j, Y'), $yesterday);
        assert_contains($link($quickIds[1]), $yesterday);
        assert_not_contains($link($quickIds[0]), $yesterday);
        $seven = $server->request($url . '?period=7d')['body'];
        assert_contains($today->modify('-6 days')->format('F j, Y') . ' to ' . $today->format('F j, Y'), $seven);
        assert_contains($link($quickIds[6]), $seven, '7 Days includes the sixth previous day');
        assert_not_contains($link($quickIds[29]), $seven);
        $thirty = $server->request($url . '?period=30d')['body'];
        assert_contains($today->modify('-29 days')->format('F j, Y') . ' to ' . $today->format('F j, Y'), $thirty);
        assert_contains($link($quickIds[29]), $thirty, '30 Days includes the 29th previous day');
        assert_not_contains($link($quickIds[30]), $thirty, '30 Days excludes the 30th previous day');
        $pdo->prepare('DELETE FROM sales WHERE sale_id = ?')->execute([$quickIds[0]]);
        assert_contains('No sales today.', $server->request($url)['body']);

        $exact = $server->request($url . '?from=2001-06-10&to=2001-06-10')['body'];
        assert_contains('data-label="Sale"', $exact, 'mobile labelled record fields');
        assert_contains('data-label="Date / time"', $exact);
        assert_contains('data-label="Source"', $exact);
        assert_contains('data-label="Customer"', $exact);
        assert_contains('data-label="Total"', $exact);
        assert_contains('>Exact Day</strong>', $exact, 'Reports same-day link is inferred');
        assert_contains('June 10, 2001', $exact, 'Reports day is human-readable');
        assert_contains('data-sales-period="exact" class="is-active" aria-current="true"', $exact);
        assert_contains('27 transactions', $exact, 'summary covers all pages');
        assert_contains('Total Sales: <strong>&#8369;31</strong>', $exact, 'stored total covers all pages');
        assert_contains('26 In-shop &middot; 1 Rescue-linked', $exact);
        assert_contains('Reset to Today', $exact);
        assert_contains('Showing 1–25 of 27 transactions', $exact);
        assert_contains($link($ids[26]), $exact);
        assert_not_contains($link($ids[0]), $exact, 'oldest sale is on page 2');
        assert_not_contains($link($nextDay), $exact, 'next-day midnight is excluded');
        assert_contains('page=2', $exact, 'pagination retains a page link');
        assert_contains('period=exact', $exact, 'pagination retains inferred period');
        assert_contains('day=2001-06-10', $exact, 'pagination retains date');
        assert_contains(e("{$tag} Cashier <i>Jo</i>"), $exact);
        assert_not_contains('<i>Jo</i>', $exact, 'cashier HTML escaped');
        assert_contains('Rescue #' . $rescueId, $exact);
        assert_true(strpos($exact, $link($ids[26])) < strpos($exact, $link($ids[25])),
            'same-timestamp sales are ordered by id');

        $second = $server->request($url . '?period=exact&day=2001-06-10&page=2')['body'];
        assert_contains('Showing 26–27 of 27 transactions', $second);
        assert_contains($link($ids[0]), $second);
        assert_not_contains($link($ids[26]), $second);
        $clamped = $server->request($url . '?period=exact&day=2001-06-10&page=999')['body'];
        assert_contains('Page 2 of 2', $clamped);
        assert_contains($link($ids[0]), $clamped);

        $rescue = $server->request($url . '?period=exact&day=2001-06-10&source=rescue&sort=highest')['body'];
        assert_contains('1 transaction', $rescue);
        assert_not_contains('1 transactions', $rescue);
        assert_contains('Total Sales: <strong>&#8369;5</strong>', $rescue);
        assert_contains('name="source" value="rescue"', $rescue, 'date controls preserve source');
        assert_contains('name="sort" value="highest"', $rescue, 'date controls preserve sort');
        assert_contains($link($ids[26]), $rescue);
        assert_not_contains($link($ids[25]), $rescue);
        $inshop = $server->request($url . '?period=exact&day=2001-06-10&source=in_shop')['body'];
        assert_contains('26 transactions', $inshop);
        assert_not_contains($link($ids[26]), $inshop);
        assert_contains($link($ids[25]), $inshop);
        assert_contains('source=in_shop', $inshop, 'source survives pagination');

        $custom = $server->request($url . '?from=2001-06-10&to=2001-06-11')['body'];
        assert_contains('>Custom</strong>', $custom, 'Reports multi-day link is inferred');
        assert_contains('June 10, 2001 to June 11, 2001', $custom);
        assert_contains('data-sales-period="custom" class="is-active" aria-current="true"', $custom);
        assert_contains('28 transactions', $custom);
        assert_contains($link($nextDay), $custom);
        assert_contains('value="2001-06-10"', $custom, 'incoming bounds are editable');
        $fromOnly = $server->request($url . '?from=2001-06-11')['body'];
        assert_contains($link($nextDay), $fromOnly);
        assert_not_contains($link($ids[26]), $fromOnly);
        $toOnly = $server->request($url . '?to=2001-06-10')['body'];
        assert_not_contains($link($nextDay), $toOnly);
        assert_contains('27 transactions', $toOnly);

        foreach (['?from=2001-02-31', '?from[]=2001-06-10', '?sort=sale_id', '?source=other',
            '?page=0', '?period=all', '?from=2001-06-11&to=2001-06-10'] as $invalid) {
            $body = $server->request($url . $invalid)['body'];
            assert_contains('role="alert"', $body, $invalid . ' shows an error');
            assert_not_contains('<table', $body, $invalid . ' never broadens results');
        }
        $empty = $server->request($url . '?period=exact&day=2099-01-01')['body'];
        assert_contains('0 transactions', $empty);
        assert_contains('Total Sales: <strong>&#8369;0</strong>', $empty);
        assert_contains('No sales in the selected range.', $empty);
        $noSource = $server->request($url . '?period=exact&day=2001-06-11&source=rescue')['body'];
        assert_contains('No matches for the selected source.', $noSource);
        $summary = $server->request('/vulcatrack/admin/transaction-summary.php?id=' . $ids[0])['body'];
        assert_contains('1.00', $summary, 'archived Item line retains frozen price');
        assert_contains('Sales History</a>', $summary, 'normal browser back and existing link remain');
        assert_not_contains('PHP Warning', $server->serverStderr());
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$ids[0]]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM items WHERE item_id = ?')->execute([$itemId]);
        $pdo->prepare('DELETE FROM service_requests WHERE request_id = ?')->execute([$rescueId]);
        $pdo->prepare('DELETE FROM vehicles WHERE vehicle_id = ?')->execute([$vehicleId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
