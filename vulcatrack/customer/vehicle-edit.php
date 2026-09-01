<?php
/**
 * Add a vehicle (no ?id) or edit an existing one (?id=N, must be owned).
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\Validator;

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
        $navActive = 'vehicles';
        require __DIR__ . '/../src/Views/partials/customer_top.php';
        echo '<h1>Vehicle not found</h1><p class="muted">That vehicle is not on your account.</p>';
        echo '<p><a href="' . e(vulcatrack_url('/customer/vehicles.php')) . '">Back to my vehicles</a></p>';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator();
        $plate = $v->text('plate_number', $_POST['plate_number'] ?? null, 'Plate number', 20);
        $type  = $v->optionalText('vehicle_type', $_POST['vehicle_type'] ?? null, 'Vehicle type', 40);
        $make  = $v->optionalText('make', $_POST['make'] ?? null, 'Make', 60);
        $model = $v->optionalText('model', $_POST['model'] ?? null, 'Model', 60);

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

$pageTitle = $editing ? 'Edit vehicle' : 'Add a vehicle';
$navActive = 'vehicles';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<h1><?= $editing ? 'Edit vehicle' : 'Add a vehicle' ?></h1>

<?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card">
  <form method="post" novalidate
        action="<?= e(vulcatrack_url('/customer/vehicle-edit.php' . ($editing ? '?id=' . $vehicleId : ''))) ?>">
    <?= Csrf::field() ?>

    <label for="plate_number">Plate number</label>
    <input type="text" id="plate_number" name="plate_number" maxlength="20" required
           value="<?= e($old['plate_number']) ?>">
    <?php if (!empty($errors['plate_number'])): ?><small class="error"><?= e($errors['plate_number']) ?></small><?php endif; ?>

    <label for="vehicle_type">Vehicle type <span class="muted">(optional, e.g. motorcycle, car)</span></label>
    <input type="text" id="vehicle_type" name="vehicle_type" maxlength="40" value="<?= e($old['vehicle_type']) ?>">
    <?php if (!empty($errors['vehicle_type'])): ?><small class="error"><?= e($errors['vehicle_type']) ?></small><?php endif; ?>

    <label for="make">Make <span class="muted">(optional)</span></label>
    <input type="text" id="make" name="make" maxlength="60" value="<?= e($old['make']) ?>">
    <?php if (!empty($errors['make'])): ?><small class="error"><?= e($errors['make']) ?></small><?php endif; ?>

    <label for="model">Model <span class="muted">(optional)</span></label>
    <input type="text" id="model" name="model" maxlength="60" value="<?= e($old['model']) ?>">
    <?php if (!empty($errors['model'])): ?><small class="error"><?= e($errors['model']) ?></small><?php endif; ?>

    <button type="submit"><?= $editing ? 'Save changes' : 'Add vehicle' ?></button>
  </form>
</section>
<p><a href="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>">Back to my vehicles</a></p>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
