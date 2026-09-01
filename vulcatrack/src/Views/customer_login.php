<?php
/**
 * Customer login form.
 * Expects: string $pageTitle, array<string,string> $errors, array<string,string> $old, ?string $notice
 */
require __DIR__ . '/partials/top.php';
?>
<h1>Log in</h1>

<?php if (!empty($notice)): ?>
  <p class="notice"><?= e($notice) ?></p>
<?php endif; ?>

<?php if (!empty($errors['form'])): ?>
  <p class="error"><?= e($errors['form']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/login.php')) ?>" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="email">Email</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autofocus autocomplete="email">

  <label for="password">Password</label>
  <input type="password" id="password" name="password" required autocomplete="current-password">

  <button type="submit">Log in</button>
</form>

<p class="muted"><a href="<?= e(vulcatrack_url('/register.php')) ?>">Need an account? Register</a></p>

<?php require __DIR__ . '/partials/bottom.php'; ?>
