<?php
/**
 * VulcaTrack -- application bootstrap.
 *
 * Loads configuration and sets timezone, error handling and the session.
 * No feature logic belongs here. Every entry point should `require` this first.
 *
 * @return array The loaded configuration array.
 */

if (defined('VULCATRACK_BOOTSTRAPPED')) {
    return $GLOBALS['vulcatrack_config'];
}
define('VULCATRACK_BOOTSTRAPPED', true);
define('VULCATRACK_ROOT', dirname(__DIR__));

$configFile = VULCATRACK_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('VulcaTrack: missing config/config.php -- copy config/config.example.php to config/config.php.');
}

$config = require $configFile;
$GLOBALS['vulcatrack_config'] = $config;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

$debug = !empty($config['app']['debug']);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

return $config;
