<?php
/**
 * Customer profile: view / edit name + contact number, change password, and
 * upload / replace / remove the profile picture (Decision 77).
 * Email (the login identifier) is shown read-only in v1.
 *
 * Each form posts its own _action (profile | password | avatar | avatar_remove)
 * with its own CSRF token; errors stay with the form that caused them.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Support\AvatarException;
use VulcaTrack\Support\AvatarStore;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$customerId = (int) $customer['id'];
$config = $GLOBALS['vulcatrack_config'];
$minLength = (int) ($config['security']['password_min_length'] ?? 8);

$repo = new CustomerRepository(vulcatrack_db());
$record = $repo->findById($customerId);
if ($record === null) { // session points at a deleted row
    vulcatrack_auth()->logout();
    header('Location: ' . vulcatrack_url('/login.php'));
    exit;
}
$avatars = AvatarStore::forApp();

$errors = [];
$pwErrors = [];
$avatarError = null;
$old = ['full_name' => $record['full_name'], 'contact_number' => $record['contact_number']];
$flash = $_GET['updated'] ?? null;

/** A posted field as a string ('' when missing or not a string, e.g. a forged full_name[]). */
function pf_post(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = pf_post('_action');

    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Bigger than post_max_size: PHP dropped the whole body, CSRF token
        // included. Nothing is changed — say why instead of "session expired".
        $avatarError = AvatarStore::TOO_LARGE;
    } elseif (!Csrf::check(pf_post('_csrf'))) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'profile') {
        $v = new Validator();
        $fullName = $v->text('full_name', $_POST['full_name'] ?? null, 'Full name', 150);
        $contact  = $v->text('contact_number', $_POST['contact_number'] ?? null, 'Contact number', 30, 3);
        $old = ['full_name' => $fullName ?? pf_post('full_name'), 'contact_number' => $contact ?? pf_post('contact_number')];

        if ($v->passes()) {
            $repo->updateProfile($customerId, $fullName, $contact);
            // keep the session display name in step
            $_SESSION['auth']['name'] = $fullName;
            header('Location: ' . vulcatrack_url('/customer/profile.php?updated=profile'));
            exit;
        }
        $errors = $v->errors();
    } elseif ($action === 'password') {
        $current = pf_post('current_password');
        $new = pf_post('new_password');
        $confirm = pf_post('new_password_confirmation');

        $v = new Validator();
        if (!Password::verify($current, $record['password_hash'])) {
            $v->add('current_password', 'Current password is incorrect.');
        }
        $v->password('new_password', $new, $minLength);
        $v->matches('new_password_confirmation', $confirm, $new, 'Passwords');

        if ($v->passes()) {
            $repo->updatePasswordHash($customerId, Password::hash($new));
            header('Location: ' . vulcatrack_url('/customer/profile.php?updated=password'));
            exit;
        }
        $pwErrors = $v->errors();
    } elseif ($action === 'avatar') {
        $previous = $record['avatar_filename'] ?? null;
        try {
            // new file first, then the row; a failed row update removes the new file
            $avatars->store($_FILES['avatar'] ?? null, $customerId, static function (string $name) use ($repo, $customerId): void {
                $repo->updateAvatarFilename($customerId, $name);
            });
            // only now drop the old picture; if that fails it is a harmless orphan
            $avatars->delete($previous, $customerId);
            header('Location: ' . vulcatrack_url('/customer/profile.php?updated=photo'));
            exit;
        } catch (AvatarException $e) {
            $avatarError = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('VulcaTrack avatar save failed: ' . $e->getMessage());
            $avatarError = 'Your profile picture could not be saved. Please try again.';
        }
    } elseif ($action === 'avatar_remove') {
        $previous = $record['avatar_filename'] ?? null;
        if ($previous !== null) {
            $repo->updateAvatarFilename($customerId, null);
            $avatars->delete($previous, $customerId);
        }
        header('Location: ' . vulcatrack_url('/customer/profile.php?updated=photo_removed'));
        exit;
    }
}

$flashText = [
    'profile'       => 'Profile updated.',
    'password'      => 'Password changed.',
    'photo'         => 'Profile picture updated.',
    'photo_removed' => 'Profile picture removed.',
][$flash] ?? null;

/** aria wiring for a field that may carry an error message. */
function pf_invalid(array $errors, string $field): string
{
    return !empty($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . $field . '"' : '';
}

$accountName = (string) ($customer['name'] ?? '');
$accountSince = $record['created_at'] ?? null;
$accountAvatarUrl = $avatars->displayUrl($record['avatar_filename'] ?? null, $customerId);
$accountActive = 'info';

$pageTitle = 'Profile';
$navActive = 'profile';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
$postUrl = vulcatrack_url('/customer/profile.php');
?>
<div class="ac">
<div class="ac-head">
  <h1>Account Profile</h1>
  <p class="ac-sub">Manage your personal information and security settings.</p>
</div>

<div class="ac-layout">
  <?php require __DIR__ . '/../src/Views/partials/customer_account_nav.php'; /* also sets $accountInitials */ ?>

  <div class="ac-main pf">
    <?php if ($flashText !== null): ?><p class="cu-notice" role="status"><?= e($flashText) ?></p><?php endif; ?>
    <?php if (!empty($errors['form'])): ?><p class="cu-alert" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>

    <section class="cu-card pf-card" id="personal" aria-labelledby="pf-personal">
      <h2 class="pf-card__title" id="pf-personal"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="1"/><circle cx="9" cy="11" r="2"/><path d="M5.8 16c.6-1.5 1.8-2.3 3.2-2.3s2.6.8 3.2 2.3M14.5 10h3.5M14.5 13.5h3.5"/></svg>Personal Information</h2>

      <div class="pf-photo">
        <?php if ($accountAvatarUrl !== null): ?>
          <img class="pf-photo__img" src="<?= e($accountAvatarUrl) ?>" alt="" width="80" height="80">
        <?php else: ?>
          <span class="pf-photo__img" aria-hidden="true"><?= e($accountInitials !== '' ? $accountInitials : '?') ?></span>
        <?php endif; ?>
        <div class="pf-photo__body">
          <form class="pf-photo__form" method="post" action="<?= e($postUrl) ?>" enctype="multipart/form-data" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="avatar">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= AvatarStore::MAX_BYTES ?>">
            <label for="avatar">Profile picture</label>
            <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp"
                   aria-describedby="avatar-hint<?= $avatarError !== null ? ' err-avatar' : '' ?>"<?= $avatarError !== null ? ' aria-invalid="true"' : '' ?>>
            <p class="vf-hint" id="avatar-hint">JPEG, PNG or WebP, up to 5 MB.</p>
            <?php if ($avatarError !== null): ?><small class="error" id="err-avatar" role="alert"><?= e($avatarError) ?></small><?php endif; ?>
            <div class="pf-actions">
              <button type="submit" class="cu-btn cu-btn--outline"><?= $record['avatar_filename'] !== null ? 'Change Photo' : 'Upload Photo' ?></button>
            </div>
          </form>
          <?php if ($record['avatar_filename'] !== null): ?>
            <form class="pf-photo__remove" method="post" action="<?= e($postUrl) ?>"
                  onsubmit="return confirm('Remove your profile picture?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="_action" value="avatar_remove">
              <button type="submit" class="pf-link">Remove photo</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" action="<?= e($postUrl) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="_action" value="profile">

        <div class="vf-field">
          <label for="full_name">Full name <span class="vf-req" aria-hidden="true">*</span></label>
          <input type="text" id="full_name" name="full_name" maxlength="150" required autocomplete="name"
                 value="<?= e($old['full_name']) ?>"<?= pf_invalid($errors, 'full_name') ?>>
          <?php if (!empty($errors['full_name'])): ?><small class="error" id="err-full_name"><?= e($errors['full_name']) ?></small><?php endif; ?>
        </div>

        <div class="vf-field">
          <label for="email">Email address</label>
          <input type="email" id="email" value="<?= e($record['email']) ?>" disabled aria-describedby="email-hint">
          <p class="vf-hint" id="email-hint">Used to sign in. It can&rsquo;t be changed here.</p>
        </div>

        <div class="vf-field">
          <label for="contact_number">Contact number <span class="vf-req" aria-hidden="true">*</span></label>
          <input type="tel" id="contact_number" name="contact_number" maxlength="30" required autocomplete="tel"
                 value="<?= e($old['contact_number']) ?>"<?= pf_invalid($errors, 'contact_number') ?>>
          <?php if (!empty($errors['contact_number'])): ?><small class="error" id="err-contact_number"><?= e($errors['contact_number']) ?></small><?php endif; ?>
        </div>

        <div class="pf-actions">
          <button type="submit" class="cu-btn cu-btn--red">Save Changes</button>
        </div>
      </form>
    </section>

    <section class="cu-card pf-card" id="security" aria-labelledby="pf-security">
      <h2 class="pf-card__title" id="pf-security"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="1"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5v2.5"/></svg>Security &amp; Password</h2>
      <form method="post" action="<?= e($postUrl) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="_action" value="password">

        <div class="vf-field">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" required autocomplete="current-password"<?= pf_invalid($pwErrors, 'current_password') ?>>
          <?php if (!empty($pwErrors['current_password'])): ?><small class="error" id="err-current_password"><?= e($pwErrors['current_password']) ?></small><?php endif; ?>
        </div>

        <div class="vf-row">
          <div class="vf-field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" minlength="<?= (int) $minLength ?>" required autocomplete="new-password"
                   aria-describedby="new-password-hint<?= !empty($pwErrors['new_password']) ? ' err-new_password' : '' ?>"<?= !empty($pwErrors['new_password']) ? ' aria-invalid="true"' : '' ?>>
            <p class="vf-hint" id="new-password-hint">At least <?= (int) $minLength ?> characters.</p>
            <?php if (!empty($pwErrors['new_password'])): ?><small class="error" id="err-new_password"><?= e($pwErrors['new_password']) ?></small><?php endif; ?>
          </div>
          <div class="vf-field">
            <label for="new_password_confirmation">Confirm new password</label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" required autocomplete="new-password"<?= pf_invalid($pwErrors, 'new_password_confirmation') ?>>
            <?php if (!empty($pwErrors['new_password_confirmation'])): ?><small class="error" id="err-new_password_confirmation"><?= e($pwErrors['new_password_confirmation']) ?></small><?php endif; ?>
          </div>
        </div>

        <div class="pf-actions">
          <button type="submit" class="cu-btn cu-btn--outline">Update Password</button>
        </div>
      </form>
    </section>
  </div>
</div>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
