<?php
/**
 * Admin login form. No registration link — admins are provisioned via CLI.
 * Expects: string $pageTitle, array<string,string> $errors, array<string,string> $old
 */
require __DIR__ . '/partials/top.php';
?>
<h1>Administrator sign-in</h1>

<?php if (!empty($errors['form'])): ?>
  <p class="error"><?= e($errors['form']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/admin/login.php')) ?>" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="email">Email</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autofocus autocomplete="email">

  <label for="password">Password</label>
  <input type="password" id="password" name="password" required autocomplete="current-password">

  <button type="submit">Log in</button>
</form>

<?php require __DIR__ . '/partials/bottom.php'; ?>
