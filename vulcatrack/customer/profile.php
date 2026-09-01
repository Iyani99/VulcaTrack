<?php
/**
 * Customer profile: view / edit name + contact number, and change password.
 * Email (the login identifier) is shown read-only in v1.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$config = $GLOBALS['vulcatrack_config'];
$minLength = (int) ($config['security']['password_min_length'] ?? 8);

$repo = new CustomerRepository(vulcatrack_db());
$record = $repo->findById((int) $customer['id']);
if ($record === null) { // session points at a deleted row
    vulcatrack_auth()->logout();
    header('Location: ' . vulcatrack_url('/login.php'));
    exit;
}

$errors = [];
$pwErrors = [];
$old = ['full_name' => $record['full_name'], 'contact_number' => $record['contact_number']];
$flash = $_GET['updated'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'profile') {
        $v = new Validator();
        $fullName = $v->text('full_name', $_POST['full_name'] ?? null, 'Full name', 150);
        $contact  = $v->text('contact_number', $_POST['contact_number'] ?? null, 'Contact number', 30, 3);
        $old = ['full_name' => $fullName ?? ($_POST['full_name'] ?? ''), 'contact_number' => $contact ?? ($_POST['contact_number'] ?? '')];

        if ($v->passes()) {
            $repo->updateProfile((int) $customer['id'], $fullName, $contact);
            // keep the session display name in step
            $_SESSION['auth']['name'] = $fullName;
            header('Location: ' . vulcatrack_url('/customer/profile.php?updated=profile'));
            exit;
        }
        $errors = $v->errors();
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirmation'] ?? '');

        $v = new Validator();
        if (!Password::verify($current, $record['password_hash'])) {
            $v->add('current_password', 'Current password is incorrect.');
        }
        $v->password('new_password', $new, $minLength);
        $v->matches('new_password_confirmation', $confirm, $new, 'Passwords');

        if ($v->passes()) {
            $repo->updatePasswordHash((int) $customer['id'], Password::hash($new));
            header('Location: ' . vulcatrack_url('/customer/profile.php?updated=password'));
            exit;
        }
        $pwErrors = $v->errors();
    }
}

$pageTitle = 'Profile';
$navActive = 'profile';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<h1>Profile</h1>

<?php if ($flash === 'profile'): ?><p class="notice">Profile updated.</p><?php endif; ?>
<?php if ($flash === 'password'): ?><p class="notice">Password changed.</p><?php endif; ?>
<?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card">
  <h2>Account details</h2>
  <form method="post" action="<?= e(vulcatrack_url('/customer/profile.php')) ?>" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="profile">

    <label for="full_name">Full name</label>
    <input type="text" id="full_name" name="full_name" maxlength="150" required
           value="<?= e($old['full_name']) ?>">
    <?php if (!empty($errors['full_name'])): ?><small class="error"><?= e($errors['full_name']) ?></small><?php endif; ?>

    <label for="email">Email (used to sign in)</label>
    <input type="email" id="email" value="<?= e($record['email']) ?>" disabled>

    <label for="contact_number">Contact number <span class="req">(required)</span></label>
    <input type="text" id="contact_number" name="contact_number" maxlength="30" required
           value="<?= e($old['contact_number']) ?>">
    <?php if (!empty($errors['contact_number'])): ?><small class="error"><?= e($errors['contact_number']) ?></small><?php endif; ?>

    <button type="submit">Save changes</button>
  </form>
</section>

<section class="card">
  <h2>Change password</h2>
  <form method="post" action="<?= e(vulcatrack_url('/customer/profile.php')) ?>" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="password">

    <label for="current_password">Current password</label>
    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
    <?php if (!empty($pwErrors['current_password'])): ?><small class="error"><?= e($pwErrors['current_password']) ?></small><?php endif; ?>

    <label for="new_password">New password (min <?= (int) $minLength ?> characters)</label>
    <input type="password" id="new_password" name="new_password" minlength="<?= (int) $minLength ?>" required autocomplete="new-password">
    <?php if (!empty($pwErrors['new_password'])): ?><small class="error"><?= e($pwErrors['new_password']) ?></small><?php endif; ?>

    <label for="new_password_confirmation">Confirm new password</label>
    <input type="password" id="new_password_confirmation" name="new_password_confirmation" required autocomplete="new-password">
    <?php if (!empty($pwErrors['new_password_confirmation'])): ?><small class="error"><?= e($pwErrors['new_password_confirmation']) ?></small><?php endif; ?>

    <button type="submit">Change password</button>
  </form>
</section>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
