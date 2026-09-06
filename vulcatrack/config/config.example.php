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

    // Book-a-Rescue landmark / address search. Resolves a place name to
    // coordinates only; the OTG request still stores lat/lng + a frozen ETA.
    // If you use the public Nominatim service you MUST keep a real identifying
    // 'user_agent' (the OSM usage policy rejects stock/library defaults) and
    // must not lower 'min_interval_ms' below ~1000 (max 1 request/second).
    'geocoding' => [
        'driver'          => 'nominatim',   // 'nominatim' | 'none' (offline_places only)
        'endpoint'        => 'https://nominatim.openstreetmap.org/search',
        'user_agent'      => 'VulcaTrack/1.0 (student project; set a real contact here)',
        'referer'         => 'http://localhost/vulcatrack/',
        'email'           => '',
        'country_codes'   => 'ph',
        'viewbox'         => '120.55,15.20,121.15,14.60', // lon,lat,lon,lat -- soft bias
        'bounded'         => false,
        'timeout'         => 6,
        'max_results'     => 5,
        'cache_ttl'       => 86400,
        'min_interval_ms' => 1100,
        'attribution'     => 'Search results from OpenStreetMap / Nominatim',
        'offline_places'  => [
            // ['label' => 'Some Landmark, Baliwag, Bulacan', 'latitude' => 14.95, 'longitude' => 120.90],
        ],
    ],
];
