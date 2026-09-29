<?php
/**
 * End-to-end HTTP tests for Admin Accounts (admin/accounts.php, Decision 79):
 * access (signed out / customer / admin), the read-only list (no hashes), the
 * footer link and neutral nav state, every validation refusal, re-authentication
 * with the SIGNED-IN admin's own password (another admin's password does not
 * count), CSRF, forged array input, PRG + no double create, case-insensitive
 * duplicates, the current admin staying signed in, and the new admin signing in
 * later through the normal admin login.
 *
 * Throwaway admins / customer are seeded via PDO; every account this test
 * creates (tracked by email) is deleted afterwards.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin accounts: access, list, re-auth, validation, CSRF, arrays, PRG, duplicates, session, new admin login', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'AA' . substr(bin2hex(random_bytes(4)), 0, 8);
    $passA = 'admin-a-password-1';
    $passB = 'admin-b-password-2';
    $emailA = TestDb::email('aa-a');
    $emailB = TestDb::email('aa-b');
    $custEmail = TestDb::email('aa-cust');
    $ins = $pdo->prepare('INSERT INTO admins (full_name, email, password_hash, created_at) VALUES (?,?,?,?)');
    $ins->execute(["{$tag} Alpha <b>Admin</b>", $emailA, Password::hash($passA), '2026-09-01 08:05:00']);
    $ins->execute(["{$tag} Bravo Admin", $emailB, Password::hash($passB), '2026-09-02 09:10:00']);
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(["{$tag} Customer", $custEmail, '09170007777', Password::hash($passA)]);
    $custId = (int) $pdo->lastInsertId();

    $newEmail = strtolower('aa-new-' . $tag . '@example.test');
    $created  = [$emailA, $emailB, $newEmail];
    $countOf  = function (string $email) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email = ?'); // case-insensitive collation
        $s->execute([$email]);
        return (int) $s->fetchColumn();
    };
    $adminTotal = fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();

    $server = new HttpServer(8702);
    $URL = '/vulcatrack/admin/accounts.php';
    $token = fn (): string => HttpServer::csrfToken($server->request($URL)['body']);
    $valid = fn (array $over = []): array => array_merge([
        'full_name' => "{$tag} New Admin", 'email' => $newEmail,
        'password' => 'new-admin-pass-9', 'password_confirmation' => 'new-admin-pass-9',
        'current_password' => $passA,
    ], $over);
    $create = fn (array $fields) => $server->request($URL, ['_csrf' => $token()] + $fields);
    $clean = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Array to string', 'TypeError', 'SQLSTATE', 'PDOException'] as $bad) {
            assert_not_contains($bad, $html, "PHP/DB error text on {$where}: {$bad}");
        }
    };
    $adminLogin = function (string $email, string $password) use ($server): array {
        $p = $server->request('/vulcatrack/admin/login.php');
        return $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($p['body']), 'email' => $email, 'password' => $password]);
    };

    try {
        $server->start();

        // ================= access =================
        $r = $server->request($URL);
        assert_same(302, $r['status'], 'signed-out GET is redirected');
        assert_contains('/admin/login.php', (string) $r['location']);
        $r = $server->request($URL, $valid(['_csrf' => 'x']));
        assert_same(302, $r['status'], 'signed-out POST is redirected');
        assert_same(0, $countOf($newEmail), 'nothing created while signed out');

        $lp = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($lp['body']), 'email' => $custEmail, 'password' => $passA]);
        assert_same(302, $server->request($URL)['status'], 'a customer session does not open Admin Accounts');
        $prof = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($prof['body'])]);

        assert_same(302, $adminLogin($emailA, $passA)['status']);
        $page = $server->request($URL);
        assert_same(200, $page['status']);
        $html = $page['body'];
        $clean($html, 'accounts');

        // ================= list + navigation =================
        assert_contains('<h1>Admin Accounts</h1>', $html);
        assert_same(1, substr_count($html, '<h1'), 'one h1');
        assert_contains(e("{$tag} Alpha <b>Admin</b>") . ' <span class="tag tag--customer">You</span>', $html, 'the signed-in admin is marked, name escaped');
        assert_not_contains("{$tag} Alpha <b>Admin</b>", $html);
        assert_contains("{$tag} Bravo Admin</td>", $html);
        assert_contains('<td class="acct-email">' . e($emailB) . '</td>', $html);
        assert_contains('<td class="nowrap">Sep 2, 2026 · 9:10 AM</td>', $html, 'friendly created date');
        $tbody = substr($html, (int) strpos($html, "<tbody>")); // the sidebar also names the signed-in admin
        assert_true(strpos($tbody, "{$tag} Bravo Admin") < strpos($tbody, "{$tag} Alpha"), "newest first");
        foreach (['$2y$', 'password_hash', 'admin_id'] as $never) {
            assert_not_contains($never, $html, "the list never exposes {$never}");
        }
        assert_contains('<th scope="col">Full name</th>', $html);
        assert_contains('<a class="adm-accounts is-active" aria-current="page" href="/vulcatrack/admin/accounts.php">Admin accounts</a>', $html, 'footer link marked current');
        assert_not_contains('class="is-active" aria-current="page"><svg', $html, 'no operational nav item is active');
        foreach (['Settings', 'role', 'Permission', 'Super'] as $absent) {
            assert_not_contains($absent, $html, "no {$absent}");
        }
        $dash = $server->request('/vulcatrack/admin/index.php')['body'];
        assert_contains('<a class="adm-accounts" href="/vulcatrack/admin/accounts.php">Admin accounts</a>', $dash, 'footer link on other admin pages, not current');
        foreach (['full_name', 'email', 'password', 'password_confirmation', 'current_password'] as $f) {
            assert_contains('<label for="' . $f . '">', $html, "label for {$f}");
            assert_contains('id="' . $f . '" name="' . $f . '"', $html, "input {$f}");
        }

        // ================= validation / re-auth refusals =================
        $before = $adminTotal();
        $cases = [
            'missing name'          => [['full_name' => '   '], 'Full name is required.', 'full_name'],
            'bad email'             => [['email' => 'not-an-email'], 'Enter a valid email address.', 'email'],
            'short password'        => [['password' => 'short', 'password_confirmation' => 'short'], 'Password must be at least 8 characters.', 'password'],
            'mismatch'              => [['password_confirmation' => 'different-pass-1'], 'Passwords do not match.', 'password_confirmation'],
            'no current password'   => [['current_password' => ''], 'Enter your current password.', 'current_password'],
            'wrong current password'=> [['current_password' => 'wrong-password-1'], 'Your current password is incorrect.', 'current_password'],
            "another admin's password" => [['current_password' => $passB], 'Your current password is incorrect.', 'current_password'],
        ];
        foreach ($cases as $label => [$over, $message, $field]) {
            $r = $create($valid($over));
            assert_same(200, $r['status'], "{$label}: re-rendered, not redirected");
            assert_contains('<small class="error" id="err-' . $field . '">' . $message . '</small>', $r['body'], "{$label}: field error");
            assert_contains('aria-describedby="err-' . $field . '" aria-invalid="true"', $r['body'], "{$label}: error tied to its input");
            assert_not_contains('new-admin-pass-9', $r['body'], "{$label}: passwords never echoed back");
            $clean($r['body'], $label);
        }
        assert_same($before, $adminTotal(), 'no account created by any refused submit');

        // bad CSRF + forged arrays
        $r = $server->request($URL, ['_csrf' => 'bogus'] + $valid());
        assert_contains('Your session expired. Please try again.', $r['body'], 'bad CSRF refused');
        foreach (['_csrf[]', 'full_name[]', 'email[]', 'password[]', 'password_confirmation[]', 'current_password[]'] as $arr) {
            $plain = substr($arr, 0, -2);
            $fields = ['_csrf' => $token()] + $valid();
            unset($fields[$plain]);
            $fields[$arr] = 'x';
            $r = $server->request($URL, $fields);
            assert_same(200, $r['status'], "{$arr}: a normal refusal, not a 500");
            $clean($r['body'], $arr);
        }
        assert_same($before, $adminTotal(), 'no account created by bad CSRF or array input');

        // ================= valid creation (PRG) =================
        $r = $create($valid(['email' => '  ' . strtoupper($newEmail) . '  ']));
        assert_same(302, $r['status'], 'a valid create redirects (PRG)');
        assert_contains('/vulcatrack/admin/accounts.php?created=1', (string) $r['location']);
        $row = $pdo->prepare('SELECT full_name, email, password_hash FROM admins WHERE email = ?');
        $row->execute([$newEmail]);
        $row = $row->fetch(\PDO::FETCH_ASSOC);
        assert_same("{$tag} New Admin", $row['full_name']);
        assert_same($newEmail, $row['email'], 'email stored trimmed + lowercased');
        assert_true(Password::verify('new-admin-pass-9', $row['password_hash']), 'password stored as a hash');
        $after = $server->request((string) $r['location']);
        assert_contains('Admin account created successfully.', $after['body']);
        assert_contains(e("{$tag} New Admin") . '</td>', $after['body'], 'the new account is listed');
        $server->request((string) $r['location']);
        assert_same(1, $countOf($newEmail), 'reloading the success page creates nothing');

        // current admin unchanged
        $dash = $server->request('/vulcatrack/admin/index.php')['body'];
        assert_contains('<span class="adm-user__name">' . e("{$tag} Alpha <b>Admin</b>") . '</span>', $dash, 'still signed in as the same admin');

        // duplicates, including a case variant
        foreach ([$newEmail, strtoupper($newEmail)] as $dup) {
            $r = $create($valid(['email' => $dup, 'full_name' => "{$tag} Dup"]));
            assert_contains('An Admin account with this email already exists.', $r['body'], "duplicate refused ({$dup})");
        }
        assert_same(1, $countOf($newEmail), 'still exactly one account for that email');

        // ================= the new admin signs in later, normally =================
        $server->request('/vulcatrack/admin/logout.php', ['_csrf' => HttpServer::csrfToken($dash)]);
        assert_same(302, $server->request($URL)['status'], 'signed out');
        $r = $adminLogin(strtoupper($newEmail), 'new-admin-pass-9');
        assert_same(302, $r['status'], 'the new admin can sign in through the normal admin login');
        assert_contains('<span class="adm-user__name">' . e("{$tag} New Admin") . '</span>', $server->request('/vulcatrack/admin/index.php')['body']);

        // still no public admin sign-up
        foreach (['/vulcatrack/admin/register.php', '/vulcatrack/admin/signup.php'] as $path) {
            assert_same(404, $server->request($path)['status'], "{$path} does not exist");
        }

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $del = $pdo->prepare('DELETE FROM admins WHERE email = ?');
        foreach ($created as $email) {
            $del->execute([$email]);
        }
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
    }
});
