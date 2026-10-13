<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Admin Promotions page creates, cancels and displays sale pricing through POS and Transaction Summary', function () {
    $pdo = test_pdo();
    $tag = 'PROMO' . bin2hex(random_bytes(4));
    $email = TestDb::email('promo-http');
    $password = 'promo-http-password';
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute([$tag . ' Admin', $email, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();
    $old = date('Y-m-d H:i:s', strtotime('-80 days'));
    $insertItem = $pdo->prepare('INSERT INTO items (item_name, item_type, price, stock_quantity, created_at)
        VALUES (?, ?, ?, ?, ?)');
    $insertItem->execute([$tag . ' Product', 'product', '1800.00', 40, $old]);
    $product = (int) $pdo->lastInsertId();
    $insertItem->execute([$tag . ' Cancel', 'product', '500.00', 4, $old]);
    $toCancel = (int) $pdo->lastInsertId();
    $server = new HttpServer(8707);
    $page = '/vulcatrack/admin/inventory-promotions.php';
    $pos = '/vulcatrack/admin/pos.php';
    try {
        $server->start();
        assert_same(302, $server->request($page)['status'], 'guest cannot view Promotions');
        assert_same(302, $server->request($page, ['_action' => 'create'])['status'], 'guest cannot create');
        $login = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', [
            '_csrf' => HttpServer::csrfToken($login['body']), 'email' => $email, 'password' => $password,
        ]);
        $initial = $server->request($page);
        assert_same(200, $initial['status']);
        assert_contains('aria-current="page">Promotions</a>', $initial['body']);
        assert_contains('Slow-moving candidates', $initial['body']);
        assert_contains($tag . ' Product', $initial['body']);
        assert_contains('datatable mobile-records', $initial['body']);
        assert_contains('data-label="Shop stock"', $initial['body']);
        assert_same(200, $server->request($page . '?product%5B%5D=1&saved%5B%5D=created')['status']);
        $form = $server->request($page . '?product=' . $product);
        assert_contains('Put ' . $tag . ' Product on sale', $form['body']);
        assert_contains('Start date <span class="promotion-form__hint">(optional; defaults to today)', $form['body']);
        assert_contains('End date <span class="promotion-form__hint">(optional; leave blank for no automatic end date)', $form['body']);
        assert_contains('until cancellation or the sale-priced unit limit is reached', $form['body']);
        $token = HttpServer::csrfToken($form['body']);
        $fields = ['_action' => 'create', 'item_id' => (string) $product,
            'sale_price' => '1550.00', 'starts_on' => date('Y-m-d'),
            'ends_on' => date('Y-m-d', strtotime('+2 days')),
            'unit_limit' => '2', 'note' => 'Slow stock'];
        $bad = $server->request($page, $fields + ['_csrf' => 'bad']);
        assert_same(200, $bad['status']);
        assert_contains('session expired', strtolower($bad['body']));
        assert_same(200, $server->request($page, ['_csrf' => $token, '_action' => ['create']])['status']);
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM product_promotions')->fetchColumn());
        $created = $server->request($page, $fields + ['_csrf' => $token]);
        assert_same(302, $created['status']);
        assert_contains('saved=created', (string) $created['location']);
        $promotionId = (int) $pdo->query('SELECT promotion_id FROM product_promotions WHERE item_id = ' . $product)->fetchColumn();
        $open = $server->request($page)['body'];
        assert_contains('Scheduled / Active', $open);
        assert_contains('2 left of 2', $open);
        assert_contains((new \DateTimeImmutable())->format('F j, Y') . ' through '
            . (new \DateTimeImmutable('+2 days'))->format('F j, Y'), $open);
        assert_contains('class="badge badge--active">ACTIVE</span>', $open);
        assert_not_contains('product=' . $product . '#promotion-form', $open, 'open promotion leaves candidates');
        $blocked = $server->request('/vulcatrack/admin/inventory.php', [
            '_csrf' => $token, '_action' => 'deactivate', 'item_id' => (string) $product,
        ]);
        assert_contains('saved=promotion_blocked', (string) $blocked['location']);

        $catalog = $server->request($pos)['body'];
        assert_contains('ON SALE!', $catalog);
        assert_contains('Regular &#8369;1,800', $catalog);
        assert_contains('Sale &#8369;1,550', $catalog);
        $add = $server->request($pos, [
            '_csrf' => $token, 'expected_rescue_id' => '', '_action' => 'add',
            'item_id' => (string) $product, 'quantity' => '5',
        ]);
        assert_same(302, $add['status']);
        $cart = $server->request($pos)['body'];
        assert_contains('2 @ &#8369;1,550 Sale price', $cart);
        assert_contains('3 @ &#8369;1,800 Regular price', $cart);
        assert_contains('name="expected_total" value="850000"', $cart);
        $checkout = $server->request($pos, [
            '_csrf' => $token, 'expected_rescue_id' => '', '_action' => 'checkout',
            'qty' => [(string) $product => '5'], 'cash_tendered' => '8500', 'expected_total' => '850000',
        ]);
        assert_same(302, $checkout['status']);
        $saleId = (int) $pdo->query('SELECT sale_id FROM sales WHERE admin_id = ' . $adminId)->fetchColumn();
        $lines = $pdo->query('SELECT quantity, unit_price, subtotal, promotion_id FROM sale_items WHERE sale_id = ' . $saleId . ' ORDER BY sale_item_id')->fetchAll();
        assert_same(2, count($lines));
        assert_same('1550.00', $lines[0]['unit_price']);
        assert_same('1800.00', $lines[1]['unit_price']);
        assert_same($promotionId, (int) $lines[0]['promotion_id']);
        assert_null($lines[1]['promotion_id']);
        assert_same(35, (int) $pdo->query('SELECT stock_quantity FROM items WHERE item_id = ' . $product)->fetchColumn());
        $summary = $server->request('/vulcatrack/admin/transaction-summary.php?id=' . $saleId)['body'];
        assert_contains('Sale price', $summary);
        assert_contains('Regular price', $summary);
        assert_contains('&#8369;8500.00', $summary);
        $ended = $server->request($page)['body'];
        assert_contains('Promotion history', $ended);
        assert_contains('Ended', $ended);
        assert_contains((new \DateTimeImmutable())->format('F j, Y') . ' through '
            . (new \DateTimeImmutable('+2 days'))->format('F j, Y'), $ended);

        $cancelForm = $server->request($page . '?product=' . $toCancel)['body'];
        $cancelToken = HttpServer::csrfToken($cancelForm);
        $second = $server->request($page, ['_csrf' => $cancelToken, '_action' => 'create',
            'item_id' => (string) $toCancel, 'sale_price' => '400.00', 'starts_on' => '',
            'ends_on' => '', 'unit_limit' => '', 'note' => '',
        ]);
        assert_same(302, $second['status']);
        $secondId = (int) $pdo->query('SELECT promotion_id FROM product_promotions WHERE item_id = ' . $toCancel)->fetchColumn();
        $secondDates = $pdo->query('SELECT starts_on, ends_on FROM product_promotions WHERE promotion_id = ' . $secondId)->fetch();
        assert_same(date('Y-m-d'), $secondDates['starts_on']);
        assert_null($secondDates['ends_on']);
        $openEnded = $server->request($page)['body'];
        assert_contains((new \DateTimeImmutable())->format('F j, Y') . ' · No automatic end date', $openEnded);
        assert_contains('class="badge badge--active">ACTIVE</span>', $openEnded);
        $cancel = $server->request($page, [
            '_csrf' => $cancelToken, '_action' => 'cancel', 'promotion_id' => (string) $secondId,
        ]);
        assert_same(302, $cancel['status']);
        assert_contains('saved=cancelled', (string) $cancel['location']);
        $cancelled = $server->request($page)['body'];
        assert_contains('Cancelled', $cancelled);
        assert_contains((new \DateTimeImmutable())->format('F j, Y') . ' · No automatic end date', $cancelled);
        assert_same(0, preg_match('/<script>.*Warning:/s', $server->serverStderr()));
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id IN (SELECT sale_id FROM sales WHERE admin_id = ?)')->execute([$adminId]);
        $pdo->prepare('DELETE FROM sales WHERE admin_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM product_promotions WHERE item_id IN (?,?)')->execute([$product, $toCancel]);
        $pdo->prepare('DELETE FROM items WHERE item_id IN (?,?)')->execute([$product, $toCancel]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    }
});
