<?php
/**
 * Unit tests for VulcaTrack\Support\VehicleType -- the Add / Edit Vehicle
 * dropdown choices (Phase 7.4b-e1). The server accepts a standard choice or
 * the vehicle's own pre-dropdown (legacy) value, never an arbitrary new one.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\VehicleType;

test('VehicleType accepts every standard choice, exactly', function () {
    foreach (VehicleType::CHOICES as $choice) {
        assert_true(VehicleType::isAllowed($choice), "{$choice} should be accepted");
    }
    assert_false(VehicleType::isAllowed('sedan'), 'matching is exact (case-sensitive)');
    assert_false(VehicleType::isAllowed('Spaceship'), 'an arbitrary value is refused');
});

test('VehicleType keeps a vehicle\'s own legacy free-text type valid for that vehicle', function () {
    assert_same('Motorcycle', VehicleType::legacyValue('Motorcycle'));
    assert_null(VehicleType::legacyValue('SUV'), 'a standard choice is not legacy');
    assert_null(VehicleType::legacyValue(null));
    assert_null(VehicleType::legacyValue('  '));

    assert_true(VehicleType::isAllowed('Motorcycle', 'Motorcycle'), 'the stored legacy value may be re-saved');
    assert_true(VehicleType::isAllowed('Sedan', 'Motorcycle'), 'and may be replaced by a standard choice');
    assert_false(VehicleType::isAllowed('Motorcycle', null), 'a legacy value is not available to other vehicles');
    assert_false(VehicleType::isAllowed('Tractor', 'Motorcycle'), 'only the stored value itself is kept');
});

test('VehicleType picks the two-wheeler icon for motorcycle-like types only', function () {
    foreach (['Motorcycle / Scooter', 'Tricycle', 'Bicycle', 'motorbike'] as $t) {
        assert_true(VehicleType::isTwoWheeler($t), "{$t} should use the two-wheeler icon");
    }
    foreach (['Sedan', 'SUV', 'Van / MPV', 'Pickup Truck', null] as $t) {
        assert_false(VehicleType::isTwoWheeler($t), var_export($t, true) . ' should use the car icon');
    }
});
