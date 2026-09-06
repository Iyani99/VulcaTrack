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

test('OtgStatus::badgeClass returns a class for every value and a safe default', function () {
    assert_same('badge--pending', OtgStatus::badgeClass('pending'));
    assert_same('badge--accepted', OtgStatus::badgeClass('accepted'));
    assert_same('badge--rejected', OtgStatus::badgeClass('rejected'));
    assert_same('badge--completed', OtgStatus::badgeClass('completed'));
    assert_same('badge--pending', OtgStatus::badgeClass('anything-else'));
});
