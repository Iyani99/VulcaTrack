<?php
/**
 * The signed-in customer's own profile picture (Decision 77). The only web
 * path to a stored avatar: storage/avatars/ itself is denied by .htaccess.
 *
 * Takes no id, file name or path from the request — the picture is always the
 * one on the signed-in customer's own row. Any query string (?v=…) is only a
 * cache-buster. Missing / malformed / unreadable -> 404 (pages show initials).
 */

use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Support\AvatarStore;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$customerId = (int) $customer['id'];
$record = (new CustomerRepository(vulcatrack_db()))->findById($customerId);
$filename = $record['avatar_filename'] ?? null;
$path = AvatarStore::forApp()->pathFor($filename, $customerId);

// done with the session: don't hold its lock while the file is sent
session_write_close();

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

header('Content-Type: ' . AvatarStore::mimeFor((string) $filename));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="avatar"');
header("Content-Security-Policy: default-src 'none'");
// each upload gets a new name, and the page's URL carries it as ?v=
// (drop the session's no-cache Pragma / Expires so they don't contradict it)
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: private, max-age=31536000, immutable');
readfile($path);
