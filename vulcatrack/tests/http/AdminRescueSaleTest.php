<?php
/**
 * End-to-end HTTP tests for Rescue sales (Phase 7.3d-c): recording the sale
 * for an accepted / completed Rescue request through the POS, and the
 * traceability that follows (Rescue detail, Sales History, Transaction Summary).
 *
 * The domain rules (eligibility, authoritative customer, one sale per request,
 * locking) are proven in integration/SaleServiceTest; this file checks the
 * workflow across real requests on the real session cart: the POST-only,
 * CSRF-checked start_rescue action and its clean-cart rule, the customer lock,
 * the stale-tab guard (every cart change carries the context its page was
 * rendered for), checkout taking the request id from the session only, the
 * result card, the Rescue "Sale recorded" panel and hidden Reject, and the
 * derived Source column / Transaction Summary row.
 *
 * Throwaway rows are seeded via PDO and deleted in FK-safe order afterwards
 * (linked sales before their Rescue requests).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

/** Seed everything the Rescue-sale workflow needs; returns ids + a cleanup closure. */
function rescue_sale_seed(\PDO $pdo): array
{
    $tag = 'RSL' . substr(bin2hex(random_bytes(4)), 0, 8);
    $pw = 'rescue-sale-123';
    $ids = ['tag' => $tag, 'pw' => $pw, 'adminEmail' => TestDb::email('rsl-admin')];
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} Cashier", $ids['adminEmail'], Password::hash($pw)]);
    $ids['admin'] = (int) $pdo->lastInsertId();

    foreach (['custA' => 'Alpha Owner', 'custB' => 'Bravo Owner', 'custC' => 'Charlie Buyer'] as $k => $name) {
        $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
            ->execute(["{$tag} {$name}", TestDb::email('rsl-' . strtolower($k)), '0917 555 0000', Password::hash($pw)]);
        $ids[$k] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO tiremen (name, contact_number) VALUES (?, ?)')->execute(["{$tag} Tireman", '0918 000 0000']);
    $ids['tireman'] = (int) $pdo->lastInsertId();
    $ids['vehicles'] = [];
    $ids['requests'] = [];
    $rescue = function (int $cust, string $status, bool $withTireman) use ($pdo, &$ids): int {
        $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?, ?)')->execute([$cust, 'RSL-' . count($ids['vehicles'])]);
        $vid = (int) $pdo->lastInsertId();
        $ids['vehicles'][] = $vid;
        $pdo->prepare(
            "INSERT INTO service_requests (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
             VALUES (?, ?, ?, ?, 'Flat tire near the market', 14.95, 120.89, 9, ?, '2001-01-01 00:00:00')"
        )->execute([$cust, $vid, $status === 'pending' ? null : $ids['admin'], $withTireman ? $ids['tireman'] : null, $status]);
        $rid = (int) $pdo->lastInsertId();
        $ids['requests'][] = $rid;
        return $rid;
    };
    $ids['RA'] = $rescue($ids['custA'], 'accepted', true);   // the main workflow
    $ids['RB'] = $rescue($ids['custB'], 'accepted', true);   // a second, different Rescue
    $ids['RC'] = $rescue($ids['custA'], 'completed', true);  // late entry
    $ids['RP'] = $rescue($ids['custA'], 'pending', false);
    $ids['RR'] = $rescue($ids['custA'], 'rejected', false);
    $ids['RL'] = $rescue($ids['custB'], 'accepted', true);   // already has a sale
    $pdo->prepare("INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount) VALUES (?, ?, ?, '2001-01-02 10:00:00', '99.00')")
        ->execute([$ids['custB'], $ids['RL'], $ids['admin']]);
    $ids['saleRL'] = (int) $pdo->lastInsertId();

    $item = function (string $name, string $type, string $price, ?int $stock) use ($pdo): int {
        $pdo->prepare('INSERT INTO items (item_name, item_type, category, price, stock_quantity, reorder_level) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $type, 'Rescue test', $price, $stock, $stock === null ? null : 2]);
        return (int) $pdo->lastInsertId();
    };
    $ids['P'] = $item("{$tag} Inner Tube", 'product', '150.00', 10);
    $ids['S'] = $item("{$tag} Vulcanizing", 'service', '200.50', null);

    $ids['cleanup'] = function () use ($pdo, &$ids): void {
        $req = implode(',', array_map('intval', $ids['requests']));
        $saleIds = $pdo->query("SELECT sale_id FROM sales WHERE admin_id = {$ids['admin']} OR service_request_id IN ({$req})")->fetchAll(\PDO::FETCH_COLUMN);
        if ($saleIds) {
            $in = implode(',', array_map('intval', $saleIds));
            $pdo->exec("DELETE FROM sale_items WHERE sale_id IN ({$in})");
            $pdo->exec("DELETE FROM sales WHERE sale_id IN ({$in})");
        }
        $pdo->exec("DELETE FROM service_requests WHERE request_id IN ({$req})");
        $pdo->exec('DELETE FROM vehicles WHERE vehicle_id IN (' . implode(',', array_map('intval', $ids['vehicles'])) . ')');
        $pdo->exec('DELETE FROM tiremen WHERE tireman_id = ' . (int) $ids['tireman']);
        $pdo->prepare('DELETE FROM items WHERE item_name LIKE ?')->execute([$ids['tag'] . '%']);
        $pdo->exec("DELETE FROM customers WHERE customer_id IN ({$ids['custA']}, {$ids['custB']}, {$ids['custC']})");
        $pdo->exec('DELETE FROM admins WHERE admin_id = ' . (int) $ids['admin']);
    };
    return $ids;
}

test('admin Rescue sale: entry point, start_rescue checks, clean-cart rule, customer lock, stale tabs, checkout, traceability', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');
    $s = rescue_sale_seed($pdo);
    [$RA, $RB, $RC, $RP, $RR, $RL, $P, $S] = [$s['RA'], $s['RB'], $s['RC'], $s['RP'], $s['RR'], $s['RL'], $s['P'], $s['S']];

    $server = new HttpServer(8696);
    $POS  = '/vulcatrack/admin/pos.php';
    $VIEW = '/vulcatrack/admin/rescue-view.php?id=';
    $q = function (string $sql, array $args = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st;
    };
    $assertClean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Stack trace:', 'Undefined ', 'PDOException', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };

    try {
        $server->start();
        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $s['adminEmail'], 'password' => $s['pw']]);

        $page = fn () => $server->request($POS)['body'];
        $ctx  = fn (string $html) => preg_match('/name="expected_rescue_id" value="([^"]*)"/', $html, $m) ? $m[1] : null;
        // A cart change submitted from a page rendered earlier (its token + its context).
        $postFrom = fn (string $renderedHtml, array $fields) => $server->request(
            $POS, $fields + ['_csrf' => HttpServer::csrfToken($renderedHtml), 'expected_rescue_id' => $ctx($renderedHtml)], true
        )['body'];
        $post  = fn (array $fields) => $postFrom($page(), $fields);         // from a freshly loaded page
        $start = fn (int $rid) => $server->request($POS, ['_action' => 'start_rescue', 'request_id' => (string) $rid, '_csrf' => HttpServer::csrfToken($page())], true)['body'];
        $cancel = fn () => $post(['_action' => 'clear']);
        $qtyOf = fn (string $html, int $item) => preg_match('/name="qty\[' . $item . '\]"\s+value="(\d+)"/', $html, $m) ? (int) $m[1] : null;
        $view  = fn (int $rid) => $server->request($VIEW . $rid)['body'];
        // sales recorded during this test (the seeded "already linked" sale for RL excluded)
        $saleCount = fn () => (int) $q('SELECT COUNT(*) FROM sales WHERE admin_id = ? AND sale_id <> ?', [$s['admin'], $s['saleRL']])->fetchColumn();
        $stale = 'This sale changed in another window';

        // ================= 1-4: Rescue detail entry point =================
        $d = $view($RA);
        assert_contains('No sale recorded for this request yet.', $d, 'accepted, no sale');
        assert_contains('name="_action" value="start_rescue"', $d, '1. accepted Rescue offers Record sale in POS');
        assert_contains('<input type="hidden" name="request_id" value="' . $RA . '">', $d);
        assert_contains('action="' . $POS . '"', $d, 'the form posts to the POS');
        assert_contains('>Record sale in POS</button>', $d);
        assert_same(1, preg_match('/<form method="post" action="[^"]*pos\.php">\s*<input type="hidden" name="_csrf"/', $d), 'POST + CSRF');
        assert_contains('value="start_rescue"', $view($RC), '2. completed Rescue without a sale offers it (late entry)');
        assert_not_contains('start_rescue', $view($RP), '3. pending: not offered');
        assert_not_contains('start_rescue', $view($RR), '4. rejected: not offered');
        $assertClean($d, 'rescue-view (accepted)');

        // ================= 5-12: start_rescue checks =================
        $server->request($POS . '?_action=start_rescue&request_id=' . $RA);
        assert_not_contains('class="pos-rescue', $page(), '5. a GET never starts Rescue mode');
        $body = $server->request($POS, ['_action' => 'start_rescue', 'request_id' => (string) $RA, '_csrf' => 'bogus'], true)['body'];
        assert_contains('Your session expired', $body, '6. CSRF checked');
        assert_not_contains('class="pos-rescue', $body);
        foreach ([[2147483646, 'was not found', '7. unknown'], ['abc', 'was not found', 'malformed id'],
                  [$RP, "request #{$RP} is pending", '8. pending'], [$RR, "request #{$RR} is rejected", '9. rejected'],
                  [$RL, "already has a recorded sale (Sale #{$s['saleRL']})", '10. already linked']] as [$rid, $msg, $why]) {
            $body = $server->request($POS, ['_action' => 'start_rescue', 'request_id' => (string) $rid, '_csrf' => HttpServer::csrfToken($page())], true)['body'];
            assert_contains($msg, $body, "{$why} refused");
            assert_not_contains('class="pos-rescue', $body, "{$why}: POS stays ordinary");
        }

        $body = $start($RA);
        assert_contains("Recording a sale for Rescue request #{$RA}", $body, '11. accepted Rescue starts');
        assert_contains('<section class="pos-rescue"', $body, 'Rescue banner');
        assert_contains("Request #{$RA}", $body);
        assert_contains("{$s['tag']} Alpha Owner", $body, '14. the Rescue customer is shown');
        assert_contains('<dd>Accepted</dd>', $body, 'status shown');
        assert_contains('href="/vulcatrack/admin/rescue-view.php?id=' . $RA . '"', $body, 'link back to the Rescue');
        assert_contains('name="expected_rescue_id" value="' . $RA . '"', $body, '13. forms now carry the Rescue context');
        assert_not_contains('name="expected_rescue_id" value=""', $body, 'no form still claims an ordinary sale');
        assert_contains("Locked to Rescue request #{$RA}", $body, '14. customer locked');
        assert_not_contains('Make walk-in', $body, '20. no Make walk-in in Rescue mode');
        assert_not_contains('Link a registered customer', $body, '19. no customer linking in Rescue mode');
        assert_contains('Cancel sale', $body, 'Cancel is available even with an empty Rescue cart');
        foreach (['ETA', 'Payment', 'GCash', 'Paid'] as $never) {
            assert_not_contains($never, (string) (preg_match('/<section class="pos-rescue.*?<\/section>/s', $body, $m) ? $m[0] : ''), "banner shows no {$never}");
        }
        $assertClean($body, 'pos (Rescue mode)');

        // ================= 15-18: clean-cart rule =================
        $body = $start($RA);
        assert_contains("Rescue request #{$RA} is already open in the POS.", $body, '18. same Rescue: opens safely');
        assert_contains("Request #{$RA}", $body);
        assert_contains('Finish or cancel the current sale first.', $start($RB), '17. a different Rescue is refused');
        assert_contains("Request #{$RA}", $page(), '17. still Rescue #A');

        $cancel();
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        $body = $start($RA);
        assert_contains('Finish or cancel the current sale first.', $body, '15. items in an ordinary sale block Rescue mode');
        assert_not_contains('class="pos-rescue', $body);
        assert_same(1, $qtyOf($body, $P), '15. the ordinary cart is untouched');
        $cancel();
        $post(['_action' => 'link_customer', 'customer_id' => (string) $s['custC']]);
        $body = $start($RA);
        assert_contains('Finish or cancel the current sale first.', $body, '16. a chosen registered customer blocks Rescue mode');
        assert_contains("{$s['tag']} Charlie Buyer", $body, '16. the chosen customer is kept');
        $cancel();
        assert_contains('Customer: <strong>Walk-in</strong>', preg_replace('/\s+/', ' ', $page()), 'back to walk-in');

        // ================= 22-24, 27-29: an ordinary page must not change a Rescue cart =================
        $normalPage = $page();
        assert_same('', $ctx($normalPage), 'ordinary page carries the empty context');
        $start($RA);
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '2']);          // current page: fine
        $body = $postFrom($normalPage, ['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1']);
        assert_contains($stale, $body, '22. old Add refused');
        assert_null($qtyOf($body, $S), '22. nothing added');
        $body = $postFrom($normalPage, ['_action' => 'update', "qty[{$P}]" => '5']);
        assert_contains($stale, $body, '23. old Update quantities refused');
        assert_same(2, $qtyOf($body, $P), '23. quantity unchanged');
        $body = $postFrom($normalPage, ['_action' => 'remove', 'item_id' => (string) $P]);
        assert_contains($stale, $body, '24. old Remove refused');
        assert_same(2, $qtyOf($body, $P), '24. line kept');
        $body = $postFrom($normalPage, ['_action' => 'checkout', 'cash_tendered' => '1000', 'expected_total' => '30000', "qty[{$P}]" => '2']);
        assert_contains($stale, $body, '27. stale Complete sale refused');
        assert_same(0, $saleCount(), '27. nothing recorded');
        $body = $postFrom($normalPage, ['_action' => 'clear']);
        assert_contains($stale, $body, '28. stale Cancel refused');
        assert_contains("Request #{$RA}", $body, '28. Rescue sale still active');
        $body = $postFrom($normalPage, ['_action' => 'link_customer', 'customer_id' => (string) $s['custC']]);
        assert_contains($stale, $body, '29. stale customer link refused');
        $body = $postFrom($normalPage, ['_action' => 'unlink_customer']);
        assert_contains($stale, $body, '29. stale Make walk-in refused');
        assert_contains("{$s['tag']} Alpha Owner", $body, 'customer unchanged');
        $body = $server->request($POS, ['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1', '_csrf' => HttpServer::csrfToken($page())], true)['body'];
        assert_contains($stale, $body, 'a form without any context is refused');
        assert_null($qtyOf($body, $S));

        // ================= 19-20: customer lock holds even for a current-context forged POST =================
        $body = $post(['_action' => 'link_customer', 'customer_id' => (string) $s['custC']]);
        assert_contains('always recorded for the Rescue', $body, '19. forged link refused');
        $body = $post(['_action' => 'unlink_customer']);
        assert_contains('always recorded for the Rescue', $body, '20. forged Make walk-in refused');
        assert_contains("Locked to Rescue request #{$RA}", $body);
        assert_contains("{$s['tag']} Alpha Owner", $body);

        // ================= 25-26: a page rendered for Rescue #A must not change anything else =================
        $rescueAPage = $page();
        assert_same((string) $RA, $ctx($rescueAPage));
        $body = $post(['_action' => 'clear']);                                            // 21. Cancel from the current page
        assert_contains("Rescue sale for request #{$RA} cancelled. Nothing was recorded", $body, '21. Cancel leaves Rescue mode');
        assert_not_contains('class="pos-rescue', $body);
        assert_contains('Customer: <strong>Walk-in</strong>', preg_replace('/\s+/', ' ', $body), '21. back to walk-in');
        assert_null($qtyOf($body, $P), '21. cart emptied');
        $body = $postFrom($rescueAPage, ['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        assert_contains($stale, $body, '25. Rescue #A page cannot change the (now ordinary) cart');
        assert_null($qtyOf($body, $P));
        $start($RB);
        $body = $postFrom($rescueAPage, ['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '1']);
        assert_contains($stale, $body, '26. Rescue #A page cannot change Rescue #B');
        assert_null($qtyOf($body, $P), '26. Rescue #B cart unchanged');
        assert_contains("Request #{$RB}", $body);
        $cancel();

        // ================= 30-39: checkout =================
        $start($RA);
        $post(['_action' => 'add', 'item_id' => (string) $P, 'quantity' => '2']);
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1']);
        $html = $page();
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        assert_same('50050', $e[1] ?? null, '2 x 150.00 + 200.50');
        $forged = $server->request($POS, [
            '_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'expected_rescue_id' => (string) $RB,
            'cash_tendered' => '600', 'expected_total' => $e[1], "qty[{$P}]" => '2', "qty[{$S}]" => '1',
        ], true)['body'];
        assert_contains($stale, $forged, '31. a forged expected context for another Rescue is refused');
        assert_same(0, $saleCount());
        $r = $server->request($POS, [
            '_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'expected_rescue_id' => $ctx($html),
            'cash_tendered' => '600', 'expected_total' => $e[1], "qty[{$P}]" => '2', "qty[{$S}]" => '1',
            // 31. hostile extras: must be ignored — the request id and customer come from the session
            'service_request_id' => (string) $RB, 'request_id' => (string) $RB, 'customer_id' => (string) $s['custC'],
        ]);
        assert_same(302, $r['status'], 'checkout redirects (PRG)');
        $sale = $q('SELECT * FROM sales WHERE admin_id = ? ORDER BY sale_id DESC LIMIT 1', [$s['admin']])->fetch(\PDO::FETCH_ASSOC);
        assert_same($RA, (int) $sale['service_request_id'], '30/32. linked to the session Rescue, not the forged one');
        assert_same($s['custA'], (int) $sale['customer_id'], '33. the Rescue customer');
        assert_same('500.50', $sale['total_amount']);
        assert_same(8, (int) $q('SELECT stock_quantity FROM items WHERE item_id = ?', [$P])->fetchColumn(), '34. product stock deducted');
        assert_null($q('SELECT stock_quantity FROM items WHERE item_id = ?', [$S])->fetchColumn(), '35. service stock untouched');
        $lines = $q('SELECT item_id, quantity, unit_price, subtotal FROM sale_items WHERE sale_id = ? ORDER BY item_id', [$sale['sale_id']])->fetchAll(\PDO::FETCH_NUM);
        assert_same([[$P, 2, '150.00', '300.00'], [$S, 1, '200.50', '200.50']], array_map(fn ($l) => [(int) $l[0], (int) $l[1], $l[2], $l[3]], $lines), 'frozen lines');
        $rowRA = $q('SELECT status, updated_at FROM service_requests WHERE request_id = ?', [$RA])->fetch(\PDO::FETCH_ASSOC);
        assert_same(['status' => 'accepted', 'updated_at' => '2001-01-01 00:00:00'], $rowRA, 'the Rescue itself is not changed by the sale');

        $body = $page();
        $card = preg_match('/<section class="card card--sold".*?<\/section>/s', $body, $m) ? $m[0] : '';
        assert_contains("Sale #{$sale['sale_id']} recorded", $card);
        assert_contains("<dt>Rescue</dt><dd>#{$RA}</dd>", $card, '38. result card shows the Rescue');
        assert_contains('href="/vulcatrack/admin/rescue-view.php?id=' . $RA . '">Back to Rescue #' . $RA . '</a>', $card, '39. Back to Rescue link');
        assert_contains("{$s['tag']} Alpha Owner", $card);
        assert_not_contains('class="pos-rescue', $body, '36. Rescue context cleared');
        assert_contains('Customer: <strong>Walk-in</strong>', preg_replace('/\s+/', ' ', $body), '37. next sale is an ordinary walk-in');
        assert_same('', $ctx($body), '37. forms carry the ordinary context again');
        assert_not_contains('Back to Rescue', $page(), 'the result card is shown once');
        $saleA = (int) $sale['sale_id'];
        assert_contains("already has a recorded sale (Sale #{$saleA})", $start($RA), 'a second sale for the same Rescue cannot be started');

        // ================= 40-49: Rescue detail after the sale =================
        $d = $view($RA);
        $panel = preg_match('/<section class="card rescue-sale".*?<\/section>/s', $d, $m) ? $m[0] : '';
        assert_contains('Sale recorded', $panel, '44. wording');
        assert_contains("Sale #{$saleA}", $panel, '40. sale id');
        assert_contains('&#8369;500.50', $panel, '41. total');
        assert_contains('<dd>' . $sale['sale_date'] . '</dd>', $panel, '42. date');
        assert_contains("<dd>{$s['tag']} Cashier</dd>", $panel, '42. recorded by');
        assert_contains('href="/vulcatrack/admin/transaction-summary.php?id=' . $saleA . '">View Transaction Summary</a>', $panel, '43.');
        assert_not_contains('Payment recorded', $d, '44. never "Payment recorded"');
        foreach (['<dt>Cash</dt>', '<dt>Payment', '<dt>Change</dt>', 'Cash received', 'GCash', 'Paid'] as $never) {
            assert_not_contains($never, $panel, "45. no payment data claimed ({$never})");   // (the admin is "… Cashier")
        }
        assert_not_contains('start_rescue', $d, 'no second Record sale');
        assert_not_contains('value="reject"', $d, '46. Reject hidden once a sale is linked');
        assert_contains('value="complete"', $d, '47. Mark as completed still offered');
        $assertClean($d, 'rescue-view (with sale)');

        // 49. a stale tab still posting Reject gets the specific message; the request stays accepted
        $body = $server->request($VIEW . $RA, ['_action' => 'reject', 'expected_status' => 'accepted', '_csrf' => HttpServer::csrfToken($d)], true)['body'];
        assert_contains('This Rescue has a recorded sale and can no longer be rejected.', $body, '49.');
        assert_same('accepted', $q('SELECT status FROM service_requests WHERE request_id = ?', [$RA])->fetchColumn());
        // 47/48. completing is separate and still works; the sale stays shown
        $body = $server->request($VIEW . $RA, ['_action' => 'complete', '_csrf' => HttpServer::csrfToken($d)], true)['body'];
        assert_contains('Request marked as completed.', $body);
        $d = $view($RA);
        assert_contains('badge--completed">Completed<', $d);
        assert_contains("Sale #{$saleA}", $d, '48. completed Rescue still shows its sale');
        assert_contains('Sale recorded', $d);
        assert_not_contains('start_rescue', $d);

        // ================= 12 + late entry: a completed Rescue without a sale =================
        $body = $start($RC);
        assert_contains("Recording a sale for Rescue request #{$RC}", $body, '12. completed Rescue starts (late entry)');
        assert_contains('<dd>Completed</dd>', $body);
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1']);
        $html = $page();
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        $server->request($POS, ['_action' => 'checkout', '_csrf' => HttpServer::csrfToken($html), 'expected_rescue_id' => $ctx($html),
            'cash_tendered' => '250', 'expected_total' => $e[1], "qty[{$S}]" => '1']);
        $late = $q('SELECT sale_id, customer_id FROM sales WHERE service_request_id = ?', [$RC])->fetch(\PDO::FETCH_ASSOC);
        assert_not_null($late ?: null, 'late sale recorded and linked');
        assert_same($s['custA'], (int) $late['customer_id']);
        assert_same('completed', $q('SELECT status FROM service_requests WHERE request_id = ?', [$RC])->fetchColumn(), 'stays completed');
        assert_contains('Sale #' . $late['sale_id'], $view($RC));

        // ================= 13 (mid-sale): the Rescue becomes ineligible in another window =================
        $start($RB);
        $post(['_action' => 'add', 'item_id' => (string) $S, 'quantity' => '1']);
        $q("UPDATE service_requests SET status = 'rejected' WHERE request_id = ?", [$RB]);   // rejected elsewhere
        $body = $page();
        assert_contains("Rescue request #{$RB} is now rejected", $body, 'warning shown');
        assert_not_contains('class="btnlink pos-complete"', $body, 'Complete sale hidden');
        assert_same(1, $qtyOf($body, $S), 'the cart is kept');
        preg_match('/name="expected_total" value="(\d+)"/', $body, $e);
        $refused = $server->request($POS, ['_action' => 'checkout', '_csrf' => HttpServer::csrfToken($body), 'expected_rescue_id' => $ctx($body),
            'cash_tendered' => '250', 'expected_total' => $e[1], "qty[{$S}]" => '1'])['body'];
        assert_contains("request #{$RB} is rejected", $refused, 'a forced checkout is still refused by SaleService');
        assert_contains('Nothing was recorded', $refused);
        assert_same(0, (int) $q('SELECT COUNT(*) FROM sales WHERE service_request_id = ?', [$RB])->fetchColumn());
        $body = $cancel();
        assert_contains("Rescue sale for request #{$RB} cancelled", $body, 'Cancel exits the broken Rescue sale');
        $q("UPDATE service_requests SET status = 'accepted' WHERE request_id = ?", [$RB]);

        // ================= 50-56: Sales History Source =================
        $q("INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (NULL, ?, '2001-01-03 09:00:00', '10.00')", [$s['admin']]);
        $inshopWalk = (int) $pdo->lastInsertId();
        $q("INSERT INTO sales (customer_id, admin_id, sale_date, total_amount) VALUES (?, ?, '2001-01-03 09:30:00', '20.00')", [$s['custA'], $s['admin']]);
        $inshopReg = (int) $pdo->lastInsertId();
        $hist = $server->request('/vulcatrack/admin/sales.php')['body'];
        assert_contains('<th>Customer</th><th>Source</th>', $hist, 'Source column');
        $rowOf = fn (int $id) => preg_match('/<tr>\s*<td>' . $id . '<\/td>.*?<\/tr>/s', $hist, $m) ? $m[0] : '';
        $w = $rowOf($inshopWalk);
        assert_contains('tag--walkin">Walk-in<', $w, '54. walk-in still shown');
        assert_contains('tag--inshop">In-shop<', $w, '50. unlinked = In-shop');
        $g = $rowOf($inshopReg);
        assert_contains("tag--customer\">{$s['tag']} Alpha Owner<", $g, '55. registered customer, in-shop');
        assert_contains('In-shop', $g, '53. same customer as the Rescue, but the source is not inferred from it');
        $a = $rowOf($saleA);
        assert_contains("tag--customer\">{$s['tag']} Alpha Owner<", $a, '56. registered customer on a Rescue sale');
        assert_contains('<a class="tag tag--rescue" href="/vulcatrack/admin/rescue-view.php?id=' . $RA . '">Rescue #' . $RA . '</a>', $a, '51/52. Rescue #N linking to the request');
        assert_not_contains('In-shop', $a);
        $assertClean($hist, 'sales.php');

        // ================= 57-60: Transaction Summary =================
        $ts = $server->request('/vulcatrack/admin/transaction-summary.php?id=' . $saleA)['body'];
        // Phase 7.4d layout: the source row names the Rescue (plain text on the printed
        // document); the link to it is the screen-only "View Rescue #N" action
        assert_contains('<dt>Source</dt><dd>Rescue #' . $RA . '</dd>', $ts, '57. Source: Rescue #N');
        assert_contains('<a class="txn-actions__link txn-actions__link--rescue" href="/vulcatrack/admin/rescue-view.php?id=' . $RA . '">View Rescue #' . $RA . '</a>', $ts, '57. View Rescue action');
        assert_true(preg_match('~<aside class="txn-actions no-print".*?View Rescue #' . $RA . '.*?</aside>~s', $ts) === 1, '57. the Rescue action is in the screen-only panel');
        assert_contains('Not an official BIR invoice', $ts, '60.');
        assert_contains('window.print()', $ts, '59. print button intact');
        assert_contains('class="admin is-printdoc"', $ts, '59. print styling hook intact');
        foreach (['Cash', 'Payment', 'Change'] as $never) {
            assert_not_contains("<dt>{$never}</dt>", $ts, "no {$never} row");   // ("Cashier" is the existing cashier row)
        }
        $plain = $server->request('/vulcatrack/admin/transaction-summary.php?id=' . $inshopReg)['body'];
        assert_contains('<dt>Source</dt><dd>In-shop</dd>', $plain, '58. an in-shop sale says In-shop');
        assert_not_contains('View Rescue', $plain, '58. no Rescue action on an in-shop sale');
        assert_not_contains('rescue-view.php', $plain, '58. no Rescue link at all');
        $assertClean($ts, 'transaction-summary');

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log: {$bad}");
        }
    } finally {
        $server->stop();
        ($s['cleanup'])();
    }
});
