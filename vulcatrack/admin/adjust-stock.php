<?php
/** Admin-only, auditable Product stock correction. */

use VulcaTrack\Auth\AdjustmentNonce;
use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\AdjustmentRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Service\AdjustmentService;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$pdo = vulcatrack_db();
$items = new ItemRepository($pdo);
$itemId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$item = $itemId > 0 ? $items->findById($itemId) : null;
if ($item === null || $item['item_type'] !== 'product') {
    http_response_code(404);
    $pageTitle = 'Product not found';
    $navActive = 'inventory';
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    $notFoundTitle = 'Product not found';
    $notFoundMessage = 'Choose a Product from Inventory to adjust its stock.';
    $notFoundBackUrl = vulcatrack_url('/admin/inventory.php');
    $notFoundBackLabel = 'Back to inventory';
    require __DIR__ . '/../src/Views/partials/not_found.php';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

$errors = [];
$old = ['direction' => 'decrease', 'quantity' => '', 'reason' => 'count_correction', 'note' => ''];
$submittedNonce = $_POST['_adjustment_nonce'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $field => $_) {
        $old[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!AdjustmentNonce::valid($submittedNonce, $itemId)) {
        $errors['form'] = 'This adjustment form was already submitted or has expired. Review current stock and history before trying again.';
        $old['quantity'] = $old['note'] = '';
    } else {
        $v = new Validator();
        $quantity = $v->quantity('quantity', $_POST['quantity'] ?? null, 'Quantity');
        $note = $v->optionalText('note', $_POST['note'] ?? null, 'Note', 500);
        if (!in_array($old['direction'], ['increase', 'decrease'], true)) {
            $v->add('direction', 'Choose Increase or Decrease.');
        }
        if (!isset(AdjustmentService::REASONS[$old['reason']])) {
            $v->add('reason', 'Choose an adjustment reason.');
        } elseif ($old['reason'] === 'other' && $note === null) {
            $v->add('note', 'Add a useful note when the reason is Other.');
        }
        if ($v->passes()) {
            if (!AdjustmentNonce::consume($submittedNonce, $itemId)) {
                $errors['form'] = 'This adjustment form was already submitted or has expired.';
            } else {
                try {
                    (new AdjustmentService($pdo))->record($itemId, $old['direction'], $quantity,
                        $old['reason'], $note, (int) $admin['id']);
                    header('Location: ' . vulcatrack_url('/admin/adjust-stock.php?id=' . $itemId . '&saved=1'));
                    exit;
                } catch (\InvalidArgumentException $e) {
                    $errors['form'] = $e->getMessage();
                } catch (\PDOException | \RuntimeException $e) {
                    $errors['form'] = 'The adjustment could not be saved. No stock was changed.';
                }
            }
        } else {
            $errors = array_merge($errors, $v->errors());
        }
    }
    $item = $items->findById($itemId);
}
$formNonce = AdjustmentNonce::valid($submittedNonce, $itemId)
    ? $submittedNonce : AdjustmentNonce::issue($itemId);
$history = (new AdjustmentRepository($pdo))->forItem($itemId);
$pageTitle = 'Adjust Stock';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div><h1>Adjust Stock</h1><p class="pagehead__meta"><?= e($item['item_name']) ?> · Current shop stock: <?= (int) $item['stock_quantity'] ?><?= (int) $item['is_active'] === 0 ? ' · Archived' : '' ?></p></div>
  <a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Back to inventory</a>
</div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="notice" role="status">Stock adjustment recorded. Current stock and adjustment history are updated.</p><?php endif; ?>
<?php if (!empty($errors['form'])): ?><p class="error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card restock-card">
  <h2>Record stock correction</h2>
  <p class="restock-current"><strong><?= e($item['item_name']) ?></strong><span>Current shop stock: <strong><?= (int) $item['stock_quantity'] ?></strong></span></p>
  <p class="muted">Use Restock for newly received stock. Increase is for count corrections, including discovered excess. Adjusting an archived Product does not reactivate it.</p>
  <form method="post" action="<?= e(vulcatrack_url('/admin/adjust-stock.php?id=' . $itemId)) ?>" data-adjust-form novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="_adjustment_nonce" value="<?= e($formNonce) ?>">
    <label for="direction">Adjustment type</label>
    <select id="direction" name="direction" required>
      <option value="decrease"<?= $old['direction'] === 'decrease' ? ' selected' : '' ?>>Decrease</option>
      <option value="increase"<?= $old['direction'] === 'increase' ? ' selected' : '' ?>>Increase</option>
    </select>
    <?php if (!empty($errors['direction'])): ?><small class="error"><?= e($errors['direction']) ?></small><?php endif; ?>
    <label for="quantity">Quantity</label>
    <input type="text" id="quantity" name="quantity" inputmode="numeric" required autofocus value="<?= e($old['quantity']) ?>">
    <?php if (!empty($errors['quantity'])): ?><small class="error"><?= e($errors['quantity']) ?></small><?php endif; ?>
    <p class="muted" data-adjust-preview aria-live="polite">Resulting stock is calculated when you enter a quantity.</p>
    <label for="reason">Reason</label>
    <select id="reason" name="reason" required>
      <?php foreach (AdjustmentService::REASONS as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $old['reason'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!empty($errors['reason'])): ?><small class="error"><?= e($errors['reason']) ?></small><?php endif; ?>
    <label for="note">Note <span class="muted">(required for Other)</span></label>
    <textarea id="note" name="note" maxlength="500" rows="3"><?= e($old['note']) ?></textarea>
    <?php if (!empty($errors['note'])): ?><small class="error"><?= e($errors['note']) ?></small><?php endif; ?>
    <button type="submit" data-adjust-submit>Record adjustment</button>
  </form>
</section>

<section class="restock-history" aria-labelledby="adjust-history-title">
  <h2 id="adjust-history-title">Adjustment history</h2>
  <?php if ($history === []): ?>
    <p class="muted">No adjustments recorded yet. Existing stock is the opening state.</p>
  <?php else: ?>
    <div class="tablepanel"><div class="table-scroll"><table class="datatable mobile-records">
      <thead><tr><th>Date and time</th><th>Change</th><th>Stock movement</th><th>Reason</th><th>Recorded by</th><th>Note</th></tr></thead>
      <tbody><?php foreach ($history as $entry): ?>
        <tr>
          <td class="cell-strong" data-label="Date and time" data-record-primary><?= e(date('M j, Y g:i A', strtotime($entry['adjusted_at']))) ?></td>
          <td data-label="Change"><?= $entry['direction'] === 'increase' ? '+' : '−' ?><?= (int) $entry['quantity'] ?></td>
          <td class="restock-movement" data-label="Stock movement"><?= (int) $entry['stock_before'] ?> &rarr; <?= (int) $entry['stock_after'] ?></td>
          <td data-label="Reason"><?= e(AdjustmentService::REASONS[$entry['reason']] ?? $entry['reason']) ?></td>
          <td data-label="Recorded by"><?= e($entry['admin_name']) ?></td>
          <td data-label="Note"><?= $entry['note'] === null ? '<span class="muted">Not recorded</span>' : '<details class="restock-note"><summary>View note</summary><p>' . nl2br(e($entry['note'])) . '</p></details>' ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
  <?php endif; ?>
</section>
<script>
(function () {
  var form = document.querySelector('[data-adjust-form]');
  if (!form) return;
  var stock = <?= (int) $item['stock_quantity'] ?>;
  var preview = form.querySelector('[data-adjust-preview]');
  var button = form.querySelector('[data-adjust-submit]');
  var submitting = false;
  function updatePreview() {
    var raw = form.elements.quantity.value.trim();
    if (!/^[0-9]+$/.test(raw)) { preview.textContent = 'Enter a positive whole number to preview the resulting stock.'; return; }
    var qty = Number(raw);
    var after = stock + (form.elements.direction.value === 'increase' ? qty : -qty);
    preview.textContent = qty > 0 && after >= 0 && after <= 2147483647
      ? 'Resulting shop stock: ' + after
      : 'This quantity cannot be applied to the current stock.';
  }
  form.elements.quantity.addEventListener('input', updatePreview);
  form.elements.direction.addEventListener('change', updatePreview);
  updatePreview();
  form.addEventListener('submit', function (event) {
    if (submitting) { event.preventDefault(); return; }
    submitting = true;
    button.disabled = true;
    button.textContent = 'Recording...';
  });
}());
</script>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
