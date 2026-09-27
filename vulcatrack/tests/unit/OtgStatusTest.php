<?php
/**
 * Unit tests for VulcaTrack\Support\OtgStatus -- the presentation mapping over
 * the four locked database status values (Decision 10). Guards against a fifth
 * status quietly appearing and against the "Tireman is on the way" wording
 * being promoted to a real status.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\OtgStatus;

test('OtgStatus exposes exactly the four locked values', function () {
    assert_same(['pending', 'accepted', 'rejected', 'completed'], OtgStatus::VALUES);
});

test('OtgStatus::isValid accepts only the four values', function () {
    foreach (['pending', 'accepted', 'rejected', 'completed'] as $s) {
        assert_true(OtgStatus::isValid($s), "{$s} should be valid");
    }
    foreach (['dispatched', 'on_the_way', 'cancelled', 'in_progress', 'arrived', ''] as $s) {
        assert_false(OtgStatus::isValid($s), "{$s} must not be a valid status");
    }
});

test('OtgStatus::label maps accepted to on-the-way wording only once a Tireman is assigned', function () {
    assert_same('Accepted — assigning a tireman', OtgStatus::label('accepted', false));
    assert_same('Tireman is on the way', OtgStatus::label('accepted', true));
    assert_same('Pending review', OtgStatus::label('pending'));
    assert_same('Request declined', OtgStatus::label('rejected'));
    assert_same('Completed', OtgStatus::label('completed'));
});

test('OtgStatus::adminLabel uses plain labels, never customer wording', function () {
    assert_same('Pending', OtgStatus::adminLabel('pending'));
    assert_same('Accepted', OtgStatus::adminLabel('accepted'));
    assert_same('Rejected', OtgStatus::adminLabel('rejected'));
    assert_same('Completed', OtgStatus::adminLabel('completed'));
});

test('OtgStatus::canTransition allows exactly the four approved admin transitions', function () {
    $allowed = [['pending', 'accepted'], ['pending', 'rejected'], ['accepted', 'completed'], ['accepted', 'rejected']];
    foreach (OtgStatus::VALUES as $from) {
        foreach (OtgStatus::VALUES as $to) {
            $expected = in_array([$from, $to], $allowed, true);
            assert_same($expected, OtgStatus::canTransition($from, $to), "{$from} -> {$to}");
        }
    }
    // No-ops, unknown values and casing are all refused.
    foreach ([['pending', 'pending'], ['accepted', 'accepted'], ['', 'accepted'], ['pending', 'cancelled'],
              ['Pending', 'accepted'], ['dispatched', 'completed']] as [$from, $to]) {
        assert_false(OtgStatus::canTransition($from, $to), "{$from} -> {$to} must be refused");
    }
});

test('OtgStatus::isFinal: rejected and completed are final, nothing leaves them', function () {
    assert_true(OtgStatus::isFinal('rejected'));
    assert_true(OtgStatus::isFinal('completed'));
    assert_false(OtgStatus::isFinal('pending'));
    assert_false(OtgStatus::isFinal('accepted'));
    foreach (['rejected', 'completed'] as $final) {
        foreach (OtgStatus::VALUES as $to) {
            assert_false(OtgStatus::canTransition($final, $to), "{$final} -> {$to} must be refused");
        }
    }
});

test('OtgStatus::badgeClass returns a class for every value and a safe default', function () {
    assert_same('badge--pending', OtgStatus::badgeClass('pending'));
    assert_same('badge--accepted', OtgStatus::badgeClass('accepted'));
    assert_same('badge--rejected', OtgStatus::badgeClass('rejected'));
    assert_same('badge--completed', OtgStatus::badgeClass('completed'));
    assert_same('badge--pending', OtgStatus::badgeClass('anything-else'));
});
