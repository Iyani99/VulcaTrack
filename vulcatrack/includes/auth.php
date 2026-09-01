<?php
/**
 * VulcaTrack -- authentication & authorization helpers.
 *
 * Include AFTER includes/bootstrap.php on any page that needs to know who is
 * signed in:
 *
 *   require __DIR__ . '/includes/bootstrap.php';
 *   require __DIR__ . '/includes/auth.php';
 *
 * Guards:
 *   require_customer()  -- customer pages; redirects to /login.php otherwise
 *   require_admin()     -- admin pages;    redirects to /admin/login.php otherwise
 *
 * A customer session never satisfies require_admin() and vice-versa: the two
 * guards check different actor types on the single per-session identity.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

use VulcaTrack\Auth\Auth;

if (!function_exists('vulcatrack_auth')) {

    function vulcatrack_auth(): Auth
    {
        static $auth = null;
        if ($auth === null) {
            $auth = new Auth($GLOBALS['vulcatrack_config']);
        }
        return $auth;
    }

    /** @return array<string,mixed>|null */
    function current_customer(): ?array
    {
        return vulcatrack_auth()->actor('customer');
    }

    /** @return array<string,mixed>|null */
    function current_admin(): ?array
    {
        return vulcatrack_auth()->actor('admin');
    }

    /** @return array<string,mixed> */
    function require_customer(): array
    {
        $customer = current_customer();
        if ($customer === null) {
            header('Location: ' . vulcatrack_url('/login.php'));
            exit;
        }
        return $customer;
    }

    /** @return array<string,mixed> */
    function require_admin(): array
    {
        $admin = current_admin();
        if ($admin === null) {
            header('Location: ' . vulcatrack_url('/admin/login.php'));
            exit;
        }
        return $admin;
    }
}
