<?php
/**
 * VulcaTrack -- local configuration TEMPLATE.
 *
 * Copy this file to `config.php` in the same folder and adjust the values for
 * your machine. `config.php` is git-ignored so machine-specific credentials are
 * never committed.
 *
 * Phase 1 scaffold -- contains no application logic.
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
];
