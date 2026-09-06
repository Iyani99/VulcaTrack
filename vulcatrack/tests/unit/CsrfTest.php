<?php
/**
 * Unit tests for VulcaTrack\Auth\Csrf. Runs against $_SESSION as a plain array
 * (no session backend needed for the token logic).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Csrf;

test('Csrf::token generates a 64-hex-char token and is stable within a session', function () {
    $_SESSION = [];
    $t1 = Csrf::token();
    $t2 = Csrf::token();
    assert_same($t1, $t2, 'token should not change until rotated');
    assert_same(1, preg_match('/^[a-f0-9]{64}$/', $t1), 'token should be 32 random bytes hex-encoded');
});

test('Csrf::rotate replaces the token', function () {
    $_SESSION = [];
    $before = Csrf::token();
    Csrf::rotate();
    $after = Csrf::token();
    assert_true($before !== $after, 'rotate() must change the token');
});

test('Csrf::check does a constant-time match and rejects everything else', function () {
    $_SESSION = [];
    $token = Csrf::token();
    assert_true(Csrf::check($token));
    assert_false(Csrf::check($token . 'x'));
    assert_false(Csrf::check(''));
    assert_false(Csrf::check(null));
    assert_false(Csrf::check('deadbeef'));
});

test('Csrf::check fails closed when the session has no token', function () {
    $_SESSION = [];
    assert_false(Csrf::check('anything'), 'no session token => every check must fail');
});

test('Csrf::field emits a hidden input carrying the current token', function () {
    $_SESSION = [];
    $token = Csrf::token();
    $html = Csrf::field();
    assert_contains('type="hidden"', $html);
    assert_contains('name="_csrf"', $html);
    assert_contains($token, $html);
});
