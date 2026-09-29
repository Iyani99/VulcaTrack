<?php
/**
 * Customer registration form (Phase 7.4b-b: follows the Customer Sign-up Figma).
 * Expects: string $pageTitle, array<string,string> $errors, array<string,string> $old,
 *          int $minLength (password minimum, from config)
 *
 * Figma difference, on purpose: the Admin | Customer switch is replaced by a
 * plain "Customer account" bar — public registration creates customer accounts
 * only (admins are provisioned internally, Decisions 18 / 40 / 46).
 */
$backLink = ['Back to login', '/login.php'];
require __DIR__ . '/partials/customer_auth_top.php';

/** aria attributes + the message element for one field's validation error. */
$fieldError = function (string $field) use ($errors): array {
    if (empty($errors[$field])) {
        return ['', ''];
    }
    return [
        ' aria-invalid="true" aria-describedby="err-' . $field . '"',
        '<small class="error" id="err-' . $field . '">' . e($errors[$field]) . '</small>',
    ];
};
[$nameAttr, $nameErr]       = $fieldError('full_name');
[$contactAttr, $contactErr] = $fieldError('contact_number');
[$emailAttr, $emailErr]     = $fieldError('email');
[, $pwErr]                  = $fieldError('password');
[$confAttr, $confErr]       = $fieldError('password_confirmation');
?>
<h1 class="cauth-title" id="cauth-title">Welcome! Create your account.</h1>

<p class="cauth-switch">Customer account</p>

<?php if (!empty($errors['form'])): ?>
  <p class="cauth-alert" role="alert"><?= e($errors['form']) ?></p>
<?php elseif ($errors): ?>
  <p class="cauth-alert" role="alert">Please check the highlighted fields.</p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/register.php')) ?>" class="cauth-form" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="full_name">Full name</label>
  <input type="text" id="full_name" name="full_name" maxlength="150"
         value="<?= e($old['full_name'] ?? '') ?>" required autofocus autocomplete="name"<?= $nameAttr ?>>
  <?= $nameErr ?>

  <label for="contact_number">Contact number</label>
  <input type="tel" id="contact_number" name="contact_number" maxlength="30"
         value="<?= e($old['contact_number'] ?? '') ?>" required autocomplete="tel"<?= $contactAttr ?>>
  <?= $contactErr ?>

  <label for="email">Email address</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autocomplete="email"<?= $emailAttr ?>>
  <?= $emailErr ?>

  <label for="password">Password</label>
  <input type="password" id="password" name="password" minlength="<?= (int) $minLength ?>" required autocomplete="new-password"
         aria-describedby="pw-hint<?= $pwErr !== '' ? ' err-password' : '' ?>"<?= $pwErr !== '' ? ' aria-invalid="true"' : '' ?>>
  <small class="cauth-hint" id="pw-hint">At least <?= (int) $minLength ?> characters.</small>
  <?= $pwErr ?>

  <label for="password_confirmation">Confirm password</label>
  <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"<?= $confAttr ?>>
  <?= $confErr ?>

  <button type="submit" class="cauth-submit">Sign up</button>
</form>

<p class="cauth-alt">Already have an account? <a href="<?= e(vulcatrack_url('/login.php')) ?>">Sign in</a></p>

<?php require __DIR__ . '/partials/customer_auth_bottom.php'; ?>
