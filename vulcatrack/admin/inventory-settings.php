<?php
/** Admin-only shop default for Inventory Intelligence. */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\InventorySettingsRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$settingsRepo = new InventorySettingsRepository(vulcatrack_db());
$error = null;
$input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = is_string($_POST['slow_moving_days'] ?? null) ? trim($_POST['slow_moving_days']) : '';
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['_action'] ?? '') !== 'update_slow_moving_days'
        || preg_match('/^[0-9]{1,3}$/D', $input) !== 1
        || (int) $input < 1 || (int) $input > 365) {
        $error = 'Enter a slow-moving threshold from 1 to 365 days.';
    } else {
        try {
            $changed = $settingsRepo->updateSlowMovingDays((int) $input, (int) $admin['id']);
            header('Location: ' . vulcatrack_url('/admin/inventory-settings.php?saved=' . ($changed ? 'saved' : 'unchanged')));
            exit;
        } catch (\PDOException | \RuntimeException $e) {
            $error = 'Inventory settings could not be saved. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

try {
    $settings = $settingsRepo->current();
} catch (\PDOException | \RuntimeException $e) {
    $settings = null;
    $error = $error ?? 'Inventory settings are unavailable until their required row is restored.';
}

$pageTitle = 'Inventory Settings';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div><h1>Inventory Settings</h1><p class="pagehead__meta">Shop-wide inventory defaults.</p></div>
</div>
<?php $inventoryActive = 'settings'; require __DIR__ . '/../src/Views/partials/inventory_nav.php'; ?>
<?php if (($_GET['saved'] ?? '') === 'saved'): ?><p class="notice" role="status">Default slow-moving threshold updated.</p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === 'unchanged'): ?><p class="notice" role="status">Default slow-moving threshold was already set to that value.</p><?php endif; ?>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

<section class="card inventory-settings-card" aria-labelledby="inventory-slow-heading">
  <h2 id="inventory-slow-heading">Slow-moving Products</h2>
  <?php if ($settings !== null): ?>
    <p class="muted">Products may optionally use their own threshold in Product Edit.</p>
    <form method="post" action="<?= e(vulcatrack_url('/admin/inventory-settings.php')) ?>" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="update_slow_moving_days">
      <label for="slow_moving_days">Default slow-moving threshold</label>
      <div class="inventory-settings-control">
        <input id="slow_moving_days" name="slow_moving_days" type="number" min="1" max="365" step="1" inputmode="numeric" required value="<?= e($error !== null && $input !== '' ? $input : (string) $settings['slow_moving_days']) ?>"<?= $error !== null ? ' aria-invalid="true"' : '' ?>>
        <span>days</span>
      </div>
      <p class="muted inventory-settings-range">Enter 1–365 days.</p>
      <button type="submit">Save default</button>
    </form>
    <?php if ($settings['updated_by_admin_name'] !== null): ?><p class="muted inventory-settings-updated">Last changed by <?= e($settings['updated_by_admin_name']) ?> · <?= e((new DateTimeImmutable($settings['updated_at']))->format('M j, Y g:i A')) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
