<?php
/** Admin Product receipt: stock and history commit together. */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Auth\RestockNonce;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\RestockRepository;
use VulcaTrack\Service\RestockService;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$pdo = vulcatrack_db();
$items = new ItemRepository($pdo);
$itemId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$item = $itemId > 0 ? $items->findById($itemId) : null;
if ($item === null || $item['item_type'] !== 'product' || (int) $item['is_active'] !== 1) {
    $archived = $item !== null && $item['item_type'] === 'product' && (int) $item['is_active'] !== 1;
    http_response_code($archived ? 409 : 404);
    $pageTitle = $archived ? 'Product archived' : 'Product not found';
    $navActive = 'inventory';
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    $notFoundTitle = $pageTitle;
    $notFoundMessage = $archived
        ? 'Reactivate this product before restocking it.'
        : 'Choose an active Product from Inventory to record a restock.';
    $notFoundBackUrl = vulcatrack_url($archived ? '/admin/inventory.php?status=inactive' : '/admin/inventory.php');
    $notFoundBackLabel = 'Back to inventory';
    require __DIR__ . '/../src/Views/partials/not_found.php';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

$errors = [];
$oldQuantity = '';
$oldSupplier = '';
$oldNote = '';
$submittedNonce = $_POST['_restock_nonce'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldQuantity = is_string($_POST['quantity'] ?? null) ? $_POST['quantity'] : '';
    $oldSupplier = is_string($_POST['supplier_name'] ?? null) ? $_POST['supplier_name'] : '';
    $oldNote = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!RestockNonce::valid($submittedNonce, $itemId)) {
        $errors['form'] = 'This restock form was already submitted or has expired. Review the current stock and history before recording another restock.';
        $oldQuantity = $oldSupplier = $oldNote = '';
    } else {
        $v = new Validator();
        $quantity = $v->quantity('quantity', $_POST['quantity'] ?? null, 'Quantity received');
        $supplier = $v->optionalText('supplier_name', $_POST['supplier_name'] ?? null, 'Supplier', 150);
        $note = $v->optionalText('note', $_POST['note'] ?? null, 'Note / Description', 500);
        if ($v->passes()) {
            if (!RestockNonce::consume($submittedNonce, $itemId)) {
                $errors['form'] = 'This restock form was already submitted or has expired. Review the current stock and history before recording another restock.';
                $oldQuantity = $oldSupplier = $oldNote = '';
            } else {
                try {
                    (new RestockService($pdo))->record($itemId, $quantity, $supplier, (int) $admin['id'], $note);
                    header('Location: ' . vulcatrack_url('/admin/restock.php?id=' . $itemId . '&saved=1'));
                    exit;
                } catch (\InvalidArgumentException $e) {
                    $errors['form'] = $e->getMessage();
                } catch (\PDOException | \RuntimeException $e) {
                    $errors['form'] = 'A database error prevented the restock. No stock was changed.';
                }
            }
        } else {
            $errors = array_merge($errors, $v->errors());
        }
    }
    $item = $items->findById($itemId);
}
$formNonce = RestockNonce::valid($submittedNonce, $itemId)
    ? $submittedNonce : RestockNonce::issue($itemId);

$history = (new RestockRepository($pdo))->forItem($itemId);
$pageTitle = 'Restock Product';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Restock Product</h1>
    <p class="pagehead__meta"><?= e($item['item_name']) ?> · Current shop stock: <?= (int) $item['stock_quantity'] ?></p>
  </div>
  <a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Back to inventory</a>
</div>

<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="notice">Restock recorded. Current stock and receipt history are updated.</p><?php endif; ?>
<?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card restock-card">
  <h2>Record received stock</h2>
  <p class="restock-current"><strong><?= e($item['item_name']) ?></strong><span>Current shop stock: <strong><?= (int) $item['stock_quantity'] ?></strong></span></p>
  <p class="muted">Only new receipts appear in the history below.</p>
  <form method="post" action="<?= e(vulcatrack_url('/admin/restock.php?id=' . $itemId)) ?>" data-restock-form novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="_restock_nonce" value="<?= e($formNonce) ?>">
    <label for="quantity">Quantity received</label>
    <input type="text" id="quantity" name="quantity" inputmode="numeric" required autofocus value="<?= e($oldQuantity) ?>">
    <?php if (!empty($errors['quantity'])): ?><small class="error"><?= e($errors['quantity']) ?></small><?php endif; ?>
    <label for="supplier_name">Supplier <span class="muted">(optional)</span></label>
    <input type="text" id="supplier_name" name="supplier_name" maxlength="150" value="<?= e($oldSupplier) ?>">
    <?php if (!empty($errors['supplier_name'])): ?><small class="error"><?= e($errors['supplier_name']) ?></small><?php endif; ?>
    <label for="note">Note / Description <span class="muted">(optional)</span></label>
    <textarea id="note" name="note" maxlength="500" rows="3"><?= e($oldNote) ?></textarea>
    <?php if (!empty($errors['note'])): ?><small class="error"><?= e($errors['note']) ?></small><?php endif; ?>
    <button type="submit" data-restock-submit aria-live="polite">Record restock</button>
  </form>
</section>

<section class="restock-history" aria-labelledby="restock-history-title">
  <h2 id="restock-history-title">Restock history</h2>
  <?php if ($history === []): ?>
    <p class="muted">No restocks recorded yet. Earlier stock has no recorded receipt history.</p>
  <?php else: ?>
    <div class="tablepanel"><div class="table-scroll">
      <table class="datatable mobile-records">
        <thead><tr><th>Date and time</th><th class="num">Received</th><th>Stock movement</th><th>Supplier</th><th>Recorded by</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($history as $receipt): ?>
          <tr>
            <td class="cell-strong" data-label="Date and time" data-record-primary><?= e(date('M j, Y g:i A', strtotime($receipt['restocked_at']))) ?></td>
            <td class="num" data-label="Received">+<?= (int) $receipt['quantity'] ?></td>
            <td class="restock-movement" data-label="Stock movement"><?= (int) $receipt['stock_before'] ?> &rarr; <?= (int) $receipt['stock_after'] ?></td>
            <td data-label="Supplier"><?= $receipt['supplier_name'] === null ? '<span class="muted">Not recorded</span>' : e($receipt['supplier_name']) ?></td>
            <td data-label="Recorded by"><?= e($receipt['admin_name']) ?></td>
            <td data-label="Note">
              <?php if ($receipt['note'] === null): ?>
                <span class="muted">Not recorded</span>
              <?php else: ?>
                <details class="restock-note"><summary>View note</summary><p><?= nl2br(e($receipt['note'])) ?></p></details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  <?php endif; ?>
</section>
<script>
(function () {
  var form = document.querySelector('[data-restock-form]');
  if (!form) return;
  var button = form.querySelector('[data-restock-submit]');
  var submitting = false;
  form.addEventListener('submit', function (event) {
    if (submitting) { event.preventDefault(); return; }
    submitting = true;
    button.disabled = true;
    button.textContent = 'Recording...';
  });
}());
</script>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
