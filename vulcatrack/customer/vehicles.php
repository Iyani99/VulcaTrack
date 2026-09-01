<?php
/**
 * Customer saved vehicles: list, plus soft-delete (is_active = 0) / restore.
 * Add / edit is on vehicle-edit.php.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\VehicleRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$repo = new VehicleRepository(vulcatrack_db());

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $flash = ['error', 'Your session expired. Please try again.'];
    } elseif ($vehicleId > 0 && in_array($action, ['deactivate', 'reactivate'], true)) {
        $vehicle = $repo->findForCustomer($vehicleId, (int) $customer['id']);
        if ($vehicle === null) {
            $flash = ['error', 'Vehicle not found.'];
        } else {
            $repo->setActive($vehicleId, (int) $customer['id'], $action === 'reactivate');
            $flash = ['notice', $action === 'reactivate' ? 'Vehicle restored.' : 'Vehicle removed from your active list.'];
        }
    }
}

$active = $repo->listForCustomer((int) $customer['id'], false);
$all = $repo->listForCustomer((int) $customer['id'], true);
$inactive = array_values(array_filter($all, static fn ($v) => (int) $v['is_active'] === 0));

function vehicle_label(array $v): string
{
    $bits = array_filter([$v['make'] ?? '', $v['model'] ?? '', $v['vehicle_type'] ?? '']);
    return $bits ? implode(' ', $bits) : '—';
}

$pageTitle = 'My Vehicles';
$navActive = 'vehicles';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="pagehead">
  <h1>My Vehicles</h1>
  <a class="btnlink" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php')) ?>">Add a vehicle</a>
</div>

<?php if ($flash !== null): ?>
  <p class="<?= $flash[0] === 'error' ? 'error' : 'notice' ?>"><?= e($flash[1]) ?></p>
<?php endif; ?>

<?php if (!$active): ?>
  <p class="muted">You have no active vehicles. Add one before booking a rescue.</p>
<?php else: ?>
  <table class="datatable">
    <thead><tr><th>Plate</th><th>Details</th><th>Added</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($active as $v): ?>
      <tr>
        <td><?= e($v['plate_number']) ?></td>
        <td><?= e(vehicle_label($v)) ?></td>
        <td class="muted"><?= e($v['created_at']) ?></td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/customer/vehicle-edit.php?id=' . (int) $v['vehicle_id'])) ?>">Edit</a>
          <form method="post" action="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>"
                onsubmit="return confirm('Remove this vehicle from your active list?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="deactivate">
            <input type="hidden" name="vehicle_id" value="<?= (int) $v['vehicle_id'] ?>">
            <button type="submit" class="linklike">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($inactive): ?>
  <details class="removed">
    <summary>Removed vehicles (<?= count($inactive) ?>)</summary>
    <table class="datatable">
      <tbody>
      <?php foreach ($inactive as $v): ?>
        <tr>
          <td><?= e($v['plate_number']) ?></td>
          <td><?= e(vehicle_label($v)) ?></td>
          <td class="rowactions">
            <form method="post" action="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>">
              <?= Csrf::field() ?>
              <input type="hidden" name="_action" value="reactivate">
              <input type="hidden" name="vehicle_id" value="<?= (int) $v['vehicle_id'] ?>">
              <button type="submit" class="linklike">Restore</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Removed vehicles stay on any past rescue requests that used them.</p>
  </details>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
