<?php
/**
 * VulcaTrack -- application bootstrap.
 *
 * Loads configuration, registers the class autoloader, sets timezone / error
 * handling, and starts a hardened PHP session. No feature logic belongs here.
 * Every entry point should `require` this first.
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

// --- Autoloader for the application's own classes (src/, namespace VulcaTrack) -
spl_autoload_register(static function (string $class): void {
    $prefix = 'VulcaTrack\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = VULCATRACK_ROOT . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Hardened session start (web requests only) -------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $s = $config['session'] ?? [];

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name($s['name'] ?? 'VULCATRACKSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $s['cookie_path'] ?? '/',
        'domain'   => '',
        'secure'   => (bool) ($s['cookie_secure'] ?? false), // true only when served over HTTPS
        'httponly' => true,
        'samesite' => $s['cookie_samesite'] ?? 'Lax',
    ]);

    session_start();
}

if (!function_exists('vulcatrack_url')) {
    /** Build an absolute application URL from a root-relative path. */
    function vulcatrack_url(string $path = '/'): string
    {
        $base = rtrim($GLOBALS['vulcatrack_config']['app']['base_url'] ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value for safe output in a view. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

return $config;
