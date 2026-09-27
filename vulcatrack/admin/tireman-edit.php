<?php
/**
 * Admin — add a Tireman (no ?id) or edit an existing one (?id=N).
 *
 * Phase 6, Chunk 6.1. Only the two approved fields are editable: name and
 * contact number (Decision 23). Activate / deactivate live on tiremen.php.
 * All persistence goes through TiremanRepository; server-side validation is
 * authoritative.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\TiremanRepository;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo  = new TiremanRepository(vulcatrack_db());

// No ?id = add. A present ?id must be a positive whole number that fits
// tiremen.tireman_id (signed INT) and belongs to a real Tireman, else 404.
$editing = isset($_GET['id']);
$rawId   = $_GET['id'] ?? '';
$tiremanId = (is_string($rawId) && preg_match('/^[1-9]\d{0,9}$/', $rawId) && (int) $rawId <= 2147483647)
    ? (int) $rawId
    : 0;
$tireman = ($editing && $tiremanId > 0) ? $repo->findById($tiremanId) : null;

if ($editing && $tireman === null) {
    http_response_code(404);
    $pageTitle = 'Tireman not found';
    $navActive = 'tiremen';
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    echo '<h1>Tireman not found</h1><p class="muted">No Tireman has that id.</p>';
    echo '<p><a href="' . e(vulcatrack_url('/admin/tiremen.php')) . '">Back to Tiremen</a></p>';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

$errors = [];
$old = [
    'name'           => (string) ($tireman['name'] ?? ''),
    'contact_number' => (string) ($tireman['contact_number'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $old = [
            'name'           => (string) ($_POST['name'] ?? ''),
            'contact_number' => (string) ($_POST['contact_number'] ?? ''),
        ];

        $v = new Validator();
        $name    = $v->text('name', $_POST['name'] ?? null, 'Name', 150);
        $contact = $v->text('contact_number', $_POST['contact_number'] ?? null, 'Contact number', 30, 3);

        if ($v->passes()) {
            try {
                if ($editing) {
                    $repo->update($tiremanId, $name, $contact);
                    $_SESSION['tireman_flash'][] = ['notice', "{$name} updated."];
                    // An inactive Tireman is not on the default (active) list.
                    $back = $tireman['is_active'] === 1 ? '/admin/tiremen.php' : '/admin/tiremen.php?status=inactive';
                } else {
                    $repo->create($name, $contact);
                    $_SESSION['tireman_flash'][] = ['notice', "{$name} added."];
                    $back = '/admin/tiremen.php';
                }
                header('Location: ' . vulcatrack_url($back));
                exit;
            } catch (\PDOException $e) {
                $errors['form'] = 'A database error prevented saving. Please try again.';
            }
        } else {
            $errors = array_merge($errors, $v->errors());
        }
    }
}

$pageTitle = $editing ? 'Edit Tireman' : 'Add Tireman';
$navActive = 'tiremen';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1><?= $editing ? 'Edit Tireman' : 'Add Tireman' ?></h1>
</div>

<?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card">
  <form method="post" novalidate
        action="<?= e(vulcatrack_url('/admin/tireman-edit.php' . ($editing ? '?id=' . $tiremanId : ''))) ?>">
    <?= Csrf::field() ?>

    <label for="name">Name</label>
    <input type="text" id="name" name="name" maxlength="150" required
           value="<?= e($old['name']) ?>" autofocus>
    <?php if (!empty($errors['name'])): ?><small class="error"><?= e($errors['name']) ?></small><?php endif; ?>

    <label for="contact_number">Contact number</label>
    <input type="text" id="contact_number" name="contact_number" maxlength="30" required
           inputmode="tel" value="<?= e($old['contact_number']) ?>">
    <?php if (!empty($errors['contact_number'])): ?><small class="error"><?= e($errors['contact_number']) ?></small><?php endif; ?>
    <p class="muted">Shown to the customer once this Tireman is assigned to their request.</p>

    <button type="submit"><?= $editing ? 'Save changes' : 'Add Tireman' ?></button>
  </form>
</section>
<p><a href="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">Back to Tiremen</a></p>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
