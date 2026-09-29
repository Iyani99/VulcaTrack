<?php
/**
 * Customer saved vehicles: list, plus soft-delete (is_active = 0) / restore.
 * Add / edit is on vehicle-edit.php.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\AvatarStore;
use VulcaTrack\Support\VehicleType;

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

/**
 * Card heading + sub-line from the vehicle's own fields: make + model when
 * known (type underneath), else the type, else a neutral "Vehicle".
 *
 * @return array{0:string,1:?string}
 */
function vehicle_card_title(array $v): array
{
    $makeModel = implode(' ', array_filter([$v['make'] ?? '', $v['model'] ?? '']));
    $type = (string) ($v['vehicle_type'] ?? '');
    if ($makeModel !== '') {
        return [$makeModel, $type !== '' ? $type : null];
    }
    return [$type !== '' ? $type : 'Vehicle', null];
}

function vehicle_added(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y', $t);
}

$record = (new CustomerRepository(vulcatrack_db()))->findById((int) $customer['id']);
$accountName = (string) ($customer['name'] ?? '');
$accountSince = $record['created_at'] ?? null;
$accountAvatarUrl = AvatarStore::forApp()->displayUrl($record['avatar_filename'] ?? null, (int) $customer['id']);
$accountActive = 'vehicles';

$pageTitle = 'My Vehicles';
$navActive = 'profile';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
$postUrl = vulcatrack_url('/customer/vehicles.php');
?>
<div class="ac">
<div class="ac-head">
  <h1>Account Profile</h1>
  <p class="ac-sub">Manage your account details, password and saved vehicles.</p>
</div>

<div class="ac-layout">
  <?php require __DIR__ . '/../src/Views/partials/customer_account_nav.php'; ?>

  <div class="ac-main">
    <div class="cu-card vh-bar">
      <h2 class="vh-bar__title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/></svg>Your Vehicles</h2>
      <a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php')) ?>"><svg class="cu-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add New Vehicle</a>
    </div>

    <?php if ($flash !== null): ?>
      <p class="<?= $flash[0] === 'error' ? 'cu-alert' : 'cu-notice' ?>" role="<?= $flash[0] === 'error' ? 'alert' : 'status' ?>"><?= e($flash[1]) ?></p>
    <?php endif; ?>

    <section class="vh-section" aria-labelledby="vh-active">
      <h3 class="vh-section__title" id="vh-active">Active Vehicles <span class="vh-count">(<?= count($active) ?>)</span></h3>
      <?php if (!$active): ?>
        <div class="cu-card vh-empty">
          <p class="vh-empty__title">You have no active vehicles.</p>
          <p>Add a vehicle before booking a rescue.</p>
          <p><a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php')) ?>">Add a vehicle</a></p>
        </div>
      <?php else: ?>
        <ul class="vh-grid">
          <?php foreach ($active as $v): ?>
            <?php [$title, $sub] = vehicle_card_title($v); ?>
            <li class="vh-card">
              <div class="vh-card__body">
                <span class="vh-card__icon"><?= VehicleType::icon($v['vehicle_type'] ?? null) ?></span>
                <h4 class="vh-card__name"><?= e($title) ?></h4>
                <?php if ($sub !== null): ?><p class="vh-card__type"><?= e($sub) ?></p><?php endif; ?>
                <p class="vh-card__plate"><span class="sr-only">Plate number </span><?= e($v['plate_number']) ?></p>
                <p class="vh-card__added">Added <?= e(vehicle_added((string) $v['created_at'])) ?></p>
              </div>
              <div class="vh-card__actions">
                <a class="vh-action" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php?id=' . (int) $v['vehicle_id'])) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg>Edit<span class="sr-only"> <?= e($v['plate_number']) ?></span></a>
                <form method="post" action="<?= e($postUrl) ?>"
                      onsubmit="return confirm('Remove this vehicle from your active list?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="_action" value="deactivate">
                  <input type="hidden" name="vehicle_id" value="<?= (int) $v['vehicle_id'] ?>">
                  <button type="submit" class="vh-action vh-action--remove"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V4.5h4V7M7 7l1 13h8l1-13M10.5 10.5v6M13.5 10.5v6"/></svg>Remove<span class="sr-only"> <?= e($v['plate_number']) ?></span></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="vh-section vh-section--removed" aria-labelledby="vh-removed">
      <h3 class="vh-section__title" id="vh-removed"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3M4.5 4.5v3.5H8M12 8.5V12l2.5 2"/></svg>Removed Vehicles <span class="vh-count">(<?= count($inactive) ?>)</span></h3>
      <p class="vh-section__note">Removed vehicles are kept, not deleted: they stay on any past rescue requests that used them. Restore one to book a rescue with it again.</p>
      <?php if (!$inactive): ?>
        <p class="cu-empty">No removed vehicles.</p>
      <?php else: ?>
        <ul class="vh-grid">
          <?php foreach ($inactive as $v): ?>
            <?php [$title, $sub] = vehicle_card_title($v); ?>
            <li class="vh-card vh-card--removed">
              <div class="vh-card__body">
                <span class="vh-card__icon"><?= VehicleType::icon($v['vehicle_type'] ?? null) ?></span>
                <p class="vh-card__state">Removed</p>
                <h4 class="vh-card__name"><?= e($title) ?></h4>
                <?php if ($sub !== null): ?><p class="vh-card__type"><?= e($sub) ?></p><?php endif; ?>
                <p class="vh-card__plate"><span class="sr-only">Plate number </span><?= e($v['plate_number']) ?></p>
              </div>
              <div class="vh-card__actions">
                <form method="post" action="<?= e($postUrl) ?>">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="_action" value="reactivate">
                  <input type="hidden" name="vehicle_id" value="<?= (int) $v['vehicle_id'] ?>">
                  <button type="submit" class="vh-action"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3M4.5 4.5v3.5H8"/></svg>Restore<span class="sr-only"> <?= e($v['plate_number']) ?></span></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
