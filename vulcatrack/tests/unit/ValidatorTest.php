<?php
/**
 * Unit tests for VulcaTrack\Support\Validator -- the shared server-side
 * validation used by every Phase 3/4 form (and, later, Phase 5).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\Validator;

test('Validator starts clean and flips to failing on the first error', function () {
    $v = new Validator();
    assert_true($v->passes());
    assert_false($v->fails());
    $v->add('field', 'boom');
    assert_true($v->fails());
    assert_false($v->passes());
});

test('Validator::add keeps only the first error per field', function () {
    $v = new Validator();
    $v->add('email', 'first');
    $v->add('email', 'second');
    assert_same('first', $v->errors()['email']);
});

test('Validator::text enforces required / min / max and trims', function () {
    $v = new Validator();
    assert_same('hello', $v->text('a', '  hello  ', 'A', 50));
    assert_null($v->text('b', '', 'B', 50));
    assert_null($v->text('c', null, 'C', 50));
    assert_null($v->text('d', 'x', 'D', 50, 3));       // too short
    assert_null($v->text('e', str_repeat('x', 51), 'E', 50)); // too long
    assert_true(isset($v->errors()['b'], $v->errors()['c'], $v->errors()['d'], $v->errors()['e']));
});

test('Validator::optionalText allows blank but rejects over-long', function () {
    $v = new Validator();
    assert_null($v->optionalText('a', '', 'A', 10));
    assert_null($v->optionalText('b', null, 'B', 10));
    assert_same('ok', $v->optionalText('c', ' ok ', 'C', 10));
    assert_null($v->optionalText('d', str_repeat('y', 11), 'D', 10));
    assert_true($v->passes() === false && isset($v->errors()['d']));
    assert_false(isset($v->errors()['a']));
});

test('Validator::email accepts valid addresses and rejects the rest', function () {
    $v = new Validator();
    assert_same('a@b.com', $v->email('ok', 'a@b.com'));
    assert_null($v->email('bad', 'not-an-email'));
    assert_null($v->email('empty', ''));
});

test('Validator::password enforces the minimum length only', function () {
    $v = new Validator();
    assert_same('abcdefgh', $v->password('a', 'abcdefgh', 8));
    assert_null($v->password('b', 'short', 8));
    assert_null($v->password('c', '', 8));
});

test('Validator::matches records an error when confirmation differs', function () {
    $v = new Validator();
    $v->matches('confirm', 'a', 'b', 'Passwords');
    assert_true(isset($v->errors()['confirm']));

    $v2 = new Validator();
    $v2->matches('confirm', 'same', 'same', 'Passwords');
    assert_true($v2->passes());
});

test('Validator::coordinates returns floats for a valid pair, null otherwise', function () {
    $v = new Validator();
    $pair = $v->coordinates('loc', '14.9466', '120.8929');
    assert_not_null($pair);
    assert_same(14.9466, $pair[0]);
    assert_same(120.8929, $pair[1]);

    $v2 = new Validator();
    assert_null($v2->coordinates('loc', '999', '0'));
    assert_true(isset($v2->errors()['loc']));
});
