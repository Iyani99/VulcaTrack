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
 * Phase 7.4d: the Sales Performance chart (exact window, one slot per day,
 * zero days, real totals only, the window note when the chart is not the
 * filter range) and Sales by Source (In-shop / Rescue from
 * sales.service_request_id; one-source, empty and ₱0 ranges). The Rescue sale
 * (August) and the ₱0 sale (September) sit outside July on purpose.
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

    // Phase 7.4d Sales by Source fixtures, outside July so the July figures stay as they are:
    // one Rescue-linked sale in August, and one ₱0 in-shop sale (a free service) in September.
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
    // Phase 7.4d chart / source panel readers
    $chartDay = fn (string $day, int $centavos): string => "<g class=\"rpt-day\" data-day=\"{$day}\" data-centavos=\"{$centavos}\">";
    $dayCount = fn (string $html): int => substr_count($html, '<g class="rpt-day"');
    $barCount = fn (string $html): int => substr_count($html, '<rect class="rpt-bar"');
    $source = function (string $html, string $key): string {
        return preg_match('~<li class="rpt-src__row rpt-src__row--' . $key . '">(.*?)</li>~s', $html, $m) === 1
            ? preg_replace('/\s+/', ' ', $m[1]) : '';
    };
    $assertSource = function (string $html, string $key, string $share, int $count, string $revenue) use ($source): void {
        $row = $source($html, $key);
        assert_contains('rpt-src__share">' . $share . ' <small>of revenue</small>', $row, "{$key}: revenue share {$share}");
        assert_contains("<dt>Transactions</dt><dd>{$count}</dd>", $row, "{$key}: {$count} transactions");
        assert_contains("<dt>Revenue</dt><dd>&#8369;{$revenue}</dd>", $row, "{$key}: revenue {$revenue}");
    };

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
        assert_contains('href="/vulcatrack/admin/reports.php" class="is-active" aria-current="page"', $html, 'the Reports nav entry is active');
        assert_contains('Range: <strong>All recorded sales</strong>', $html, 'the default range is stated');
        assert_not_contains('>Clear</a>', $html, 'no Clear link without a filter');
        assert_contains($dayRow('2001-07-01', 2, '586.50'), $flat($html), 'the default range includes the seeded days');
        assert_not_contains('method="post" action="/vulcatrack/admin/reports.php"', $html, 'Reports has no mutation forms');
        // no filter: the chart is the 30 days up to today, and says the tables cover everything
        $f = $flat($html);
        assert_same(30, $dayCount($html), 'default chart window: 30 days');
        assert_contains('data-day="' . date('Y-m-d') . '"', $html, 'the default window ends today');
        assert_contains('The chart shows the 30 days ending ' . date('M j, Y') . ' (today).', $f);
        assert_contains('cover <strong>All recorded sales</strong>', $f);

        // ================= a closed range: cards + both tables =================
        $html = $server->request($URL . '?from=2001-07-01&to=2001-07-31')['body'];
        $assertClean($html, 'reports range');
        $f = $flat($html);
        assert_contains('Range: <strong>2001-07-01 to 2001-07-31</strong>', $html);
        assert_contains('<p class="card__label">Transactions</p> ' . $card('3'), $f, 'Transactions card');
        assert_contains('<p class="card__label">Total Sales</p> ' . $card('&#8369;632'), $f, 'Total Sales card');
        assert_true(strpos($f, $dayRow('2001-07-02', 1, '45.50')) !== false
            && strpos($f, $dayRow('2001-07-02', 1, '45.50')) < strpos($f, $dayRow('2001-07-01', 2, '586.50')),
            'daily rows: 23:59:59 stays on its day, 00:00:00 starts the next; newest day first');
        assert_true(strpos($f, $itemRow($valveName, 4, '182')) !== false
            && strpos($f, $itemRow($valveName, 4, '182')) < strpos($f, $itemRow($patchName, 3, '450')),
            'items sold: frozen revenue, most units first');
        assert_not_contains('999.99', $html, 'the current catalog price is never used');
        assert_not_contains('<img src=x', $html, 'item names are escaped');
        assert_not_contains('>Type<', $html, 'no item-type column');
        assert_contains('href="/vulcatrack/admin/reports.php">Clear</a>', $html, 'Clear returns to the unfiltered report');
        assert_contains('type="date" name="from" value="2001-07-01"', $html);

        // ================= Sales Performance chart: a range <= 92 days is charted in full =================
        assert_contains('Daily sales, <strong>Jul 1, 2001 &ndash; Jul 31, 2001</strong> (31 days)', $f, 'the chart states its exact window');
        assert_same(31, $dayCount($html), 'one chart slot per calendar day of the range');
        assert_contains($chartDay('2001-07-01', 58650), $html, 'a day with two sales: its recorded total');
        assert_contains($chartDay('2001-07-02', 4550), $html);
        assert_contains($chartDay('2001-07-03', 0), $html, 'a day without sales is a real zero day');
        assert_contains('<title>Jul 3, 2001: &#8369;0 &middot; 0 transactions</title>', $html, 'zero day tooltip');
        assert_contains('<title>Jul 1, 2001: &#8369;586.50 &middot; 2 transactions</title>', $html);
        assert_same(2, $barCount($html), 'bars only for the two days with revenue — no filler bars');
        assert_same(1, preg_match_all('~<text class="rpt-peak"[^>]*>&#8369;586\.50</text>~', $html), 'only the peak day is labelled, with its real total');
        assert_contains('Chart total: <strong>&#8369;632</strong> from 3 transactions', $f, 'chart total = the cards for this range');
        assert_contains('>&#8369;600</text>', $html, 'clean y-axis top');
        assert_not_contains('rpt-chart__scope', $html, 'no window note when the chart is the filter range');
        assert_contains('role="img" aria-labelledby="rpt-svg-title rpt-svg-desc"', $html, 'the SVG is labelled for assistive tech');
        foreach (['vs yesterday', 'on track', 'target', 'success rate', 'export', 'growth'] as $fake) {
            assert_not_contains($fake, strtolower($html), "no invented metric: {$fake}");
        }

        // ================= Sales by Source: In-shop only in July =================
        $assertSource($html, 'inshop', '100.0%', 3, '632');
        $assertSource($html, 'rescue', '0.0%', 0, '0');
        assert_contains('For <strong>2001-07-01 to 2001-07-31</strong>', $f, 'the source panel states its range');

        // Rescue only (August) — chart + source
        $html = $server->request($URL . '?from=2001-08-01&to=2001-08-31')['body'];
        $assertClean($html, 'reports august');
        $assertSource($html, 'rescue', '100.0%', 1, '368');
        $assertSource($html, 'inshop', '0.0%', 0, '0');
        assert_contains($chartDay('2001-08-10', 36800), $html, 'the Rescue sale is charted on its day');
        assert_same(1, $barCount($html));

        // both sources (62 days, still charted in full)
        $html = $server->request($URL . '?from=2001-07-01&to=2001-08-31')['body'];
        assert_contains('(62 days)', $html);
        assert_same(62, $dayCount($html));
        $assertSource($html, 'inshop', '63.2%', 3, '632');
        $assertSource($html, 'rescue', '36.8%', 1, '368');
        assert_contains('style="width: 63.2%"', $html, 'the meter shows revenue share only');

        // a ₱0 sale: a real transaction, but no revenue to share or to draw
        $html = $server->request($URL . '?from=2001-09-01&to=2001-09-30')['body'];
        $f = $flat($html);
        $assertClean($html, 'reports september');
        assert_contains($card('1'), $f);
        $assertSource($html, 'inshop', '&mdash;', 1, '0');
        $assertSource($html, 'rescue', '&mdash;', 0, '0');
        assert_contains('No revenue in this range, so no revenue share is shown.', $html, 'no fake percentages');
        assert_same(0, $barCount($html));
        assert_contains('>No revenue in this window</text>', $html, 'not "no sales" — there was one');
        assert_contains('Chart total: <strong>&#8369;0</strong> from 1 transaction', $f);

        // ================= chart window vs a wider or one-sided filter =================
        $f = $flat($server->request($URL . '?from=2001-07-01&to=2001-12-31')['body']);
        assert_contains('Daily sales, <strong>Dec 2, 2001 &ndash; Dec 31, 2001</strong> (30 days)', $f, 'over 92 days → the 30 ending at To');
        assert_contains('The chart shows the 30 days ending Dec 31, 2001, because ranges over 92 days are not charted day by day.', $f);
        assert_contains('The totals, Sales by Source and tables cover <strong>2001-07-01 to 2001-12-31</strong>.', $f, 'the wider table range is spelled out');
        assert_contains($card('5'), $f, 'the cards keep the full filter');
        assert_contains('>No sales in this window</text>', $f);

        $f = $flat($server->request($URL . '?to=2001-07-10')['body']);
        assert_contains('Daily sales, <strong>Jun 11, 2001 &ndash; Jul 10, 2001</strong> (30 days)', $f, 'To only → the 30 days ending at To');
        assert_contains('The chart shows the 30 days ending Jul 10, 2001.', $f, 'not "(today)" when To is given');
        assert_contains('cover <strong>Up to 2001-07-10</strong>', $f);
        assert_contains($chartDay('2001-07-01', 58650), $f);

        // ================= one exact day =================
        $f = $flat($server->request($URL . '?from=2001-07-01&to=2001-07-01')['body']);
        assert_contains('Range: <strong>2001-07-01</strong>', $f);
        assert_contains($card('2'), $f);
        assert_contains($card('&#8369;586.50'), $f);
        assert_not_contains('<td>2001-07-02</td>', $f);
        assert_contains($itemRow($patchName, 3, '450'), $f);
        assert_contains($itemRow($valveName, 3, '136.50'), $f);

        // ================= From only / To only (row checks; open ends may include dev sales) =================
        $f = $flat($server->request($URL . '?from=2001-07-02')['body']);
        assert_contains('Range: <strong>From 2001-07-02</strong>', $f);
        assert_contains('The chart shows the 30 days ending ' . date('M j, Y') . ' (today).', $f, 'From only → the chart still ends today');
        assert_contains('cover <strong>From 2001-07-02</strong>', $f);
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
        assert_not_contains('rpt-plot', $r['body'], 'no chart for an impossible range');
        assert_not_contains('rpt-src', $r['body'], 'no source panel for an impossible range');
        assert_contains('>Clear</a>', $r['body']);

        // ================= empty range: zeros + message =================
        $r = $server->request($URL . '?from=2001-01-01&to=2001-01-31');
        assert_same(200, $r['status']);
        $f = $flat($r['body']);
        assert_contains($card('0'), $f, '0 transactions');
        assert_contains($card('&#8369;0'), $f, '₱0 total');
        assert_same(3, substr_count($f, 'No sales in this date range.'), 'both tables and Sales by Source show the empty message');
        assert_not_contains('<table', $f);
        assert_not_contains('rpt-src__share', $f, 'no percentages without sales');
        assert_same(31, $dayCount($f), 'the empty month is still charted as 31 zero days');
        assert_same(0, $barCount($f));
        assert_contains('>No sales in this window</text>', $f);
        assert_contains('Chart total: <strong>&#8369;0</strong> from 0 transactions', $f);
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
