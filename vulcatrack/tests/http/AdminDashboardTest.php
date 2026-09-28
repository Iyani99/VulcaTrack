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

test('admin dashboard: guards, sales-today / low-stock / pending cards, links', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'DB' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'dashboard-password-123';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // baselines, read exactly as the page reads them
    $baseSales   = (new SaleRepository($pdo))->summarize($today, $today);
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

        $expectedSales = Money::format($baseSales['total_centavos'] + 12345);
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
