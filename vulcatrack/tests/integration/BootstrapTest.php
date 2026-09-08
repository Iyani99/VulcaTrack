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

test('vulcatrack_url() builds a HOST-RELATIVE app URL so it works from any device', function () {
    // Must NOT bake in a scheme/host: a phone on the LAN reaches the PC by its
    // LAN IP, and "localhost" there is the phone itself -> "Safari cannot connect".
    $url = vulcatrack_url('/customer/dashboard.php');
    assert_same('/', substr($url, 0, 1), 'the URL is rooted at "/", not "http://host/..."');
    assert_not_contains('://', $url);
    assert_not_contains('localhost', $url);
    assert_not_contains('127.0.0.1', $url);
    assert_contains('/customer/dashboard.php', $url);

    // It uses only the PATH of the configured base_url, whatever the host is.
    $saved = $GLOBALS['vulcatrack_config']['app']['base_url'];
    try {
        $GLOBALS['vulcatrack_config']['app']['base_url'] = 'https://shop.example.com/vulcatrack';
        assert_same('/vulcatrack/login.php', vulcatrack_url('/login.php'));
        $GLOBALS['vulcatrack_config']['app']['base_url'] = 'http://192.168.1.50/vulcatrack/';
        assert_same('/vulcatrack/admin/index.php', vulcatrack_url('/admin/index.php'));
        $GLOBALS['vulcatrack_config']['app']['base_url'] = '';
        assert_same('/login.php', vulcatrack_url('/login.php'), 'app served from the web root still works');
        $GLOBALS['vulcatrack_config']['app']['base_url'] = '/vulcatrack';
        assert_same('/vulcatrack/x.php?id=3', vulcatrack_url('/x.php?id=3'), 'query strings pass through');
    } finally {
        $GLOBALS['vulcatrack_config']['app']['base_url'] = $saved;
    }
});

test('vulcatrack_asset() adds a mtime cache-buster for a real file, plain URL otherwise', function () {
    $asset = vulcatrack_asset('/assets/js/otg-map.js');
    assert_same(1, preg_match('~/assets/js/otg-map\.js\?v=\d+$~', $asset),
        'a real asset gets ?v=<mtime>');
    $mtime = filemtime(VULCATRACK_ROOT . '/assets/js/otg-map.js');
    assert_contains('?v=' . $mtime, $asset, 'the version is the file mtime');

    $missing = vulcatrack_asset('/assets/js/does-not-exist.js');
    assert_not_contains('?v=', $missing, 'a missing file falls back to the plain URL');
});

test('the shop config holds the real Baliwag location, not 0/0 or a placeholder', function () {
    $shop = require app_path('config/shop.php');
    assert_same('Gerald Tabayag Vulcanizing Shop', $shop['name']);
    assert_contains('Baliwag', $shop['address']);
    assert_true(abs($shop['latitude'] - 14.946654430279454) < 1e-9, 'latitude drifted from the real shop value');
    assert_true(abs($shop['longitude'] - 120.89290174619997) < 1e-9, 'longitude drifted from the real shop value');
});
