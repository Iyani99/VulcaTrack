<?php
/**
 * Admin login. Separate route and separate session actor from the customer
 * side. There is NO admin registration — see database/seed_admin.php.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

if (current_admin() !== null) {
    header('Location: ' . vulcatrack_url('/admin/index.php'));
    exit;
}

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $old['email'] = $email;

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $errors['form'] = 'Enter your email and password.';
    } else {
        $repo = new AdminRepository(vulcatrack_db());
        $row = $repo->findByEmail($email);

        if ($row === null) {
            Password::fakeVerify($password);
            $errors['form'] = 'Invalid email or password.';
        } elseif (!Password::verify($password, $row['password_hash'])) {
            $errors['form'] = 'Invalid email or password.';
        } else {
            if (Password::needsRehash($row['password_hash'])) {
                $repo->updatePasswordHash((int) $row['admin_id'], Password::hash($password));
            }
            vulcatrack_auth()->login('admin', (int) $row['admin_id'], (string) $row['full_name']);
            header('Location: ' . vulcatrack_url('/admin/index.php'));
            exit;
        }
    }
}

$pageTitle = 'Admin sign-in';
require __DIR__ . '/../src/Views/admin_login.php';
