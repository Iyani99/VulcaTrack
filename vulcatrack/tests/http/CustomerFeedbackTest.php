<?php
/**
 * Completed Rescue feedback over HTTP (Phase 7.4c, Decision 78).
 *
 * customer/feedback.php + the Request Status entry point / read-only card +
 * the admin read-only card: only the owner, only a completed and serviced
 * request, only once (a stale second submit never overwrites), strict 1–5
 * rating, optional ≤ 500-character comment (CRLF counted as one), malformed
 * input never a 500, and feedback never changes the request or its sale.
 * Also the central Csrf::check hardening (`_csrf[]`) and a Profile / Vehicle
 * regression pass, since every form now goes through it.
 *
 * Throwaway customers / admin / Tireman / vehicle / requests / sale are seeded
 * via PDO and deleted afterwards (FK-safe order).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('rescue feedback: eligibility, validation, one-time guard, read-only displays, CSRF hardening', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $pw = 'feedback-password-123';
    $insC = $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)');
    $insC->execute(['Feedback Owner', $emailA = TestDb::email('fb-a'), '09170000021', Password::hash($pw)]);
    $custA = (int) $pdo->lastInsertId();
    $insC->execute(['Feedback Other', TestDb::email('fb-b'), '09170000022', Password::hash($pw)]);
    $custB = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['Feedback Admin', $emailAdmin = TestDb::email('fb-admin'), Password::hash($pw)]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tiremen (name, contact_number, is_active) VALUES (?,?,1)')->execute(['Rafael Serviceman', '0918 777 6655']);
    $tireman = (int) $pdo->lastInsertId();
    $vehicles = [];
    foreach ([$custA, $custB] as $cid) {
        $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, vehicle_type, make, model) VALUES (?, 'FB-1', 'Sedan', 'Toyota', 'Vios')")->execute([$cid]);
        $vehicles[$cid] = (int) $pdo->lastInsertId();
    }
    $req = function (int $cid, string $status, bool $tiremanSet = true) use ($pdo, $vehicles, $adminId, $tireman): int {
        $pdo->prepare(
            "INSERT INTO service_requests (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
             VALUES (?,?,?,?, 'Flat rear tire', 14.95, 120.89, 14, ?, '2026-01-02 10:00:00')"
        )->execute([$cid, $vehicles[$cid], $status === 'pending' ? null : $adminId, $tiremanSet && $status !== 'pending' ? $tireman : null, $status]);
        return (int) $pdo->lastInsertId();
    };
    $r1 = $req($custA, 'completed');
    $r2 = $req($custA, 'completed');
    $r3 = $req($custA, 'completed');
    $rPending = $req($custA, 'pending');
    $rAccepted = $req($custA, 'accepted');
    $rRejected = $req($custA, 'rejected');
    $rNoTireman = $req($custA, 'completed', false);
    $rB = $req($custB, 'completed');
    $pdo->prepare("INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount) VALUES (?,?,?, '2026-01-02 11:00:00', 250.00)")
        ->execute([$custA, $r2, $adminId]);
    $saleId = (int) $pdo->lastInsertId();

    $row = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT status, tireman_id, admin_id, updated_at, feedback_rating, feedback_comment, feedback_submitted_at FROM service_requests WHERE request_id = ?');
        $s->execute([$id]);
        return $s->fetch();
    };
    $saleRow = static function () use ($pdo, $saleId): array {
        $s = $pdo->prepare('SELECT * FROM sales WHERE sale_id = ?');
        $s->execute([$saleId]);
        return $s->fetch();
    };

    $server = new HttpServer(8700);
    $cleanup = function () use ($pdo, $custA, $custB, $adminId, $tireman): void {
        $pdo->prepare('DELETE FROM sales WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM service_requests WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM vehicles WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM tiremen WHERE tireman_id = ?')->execute([$tireman]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $fb = fn (int $id): string => '/vulcatrack/customer/feedback.php?id=' . $id;
    $status = fn (int $id): string => '/vulcatrack/customer/booking.php?id=' . $id;
    $loginAs = function (string $email, bool $admin = false) use ($server, $pw): void {
        $path = $admin ? '/vulcatrack/admin/login.php' : '/vulcatrack/login.php';
        $page = $server->request($path);
        $r = $server->request($path, ['_csrf' => HttpServer::csrfToken($page['body']), 'email' => $email, 'password' => $pw]);
        assert_same(302, $r['status'], "sign in as {$email}");
    };
    $token = fn (): string => (string) HttpServer::csrfToken($server->request('/vulcatrack/customer/profile.php')['body']);
    $logout = function (string $tokenPage = '/vulcatrack/customer/profile.php') use ($server): void {
        $server->request('/vulcatrack/logout.php', ['_csrf' => (string) HttpServer::csrfToken($server->request($tokenPage)['body'])]);
    };
    $post = fn (int $id, array $fields, ?string $csrf = null) => $server->request($fb($id), ['_csrf' => $csrf ?? $token()] + $fields);
    $noPhpErrors = function (array $r, string $where): void {
        assert_true($r['status'] !== 500, "{$where}: no HTTP 500");
        foreach (['Fatal error', 'Warning:', 'Notice:', 'TypeError', 'Uncaught'] as $bad) {
            assert_not_contains($bad, $r['body'], "{$where}: no PHP error text ({$bad})");
        }
    };

    try {
        $server->start();

        // --- signed out ------------------------------------------------------
        $r = $server->request($fb($r1));
        assert_same(302, $r['status'], 'a guest is sent to log in');
        assert_contains('/login.php', (string) $r['location']);
        $r = $server->request($fb($r1), ['rating' => '5']);
        assert_same(302, $r['status'], 'a guest POST is not accepted either');
        assert_null($row($r1)['feedback_rating']);

        $loginAs($emailA);

        // --- entry point + page ---------------------------------------------
        $page = $server->request($status($r1));
        assert_contains('href="' . $fb($r1) . '"', $page['body'], 'a completed request offers Rate this service');
        assert_contains('Rate this service', $page['body']);
        foreach ([$rPending, $rAccepted, $rRejected, $rNoTireman] as $id) {
            assert_not_contains('Rate this service', $server->request($status($id))['body'], "no Rate button on request {$id}");
        }

        $page = $server->request($fb($r1));
        assert_same(200, $page['status'], 'the owner can open the feedback page');
        $noPhpErrors($page, 'feedback page');
        assert_contains('Rafael Serviceman', $page['body'], 'shows who serviced it');
        assert_not_contains('0918 777 6655', $page['body'], 'but never the Tireman\'s phone after completion');
        assert_not_contains('09187776655', $page['body']);
        assert_contains('<legend class="fb-section">', $page['body'], 'the stars are a fieldset with a legend');
        for ($i = 1; $i <= 5; $i++) {
            assert_contains('<input type="radio" id="rating-' . $i . '" name="rating" value="' . $i . '"', $page['body']);
            assert_contains('<span class="sr-only">' . $i . ' star' . ($i > 1 ? 's' : '') . '</span>', $page['body'], "accessible name for {$i}");
        }
        assert_contains('href="/vulcatrack/customer/bookings.php" class="is-active" aria-current="page"', $page['body'], 'My Bookings is the active nav item');
        assert_contains('Skip for now', $page['body']);
        foreach (['Tracking', 'Notification', 'GCash', 'Payment', 'Tip', '&#8369;', '₱', 'Safety', 'Dispatch', 'Certified', 'Fast Service'] as $absent) {
            assert_not_contains($absent, $page['body'], "stale Figma item absent: {$absent}");
        }

        // --- ownership / eligibility -----------------------------------------
        $r = $server->request($fb($rB));
        assert_same(404, $r['status'], 'another customer\'s request is not found');
        $r = $post($rB, ['rating' => '5', 'comment' => 'not mine']);
        assert_same(404, $r['status'], 'and a direct POST to it is refused');
        assert_null($row($rB)['feedback_rating']);

        foreach ([$rPending => 'Feedback is available once the service is completed.', $rAccepted => 'Feedback is available once the service is completed.',
                  $rRejected => 'Feedback is available once the service is completed.', $rNoTireman => 'Feedback is not available for this request.'] as $id => $msg) {
            $r = $server->request($fb($id));
            assert_same(200, $r['status']);
            assert_contains($msg, $r['body'], "request {$id}: explains why");
            assert_not_contains('name="rating"', $r['body'], "request {$id}: no form");
            $before = $row($id);
            $r = $post($id, ['rating' => '5']);
            assert_same($before, $row($id), "request {$id}: a direct POST changes nothing");
        }

        // --- validation (nothing saved, never a 500) -------------------------
        $bad = [
            'rating 0'      => [['rating' => '0'], 'Choose a rating from 1 to 5 stars.'],
            'rating 6'      => [['rating' => '6'], 'Choose a rating from 1 to 5 stars.'],
            'rating 4.5'    => [['rating' => '4.5'], 'Choose a rating from 1 to 5 stars.'],
            'rating 5.0'    => [['rating' => '5.0'], 'Choose a rating from 1 to 5 stars.'],
            'rating -1'     => [['rating' => '-1'], 'Choose a rating from 1 to 5 stars.'],
            'missing'       => [['comment' => 'no stars'], 'Choose a rating from 1 to 5 stars.'],
            'rating[]'      => [['rating' => ['5']], 'Choose a rating from 1 to 5 stars.'],
            'comment[]'     => [['rating' => '5', 'comment' => ['hello']], 'Comment is invalid.'],
            '501 chars'     => [['rating' => '5', 'comment' => str_repeat('x', 501)], 'Comment is too long.'],
        ];
        foreach ($bad as $label => [$fields, $msg]) {
            $r = $post($r1, $fields);
            $noPhpErrors($r, $label);
            assert_same(200, $r['status'], "{$label}: the form is shown again");
            assert_contains($msg, $r['body'], "{$label}: a clear message");
            assert_null($row($r1)['feedback_rating'], "{$label}: nothing saved");
        }
        $r = $post($r1, ['rating' => '4', 'comment' => 'kept'], 'bogus-token');
        assert_contains('Your session expired', $r['body'], 'a bad CSRF token is refused');
        $r = $server->request($fb($r1), ['_csrf' => ['x'], 'rating' => '4']);
        $noPhpErrors($r, '_csrf[] on feedback');
        assert_contains('Your session expired', $r['body'], '_csrf[] is a normal refusal (central Csrf::check fix)');
        assert_contains('value="4" checked', $r['body'], 'the chosen rating is kept on re-render');
        assert_null($row($r1)['feedback_rating']);

        // --- a valid submission ----------------------------------------------
        $before = $row($r1);
        $r = $post($r1, ['rating' => '5', 'comment' => "Arrived fast.\r\nFixed the tire."]);
        assert_same(302, $r['status'], 'a valid submission redirects (PRG)');
        assert_same($status($r1) . '&rated=1', (string) $r['location']);
        $after = $row($r1);
        assert_same('5', (string) $after['feedback_rating']);
        assert_same("Arrived fast.\nFixed the tire.", $after['feedback_comment'], 'CRLF stored as LF');
        assert_not_null($after['feedback_submitted_at']);
        foreach (['status', 'tireman_id', 'admin_id', 'updated_at'] as $col) {
            assert_same($before[$col], $after[$col], "feedback leaves {$col} alone");
        }

        $page = $server->request($status($r1) . '&rated=1');
        assert_contains('Thank you, your feedback was submitted.', $page['body']);
        assert_contains('Your feedback', $page['body']);
        assert_contains('5 out of 5', $page['body']);
        assert_contains('Arrived fast.<br />', $page['body']);
        assert_not_contains('Rate this service', $page['body'], 'the Rate button is gone');
        assert_not_contains('Edit', $page['body'], 'no edit action');
        $r = $server->request($fb($r1));
        assert_same(303, $r['status'], 'the feedback page now sends you to Request Status');
        assert_same($status($r1), (string) $r['location']);

        // --- a stale second tab never overwrites -----------------------------
        $saved = $row($r1);
        $r = $post($r1, ['rating' => '1', 'comment' => 'Second tab']);
        assert_same(303, $r['status'], 'a second submit is not saved…');
        assert_same($status($r1) . '&feedback=exists', (string) $r['location'], '…and Request Status says it was already submitted');
        assert_contains('Feedback for this request was already submitted.', $server->request($status($r1) . '&feedback=exists')['body']);
        assert_same($saved, $row($r1), '…rating, comment and time keep the first submission');
        $r = $post($r1, ['rating' => '1']);
        assert_same($saved, $row($r1));

        // --- rating 1 + blank comment; CRLF length; sale independence ---------
        $r = $post($r3, ['rating' => '1', 'comment' => "   \r\n  "]);
        assert_same(302, $r['status'], 'rating 1 is accepted');
        assert_same('1', (string) $row($r3)['feedback_rating']);
        assert_null($row($r3)['feedback_comment'], 'a whitespace-only comment is stored as NULL');
        $sale = $saleRow();
        $crlf = str_repeat('a', 249) . "\r\n" . str_repeat('b', 250); // 500 as the browser counts it
        $r = $post($r2, ['rating' => '4', 'comment' => $crlf]);
        assert_same(302, $r['status'], 'a 500-character comment with a CRLF line break is accepted');
        assert_same(500, mb_strlen((string) $row($r2)['feedback_comment']));
        assert_same($sale, $saleRow(), 'the linked sale is untouched');

        // --- persists across logout / login -----------------------------------
        $logout();
        $loginAs($emailA);
        assert_contains('5 out of 5', $server->request($status($r1))['body'], 'still there after signing in again');

        // --- central CSRF: Profile / Vehicle forms still behave ---------------
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'profile', 'full_name' => 'Feedback Owner', 'contact_number' => '09170000021']);
        assert_same(302, $r['status'], 'profile save still works');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => $token(), '_action' => 'password', 'current_password' => $pw, 'new_password' => $pw, 'new_password_confirmation' => $pw]);
        assert_same(302, $r['status'], 'password change still works');
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => $token(), 'plate_number' => 'FB-2', 'vehicle_type' => 'SUV']);
        assert_same(302, $r['status'], 'vehicle add still works');
        $r = $server->request('/vulcatrack/customer/profile.php', ['_csrf' => 'nope', '_action' => 'profile', 'full_name' => 'X', 'contact_number' => '123']);
        assert_contains('Your session expired', $r['body'], 'a bad token is still refused');
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => ['x'], '_action' => 'deactivate', 'vehicle_id' => (string) $vehicles[$custA]]);
        $noPhpErrors($r, '_csrf[] on vehicles.php (unguarded handler)');
        assert_contains('Your session expired', $r['body']);

        // --- admin: read-only card --------------------------------------------
        $logout();
        $loginAs($emailAdmin, true);
        $view = $server->request('/vulcatrack/admin/rescue-view.php?id=' . $r1);
        assert_same(200, $view['status']);
        assert_true(preg_match('#<section class="card rescue-feedback"[^>]*>(.*?)</section>#s', $view['body'], $card) === 1, 'the admin sees a Customer feedback card');
        assert_contains('Customer feedback', $card[1]);
        assert_contains('5 / 5', $card[1]);
        assert_contains('Arrived fast.<br />', $card[1]);
        foreach (['<form', '<button', '<input', '<textarea'] as $control) {
            assert_not_contains($control, $card[1], "the feedback card has no {$control} (read-only)");
        }
        assert_not_contains('feedback_rating', $view['body'], 'no feedback field is posted anywhere on the page');
        assert_contains('No comment.', $server->request('/vulcatrack/admin/rescue-view.php?id=' . $r3)['body'], 'no comment shown as such');
        $none = $server->request('/vulcatrack/admin/rescue-view.php?id=' . $rNoTireman);
        assert_contains('No feedback yet.', $none['body']);
        assert_not_contains('Customer feedback', $server->request('/vulcatrack/admin/rescue-view.php?id=' . $rPending)['body'], 'no feedback card before completion');
        foreach (['/vulcatrack/admin/tiremen.php', '/vulcatrack/admin/reports.php'] as $p) {
            $b = $server->request($p)['body'];
            assert_not_contains('out of 5', $b, "{$p}: no ratings");
            assert_not_contains('&#9733;', $b, "{$p}: no stars");
        }

        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
