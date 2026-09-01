<?php
/**
 * Customer login (public).
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\CustomerRepository;

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

if (current_customer() !== null) {
    header('Location: ' . vulcatrack_url('/account.php'));
    exit;
}

$errors = [];
$old = [];
$notice = isset($_GET['registered']) ? 'Registration successful. Please log in.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $old['email'] = $email;

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $errors['form'] = 'Enter your email and password.';
    } else {
        $repo = new CustomerRepository(vulcatrack_db());
        $row = $repo->findByEmail($email);

        if ($row === null) {
            Password::fakeVerify($password);              // blunt timing-based enumeration
            $errors['form'] = 'Invalid email or password.';
        } elseif (!Password::verify($password, $row['password_hash'])) {
            $errors['form'] = 'Invalid email or password.';
        } else {
            if (Password::needsRehash($row['password_hash'])) {
                $repo->updatePasswordHash((int) $row['customer_id'], Password::hash($password));
            }
            vulcatrack_auth()->login('customer', (int) $row['customer_id'], (string) $row['full_name']);
            header('Location: ' . vulcatrack_url('/account.php'));
            exit;
        }
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/src/Views/customer_login.php';
