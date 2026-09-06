<?php
/**
 * Integration tests -- VulcaTrack\Auth\Auth (Phase 3, Decisions 45/47).
 *
 * Exercises the session-actor logic directly against $_SESSION as a plain
 * array: login writes an actor, the two actor types never cross, the 30-minute
 * sliding idle timeout expires a stale session, and logout clears it.
 * (The redirect behaviour of require_customer()/require_admin() is covered
 * end-to-end by tests/http/.)
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Auth;

function fresh_auth(array $overrides = []): Auth
{
    $_SESSION = [];
    $config = $GLOBALS['vulcatrack_config'];
    $config['session'] = array_merge($config['session'], $overrides);
    return new Auth($config);
}

test('login writes an actor record with the expected fields', function () {
    $auth = fresh_auth();
    $auth->login('customer', 42, 'Jane Motorist');

    $actor = $_SESSION['auth'];
    assert_same('customer', $actor['type']);
    assert_same(42, $actor['id']);
    assert_same('Jane Motorist', $actor['name']);
    assert_true(is_int($actor['login_at']) && is_int($actor['last_activity']));
});

test('a customer session never satisfies the admin actor and vice-versa', function () {
    $auth = fresh_auth();
    $auth->login('customer', 1, 'Cust');
    assert_not_null($auth->actor('customer'));
    assert_null($auth->actor('admin'), 'a customer session must not read back as an admin');
    assert_true($auth->check('customer'));
    assert_false($auth->check('admin'));

    $auth->login('admin', 7, 'Boss'); // login() replaces the actor
    assert_not_null($auth->actor('admin'));
    assert_null($auth->actor('customer'));
});

test('the idle window slides forward on each successful actor() call', function () {
    $auth = fresh_auth();
    $auth->login('customer', 1, 'Cust');
    $_SESSION['auth']['last_activity'] = time() - 100;
    $auth->actor('customer');
    assert_true(time() - $_SESSION['auth']['last_activity'] < 5, 'last_activity should be refreshed');
});

test('a session idle past the timeout is invalidated (Decision 45)', function () {
    $auth = fresh_auth(['idle_timeout' => 1800]);
    $auth->login('customer', 1, 'Cust');
    $_SESSION['auth']['last_activity'] = time() - 1801;
    assert_null($auth->actor('customer'), 'a 30-min-idle session must expire');
    assert_false(isset($_SESSION['auth']), 'the expired actor should be cleared');
});

test('a session just inside the timeout still authenticates', function () {
    $auth = fresh_auth(['idle_timeout' => 1800]);
    $auth->login('admin', 3, 'Boss');
    $_SESSION['auth']['last_activity'] = time() - 1700;
    assert_not_null($auth->actor('admin'));
});

test('logout clears the actor', function () {
    $auth = fresh_auth();
    $auth->login('customer', 1, 'Cust');
    $auth->logout();
    assert_null($auth->actor('customer'));
    assert_false(isset($_SESSION['auth']));
});
