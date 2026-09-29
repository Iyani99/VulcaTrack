<?php
/**
 * Admin login form. No sign-up link — admins are provisioned internally
 * (database/seed_admin.php). Expects: string $pageTitle,
 * array<string,string> $errors, array<string,string> $old
 *
 * Uses the customer auth shell (brand, card, fields, red submit), so both
 * sign-in screens look like one product — but it stays its own route and
 * form: no Admin | Customer toggle, no role selector, no "Forgot password?"
 * (no reset flow exists). The shell's top-right action leads back to the
 * customer login, which in turn links here.
 */
$backLink = ['Back to Customer Login', '/login.php'];
require __DIR__ . '/partials/customer_auth_top.php';
?>
<h1 class="cauth-title cauth-title--tight" id="cauth-title">Administrator Access</h1>
<p class="cauth-sub">Authorized shop personnel only.</p>

<?php if (!empty($errors['form'])): ?>
  <p class="cauth-alert" role="alert"><?= e($errors['form']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/admin/login.php')) ?>" class="cauth-form" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="email">Email</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autofocus autocomplete="email">

  <label for="password">Password</label>
  <input type="password" id="password" name="password" required autocomplete="current-password">

  <button type="submit" class="cauth-submit">Log in</button>
</form>

<?php require __DIR__ . '/partials/customer_auth_bottom.php'; ?>
