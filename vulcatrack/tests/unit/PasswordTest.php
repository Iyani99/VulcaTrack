<?php
/**
 * Unit tests for VulcaTrack\Auth\Password -- the thin wrapper over PHP's
 * password_* functions used by every login and by seed_admin.php.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('Password::hash produces a verifiable, non-plaintext hash', function () {
    $hash = Password::hash('correct horse battery staple');
    assert_true($hash !== 'correct horse battery staple');
    assert_true(strlen($hash) >= 20);
    assert_true(Password::verify('correct horse battery staple', $hash));
});

test('Password::verify rejects the wrong password', function () {
    $hash = Password::hash('s3cret-value');
    assert_false(Password::verify('s3cret-Value', $hash));
    assert_false(Password::verify('', $hash));
});

test('Password::needsRehash is false for a freshly created default hash', function () {
    assert_false(Password::needsRehash(Password::hash('whatever-123')));
});

test('Password::needsRehash is true for a weaker legacy bcrypt cost', function () {
    $weak = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    assert_true(Password::needsRehash($weak));
});

test('Password::fakeVerify runs without throwing and returns nothing', function () {
    $result = Password::fakeVerify('any-string');
    assert_null($result);
});
