<?php
/**
 * Customer logout. POST + CSRF only.
 */

use VulcaTrack\Auth\Csrf;

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!Csrf::check($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    exit('Bad Request');
}

vulcatrack_auth()->logout();
header('Location: ' . vulcatrack_url('/login.php'));
exit;
