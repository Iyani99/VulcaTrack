<?php
/**
 * Admin Accounts (Decision 79): the signed-in admin can see every admin
 * account and create another one. Create + list only — no edit, delete,
 * disable, password reset, roles or permission levels; every admin is
 * equivalent. There is still no public admin sign-up, and the CLI
 * database/seed_admin.php stays the bootstrap / recovery / development path.
 *
 * Creating an account needs POST + CSRF and the signed-in admin's OWN current
 * password, checked against the admin row of the trusted session (never an id
 * from the form) — an unattended signed-in browser alone is not enough. The
 * current admin stays signed in; the new admin is not signed in and uses the
 * normal admin login later. Success redirects back here (PRG). The UNIQUE
 * (case-insensitive) email key is the final duplicate guard.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin   = require_admin();
$adminId = (int) $admin['id'];
$repo    = new AdminRepository(vulcatrack_db());

$config    = $GLOBALS['vulcatrack_config'];
$minLength = (int) ($config['security']['password_min_length'] ?? 8);

/** Stored 'Y-m-d H:i:s' → "Sep 29, 2026 · 4:51 PM" (presentation only). */
function accounts_when(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y · g:i A', $t);
}

$errors = [];
$old    = ['full_name' => '', 'email' => ''];
$duplicate = 'An Admin account with this email already exists.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator(); // rejects non-string (array) values as missing / invalid
        $fullName = $v->text('full_name', $_POST['full_name'] ?? null, 'Full name', 150);
        $email    = $v->email('email', $_POST['email'] ?? null, 190);
        $password = $v->password('password', $_POST['password'] ?? null, $minLength);
        $v->matches('password_confirmation', $_POST['password_confirmation'] ?? null, $_POST['password'] ?? null, 'Passwords');

        // Re-authenticate the signed-in admin (the session's admin, never a form value).
        $current = $_POST['current_password'] ?? null;
        if (!is_string($current) || $current === '') {
            $v->add('current_password', 'Enter your current password.');
        } else {
            $me = $repo->findById($adminId);
            if ($me === null || !Password::verify($current, (string) $me['password_hash'])) {
                $v->add('current_password', 'Your current password is incorrect.');
            }
        }

        $email = $email !== null ? strtolower($email) : null; // stored trimmed + lowercased
        $old = ['full_name' => $fullName ?? '', 'email' => $email ?? ''];

        if ($v->passes() && $repo->findByEmail($email) !== null) {
            $v->add('email', $duplicate);
        }

        if ($v->passes()) {
            try {
                $repo->create($fullName, $email, Password::hash($password));
                header('Location: ' . vulcatrack_url('/admin/accounts.php?created=1'));
                exit;
            } catch (PDOException $ex) {
                // UNIQUE uq_admins_email (case-insensitive collation) is the final guard.
                if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    $v->add('email', $duplicate);
                } else {
                    throw $ex;
                }
            }
        }

        $errors = array_merge($errors, $v->errors());
    }
}

$admins  = $repo->listAll();
$created = ($_GET['created'] ?? null) === '1' && $_SERVER['REQUEST_METHOD'] !== 'POST';

/** Field error <small> with an id the input points at (aria-describedby). */
$fieldError = function (string $field) use ($errors): string {
    return !empty($errors[$field])
        ? '<small class="error" id="err-' . e($field) . '">' . e($errors[$field]) . '</small>'
        : '';
};
$describedBy = fn (string $field): string => !empty($errors[$field]) ? ' aria-describedby="err-' . e($field) . '" aria-invalid="true"' : '';

$pageTitle = 'Admin Accounts';
$navActive = 'accounts'; // not an operational nav section: only the footer link is marked current
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Admin Accounts</h1>
    <p class="pagehead__meta">Everyone listed here can sign in to the admin area. All admin accounts have the same access.</p>
  </div>
</div>

<?php if ($created): ?>
  <p class="notice" role="status">Admin account created successfully. The new administrator can now sign in on the admin login page.</p>
<?php endif; ?>
<?php if (!empty($errors['form'])): ?><p class="error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>

<div class="panelgrid acct-grid">
  <section class="panel" aria-labelledby="acct-list-h">
    <header class="panel__head"><h2 id="acct-list-h">Existing Administrators</h2></header>
    <div class="table-scroll">
    <table class="datatable">
      <thead>
        <tr><th scope="col">Full name</th><th scope="col">Email</th><th scope="col">Created</th></tr>
      </thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td class="cell-strong"><?= e($a['full_name']) ?><?php if ($a['admin_id'] === $adminId): ?> <span class="tag tag--customer">You</span><?php endif; ?></td>
          <td class="acct-email"><?= e($a['email']) ?></td>
          <td class="nowrap"><?= e(accounts_when($a['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="panel__foot muted"><?= count($admins) ?> admin <?= count($admins) === 1 ? 'account' : 'accounts' ?></p>
  </section>

  <section class="panel" aria-labelledby="acct-create-h">
    <header class="panel__head"><h2 id="acct-create-h">Create Admin Account</h2></header>
    <form class="acct-form" method="post" action="<?= e(vulcatrack_url('/admin/accounts.php')) ?>" novalidate>
      <?= Csrf::field() ?>

      <label for="full_name">Full name</label>
      <input type="text" id="full_name" name="full_name" maxlength="150" required autocomplete="off"
             value="<?= e($old['full_name']) ?>"<?= $describedBy('full_name') ?>>
      <?= $fieldError('full_name') ?>

      <label for="email">Email</label>
      <input type="email" id="email" name="email" maxlength="190" required autocomplete="off"
             value="<?= e($old['email']) ?>"<?= $describedBy('email') ?>>
      <?= $fieldError('email') ?>

      <label for="password">New password</label>
      <input type="password" id="password" name="password" required autocomplete="new-password"<?= $describedBy('password') ?>>
      <?= $fieldError('password') ?>
      <p class="muted acct-hint">At least <?= $minLength ?> characters.</p>

      <label for="password_confirmation">Confirm new password</label>
      <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"<?= $describedBy('password_confirmation') ?>>
      <?= $fieldError('password_confirmation') ?>

      <fieldset class="acct-confirm">
        <legend>Confirm it's you</legend>
        <label for="current_password">Your current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password"<?= $describedBy('current_password') ?>>
        <?= $fieldError('current_password') ?>
        <p class="muted acct-hint">The password you, <?= e((string) $admin['name']) ?>, signed in with.</p>
      </fieldset>

      <button type="submit">Create Admin Account</button>
    </form>
  </section>
</div>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
