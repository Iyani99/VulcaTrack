<?php
/**
 * End-to-end HTTP test for the admin dashboard cards (admin/index.php, Phase 7.2).
 *
 * The cards show shop-wide figures, so the test reads each baseline through
 * the same repository the page uses, seeds rows that must and must not count,
 * and expects exactly baseline + the rows that qualify:
 *
 * - Total Sales Today: a sale at today 00:00:05 counts; yesterday 23:59:59 does not.
 * - Low Stock Alerts: an active product at/below its reorder level counts; a
 *   product above it, an inactive low product, a product without a reorder
 *   level and a service do not.
 * - Pending Rescues: pending requests count; accepted / rejected / completed do not.
 *
 * Also: admin guard + actor separation, card links, escaping of the admin name,
 * no forms, clean output + server log. Seeded rows are deleted afterwards.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\ReportChart;

test('admin dashboard: guards, sales-today / low-stock / pending cards, links', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'DB' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'dashboard-password-123';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $trendFrom = date('Y-m-d', strtotime('-6 days'));
    $middleDay = date('Y-m-d', strtotime('-3 days'));

    // baselines, read exactly as the page reads them
    $baseSales   = (new SaleRepository($pdo))->summarize($today, $today);
    $baseTrend   = ReportChart::fillDays((new SaleRepository($pdo))->listDailyTotals($trendFrom, $today), $trendFrom, 7);
    $baseLow     = count((new ItemRepository($pdo))->lowStockProducts());
    $basePending = count((new ServiceRequestRepository($pdo))->listForAdmin('pending'));

    $custEmail = TestDb::email('db-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} Customer", $custEmail, '09170005555', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$custId, $tag]);
    $vehicleId = (int) $pdo->lastInsertId();

    $adminEmail = TestDb::email('db-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} <i>Boss</i>", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    // sales: today counts, yesterday does not
    $sale = $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)');
    $sale->execute([$adminId, $today . ' 00:00:05', '123.45']);
    $sale->execute([$adminId, $yesterday . ' 23:59:59', '999.99']);
    $sale->execute([$adminId, $middleDay . ' 12:00:00', '0.05']);
    $sale->execute([$adminId, $trendFrom . ' 12:00:00', '1250.00']);

    // items: only the first is low stock by the Inventory rule
    $item = $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, reorder_level, is_active) VALUES (?,?,?,?,?,?)');
    $item->execute(["{$tag} Low Active", 'product', '10.00', 2, 5, 1]);
    $item->execute(["{$tag} Plenty", 'product', '10.00', 10, 5, 1]);
    $item->execute(["{$tag} Low Inactive", 'product', '10.00', 1, 5, 0]);
    $item->execute(["{$tag} No Reorder", 'product', '10.00', 0, null, 1]);
    $item->execute(["{$tag} Service", 'service', '10.00', null, null, 1]);

    // requests: two pending, one of each other status
    $requests = new ServiceRequestRepository($pdo);
    $requestIds = [];
    foreach (['pending', 'pending', 'accepted', 'rejected', 'completed'] as $status) {
        $id = $requests->createPending($custId, $vehicleId, "{$tag} flat tire", 14.95, 120.89, 10);
        $pdo->prepare('UPDATE service_requests SET status = ? WHERE request_id = ?')->execute([$status, $id]);
        $requestIds[] = $id;
    }

    $server = new HttpServer(8695);
    $cleanup = function () use ($pdo, $adminId, $custId, $tag): void {
        $pdo->prepare('DELETE FROM service_requests WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM vehicles WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $URL = '/vulcatrack/admin/index.php';

    try {
        $server->start();

        // guards / actor separation
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'a guest is redirected');
        assert_contains('/admin/login.php', (string) $r['location']);
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'a customer cannot open the admin dashboard');
        assert_contains('/admin/login.php', (string) $r['location']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        $r = $server->request($URL);
        assert_same(200, $r['status']);
        $html = $r['body'];
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined ', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on the dashboard: {$bad}");
        }
        assert_contains('<h1>Dashboard Overview</h1>', $html);
        assert_contains(e("{$tag} <i>Boss</i>"), $html, 'the admin name is escaped');
        assert_not_contains('<i>Boss</i>', $html);

        preg_match_all('#<p class="dash-card__value">(.*?)</p>#', $html, $m);
        assert_count(3, $m[1], 'three summary cards');
        [$salesValue, $lowValue, $pendingValue] = $m[1];

        $expectedSales = Money::formatDisplay($baseSales['total_centavos'] + 12345);
        assert_same('&#8369;' . $expectedSales, $salesValue, 'today\'s sale counts, yesterday\'s does not');
        $n = $baseSales['count'] + 1;
        assert_contains($n . ' ' . ($n === 1 ? 'transaction' : 'transactions') . ' recorded today', $html);
        assert_same((string) ($baseLow + 1), $lowValue, 'only the active product at/below its reorder level counts');
        assert_same((string) ($basePending + 2), $pendingValue, 'only pending requests count');

        // each card links to the page behind its number
        assert_contains('href="/vulcatrack/admin/reports.php?from=' . $today . '&amp;to=' . $today . '"', $html);
        assert_contains('href="/vulcatrack/admin/inventory.php?low_stock=1"', $html);
        assert_contains('href="/vulcatrack/admin/rescue.php?status=pending"', $html);
        assert_not_contains('<form method="post" action="/vulcatrack/admin/index.php"', $html, 'read-only');

        // Needs Attention (Phase 7.3b): rendered from the same two reads as the
        // cards — pending requests only (newest first, so the seeded ones lead),
        // low-stock items only, at most 5 rows each, then a link to the full page.
        assert_contains('<h2 class="dash-section">Needs Attention</h2>', $html);
        foreach ([$requestIds[0], $requestIds[1]] as $id) {
            assert_contains('href="/vulcatrack/admin/rescue-view.php?id=' . $id . '"', $html, 'a pending request is listed');
        }
        foreach ([$requestIds[2], $requestIds[3], $requestIds[4]] as $id) {
            assert_not_contains('rescue-view.php?id=' . $id . '"', $html, 'accepted / rejected / completed requests are not listed');
        }
        foreach (['Plenty', 'Low Inactive', 'No Reorder', 'Service'] as $other) {
            assert_not_contains("{$tag} {$other}", $html, "{$other} is not a low-stock item");
        }
        if ($baseLow + 1 <= 5) {
            assert_contains(e("{$tag} Low Active"), $html, 'the seeded low-stock product is listed');
        } else {
            assert_contains('View all ' . ($baseLow + 1) . ' low-stock items', $html, 'a longer list links to Inventory');
        }
        assert_true(substr_count($html, '<li>') <= 10, 'at most 5 rows per list');

        // The trend reuses recorded daily totals and fills the seven calendar
        // days oldest first, including any day with no recorded sales.
        assert_contains('<h2 id="dash-trend-title">Sales Trend — Last 7 Days</h2>', $html);
        assert_contains('class="dash-trend__line" pathLength="1"', $html, 'the line is ready for a progressive draw');
        preg_match_all('/<li data-day="(\d{4}-\d{2}-\d{2})" data-centavos="(\d+)">/', $html, $trendRows, PREG_SET_ORDER);
        assert_count(7, $trendRows, 'exactly seven trend days');
        $additions = [$today => 12345, $yesterday => 99999, $middleDay => 5, $trendFrom => 125000];
        $expectedTotal = 0;
        foreach ($baseTrend as $i => $day) {
            $expected = $day['total_centavos'] + ($additions[$day['day']] ?? 0);
            assert_same($day['day'], $trendRows[$i][1], 'trend ordering follows calendar days');
            assert_same((string) $expected, $trendRows[$i][2], 'trend day uses the recorded total, including zero days');
            $expectedTotal += $expected;
        }
        assert_contains('7-day total <strong>&#8369;' . Money::formatDisplay($expectedTotal) . '</strong>', $html);

        // the Inventory low-stock view lists the seeded low product and not the others
        $inv = $server->request('/vulcatrack/admin/inventory.php?low_stock=1&q=' . $tag)['body'];
        assert_contains("{$tag} Low Active", $inv);
        foreach (['Plenty', 'Low Inactive', 'No Reorder', 'Service'] as $other) {
            assert_not_contains("{$tag} {$other}", $inv, "{$other} is not low stock");
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
