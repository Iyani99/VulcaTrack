<?php
/**
 * End-to-end HTTP test for the admin dashboard cards (admin/index.php, Phase 7.2).
 *
 * The cards show shop-wide figures, so the test reads each baseline through
 * the same repository the page uses, seeds rows that must and must not count,
 * and expects exactly baseline + the rows that qualify:
 *
 * - Total Sales Today: a sale on the date rendered by the page counts; a sale
 *   on the prior date does not, even if the test crosses midnight.
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
    // The override exercises a parent that seeded just before midnight while
    // the HTTP child renders the next day. It affects this test's fixtures only.
    $fixtureDate = getenv('VULCATRACK_DASHBOARD_FIXTURE_DATE');
    $today = $fixtureDate !== false ? $fixtureDate : date('Y-m-d');
    $fixtureDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    if ($fixtureDay === false || $fixtureDay->format('Y-m-d') !== $today) {
        throw new \RuntimeException('Invalid dashboard test fixture date.');
    }
    $yesterday = $fixtureDay->modify('-1 day')->format('Y-m-d');
    $trendFrom = $fixtureDay->modify('-6 days')->format('Y-m-d');
    $middleDay = $fixtureDay->modify('-3 days')->format('Y-m-d');
    $nextDay = $fixtureDay->modify('+1 day')->format('Y-m-d');

    // Read both possible dashboard dates before seeding. The page prints the
    // date it used, so later assertions can select the matching baseline.
    $saleRepo = new SaleRepository($pdo);
    $baseSales = [];
    $baseTrend = [];
    foreach ([$today, $nextDay] as $pageDate) {
        $from = (new \DateTimeImmutable($pageDate))->modify('-6 days')->format('Y-m-d');
        $baseSales[$pageDate] = $saleRepo->summarize($pageDate, $pageDate);
        $baseTrend[$pageDate] = ReportChart::fillDays($saleRepo->listDailyTotals($from, $pageDate), $from, 7);
    }
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

    // Distinct sales on both possible rendered dates keep the boundary case
    // meaningful even when the child reaches the next day after fixture setup.
    $sale = $pdo->prepare('INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL,?,?,?)');
    $sale->execute([$adminId, $today . ' 00:00:05', '123.45']);
    $sale->execute([$adminId, $nextDay . ' 00:00:05', '234.56']);
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
    $pageDate = static function (string $html) use ($baseSales): string {
        assert_same(1, preg_match('/Overview as of (\d{4}-\d{2}-\d{2});/', $html, $match),
            'the dashboard identifies the application date used for its figures');
        assert_true(isset($baseSales[$match[1]]), 'dashboard date must be the fixture day or its next day');
        return $match[1];
    };

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
        $renderedToday = $pageDate($html);
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined ', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on the dashboard: {$bad}");
        }
        assert_contains('<h1>Dashboard Overview</h1>', $html);
        assert_contains(e("{$tag} <i>Boss</i>"), $html, 'the admin name is escaped');
        assert_not_contains('<i>Boss</i>', $html);

        preg_match_all('#<p class="dash-card__value">(.*?)</p>#', $html, $m);
        assert_count(3, $m[1], 'three summary cards');
        [$salesValue, $lowValue, $pendingValue] = $m[1];

        $fixtureSaleToday = $renderedToday === $today ? 12345 : 23456;
        $expectedSales = Money::formatDisplay($baseSales[$renderedToday]['total_centavos'] + $fixtureSaleToday);
        assert_same('&#8369;' . $expectedSales, $salesValue, 'today\'s sale counts, yesterday\'s does not');
        $n = $baseSales[$renderedToday]['count'] + 1;
        assert_contains($n . ' ' . ($n === 1 ? 'transaction' : 'transactions') . ' recorded today', $html);
        assert_same((string) ($baseLow + 1), $lowValue, 'only the active product at/below its reorder level counts');
        assert_same((string) ($basePending + 2), $pendingValue, 'only pending requests count');

        // each card links to the page behind its number
        assert_contains('href="/vulcatrack/admin/reports.php?period=today"', $html);
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
        assert_contains('<h2 id="dash-trend-title">Sales Trend — Last 7 days</h2>', $html);
        assert_contains('class="dash-trend__line" pathLength="1"', $html, 'the line is ready for a progressive draw');
        preg_match_all('/<li data-day="(\d{4}-\d{2}-\d{2})" data-centavos="(\d+)">/', $html, $trendRows, PREG_SET_ORDER);
        assert_count(7, $trendRows, 'exactly seven trend days');
        $additions = [$today => 12345, $nextDay => 23456, $yesterday => 99999, $middleDay => 5, $trendFrom => 125000];
        $expectedTotal = 0;
        foreach ($baseTrend[$renderedToday] as $i => $day) {
            $expected = $day['total_centavos'] + ($additions[$day['day']] ?? 0);
            assert_same($day['day'], $trendRows[$i][1], 'trend ordering follows calendar days');
            assert_same((string) $expected, $trendRows[$i][2], 'trend day uses the recorded total, including zero days');
            $expectedTotal += $expected;
        }
        assert_contains('Trend total <strong>&#8369;' . Money::formatDisplay($expectedTotal) . '</strong>', $html);

        $dashboardUrl = '/vulcatrack/admin/index.php';
        $thirty = $server->request($dashboardUrl . '?period=30d')['body'];
        $thirtyDate = $pageDate($thirty);
        assert_contains('Sales Trend — Last 30 days', $thirty);
        preg_match_all('/<li data-day="(\d{4}-\d{2}-\d{2})" data-centavos="(\d+)">/', $thirty, $thirtyRows);
        assert_count(30, $thirtyRows[0], '30d keeps one recorded or zero slot for each day');
        assert_contains('Total Sales (Today)', $thirty, 'the period does not redefine the today card');
        preg_match('#<p class="dash-card__value">(.*?)</p>#', $thirty, $thirtyCard);
        assert_same('&#8369;' . Money::formatDisplay($baseSales[$thirtyDate]['total_centavos']
            + ($thirtyDate === $today ? 12345 : 23456)), $thirtyCard[1] ?? null,
            'the 30-day trend filter does not change the sale total for the displayed day');

        $year = $server->request($dashboardUrl . '?period=365d')['body'];
        $yearDate = $pageDate($year);
        assert_contains('Sales Trend — Last 365 days', $year);
        preg_match_all('/<li data-day="(\d{4}-\d{2})" data-centavos="(\d+)">/', $year, $monthRows);
        assert_true(count($monthRows[0]) >= 12 && count($monthRows[0]) <= 13, 'rolling 365 days is grouped into calendar months');
        assert_contains('data-day="' . substr($yearDate, 0, 7) . '"', $year);

        $selected = substr($today, 0, 7);
        $month = $server->request($dashboardUrl . '?period=month&month=' . $selected)['body'];
        assert_contains('Sales Trend — ' . date('M Y', strtotime($selected . '-01')), $month);
        preg_match_all('/<li data-day="(\d{4}-\d{2}-\d{2})" data-centavos="(\d+)">/', $month, $monthDays);
        assert_count((int) date('t', strtotime($selected . '-01')), $monthDays[0], 'specific month includes every calendar day');

        $emptyMonth = $server->request($dashboardUrl . '?period=month&month=1000-02')['body'];
        assert_same(28, preg_match_all('/<li data-day="1000-02-\d{2}" data-centavos="0">/', $emptyMonth), 'empty historical month has real zero days');
        foreach (['?period=bad', '?period=month&month=2026-02-31', '?period[]=30d'] as $badPeriod) {
            assert_contains('Sales Trend — Last 7 days', $server->request($dashboardUrl . $badPeriod)['body'], 'invalid period falls back to 7d');
        }

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
