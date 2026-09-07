<?php
/**
 * End-to-end HTTP tests for the Phase 5 Chunk 3A Inventory browsing page
 * (admin/inventory.php) — read-only.
 *
 * Covers the admin guard, actor separation, the GET filters (search / type /
 * status / low-stock), the product-vs-service distinction, low-stock flagging
 * only where appropriate, output escaping, and clean (warning-free) rendering.
 *
 * Throwaway items + a customer + an admin are seeded via PDO before the server
 * starts and deleted afterwards. The database must be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin inventory: guard, actor separation, filters, low-stock and escaping', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'INVX' . substr(bin2hex(random_bytes(4)), 0, 8);

    $custEmail  = TestDb::email('inv-cust');
    $adminEmail = TestDb::email('inv-admin');
    $password   = 'inv-password-123';

    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Inv Customer', $custEmail, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['Inv Admin', $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $mk = function (array $o) use ($pdo): int {
        $pdo->prepare(
            'INSERT INTO items (item_name, item_type, category, price, stock_quantity, reorder_level, is_active)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $o['name'], $o['type'], $o['cat'] ?? null, $o['price'],
            $o['stock'] ?? null, $o['reorder'] ?? null, $o['active'] ?? 1,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $ids = [];
    $ids['lowProduct']  = $mk(['name' => "{$tag} Low Widget",  'type' => 'product', 'cat' => 'Fasteners', 'price' => '12.50', 'stock' => 3,  'reorder' => 5]);
    $ids['okProduct']   = $mk(['name' => "{$tag} OK Widget",   'type' => 'product', 'cat' => 'Fasteners', 'price' => '100.00', 'stock' => 50, 'reorder' => 5]);
    $ids['service']     = $mk(['name' => "{$tag} Labor",       'type' => 'service', 'cat' => 'Services',  'price' => '250.00']);
    $ids['inactive']    = $mk(['name' => "{$tag} Retired",     'type' => 'product', 'cat' => 'Fasteners', 'price' => '9.00', 'stock' => 0, 'reorder' => 5, 'active' => 0]);
    $ids['xss']         = $mk(['name' => "{$tag} <script>alert(1)</script>", 'type' => 'product', 'cat' => '<b>cat</b>&"', 'price' => '1.00', 'stock' => 10]);

    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8682);
    $cleanup = function () use ($pdo, $custId, $adminId, $ids): void {
        $pdo->prepare('DELETE FROM items WHERE item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
            ->execute(array_values($ids));
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $base = '/vulcatrack/admin/inventory.php';

    try {
        $server->start();

        // 1. Unauthenticated -> redirect to the admin login.
        $r = $server->request($base);
        assert_same(302, $r['status'], 'an unauthenticated visitor is redirected');
        assert_contains('/admin/login.php', (string) $r['location']);

        // 2. A signed-in CUSTOMER cannot reach admin inventory.
        $login = $server->request('/vulcatrack/login.php');
        $token = HttpServer::csrfToken($login['body']);
        $server->request('/vulcatrack/login.php', ['_csrf' => $token, 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($base);
        assert_same(302, $r['status'], 'a customer session must not satisfy the admin guard');
        assert_contains('/admin/login.php', (string) $r['location']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        // 3. Admin logs in and opens inventory (default: active only).
        $adminLogin = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', [
            '_csrf' => HttpServer::csrfToken($adminLogin['body']),
            'email' => $adminEmail, 'password' => $password,
        ]);

        $r = $server->request($base);
        assert_same(200, $r['status'], 'the admin can open inventory');
        $assertCleanHtml($r['body'], 'admin/inventory.php');
        assert_contains("{$tag} Low Widget", $r['body']);
        assert_contains("{$tag} OK Widget", $r['body']);
        assert_contains("{$tag} Labor", $r['body']);
        assert_not_contains("{$tag} Retired", $r['body'], 'the default view is active-only');

        // 4. Product / service distinction: the service row shows no stock number.
        assert_contains('badge--product', $r['body']);
        assert_contains('badge--service', $r['body']);
        assert_contains('n/a', $r['body'], 'a service has no stock cell value');

        // 5. Low-stock flag: present in the default view (Low Widget qualifies),
        //    and the OK widget alone does not trigger it.
        assert_contains('badge--low', $r['body']);
        $productsOnly = $server->request($base . '?type=product&q=' . $tag);
        assert_same(200, $productsOnly['status']);
        assert_not_contains('n/a', $productsOnly['body'], 'a product view never shows the service placeholder');

        // 6. type=service filter -> only the service; no low-stock treatment for services.
        $svc = $server->request($base . '?type=service&q=' . $tag);
        assert_contains("{$tag} Labor", $svc['body']);
        assert_not_contains("{$tag} Low Widget", $svc['body']);
        assert_not_contains('badge--low', $svc['body'], 'services are never flagged low-stock');
        $assertCleanHtml($svc['body'], 'inventory?type=service');

        // 7. status=inactive + search -> only the inactive product, and it is NOT
        //    flagged low-stock even though its numbers would qualify.
        $inactive = $server->request($base . '?status=inactive&q=' . $tag);
        assert_contains("{$tag} Retired", $inactive['body']);
        assert_not_contains("{$tag} Low Widget", $inactive['body']);
        assert_not_contains('badge--low', $inactive['body'], 'an inactive product is not a live low-stock alert');
        assert_contains('badge--inactive', $inactive['body']);

        // 8. text search narrows by name.
        $q = $server->request($base . '?q=' . $tag . '%20Labor');
        assert_contains("{$tag} Labor", $q['body']);
        assert_not_contains("{$tag} OK Widget", $q['body']);

        // 9. low_stock=1 -> only the low product among mine.
        $low = $server->request($base . '?low_stock=1&q=' . $tag);
        assert_contains("{$tag} Low Widget", $low['body']);
        assert_not_contains("{$tag} OK Widget", $low['body']);
        assert_not_contains("{$tag} Labor", $low['body']);

        // 10. Output escaping: the raw script/markup never reaches the HTML.
        $all = $server->request($base . '?status=all&q=' . $tag);
        assert_not_contains('<script>alert(1)</script>', $all['body'], 'item name is escaped');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $all['body']);
        assert_not_contains('<b>cat</b>&"', $all['body'], 'category is escaped');
        assert_contains('&lt;b&gt;cat&lt;/b&gt;', $all['body']);

        // 11. Filter values are preserved in the rendered controls.
        $pre = $server->request($base . '?q=' . $tag . '&type=service&status=all&low_stock=1');
        assert_contains('value="' . $tag . '"', $pre['body']);
        assert_contains('<option value="service" selected>', $pre['body']);
        assert_contains('<option value="all" selected>', $pre['body']);
        assert_contains('name="low_stock" value="1" checked', $pre['body']);

        // 12. Price uses the Money helper (two-decimal peso string), not a float.
        assert_contains('12.50', $r['body']);
        assert_contains('250.00', $r['body']);

        // 13. The server process logged no PHP warnings/notices/fatals.
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
