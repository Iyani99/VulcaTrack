<?php
/**
 * Customer registration form.
 * Expects: string $pageTitle, array<string,string> $errors, array<string,string> $old
 */
require __DIR__ . '/partials/top.php';
?>
<h1>Create your account</h1>

<?php if (!empty($errors['form'])): ?>
  <p class="error"><?= e($errors['form']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(vulcatrack_url('/register.php')) ?>" novalidate>
  <?= \VulcaTrack\Auth\Csrf::field() ?>

  <label for="full_name">Full name</label>
  <input type="text" id="full_name" name="full_name" maxlength="150"
         value="<?= e($old['full_name'] ?? '') ?>" required autofocus>
  <?php if (!empty($errors['full_name'])): ?><small class="error"><?= e($errors['full_name']) ?></small><?php endif; ?>

  <label for="email">Email</label>
  <input type="email" id="email" name="email" maxlength="190"
         value="<?= e($old['email'] ?? '') ?>" required autocomplete="email">
  <?php if (!empty($errors['email'])): ?><small class="error"><?= e($errors['email']) ?></small><?php endif; ?>

  <label for="contact_number">Contact number</label>
  <input type="text" id="contact_number" name="contact_number" maxlength="30"
         value="<?= e($old['contact_number'] ?? '') ?>" required>
  <?php if (!empty($errors['contact_number'])): ?><small class="error"><?= e($errors['contact_number']) ?></small><?php endif; ?>

  <label for="password">Password (min 8 characters)</label>
  <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
  <?php if (!empty($errors['password'])): ?><small class="error"><?= e($errors['password']) ?></small><?php endif; ?>

  <label for="password_confirmation">Confirm password</label>
  <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
  <?php if (!empty($errors['password_confirmation'])): ?><small class="error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>

  <button type="submit">Register</button>
</form>

<p class="muted"><a href="<?= e(vulcatrack_url('/login.php')) ?>">Already have an account? Log in</a></p>

<?php require __DIR__ . '/partials/bottom.php'; ?>
