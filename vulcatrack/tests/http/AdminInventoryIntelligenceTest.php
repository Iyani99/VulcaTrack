<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Reports inventory intelligence is current, guarded, responsive and editable by either Admin', function () {
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
        $login($admins[0]['email']);
        $historical = $url . '?period=month&month=2001-07';
        $view = $server->request($historical);
        assert_same(200, $view['status']);
        assert_contains('Inventory Intelligence', $view['body']);
        assert_contains('Range: <strong>Jul 2001:', $view['body']);
        assert_contains('Sales period controls above do not change this section.', $view['body']);
        assert_contains('No slow-moving Products at the current threshold.', $view['body'], '30-day-old never-sold Product is too new for 60-day grace');
        assert_contains('Low stock', $view['body']);
        assert_contains('data-label="Shop stock"', $view['body']);
        assert_contains('datatable mobile-records', $view['body']);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $view['body']);
        assert_not_contains('<script>alert(1)</script>', $view['body']);
        $token = HttpServer::csrfToken($view['body']);
        assert_not_null($token);

        $invalidCsrf = $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => 'bad', 'slow_moving_days' => '20']);
        assert_contains('Your session expired.', $invalidCsrf['body']);
        foreach (['0', '366', '1.5', 'abc'] as $bad) {
            $invalid = $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => $bad]);
            assert_same(200, $invalid['status']);
            assert_contains('Enter a slow-moving threshold from 1 to 365 days.', $invalid['body']);
        }
        $arrayInput = $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => ['20']]);
        assert_contains('Enter a slow-moving threshold from 1 to 365 days.', $arrayInput['body']);
        assert_same(60, (int) $pdo->query('SELECT slow_moving_days FROM inventory_settings')->fetchColumn());
        $changed = $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => '20']);
        assert_same(302, $changed['status']);
        assert_contains('period=month', (string) $changed['location'], 'Sales filter survives the setting redirect');
        assert_contains('inventory=saved', (string) $changed['location']);
        $at20 = $server->request($historical)['body'];
        assert_contains('No recorded sales in the configured <strong>20-day period</strong>.', $at20);
        assert_contains('Never sold', $at20);
        assert_contains('No recorded restock history', $at20);
        assert_contains('Review demand, display, promotion, or price.', $at20);
        assert_contains('Range: <strong>Jul 2001:', $at20);
        assert_contains('data-label="Advisory"', $at20);
        assert_contains('data-label="Latest recorded Restock"', $at20);
        $pdo->prepare('INSERT INTO sales (admin_id, sale_date, total_amount) VALUES (?,?,?)')
            ->execute([$admins[0]['id'], $now->format('Y-m-d H:i:s'), '10.00']);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, item_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)')
            ->execute([$saleId, $itemId, 1, '10.00', '10.00']);
        $refreshed = $server->request($historical)['body'];
        assert_contains('No slow-moving Products at the current threshold.', $refreshed, 'a committed Sale changes current intelligence on refresh');
        assert_contains('Range: <strong>Jul 2001:', $refreshed, 'historical Sales filter remains independent');
        $changedMetadata = $pdo->query('SELECT updated_at, updated_by_admin_id FROM inventory_settings')->fetch();
        assert_same($admins[0]['id'], (int) $changedMetadata['updated_by_admin_id']);
        $unchanged = $server->request($historical, ['_action' => 'update_slow_moving_days', '_csrf' => $token, 'slow_moving_days' => '20']);
        assert_contains('inventory=unchanged', (string) $unchanged['location']);
        assert_same($changedMetadata, $pdo->query('SELECT updated_at, updated_by_admin_id FROM inventory_settings')->fetch());

        $server->request('/vulcatrack/admin/logout.php', ['_csrf' => $token]);
        $login($admins[1]['email']);
        $secondView = $server->request($url);
        $secondToken = HttpServer::csrfToken($secondView['body']);
        assert_same(302, $server->request($url, ['_action' => 'update_slow_moving_days', '_csrf' => $secondToken, 'slow_moving_days' => '25'])['status']);
        assert_same($admins[1]['id'], (int) $pdo->query('SELECT updated_by_admin_id FROM inventory_settings')->fetchColumn());
        $currentSettings = $pdo->query('SELECT slow_moving_days, updated_by_admin_id, updated_at FROM inventory_settings WHERE setting_id = 1')->fetch();
        $pdo->exec('DELETE FROM inventory_settings WHERE setting_id = 1');
        try {
            $missing = $server->request($url);
            assert_same(200, $missing['status']);
            assert_contains('Inventory Intelligence is unavailable until its settings are restored.', $missing['body']);
            assert_contains('Slow-moving results are unavailable.', $missing['body']);
            assert_not_contains('No slow-moving Products at the current threshold.', $missing['body']);
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
