<?php
/**
 * Unit tests for VulcaTrack\Support\Geo -- the frozen-ETA maths for OTG
 * (Decisions 5/6/32/48). No database, no network.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\Geo;

test('Geo::isValidLatitude accepts the poles and rejects out-of-range / junk', function () {
    assert_true(Geo::isValidLatitude(0));
    assert_true(Geo::isValidLatitude(90));
    assert_true(Geo::isValidLatitude(-90));
    assert_true(Geo::isValidLatitude('14.946654'));
    assert_false(Geo::isValidLatitude(90.0001));
    assert_false(Geo::isValidLatitude(-91));
    assert_false(Geo::isValidLatitude('not-a-number'));
    assert_false(Geo::isValidLatitude(null));
});

test('Geo::isValidLongitude enforces the -180..180 range', function () {
    assert_true(Geo::isValidLongitude(120.8929));
    assert_true(Geo::isValidLongitude(180));
    assert_true(Geo::isValidLongitude(-180));
    assert_false(Geo::isValidLongitude(180.5));
    assert_false(Geo::isValidLongitude('abc'));
});

test('Geo::haversineKm is ~0 for identical points', function () {
    $d = Geo::haversineKm(14.946654, 120.892901, 14.946654, 120.892901);
    assert_true($d < 0.0001, 'distance between a point and itself should be ~0');
});

test('Geo::haversineKm matches a known short distance within tolerance', function () {
    // ~1 degree of latitude near the equator is ~111 km.
    $d = Geo::haversineKm(14.0, 120.0, 15.0, 120.0);
    assert_true($d > 110 && $d < 112, "expected ~111 km, got {$d}");
});

test('Geo::etaMinutes rounds up and honours the floor', function () {
    // 25 km at 25 km/h = 60 minutes exactly.
    assert_same(60, Geo::etaMinutes(25.0, 25.0, 5));
    // 1 km at 25 km/h = 2.4 min -> ceil -> 3, above the floor of 5? no -> floored to 5.
    assert_same(5, Geo::etaMinutes(1.0, 25.0, 5));
    // 10 km at 25 km/h = 24 min -> above floor.
    assert_same(24, Geo::etaMinutes(10.0, 25.0, 5));
    // fractional -> always rounds up.
    assert_same(25, Geo::etaMinutes(10.01, 25.0, 5));
});

test('Geo::etaMinutes degrades to the floor when speed is non-positive', function () {
    assert_same(5, Geo::etaMinutes(50.0, 0.0, 5));
    assert_same(7, Geo::etaMinutes(50.0, -10.0, 7));
});

test('Geo::etaMinutes with the real shop config values is a sane, stable number', function () {
    $config = $GLOBALS['vulcatrack_config'];
    $shop = require app_path('config/shop.php');
    // A customer ~3 km away.
    $dist = Geo::haversineKm(14.973, 120.905, (float) $shop['latitude'], (float) $shop['longitude']);
    $eta = Geo::etaMinutes(
        $dist,
        (float) $config['otg']['average_speed_kmph'],
        (int) $config['otg']['min_eta_minutes']
    );
    assert_true($eta >= 5 && $eta < 60, "ETA {$eta} should be a small positive number of minutes");
});
