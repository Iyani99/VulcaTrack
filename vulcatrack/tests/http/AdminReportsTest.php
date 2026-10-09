<?php
/** End-to-end coverage for the single period model used by Sales Reports. */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\ReportPeriod;

test('sales reports: guarded, consistent period totals, chart, source and item breakdowns', function () {
    $pdo = test_pdo();
    assert_not_null($pdo);
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
    $sale('2001-07-02 00:00:00', '45.50', [[$valve, 1, '45.50', '45.50']]);
    $pdo->prepare('UPDATE items SET price = ? WHERE item_id IN (?, ?)')->execute(['999.99', $valve, $patch]);
    $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'RP-1')")->execute([$custId]);
    $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'flat', 'completed')")
        ->execute([$custId, (int) $pdo->lastInsertId()]);
    $rescueId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount) VALUES (?,?,?,?,?)')
        ->execute([$custId, $rescueId, $adminId, '2001-08-10 11:00:00', '368.00']);
    $sale('2001-09-15 08:00:00', '0.00', [[$patch, 1, '0.00', '0.00']]);

    $server = new HttpServer(8692);
    $cleanup = function () use ($pdo, $custId, $adminId, $tag): void {
        $pdo->prepare('DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM service_requests WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM vehicles WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };
    $flat = static fn (string $html): string => preg_replace('/\s+/', ' ', $html);
    $dayCount = static fn (string $html): int => substr_count($html, '<g class="rpt-day"');
    $card = static fn (string $value): string => '<p class="card__num">' . $value . '</p>';
    $source = static function (string $html, string $key): string {
        return preg_match('~<li class="rpt-src__row rpt-src__row--' . $key . '">(.*?)</li>~s', $html, $match) === 1
            ? preg_replace('/\s+/', ' ', $match[1]) : '';
    };
    $url = '/vulcatrack/admin/reports.php';

    try {
        $server->start();
        $guest = $server->request($url);
        assert_same(302, $guest['status']);
        assert_contains('/admin/login.php', (string) $guest['location']);
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        assert_same(302, $server->request($url)['status'], 'customer cannot read admin reports');
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);
        $adminLogin = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($adminLogin['body']), 'email' => $adminEmail, 'password' => $password]);

        $today = date('Y-m-d');
        $repo = new SaleRepository($pdo);
        foreach (['7d' => 7, '30d' => 30] as $key => $length) {
            $html = $server->request($url . '?period=' . $key)['body'];
            $period = ReportPeriod::resolve($key, null, $today, '30d');
            $summary = $repo->summarize($period['from'], $period['to']);
            $f = $flat($html);
            assert_contains('Range: <strong>' . $period['label'] . ': ' . $period['from'] . ' to ' . $period['to'] . '</strong>', $f);
            assert_contains($card((string) $summary['count']), $f);
            assert_contains($card('&#8369;' . Money::formatDisplay($summary['total_centavos'])), $f);
            assert_contains('Chart total: <strong>&#8369;' . Money::formatDisplay($summary['total_centavos']) . '</strong> from ' . $summary['count'], $f);
            assert_same($length, $dayCount($html), 'every date is represented, including zero days');
            assert_contains('Daily sales,', $f);
        }
        $default = $server->request($url)['body'];
        assert_contains('<option value="30d" selected>', $default, 'Reports defaults to 30d');
        assert_same(30, $dayCount($default));
        assert_contains('<option value="2001-07">Jul 2001</option>', $default, 'recorded historical months include their year');
        $todayReport = $server->request($url . '?period=today')['body'];
        $todaySummary = $repo->summarize($today, $today);
        assert_contains('Range: <strong>Today: ' . $today . '</strong>', $todayReport);
        assert_contains($card((string) $todaySummary['count']), $flat($todayReport));
        assert_same(1, $dayCount($todayReport), 'Dashboard today card links to one exact day');

        $july = $server->request($url . '?period=month&month=2001-07')['body'];
        $f = $flat($july);
        assert_contains('Range: <strong>Jul 2001: 2001-07-01 to 2001-07-31</strong>', $f);
        assert_contains($card('3'), $f);
        assert_contains($card('&#8369;632'), $f);
        assert_contains('Chart total: <strong>&#8369;632</strong> from 3 transactions', $f);
        assert_same(31, $dayCount($july));
        assert_contains('<g class="rpt-day" data-day="2001-07-01" data-centavos="58650">', $july);
        assert_contains('<g class="rpt-day" data-day="2001-07-03" data-centavos="0">', $july);
        assert_contains('<title>Jul 1, 2001: &#8369;586.50 &middot; 2 transactions</title>', $july);
        assert_contains('<title>Jul 3, 2001: &#8369;0 &middot; 0 transactions</title>', $july);
        assert_same(2, substr_count($july, '<rect class="rpt-bar"'), 'only revenue days draw bars');
        assert_contains('<td>2001-07-02</td> <td class="num">1</td> <td class="num">&#8369;45.50</td>', $f);
        assert_contains('<td>2001-07-01</td> <td class="num">2</td> <td class="num">&#8369;586.50</td>', $f);
        assert_contains('<td>' . e($valveName) . '</td>', $f, 'stored item name is escaped');
        assert_contains('<td>' . e($valveName) . '</td> <td class="num">4</td> <td class="num">&#8369;182</td>', $f);
        assert_contains('<td>' . e($patchName) . '</td> <td class="num">3</td> <td class="num">&#8369;450</td>', $f);
        assert_not_contains('999.99', $f, 'current catalog price is not report revenue');
        assert_not_contains('<img src=x', $f);
        assert_contains('100.0% <small>of revenue</small>', $f, 'July is all in-shop');
        assert_contains('<dt>Revenue</dt><dd>&#8369;632</dd>', $f);
        assert_contains('100.0% <small>of revenue</small>', $source($july, 'inshop'));
        assert_contains('<dt>Transactions</dt><dd>3</dd>', $source($july, 'inshop'));
        assert_contains('<dt>Revenue</dt><dd>&#8369;632</dd>', $source($july, 'inshop'));
        assert_contains('0.0% <small>of revenue</small>', $source($july, 'rescue'));
        assert_contains('<dt>Transactions</dt><dd>0</dd>', $source($july, 'rescue'));
        assert_not_contains('2001-08-10', $f, 'month ends on July 31');

        $august = $flat($server->request($url . '?period=month&month=2001-08')['body']);
        assert_contains($card('1'), $august);
        assert_contains($card('&#8369;368'), $august);
        assert_contains('100.0% <small>of revenue</small>', $august, 'Rescue source takes August revenue');
        assert_contains('<dt>Transactions</dt><dd>1</dd>', $source($august, 'rescue'));
        assert_contains('<dt>Revenue</dt><dd>&#8369;368</dd>', $source($august, 'rescue'));
        assert_contains('0.0% <small>of revenue</small>', $source($august, 'inshop'));

        $september = $flat($server->request($url . '?period=month&month=2001-09')['body']);
        assert_contains($card('1'), $september);
        assert_contains('No revenue in this window', $september, 'a free sale has a count but no revenue bar');
        assert_contains('No revenue in this range, so no revenue share is shown.', $september);
        assert_contains('<dt>Transactions</dt><dd>1</dd>', $source($september, 'inshop'));
        assert_contains('<dt>Revenue</dt><dd>&#8369;0</dd>', $source($september, 'inshop'));

        $empty = $server->request($url . '?period=month&month=1000-02')['body'];
        assert_same(28, $dayCount($empty), 'historical non-leap February has 28 zero days');
        assert_contains($card('0'), $flat($empty));
        assert_contains('No sales in this date range.', $empty);
        assert_contains('No sales in this window', $empty);
        assert_same(0, substr_count($empty, '<rect class="rpt-bar"'));
        assert_not_contains('rpt-src__share', $empty, 'zero-sales range has no invented source share');

        $year = $server->request($url . '?period=365d')['body'];
        $yearPeriod = ReportPeriod::resolve('365d', null, $today);
        $yearSummary = $repo->summarize($yearPeriod['from'], $yearPeriod['to']);
        assert_contains('Monthly sales,', $year);
        assert_true($dayCount($year) >= 12 && $dayCount($year) <= 13, '365d uses monthly buckets');
        assert_contains('data-day="' . substr($today, 0, 7) . '"', $year);
        assert_contains($card((string) $yearSummary['count']), $flat($year));
        assert_contains('Chart total: <strong>&#8369;' . Money::formatDisplay($yearSummary['total_centavos']) . '</strong>', $flat($year));

        foreach (['?period=invalid', '?period[]=month', '?period=month&month=2001-13', '?period=month&month[]=2001-07'] as $bad) {
            $response = $server->request($url . $bad);
            assert_same(200, $response['status']);
            assert_contains('<option value="30d" selected>', $response['body'], 'invalid state falls back safely');
            assert_same(30, $dayCount($response['body']));
        }
        foreach ([$july, $year, $empty] as $html) {
            foreach (['Warning:', 'Notice:', 'Fatal error', 'PDOException', 'SQLSTATE'] as $bad) {
                assert_not_contains($bad, $html);
            }
        }
        assert_not_contains('PHP Warning', $server->serverStderr());
    } finally {
        $server->stop();
        $cleanup();
    }
});
