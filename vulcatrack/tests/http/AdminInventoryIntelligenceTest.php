<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Inventory Insights owns current stock attention while Reports remains read-only Sales analysis', function () {
    $pdo = test_pdo();
    $tag = 'RII' . bin2hex(random_bytes(4));
    $password = 'intelligence-password-123';
    $previous = $pdo->query('SELECT slow_moving_days, updated_by_admin_id, updated_at FROM inventory_settings WHERE setting_id = 1')->fetch();
    $admins = [];
    foreach (['First', 'Second'] as $name) {
        $email = TestDb::email('rii');
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute(["{$tag} {$name}", $email, Password::hash($password)]);
        $admins[] = ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
    }
    $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
    $created = $now->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, reorder_level, created_at) VALUES (?,?,?,?,?,?)')
        ->execute(["{$tag} <script>alert(1)</script>", 'product', '10.00', 2, 2, $created]);
    $itemId = (int) $pdo->lastInsertId();
    $saleId = null;
    $server = new HttpServer(8694);
    $url = '/vulcatrack/admin/reports.php';
    $insightsUrl = '/vulcatrack/admin/inventory-insights.php';
    $settingsUrl = '/vulcatrack/admin/inventory-settings.php';
    $inventoryUrl = '/vulcatrack/admin/inventory.php';
    $login = function (string $email) use ($server, $password): void {
        $form = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', [
            '_csrf' => HttpServer::csrfToken($form['body']), 'email' => $email, 'password' => $password,
        ]);
    };
    try {
        $server->start();
        assert_same(302, $server->request($url)['status'], 'guests cannot see or edit Admin Reports');
        assert_same(302, $server->request($url, ['_action' => 'update_slow_moving_days', 'slow_moving_days' => '20'])['status']);
        assert_same(302, $server->request($insightsUrl)['status'], 'Inventory Insights requires Admin');
        assert_same(302, $server->request($settingsUrl)['status'], 'Inventory Settings requires Admin');
        assert_same(302, $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', 'slow_moving_days' => '20'])['status']);
        $login($admins[0]['email']);
        $itemsView = $server->request($inventoryUrl)['body'];
        assert_contains('<h1>Inventory</h1>', $itemsView);
        assert_contains('aria-label="Inventory"', $itemsView);
        assert_contains('href="' . $insightsUrl . '"', $itemsView);
        assert_contains('href="' . $settingsUrl . '"', $itemsView);
        assert_contains('href="' . $inventoryUrl . '" aria-current="page">Items</a>', $itemsView);
        $settingsView = $server->request($settingsUrl);
        assert_same(200, $settingsView['status']);
        assert_contains('href="' . $settingsUrl . '" aria-current="page">Settings</a>', $settingsView['body']);
        assert_contains('Slow-moving Products', $settingsView['body']);
        assert_contains('Default slow-moving threshold', $settingsView['body']);
        assert_contains('value="60"', $settingsView['body']);
        assert_contains('Products may optionally use their own threshold in Product Edit.', $settingsView['body']);
        $token = HttpServer::csrfToken($settingsView['body']);
        assert_not_null($token);
        $historical = $url . '?period=month&month=2001-07';
        $view = $server->request($historical);
        assert_same(200, $view['status']);
        assert_not_contains('Inventory Intelligence', $view['body']);
        assert_not_contains('Current inventory', $view['body']);
        assert_not_contains('Low stock', $view['body']);
        assert_not_contains('Slow-moving Products', $view['body']);
        assert_contains('Range: <strong>Jul 2001:', $view['body']);
        assert_contains('Sales Performance', $view['body']);
        assert_contains('Items Sold', $view['body']);
        assert_contains('Rescue Success Rate', $view['body']);
        assert_not_contains('name="slow_moving_days"', $view['body'], 'Reports has no editable default');
        assert_not_contains('update_slow_moving_days', $view['body']);
        assert_same(405, $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => '20'])['status']);
        assert_same(60, (int) $pdo->query('SELECT slow_moving_days FROM inventory_settings')->fetchColumn());
        $insights = $server->request($insightsUrl);
        assert_same(200, $insights['status']);
        assert_contains('href="' . $insightsUrl . '" aria-current="page">Insights</a>', $insights['body']);
        assert_contains('href="' . $inventoryUrl . '"', $insights['body']);
        assert_contains('href="' . $settingsUrl . '"', $insights['body']);
        assert_contains('Current stock and recorded Sales inactivity as of ', $insights['body']);
        assert_contains(' Asia/Manila.', $insights['body']);
        assert_contains('No slow-moving Products at their effective thresholds.', $insights['body'], '30-day-old never-sold Product is too new for 60-day grace');
        assert_contains('Low stock', $insights['body']);
        assert_contains('View low-stock items', $insights['body']);
        assert_not_contains('Active Products at or below their own reorder level, including zero stock.', $insights['body']);
        assert_contains('data-label="Shop stock"', $insights['body']);
        assert_contains('datatable mobile-records', $insights['body']);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $insights['body']);
        assert_not_contains('<script>alert(1)</script>', $insights['body']);
        assert_not_contains('Shop default: <strong>', $insights['body']);
        assert_contains('Flagged by recorded Sales inactivity within each Product’s effective threshold.', $insights['body']);
        assert_not_contains('Suggested action: review demand, display, promotion, or price for flagged Products.', $insights['body']);
        assert_same(405, $server->request($insightsUrl, ['_action' => 'update_slow_moving_days'])['status']);

        $invalidCsrf = $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => 'bad', 'slow_moving_days' => '20']);
        assert_contains('Your session expired.', $invalidCsrf['body']);
        foreach (['0', '366', '1.5', 'abc'] as $bad) {
            $invalid = $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => $bad]);
            assert_same(200, $invalid['status']);
            assert_contains('Enter a slow-moving threshold from 1 to 365 days.', $invalid['body']);
        }
        $arrayInput = $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => ['20']]);
        assert_contains('Enter a slow-moving threshold from 1 to 365 days.', $arrayInput['body']);
        assert_same(60, (int) $pdo->query('SELECT slow_moving_days FROM inventory_settings')->fetchColumn());
        $changed = $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => '20']);
        assert_same(302, $changed['status']);
        assert_contains('inventory-settings.php?saved=saved', (string) $changed['location']);
        assert_contains('Default slow-moving threshold updated.', $server->request($changed['location'])['body']);
        $at20 = $server->request($insightsUrl)['body'];
        assert_not_contains('Shop default: <strong>', $at20);
        assert_contains('Flagged by recorded Sales inactivity within each Product’s effective threshold.', $at20);
        assert_contains('Never sold', $at20);
        assert_contains('No recorded restock since tracking began', $at20);
        assert_not_contains('Suggested action: review demand, display, promotion, or price for flagged Products.', $at20);
        assert_not_contains('Range: <strong>Jul 2001:', $at20);
        assert_contains('data-label="Threshold"', $at20);
        assert_contains('20 days', $at20);
        assert_contains('Default</small>', $at20);
        assert_not_contains('data-label="Advisory"', $at20);
        assert_contains('data-label="Latest recorded Restock"', $at20);
        assert_not_contains('panel__foot muted">Never-sold Products', $at20, 'redundant footer was removed');
        $pdo->prepare('UPDATE items SET slow_moving_days_override = 10 WHERE item_id = ?')->execute([$itemId]);
        $overrideView = $server->request($insightsUrl)['body'];
        assert_contains('10 days', $overrideView);
        assert_contains('Item override</small>', $overrideView);
        assert_not_contains('data-label="Advisory"', $overrideView);
        $pdo->prepare('UPDATE items SET slow_moving_days_override = NULL WHERE item_id = ?')->execute([$itemId]);
        $pdo->prepare('INSERT INTO sales (admin_id, sale_date, total_amount) VALUES (?,?,?)')
            ->execute([$admins[0]['id'], $now->format('Y-m-d H:i:s'), '10.00']);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
            ->execute([$saleId, $itemId, 1, '10.00', '10.00']);
        $refreshed = $server->request($insightsUrl)['body'];
        assert_contains('No slow-moving Products at their effective thresholds.', $refreshed, 'a committed Sale changes current intelligence on refresh');
        assert_contains('Range: <strong>Jul 2001:', $server->request($historical)['body'], 'historical Sales filter remains independent');
        $changedMetadata = $pdo->query('SELECT updated_at, updated_by_admin_id FROM inventory_settings')->fetch();
        assert_same($admins[0]['id'], (int) $changedMetadata['updated_by_admin_id']);
        $unchanged = $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => '20']);
        assert_contains('inventory-settings.php?saved=unchanged', (string) $unchanged['location']);
        assert_same($changedMetadata, $pdo->query('SELECT updated_at, updated_by_admin_id FROM inventory_settings')->fetch());

        $server->request('/vulcatrack/admin/logout.php', ['_csrf' => $token]);
        $login($admins[1]['email']);
        $secondView = $server->request($settingsUrl);
        $secondToken = HttpServer::csrfToken($secondView['body']);
        assert_same(302, $server->request($settingsUrl, ['_action' => 'update_slow_moving_days', '_csrf' => $secondToken, 'slow_moving_days' => '25'])['status']);
        assert_same($admins[1]['id'], (int) $pdo->query('SELECT updated_by_admin_id FROM inventory_settings')->fetchColumn());
        $currentSettings = $pdo->query('SELECT slow_moving_days, updated_by_admin_id, updated_at FROM inventory_settings WHERE setting_id = 1')->fetch();
        $pdo->exec('DELETE FROM inventory_settings WHERE setting_id = 1');
        try {
            $missing = $server->request($insightsUrl);
            assert_same(200, $missing['status']);
            assert_contains('Inventory insights are unavailable until their settings are restored.', $missing['body']);
            assert_contains('Slow-moving results are unavailable.', $missing['body']);
            assert_not_contains('No slow-moving Products at their effective thresholds.', $missing['body']);
            assert_contains('Inventory settings are unavailable until their required row is restored.', $server->request($settingsUrl)['body']);
            $itemEditWithoutSettings = $server->request('/vulcatrack/admin/item-edit.php?id=' . $itemId);
            assert_same(200, $itemEditWithoutSettings['status']);
            assert_contains('Shop default is unavailable; restore Inventory Settings', $itemEditWithoutSettings['body']);
        } finally {
            $pdo->prepare('INSERT INTO inventory_settings (setting_id, slow_moving_days, updated_by_admin_id, updated_at) VALUES (1, ?, ?, ?)')
                ->execute([$currentSettings['slow_moving_days'], $currentSettings['updated_by_admin_id'], $currentSettings['updated_at']]);
        }
        foreach (['Warning:', 'Notice:', 'Fatal error', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $at20);
        }
        assert_not_contains('PHP Warning', $server->serverStderr());
    } finally {
        $server->stop();
        if ($saleId !== null) {
            $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$saleId]);
            $pdo->prepare('DELETE FROM sales WHERE sale_id = ?')->execute([$saleId]);
        }
        $pdo->prepare('UPDATE inventory_settings SET slow_moving_days = ?, updated_by_admin_id = ?, updated_at = ? WHERE setting_id = 1')
            ->execute([$previous['slow_moving_days'], $previous['updated_by_admin_id'], $previous['updated_at']]);
        $pdo->prepare('DELETE FROM items WHERE item_id = ?')->execute([$itemId]);
        foreach ($admins as $admin) {
            $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$admin['id']]);
        }
    }
});
