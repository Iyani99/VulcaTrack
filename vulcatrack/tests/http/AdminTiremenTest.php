<?php
/**
 * End-to-end HTTP tests for Phase 6 Chunk 6.1 — Tireman management
 * (admin/tiremen.php list + activate/deactivate, admin/tireman-edit.php
 * add/edit).
 *
 * Covers: the admin guard and actor separation on every path, CSRF, POST-only
 * mutation (no GET side effects), strict id handling (junk / unknown -> 404),
 * name + contact validation boundaries, the active/inactive/all filter (junk
 * falls back to active), soft activate/deactivate (no delete), the one-time
 * session flash, and output escaping.
 *
 * A throwaway admin + customer + Tiremen are seeded via PDO and removed
 * afterwards. The database must be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\TiremanRepository;

test('admin tiremen: guard, CSRF, list/filter, add, edit, activate/deactivate', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'TMN' . substr(bin2hex(random_bytes(4)), 0, 8);

    $custEmail  = TestDb::email('tmn-cust');
    $adminEmail = TestDb::email('tmn-admin');
    $password   = 'tmn-password-123';

    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Tmn Customer', $custEmail, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['Tmn Admin', $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $mk = function (string $name, int $active = 1) use ($pdo): int {
        $pdo->prepare('INSERT INTO tiremen (name, contact_number, is_active) VALUES (?,?,?)')
            ->execute([$name, '0917 555 0000', $active]);
        return (int) $pdo->lastInsertId();
    };
    $seedActive   = $mk("{$tag} Seed Active");
    $seedInactive = $mk("{$tag} Seed Inactive", 0);

    $row = fn (int $id) => $pdo->query('SELECT * FROM tiremen WHERE tireman_id = ' . (int) $id)->fetch(\PDO::FETCH_ASSOC) ?: null;
    $myCount = function () use ($pdo, $tag): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM tiremen WHERE name LIKE ?');
        $s->execute([$tag . '%']);
        return (int) $s->fetchColumn();
    };
    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8687);
    $cleanup = function () use ($pdo, $custId, $adminId, $tag): void {
        $pdo->prepare('DELETE FROM tiremen WHERE name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $LIST = '/vulcatrack/admin/tiremen.php';
    $EDIT = '/vulcatrack/admin/tireman-edit.php';

    try {
        $server->start();

        // ============ SECURITY: unauthenticated ============
        foreach ([$LIST, $EDIT, $EDIT . '?id=' . $seedActive] as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "{$path} redirects an unauthenticated visitor");
            assert_contains('/admin/login.php', (string) $r['location']);
        }
        $r = $server->request($LIST, ['_action' => 'deactivate', 'tireman_id' => (string) $seedActive, '_csrf' => 'x']);
        assert_same(302, $r['status']);
        assert_contains('/admin/login.php', (string) $r['location'], 'an unauthenticated deactivate POST is refused');
        $r = $server->request($EDIT, ['name' => "{$tag} Anon", 'contact_number' => '12345', '_csrf' => 'x']);
        assert_contains('/admin/login.php', (string) $r['location'], 'an unauthenticated add POST is refused');
        assert_same(1, (int) $row($seedActive)['is_active'], 'nothing was mutated');

        // ============ SECURITY: a customer session is not an admin ============
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        foreach ([$LIST, $EDIT, $EDIT . '?id=' . $seedActive] as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "a customer session must not open {$path}");
            assert_contains('/admin/login.php', (string) $r['location']);
        }
        $r = $server->request($LIST, ['_action' => 'deactivate', 'tireman_id' => (string) $seedActive, '_csrf' => 'x']);
        assert_contains('/admin/login.php', (string) $r['location'], 'a customer cannot deactivate a Tireman');
        assert_same(1, (int) $row($seedActive)['is_active']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        // ============ log in as admin ============
        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);
        $token = fn (string $path) => HttpServer::csrfToken($server->request($path)['body']);

        // ============ LIST + FILTER ============
        $list = $server->request($LIST);
        assert_same(200, $list['status'], 'the admin can view the Tireman list');
        assert_contains('>Tiremen<', $list['body'], 'the Tiremen nav entry is present');
        assert_contains("{$tag} Seed Active", $list['body'], 'default filter shows active Tiremen');
        assert_not_contains("{$tag} Seed Inactive", $list['body'], 'default filter hides inactive Tiremen');
        assert_contains('<option value="active" selected>', $list['body']);
        // Active / Inactive badges follow is_active (Phase 7.3b: black = active, outlined = inactive).
        assert_contains('badge--active', $list['body'], 'active Tiremen carry the Active badge');
        assert_not_contains('badge--inactive', $list['body'], 'the active-only list shows no Inactive badge');
        $assertCleanHtml($list['body'], 'tiremen.php');

        $r = $server->request($LIST . '?status=inactive');
        assert_contains("{$tag} Seed Inactive", $r['body']);
        assert_not_contains("{$tag} Seed Active", $r['body'], 'inactive filter hides active Tiremen');
        assert_contains('<option value="inactive" selected>', $r['body']);
        assert_contains('badge--inactive', $r['body'], 'inactive Tiremen carry the Inactive badge');
        assert_not_contains('badge--active', $r['body'], 'the inactive-only list shows no Active badge');

        $r = $server->request($LIST . '?status=all');
        assert_contains("{$tag} Seed Active", $r['body']);
        assert_contains("{$tag} Seed Inactive", $r['body'], 'all shows both');

        $junk = '"><script>alert(1)</script>';
        $r = $server->request($LIST . '?status=' . rawurlencode($junk));
        assert_same(200, $r['status'], 'a junk filter value is handled safely');
        assert_contains('<option value="active" selected>', $r['body'], 'a junk filter falls back to active');
        assert_not_contains('<script>alert(1)</script>', $r['body'], 'the junk value is never reflected');
        assert_not_contains("{$tag} Seed Inactive", $r['body']);

        // ============ POST-only: a GET with mutation params does nothing ============
        $server->request($LIST . '?_action=deactivate&tireman_id=' . $seedActive);
        assert_same(1, (int) $row($seedActive)['is_active'], 'a GET never mutates');

        // ============ CSRF: bad token on activate/deactivate ============
        $r = $server->request($LIST, ['_action' => 'deactivate', 'tireman_id' => (string) $seedActive, '_csrf' => 'bogus']);
        assert_same(302, $r['status']);
        assert_same(1, (int) $row($seedActive)['is_active'], 'a bad-CSRF deactivate did nothing');
        $r = $server->request($LIST);
        assert_contains('session expired', strtolower($r['body']), 'the refusal is flashed');

        // ============ DEACTIVATE / ACTIVATE (soft, filter preserved, one-time flash) ============
        $r = $server->request($LIST, ['_action' => 'deactivate', 'tireman_id' => (string) $seedActive, '_csrf' => $token($LIST)]);
        assert_same(302, $r['status']);
        assert_same(0, (int) $row($seedActive)['is_active'], 'deactivate sets is_active = 0');
        assert_not_null($row($seedActive), 'deactivation never deletes the row');
        $r = $server->request($LIST);
        assert_contains("{$tag} Seed Active deactivated.", $r['body'], 'the success flash is shown');
        $r = $server->request($LIST);
        assert_not_contains('deactivated.', $r['body'], 'the flash is one-time (gone on refresh)');

        $r = $server->request($LIST, ['_action' => 'activate', 'tireman_id' => (string) $seedInactive, 'status' => 'inactive', '_csrf' => $token($LIST)]);
        assert_same(302, $r['status']);
        assert_contains('status=inactive', (string) $r['location'], 'the admin returns to the same filter');
        assert_same(1, (int) $row($seedInactive)['is_active'], 'activate sets is_active = 1');

        $ids = array_map(fn ($t) => $t['tireman_id'], (new TiremanRepository($pdo))->listActive());
        assert_true(in_array($seedInactive, $ids, true), 'a reactivated Tireman is offered by listActive()');
        assert_false(in_array($seedActive, $ids, true), 'a deactivated Tireman is excluded from listActive()');

        // Junk / unknown ids and unknown actions are refused without a raw error.
        foreach (['abc', '0', '-1', '1e3', '99999999999', '2147483647'] as $badId) {
            $r = $server->request($LIST, ['_action' => 'activate', 'tireman_id' => $badId, '_csrf' => $token($LIST)]);
            assert_same(302, $r['status'], "id '{$badId}' is handled safely");
            $page = $server->request($LIST);
            assert_contains('could not be completed', $page['body'], "id '{$badId}' is refused");
            $assertCleanHtml($page['body'], "tiremen.php after id {$badId}");
        }
        $server->request($LIST, ['_action' => 'delete', 'tireman_id' => (string) $seedInactive, '_csrf' => $token($LIST)]);
        assert_not_null($row($seedInactive), 'there is no delete action');

        // ============ ADD / EDIT forms open ============
        $r = $server->request($EDIT);
        assert_same(200, $r['status'], 'the admin can open the add form');
        assert_contains('Add Tireman', $r['body']);
        $assertCleanHtml($r['body'], 'tireman-edit.php (add)');

        $r = $server->request($EDIT . '?id=' . $seedActive);
        assert_same(200, $r['status'], 'the admin can open the edit form');
        assert_contains('value="' . $tag . ' Seed Active"', $r['body'], 'the edit form is prefilled');

        foreach (['abc', '0', '-5', '1.5', '2147483648', '2147483647', ''] as $badId) {
            $r = $server->request($EDIT . '?id=' . rawurlencode($badId));
            assert_same(404, $r['status'], "edit id '{$badId}' is not found");
            assert_contains('Tireman not found', $r['body']);
            $assertCleanHtml($r['body'], "tireman-edit.php?id={$badId}");
        }

        // ============ CSRF: bad token on add ============
        $before = $myCount();
        $r = $server->request($EDIT, ['_csrf' => 'bogus', 'name' => "{$tag} Ghost", 'contact_number' => '12345']);
        assert_same(200, $r['status'], 'a bad-CSRF add re-renders the form');
        assert_contains('session expired', strtolower($r['body']));
        assert_same($before, $myCount(), 'nothing was created');

        // ============ VALIDATION boundaries ============
        $cases = [
            'blank name'          => [['name' => '   ', 'contact_number' => '12345'], 'Name is required'],
            'name 151 chars'      => [['name' => $tag . str_repeat('n', 151 - strlen($tag)), 'contact_number' => '12345'], 'Name is too long'],
            'blank contact'       => [['name' => "{$tag} X", 'contact_number' => '  '], 'Contact number is required'],
            'contact 2 chars'     => [['name' => "{$tag} X", 'contact_number' => ' 12 '], 'Contact number is too short'],
            'contact 31 chars'    => [['name' => "{$tag} X", 'contact_number' => str_repeat('9', 31)], 'Contact number is too long'],
        ];
        foreach ($cases as $label => [$fields, $message]) {
            $r = $server->request($EDIT, ['_csrf' => $token($EDIT)] + $fields);
            assert_same(200, $r['status'], "{$label}: the form re-renders");
            assert_contains($message, $r['body'], "{$label}: the error is shown");
            assert_same($before, $myCount(), "{$label}: nothing was created");
        }

        // ============ ADD: valid, at the exact boundaries, trimmed ============
        $longName = $tag . str_repeat('n', 150 - strlen($tag));
        $r = $server->request($EDIT, ['_csrf' => $token($EDIT), 'name' => "  {$longName}  ", 'contact_number' => ' 123 ']);
        assert_same(302, $r['status'], 'a 150-char name and a 3-char contact are accepted');
        assert_contains('/admin/tiremen.php', (string) $r['location']);
        $new = $pdo->query('SELECT * FROM tiremen WHERE name = ' . $pdo->quote($longName))->fetch(\PDO::FETCH_ASSOC);
        assert_not_null($new, 'the new Tireman was stored with surrounding whitespace trimmed');
        assert_same('123', $new['contact_number']);
        assert_same(1, (int) $new['is_active'], 'a new Tireman is active');
        $r = $server->request($LIST);
        assert_contains("{$longName} added.", $r['body']);

        $r = $server->request($EDIT, ['_csrf' => $token($EDIT), 'name' => "{$tag} Thirty", 'contact_number' => str_repeat('9', 30)]);
        assert_same(302, $r['status'], 'a 30-char contact is accepted');

        // ============ EDIT: valid ============
        $r = $server->request($EDIT . '?id=' . $seedActive, [
            '_csrf' => $token($EDIT . '?id=' . $seedActive), 'name' => "{$tag} Renamed", 'contact_number' => '+63 917 000 1111',
        ]);
        assert_same(302, $r['status']);
        assert_contains('status=inactive', (string) $r['location'], 'editing an inactive Tireman returns to the inactive list');
        $edited = $row($seedActive);
        assert_same("{$tag} Renamed", $edited['name']);
        assert_same('+63 917 000 1111', $edited['contact_number']);
        assert_same(0, (int) $edited['is_active'], 'editing never changes is_active');

        // Invalid edit leaves the row untouched and keeps the typed values.
        $r = $server->request($EDIT . '?id=' . $seedActive, [
            '_csrf' => $token($EDIT . '?id=' . $seedActive), 'name' => "{$tag} Kept", 'contact_number' => '1',
        ]);
        assert_same(200, $r['status']);
        assert_contains('value="' . $tag . ' Kept"', $r['body'], 'the typed name is preserved on failure');
        assert_same("{$tag} Renamed", $row($seedActive)['name'], 'a failed edit changes nothing');

        // Posting an edit to an unknown id is a 404, not an insert.
        $before = $myCount();
        $r = $server->request($EDIT . '?id=2147483647', ['_csrf' => $token($EDIT), 'name' => "{$tag} Nope", 'contact_number' => '12345']);
        assert_same(404, $r['status']);
        assert_same($before, $myCount(), 'no row created by an edit to an unknown id');

        // ============ ESCAPING ============
        $r = $server->request($EDIT, ['_csrf' => $token($EDIT), 'name' => "{$tag} <script>alert('x')</script>", 'contact_number' => '1']);
        assert_same(200, $r['status'], 'validation fails so the form re-renders');
        assert_not_contains("<script>alert('x')</script>", $r['body'], 'the hostile name is escaped on the form');
        assert_contains('&lt;script&gt;', $r['body']);

        $r = $server->request($EDIT, ['_csrf' => $token($EDIT), 'name' => "{$tag} <b>bold</b>", 'contact_number' => '<i>0917</i>']);
        assert_same(302, $r['status']);
        $listed = $server->request($LIST);
        assert_not_contains('<b>bold</b>', $listed['body'], 'the hostile name is escaped in the list and flash');
        assert_contains('&lt;b&gt;bold&lt;/b&gt;', $listed['body']);
        assert_not_contains('<i>0917</i>', $listed['body'], 'the hostile contact is escaped');
        assert_contains('&lt;i&gt;0917&lt;/i&gt;', $listed['body']);
        $assertCleanHtml($listed['body'], 'tiremen list');

        // ============ server log is clean ============
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
