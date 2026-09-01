<?php
/**
 * Customer registration (public). On success: redirect to /login.php — the
 * customer is NOT auto-logged-in.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Support\Validator;

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

if (current_customer() !== null) {
    header('Location: ' . vulcatrack_url('/customer/dashboard.php'));
    exit;
}

$config = $GLOBALS['vulcatrack_config'];
$minLength = (int) ($config['security']['password_min_length'] ?? 8);

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator();
        $fullName = $v->text('full_name', $_POST['full_name'] ?? null, 'Full name', 150);
        $email    = $v->email('email', $_POST['email'] ?? null, 190);
        $contact  = $v->text('contact_number', $_POST['contact_number'] ?? null, 'Contact number', 30, 3);
        $password = $v->password('password', $_POST['password'] ?? null, $minLength);
        $v->matches('password_confirmation', $_POST['password_confirmation'] ?? null, $_POST['password'] ?? null, 'Passwords');

        $old = [
            'full_name'      => $fullName ?? '',
            'email'          => $email ?? '',
            'contact_number' => $contact ?? '',
        ];

        $repo = new CustomerRepository(vulcatrack_db());

        if ($v->passes() && $repo->emailExists($email)) {
            $v->add('email', 'That email is already registered.');
        }

        if ($v->passes()) {
            try {
                $repo->create($fullName, $email, $contact, Password::hash($password));
                header('Location: ' . vulcatrack_url('/login.php?registered=1'));
                exit;
            } catch (PDOException $ex) {
                // Unique constraint on customers.email is the final source of truth.
                if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    $v->add('email', 'That email is already registered.');
                } else {
                    throw $ex;
                }
            }
        }

        $errors = array_merge($errors, $v->errors());
    }
}

$pageTitle = 'Register';
require __DIR__ . '/src/Views/customer_register.php';
