<?php
/**
 * End-to-end HTTP tests for Phase 5 Chunk 3B — Inventory mutations
 * (admin/item-edit.php create/edit, admin/inventory.php activate/deactivate).
 *
 * Covers: the admin guard and actor separation on every mutation path, CSRF,
 * POST-only mutation (no GET side effects), create/edit validation, the
 * product<->service stock-field rules, centavo-safe prices, soft
 * activate/deactivate (no hard delete), value preservation on failure, and
 * output escaping.
 *
 * Throwaway admin + customer + items are seeded via PDO and removed afterwards.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin inventory mutations: guard, CSRF, create, edit, activate/deactivate', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'MUT' . substr(bin2hex(random_bytes(4)), 0, 8);

    $custEmail  = TestDb::email('mut-cust');
    $adminEmail = TestDb::email('mut-admin');
    $password   = 'mut-password-123';

    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Mut Customer', $custEmail, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['Mut Admin', $adminEmail, Password::hash($password)]);
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

    $seedProduct = $mk(['name' => "{$tag} Seed Product", 'type' => 'product', 'cat' => 'Parts', 'price' => '10.00', 'stock' => 7, 'reorder' => 3]);
    $seedService = $mk(['name' => "{$tag} Seed Service", 'type' => 'service', 'cat' => 'Labor', 'price' => '120.00']);
    $seedToggle  = $mk(['name' => "{$tag} Seed Toggle",  'type' => 'product', 'cat' => 'Parts', 'price' => '5.00', 'stock' => 1, 'reorder' => 2]);

    $row = fn (int $id) => $pdo->query('SELECT * FROM items WHERE item_id = ' . (int) $id)->fetch(\PDO::FETCH_ASSOC) ?: null;
    $myItemCount = function () use ($pdo, $tag): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM items WHERE item_name LIKE ?');
        $s->execute([$tag . '%']);
        return (int) $s->fetchColumn();
    };
    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8683);
    $cleanup = function () use ($pdo, $custId, $adminId, $tag): void {
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $INV  = '/vulcatrack/admin/inventory.php';
    $EDIT = '/vulcatrack/admin/item-edit.php';

    try {
        $server->start();

        // ============ SECURITY: unauthenticated ============
        $r = $server->request($INV, ['_action' => 'deactivate', 'item_id' => $seedProduct, '_csrf' => 'x']);
        assert_same(302, $r['status'], 'an unauthenticated activate POST is refused');
        assert_contains('/admin/login.php', (string) $r['location']);
        assert_same(1, (int) $row($seedProduct)['is_active'], 'the item was not mutated');

        assert_same(302, $server->request($EDIT)['status'], 'unauthenticated GET item-edit redirects');
        $r = $server->request($EDIT, ['item_name' => 'x', 'item_type' => 'product', 'price' => '1', '_csrf' => 'x']);
        assert_same(302, $r['status'], 'unauthenticated POST item-edit redirects');
        assert_contains('/admin/login.php', (string) $r['location']);

        // ============ SECURITY: customer session ============
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $r = $server->request($INV, ['_action' => 'deactivate', 'item_id' => $seedProduct, '_csrf' => 'x']);
        assert_same(302, $r['status']);
        assert_contains('/admin/login.php', (string) $r['location'], 'a customer cannot mutate inventory');
        assert_same(302, $server->request($EDIT . '?id=' . $seedProduct)['status'], 'a customer cannot open item-edit');
        assert_same(1, (int) $row($seedProduct)['is_active']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        // ============ log in as admin ============
        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);
        $token = fn (string $path) => HttpServer::csrfToken($server->request($path)['body']);

        // ============ POST-only: a GET with mutation params does nothing ============
        $server->request($INV . '?_action=deactivate&item_id=' . $seedProduct);
        assert_same(1, (int) $row($seedProduct)['is_active'], 'a GET never mutates');

        // ============ CSRF: bad token on inventory POST ============
        $r = $server->request($INV, ['_action' => 'deactivate', 'item_id' => $seedProduct, '_csrf' => 'bogus']);
        assert_same(302, $r['status']);
        assert_contains('saved=error', (string) $r['location']);
        assert_same(1, (int) $row($seedProduct)['is_active'], 'a bad-CSRF deactivate did nothing');

        // ============ CSRF: bad token on item-edit POST ============
        $before = $myItemCount();
        $r = $server->request($EDIT, ['_csrf' => 'bogus', 'item_name' => "{$tag} Ghost", 'item_type' => 'product', 'price' => '1.00', 'stock_quantity' => '1']);
        assert_same(200, $r['status'], 'a bad-CSRF create re-renders the form');
        assert_contains('session expired', strtolower($r['body']));
        assert_same($before, $myItemCount(), 'nothing was created');

        // ============ CREATE: valid product ============
        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} New Product",
            'item_type' => 'product', 'category' => 'Fasteners', 'price' => '49.95',
            'stock_quantity' => '12', 'reorder_level' => '5',
        ]);
        assert_same(302, $r['status']);
        assert_contains('saved=created', (string) $r['location']);
        $np = $pdo->query("SELECT * FROM items WHERE item_name = " . $pdo->quote("{$tag} New Product"))->fetch(\PDO::FETCH_ASSOC);
        assert_not_null($np);
        assert_same('product', $np['item_type']);
        assert_same('Fasteners', $np['category']);
        assert_same('49.95', $np['price'], 'price stored as an exact two-place decimal');
        assert_same(12, (int) $np['stock_quantity']);
        assert_same(5, (int) $np['reorder_level']);
        assert_same(1, (int) $np['is_active']);

        // confirmation flash on the redirect target
        $invPage = $server->request($INV . '?saved=created');
        assert_contains('Item created.', $invPage['body']);
        $assertCleanHtml($invPage['body'], 'inventory?saved=created');

        // ============ CREATE: valid service ignores stock fields ============
        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} New Service",
            'item_type' => 'service', 'category' => 'Labor', 'price' => '150',
            'stock_quantity' => '999', 'reorder_level' => '10',   // must be ignored
        ]);
        assert_same(302, $r['status']);
        $ns = $pdo->query("SELECT * FROM items WHERE item_name = " . $pdo->quote("{$tag} New Service"))->fetch(\PDO::FETCH_ASSOC);
        assert_same('service', $ns['item_type']);
        assert_null($ns['stock_quantity'], 'a service stores NULL stock');
        assert_null($ns['reorder_level'], 'a service stores NULL reorder level');
        assert_same('150.00', $ns['price']);

        // ============ CREATE: invalid inputs are rejected, values preserved ============
        $before = $myItemCount();
        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} Bad Price",
            'item_type' => 'product', 'price' => 'abc', 'stock_quantity' => '1',
        ]);
        assert_same(200, $r['status']);
        assert_contains('value="' . $tag . ' Bad Price"', $r['body'], 'the entered name is preserved on failure');
        assert_contains('Price must be', $r['body']);
        assert_same($before, $myItemCount(), 'no row created for a bad price');

        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} Bad Stock",
            'item_type' => 'product', 'price' => '1.00', 'stock_quantity' => '-3',
        ]);
        assert_same(200, $r['status']);
        assert_contains('Stock quantity must be', $r['body']);
        assert_same($before, $myItemCount());

        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} Bad Type",
            'item_type' => 'widget', 'price' => '1.00',
        ]);
        assert_same(200, $r['status']);
        assert_contains('valid item type', $r['body']);
        assert_same($before, $myItemCount());

        // ============ CREATE: hostile text is escaped everywhere ============
        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} <script>alert('x')</script>",
            'item_type' => 'product', 'price' => 'nope', 'stock_quantity' => '1',
        ]);
        assert_same(200, $r['status'], 'validation fails so the form re-renders');
        assert_not_contains("<script>alert('x')</script>", $r['body'], 'the hostile name is escaped on the form');
        assert_contains('&lt;script&gt;', $r['body']);

        $r = $server->request($EDIT, [
            '_csrf' => $token($EDIT), 'item_name' => "{$tag} <b>bold</b>",
            'item_type' => 'product', 'price' => '2.00', 'stock_quantity' => '4',
        ]);
        assert_same(302, $r['status']);
        $listed = $server->request($INV . '?q=' . $tag . '&status=all');
        assert_not_contains('<b>bold</b>', $listed['body'], 'the hostile name is escaped in the list');
        assert_contains('&lt;b&gt;bold&lt;/b&gt;', $listed['body']);
        $assertCleanHtml($listed['body'], 'inventory list');

        // ============ EDIT FORM: the product->service stock-loss warning ============
        $warn = 'permanently clears its stock quantity and reorder level';
        $pf = $server->request($EDIT . '?id=' . $seedProduct);
        assert_contains($warn, $pf['body'], 'editing a product warns that switching to a service clears stock fields');
        $sf = $server->request($EDIT . '?id=' . $seedService);
        assert_not_contains($warn, $sf['body'], 'the warning is irrelevant when the item is already a service');
        $cf = $server->request($EDIT);
        assert_not_contains($warn, $cf['body'], 'the warning does not appear on the create form');

        // ============ EDIT: update a product, timestamps behave ============
        $pdo->prepare('UPDATE items SET created_at = :c1, updated_at = :c2 WHERE item_id = :id')
            ->execute([':c1' => '2001-02-03 04:05:06', ':c2' => '2001-02-03 04:05:06', ':id' => $seedProduct]);
        $r = $server->request($EDIT . '?id=' . $seedProduct, [
            '_csrf' => $token($EDIT . '?id=' . $seedProduct),
            'item_name' => "{$tag} Seed Product EDITED", 'item_type' => 'product',
            'category' => 'Parts', 'price' => '11.25', 'stock_quantity' => '9', 'reorder_level' => '4',
        ]);
        assert_same(302, $r['status']);
        assert_contains('saved=updated', (string) $r['location']);
        $edited = $row($seedProduct);
        assert_same("{$tag} Seed Product EDITED", $edited['item_name']);
        assert_same('11.25', $edited['price']);
        assert_same(9, (int) $edited['stock_quantity']);
        assert_same('2001-02-03 04:05:06', $edited['created_at'], 'created_at is not touched on edit');
        assert_true($edited['updated_at'] > '2001-02-03 04:05:06', 'updated_at is refreshed on edit');

        // ============ EDIT: product -> service clears stock + reorder ============
        $r = $server->request($EDIT . '?id=' . $seedProduct, [
            '_csrf' => $token($EDIT . '?id=' . $seedProduct),
            'item_name' => "{$tag} Now A Service", 'item_type' => 'service',
            'category' => 'Labor', 'price' => '11.25',
            'stock_quantity' => '9', 'reorder_level' => '4',   // sent, must be dropped
        ]);
        assert_same(302, $r['status']);
        $conv = $row($seedProduct);
        assert_same('service', $conv['item_type']);
        assert_null($conv['stock_quantity'], 'product -> service nulls stock_quantity');
        assert_null($conv['reorder_level'], 'product -> service nulls reorder_level');

        // ============ EDIT: service -> product WITHOUT stock is rejected ============
        $r = $server->request($EDIT . '?id=' . $seedService, [
            '_csrf' => $token($EDIT . '?id=' . $seedService),
            'item_name' => "{$tag} Seed Service", 'item_type' => 'product',
            'category' => 'Labor', 'price' => '120.00', 'stock_quantity' => '',
        ]);
        assert_same(200, $r['status'], 'missing stock re-renders the form');
        assert_contains('Stock quantity must be', $r['body']);
        assert_same('service', $row($seedService)['item_type'], 'the row stayed a service');
        assert_null($row($seedService)['stock_quantity']);

        // ============ EDIT: service -> product WITH valid stock succeeds ============
        $r = $server->request($EDIT . '?id=' . $seedService, [
            '_csrf' => $token($EDIT . '?id=' . $seedService),
            'item_name' => "{$tag} Seed Service", 'item_type' => 'product',
            'category' => 'Labor', 'price' => '1234.5', 'stock_quantity' => '6', 'reorder_level' => '',
        ]);
        assert_same(302, $r['status']);
        $conv2 = $row($seedService);
        assert_same('product', $conv2['item_type']);
        assert_same(6, (int) $conv2['stock_quantity']);
        assert_null($conv2['reorder_level'], 'a blank reorder level stays NULL');
        assert_same('1234.50', $conv2['price'], 'price stays centavo-exact ("1234.5" -> "1234.50")');

        // ============ ACTIVATE / DEACTIVATE: soft, POST, no hard delete ============
        $countBefore = $myItemCount();
        $r = $server->request($INV, ['_csrf' => $token($INV), '_action' => 'deactivate', 'item_id' => $seedToggle]);
        assert_same(302, $r['status']);
        assert_contains('saved=deactivated', (string) $r['location']);
        assert_not_null($row($seedToggle), 'the row still exists after deactivate');
        assert_same(0, (int) $row($seedToggle)['is_active']);
        assert_same($countBefore, $myItemCount(), 'no hard delete');

        // hidden by default, visible under inactive / all
        assert_not_contains("{$tag} Seed Toggle", $server->request($INV . '?q=' . $tag)['body']);
        assert_contains("{$tag} Seed Toggle", $server->request($INV . '?q=' . $tag . '&status=inactive')['body']);
        assert_contains("{$tag} Seed Toggle", $server->request($INV . '?q=' . $tag . '&status=all')['body']);

        $r = $server->request($INV, ['_csrf' => $token($INV), '_action' => 'activate', 'item_id' => $seedToggle]);
        assert_same(302, $r['status']);
        assert_contains('saved=activated', (string) $r['location']);
        assert_same(1, (int) $row($seedToggle)['is_active'], 'activate restores the item');

        // an unknown item id fails safely
        $r = $server->request($INV, ['_csrf' => $token($INV), '_action' => 'deactivate', 'item_id' => 999999999]);
        assert_same(302, $r['status']);
        assert_contains('saved=error', (string) $r['location']);

        // ============ clean server log ============
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
