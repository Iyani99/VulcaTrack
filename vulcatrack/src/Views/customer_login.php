<?php
/**
 * Customer login form (Phase 7.4b-b: follows the Customer Login Figma).
 * Expects: string $pageTitle, array<string,string> $errors, array<string,string> $old, ?string $notice
 *
 * Figma differences, on purpose: no Admin | Customer toggle — this form
 * authenticates customers only, and admins get a subtle text link to the
 * separate admin login (navigation only); the identifier is email only (no
 * phone login); there is no "Forgot password?" link (no reset flow exists).
 */
$backLink = ['Back to home page', '/'];
$customerLogin = true;
require __DIR__ . '/partials/customer_auth_top.php';
?>
<h1 class="cauth-title" id="cauth-title">Welcome! Please log in.</h1>

<?php if (!empty($notice)): ?>
  <p class="cauth-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if (!empty($errors['form'])): ?>
  <p class="cauth-alert" role="alert"><?= e($errors['form']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/login.php')) ?>" class="cauth-form" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="email">Email address</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autofocus autocomplete="email">

  <label for="password">Password</label>
  <div class="cauth-password">
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button class="cauth-password__toggle" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M2.5 12s3.5-5.5 9.5-5.5 9.5 5.5 9.5 5.5-3.5 5.5-9.5 5.5S2.5 12 2.5 12Z"/>
        <circle cx="12" cy="12" r="2.5"/>
        <path class="cauth-password__slash" d="M3.5 20.5 20.5 3.5"/>
      </svg>
    </button>
  </div>

  <button type="submit" class="cauth-submit">Log in</button>
</form>
<script defer src="<?= e(vulcatrack_asset('/assets/js/customer-password.js')) ?>"></script>

<p class="cauth-alt">Don't have an account? <a href="<?= e(vulcatrack_url('/register.php')) ?>">Sign up</a></p>
<p class="cauth-admin"><a href="<?= e(vulcatrack_url('/admin/login.php')) ?>">Admin? Sign in here</a></p>

<?php require __DIR__ . '/partials/customer_auth_bottom.php'; ?>
