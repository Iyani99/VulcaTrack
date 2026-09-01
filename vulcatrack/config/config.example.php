<?php
/**
 * VulcaTrack -- local configuration TEMPLATE.
 *
 * Copy this file to `config.php` in the same folder and adjust the values for
 * your machine. `config.php` is git-ignored so machine-specific credentials are
 * never committed.
 */

return [
    'app' => [
        'name'     => 'VulcaTrack',
        'env'      => 'development',
        'base_url' => 'http://localhost/vulcatrack',
        'timezone' => 'Asia/Manila', // adjust to the shop's timezone
        'debug'    => true,
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'vulcatrack',
        'user'    => 'root',
        'pass'    => '',           // XAMPP default: empty root password
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name'            => 'VULCATRACKSESSID',
        'cookie_path'     => '/vulcatrack/', // must match the app's URL path
        'cookie_secure'   => false,          // set true only when served over HTTPS
        'cookie_samesite' => 'Lax',
        'idle_timeout'    => 1800,           // seconds of inactivity before a login is invalidated (30 min)
    ],

    'security' => [
        'password_min_length' => 8,
    ],

    'otg' => [
        // On-the-Go ETA is a one-time snapshot: straight-line distance
        // (customer -> shop, from config/shop.php) / this speed, rounded up,
        // with a floor. Not a live/continuous calculation (Decisions 5/6/32).
        'average_speed_kmph' => 25,
        'min_eta_minutes'    => 5,
    ],
];
