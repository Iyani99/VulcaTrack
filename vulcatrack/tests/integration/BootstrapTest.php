<?php
/**
 * Integration tests -- the application bootstrap and autoloader (Phase 1).
 */

namespace VulcaTrack\Tests;

test('bootstrap defined the framework constants and loaded config', function () {
    assert_true(defined('VULCATRACK_BOOTSTRAPPED'));
    assert_true(defined('VULCATRACK_ROOT'));
    assert_true(is_array($GLOBALS['vulcatrack_config']));
    assert_true(isset($GLOBALS['vulcatrack_config']['db']['name']));
    assert_same('vulcatrack', $GLOBALS['vulcatrack_config']['db']['name']);
});

test('the session idle timeout is the locked 30 minutes (Decision 45)', function () {
    assert_same(1800, (int) $GLOBALS['vulcatrack_config']['session']['idle_timeout']);
});

test('the password minimum length is the locked 8 (Decision 44)', function () {
    assert_same(8, (int) $GLOBALS['vulcatrack_config']['security']['password_min_length']);
});

test('the class autoloader resolves every Phase 3/4 class', function () {
    $classes = [
        \VulcaTrack\Auth\Auth::class,
        \VulcaTrack\Auth\Csrf::class,
        \VulcaTrack\Auth\Password::class,
        \VulcaTrack\Repository\CustomerRepository::class,
        \VulcaTrack\Repository\AdminRepository::class,
        \VulcaTrack\Repository\VehicleRepository::class,
        \VulcaTrack\Repository\ServiceRequestRepository::class,
        \VulcaTrack\Support\Validator::class,
        \VulcaTrack\Support\Geo::class,
        \VulcaTrack\Support\OtgStatus::class,
    ];
    foreach ($classes as $class) {
        assert_true(class_exists($class), "{$class} should autoload");
    }
});

test('the e() helper HTML-escapes for output', function () {
    assert_same('&lt;b&gt;&amp;&quot;', e('<b>&"'));
    assert_same('', e(null));
});

test('vulcatrack_url() builds an app URL from the configured base', function () {
    $url = vulcatrack_url('/customer/dashboard.php');
    assert_contains('/customer/dashboard.php', $url);
    assert_same(0, strpos($url, 'http'), 'should be an absolute URL');
});

test('the shop config holds the real Baliwag location, not 0/0 or a placeholder', function () {
    $shop = require app_path('config/shop.php');
    assert_same('Gerald Tabayag Vulcanizing Shop', $shop['name']);
    assert_contains('Baliwag', $shop['address']);
    assert_true(abs($shop['latitude'] - 14.946654430279454) < 1e-9, 'latitude drifted from the real shop value');
    assert_true(abs($shop['longitude'] - 120.89290174619997) < 1e-9, 'longitude drifted from the real shop value');
});
