<?php
/**
 * Add a vehicle (no ?id) or edit an existing one (?id=N, must be owned).
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\Validator;
use VulcaTrack\Support\VehicleType;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$repo = new VehicleRepository(vulcatrack_db());

$vehicleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $vehicleId > 0;
$vehicle = null;

if ($editing) {
    $vehicle = $repo->findForCustomer($vehicleId, (int) $customer['id']);
    if ($vehicle === null) {
        http_response_code(404);
        $pageTitle = 'Vehicle not found';
        $navActive = 'profile';
        $mainClass = 'app--wide';
        require __DIR__ . '/../src/Views/partials/customer_top.php';
        echo '<div class="vf"><section class="cu-card vf-card"><h1>Vehicle not found</h1>'
            . '<p class="vf-sub">That vehicle is not on your account.</p>'
            . '<p class="vf-actions"><a class="cu-btn cu-btn--outline" href="' . e(vulcatrack_url('/customer/vehicles.php')) . '">Back to my vehicles</a></p>'
            . '</section></div>';
        require __DIR__ . '/../src/Views/partials/customer_bottom.php';
        exit;
    }
}

$errors = [];
$old = [
    'plate_number' => $vehicle['plate_number'] ?? '',
    'vehicle_type' => $vehicle['vehicle_type'] ?? '',
    'make'         => $vehicle['make'] ?? '',
    'model'        => $vehicle['model'] ?? '',
];
// A type saved before the dropdown existed stays selectable on this vehicle only.
$legacyType = VehicleType::legacyValue($vehicle['vehicle_type'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator();
        $plate = $v->text('plate_number', $_POST['plate_number'] ?? null, 'Plate number', 20);
        $type  = $v->optionalText('vehicle_type', $_POST['vehicle_type'] ?? null, 'Vehicle type', 40);
        $make  = $v->optionalText('make', $_POST['make'] ?? null, 'Brand', 60);
        $model = $v->optionalText('model', $_POST['model'] ?? null, 'Model', 60);
        if ($type !== null && !VehicleType::isAllowed($type, $vehicle['vehicle_type'] ?? null)) {
            $v->add('vehicle_type', 'Choose a vehicle type from the list.');
        }

        $old = [
            'plate_number' => $_POST['plate_number'] ?? '',
            'vehicle_type' => $_POST['vehicle_type'] ?? '',
            'make'         => $_POST['make'] ?? '',
            'model'        => $_POST['model'] ?? '',
        ];

        if ($v->passes()) {
            if ($editing) {
                $repo->update($vehicleId, (int) $customer['id'], $plate, $type, $make, $model);
            } else {
                $repo->create((int) $customer['id'], $plate, $type, $make, $model);
            }
            header('Location: ' . vulcatrack_url('/customer/vehicles.php'));
            exit;
        }
        $errors = $v->errors();
    }
}

/** aria wiring for a field that may carry an error message. */
function vf_invalid(array $errors, string $field): string
{
    return !empty($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . $field . '"' : '';
}

$typeOptions = VehicleType::CHOICES;
if ($legacyType !== null) {
    array_unshift($typeOptions, $legacyType);
}
$selectedType = is_string($old['vehicle_type']) ? trim($old['vehicle_type']) : '';

$pageTitle = $editing ? 'Edit vehicle' : 'Add a vehicle';
$navActive = 'profile';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="vf">
<section class="cu-card vf-card">
  <h1><?= $editing ? 'Edit Vehicle' : 'Add New Vehicle' ?></h1>
  <p class="vf-sub"><?= $editing
      ? 'Update this vehicle&rsquo;s details. Changes also show on past rescue requests that used it.'
      : 'Enter your vehicle&rsquo;s details. Saved vehicles can be chosen when you book a rescue.' ?></p>

  <?php if (!empty($errors['form'])): ?>
    <p class="cu-alert" role="alert"><?= e($errors['form']) ?></p>
  <?php elseif ($errors): ?>
    <p class="cu-alert" role="alert">Please check the highlighted fields.</p>
  <?php endif; ?>

  <form method="post" novalidate
        action="<?= e(vulcatrack_url('/customer/vehicle-edit.php' . ($editing ? '?id=' . $vehicleId : ''))) ?>">
    <?= Csrf::field() ?>

    <div class="vf-field">
      <label for="plate_number">Plate number <span class="vf-req" aria-hidden="true">*</span></label>
      <div class="vf-plate">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/></svg>
        <input type="text" id="plate_number" name="plate_number" maxlength="20" required placeholder="e.g. ABC 1234"
               value="<?= e($old['plate_number']) ?>"<?= vf_invalid($errors, 'plate_number') ?>>
      </div>
      <?php if (!empty($errors['plate_number'])): ?><small class="error" id="err-plate_number"><?= e($errors['plate_number']) ?></small><?php endif; ?>
    </div>

    <div class="vf-field">
      <label for="vehicle_type">Vehicle type <span class="vf-opt">(optional)</span></label>
      <select id="vehicle_type" name="vehicle_type"<?= vf_invalid($errors, 'vehicle_type') ?>>
        <option value="">Select vehicle type</option>
        <?php foreach ($typeOptions as $opt): ?>
          <option value="<?= e($opt) ?>"<?= $opt === $selectedType ? ' selected' : '' ?>><?= e($opt) ?><?= $opt === $legacyType ? ' (current)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['vehicle_type'])): ?><small class="error" id="err-vehicle_type"><?= e($errors['vehicle_type']) ?></small><?php endif; ?>
    </div>

    <div class="vf-row">
      <div class="vf-field">
        <label for="make">Brand <span class="vf-opt">(optional)</span></label>
        <input type="text" id="make" name="make" maxlength="60" placeholder="e.g. Honda"
               value="<?= e($old['make']) ?>"<?= vf_invalid($errors, 'make') ?>>
        <?php if (!empty($errors['make'])): ?><small class="error" id="err-make"><?= e($errors['make']) ?></small><?php endif; ?>
      </div>
      <div class="vf-field">
        <label for="model">Model <span class="vf-opt">(optional)</span></label>
        <input type="text" id="model" name="model" maxlength="60" placeholder="e.g. Click 125"
               value="<?= e($old['model']) ?>"<?= vf_invalid($errors, 'model') ?>>
        <?php if (!empty($errors['model'])): ?><small class="error" id="err-model"><?= e($errors['model']) ?></small><?php endif; ?>
      </div>
    </div>

    <p class="vf-hint"><span aria-hidden="true">*</span> Required</p>

    <div class="vf-actions">
      <a class="cu-btn cu-btn--outline" href="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>">Cancel</a>
      <button type="submit" class="cu-btn cu-btn--red"><svg class="vf-save" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5zM8 4v5h7V4M8 20v-6h8v6"/></svg><?= $editing ? 'Save Changes' : 'Save Vehicle' ?></button>
    </div>
  </form>
</section>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
