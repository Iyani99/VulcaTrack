<?php
/**
 * Admin — create an inventory item (no ?id) or edit an existing one (?id=N).
 *
 * Phase 5, Chunk 3B. All persistence goes through ItemRepository; money is
 * handled as integer centavos via Money / Validator::price(). Server-side
 * validation is authoritative — the small type-toggle script is only a
 * convenience.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo  = new ItemRepository(vulcatrack_db());

$itemId  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $itemId > 0;
$item    = null;

if ($editing) {
    $item = $repo->findById($itemId);
    if ($item === null) {
        http_response_code(404);
        $pageTitle = 'Item not found';
        $navActive = 'inventory';
        require __DIR__ . '/../src/Views/partials/admin_top.php';
        echo '<h1>Item not found</h1><p class="muted">No inventory item has that id.</p>';
        echo '<p><a href="' . e(vulcatrack_url('/admin/inventory.php')) . '">Back to inventory</a></p>';
        require __DIR__ . '/../src/Views/partials/admin_bottom.php';
        exit;
    }
}

$errors = [];
$old = [
    'item_name'      => (string) ($item['item_name'] ?? ''),
    'item_type'      => (string) ($item['item_type'] ?? 'product'),
    'category'       => (string) ($item['category'] ?? ''),
    'price'          => isset($item['price']) ? (string) $item['price'] : '',
    'stock_quantity' => isset($item['stock_quantity']) && $item['stock_quantity'] !== null ? (string) $item['stock_quantity'] : '',
    'reorder_level'  => isset($item['reorder_level']) && $item['reorder_level'] !== null ? (string) $item['reorder_level'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $old = [
            'item_name'      => (string) ($_POST['item_name'] ?? ''),
            'item_type'      => (string) ($_POST['item_type'] ?? ''),
            'category'       => (string) ($_POST['category'] ?? ''),
            'price'          => (string) ($_POST['price'] ?? ''),
            'stock_quantity' => (string) ($_POST['stock_quantity'] ?? ''),
            'reorder_level'  => (string) ($_POST['reorder_level'] ?? ''),
        ];

        $v = new Validator();
        $name          = $v->text('item_name', $_POST['item_name'] ?? null, 'Item name', 150);
        $type          = $v->itemType('item_type', $_POST['item_type'] ?? null);
        $category      = $v->optionalText('category', $_POST['category'] ?? null, 'Category', 60);
        $priceCentavos = $v->price('price', $_POST['price'] ?? null, 'Price');

        // Stock fields apply to products only. For a service they are neither
        // validated nor stored (the repository forces NULL / NULL regardless).
        $stock   = null;
        $reorder = null;
        if ($type === 'product') {
            $stock   = $v->stock('stock_quantity', $_POST['stock_quantity'] ?? null, 'Stock quantity');
            $reorder = $v->optionalNonNegativeInt('reorder_level', $_POST['reorder_level'] ?? null, 'Reorder level');
        }

        if ($v->passes()) {
            try {
                if ($editing) {
                    $repo->update($itemId, $name, $type, $category, $priceCentavos, $stock, $reorder);
                    $done = 'updated';
                } else {
                    $repo->create($name, $type, $category, $priceCentavos, $stock, $reorder);
                    $done = 'created';
                }
                header('Location: ' . vulcatrack_url('/admin/inventory.php?saved=' . $done));
                exit;
            } catch (\InvalidArgumentException $e) {
                $errors['form'] = 'That item could not be saved. Please review the values and try again.';
            } catch (\PDOException $e) {
                $errors['form'] = 'A database error prevented saving. Please try again.';
            }
        } else {
            $errors = array_merge($errors, $v->errors());
        }
    }
}

$categories = $repo->distinctCategories();

$pageTitle = $editing ? 'Edit item' : 'Add item';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1><?= $editing ? 'Edit item' : 'Add item' ?></h1>
</div>

<?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

<section class="card">
  <form method="post" novalidate
        action="<?= e(vulcatrack_url('/admin/item-edit.php' . ($editing ? '?id=' . $itemId : ''))) ?>">
    <?= Csrf::field() ?>

    <label for="item_name">Item name</label>
    <input type="text" id="item_name" name="item_name" maxlength="150" required
           value="<?= e($old['item_name']) ?>" autofocus>
    <?php if (!empty($errors['item_name'])): ?><small class="error"><?= e($errors['item_name']) ?></small><?php endif; ?>

    <label for="item_type">Item type</label>
    <select id="item_type" name="item_type">
      <option value="product"<?= $old['item_type'] === 'product' ? ' selected' : '' ?>>Product</option>
      <option value="service"<?= $old['item_type'] === 'service' ? ' selected' : '' ?>>Service</option>
    </select>
    <?php if (!empty($errors['item_type'])): ?><small class="error"><?= e($errors['item_type']) ?></small><?php endif; ?>
    <?php if ($editing && $item['item_type'] === 'product'): ?>
      <p class="muted">Switching this product to a Service permanently clears its stock quantity and reorder level.</p>
    <?php endif; ?>

    <label for="category">Category <span class="muted">(optional)</span></label>
    <input type="text" id="category" name="category" maxlength="60" list="category-options"
           value="<?= e($old['category']) ?>">
    <datalist id="category-options">
      <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
    </datalist>
    <?php if (!empty($errors['category'])): ?><small class="error"><?= e($errors['category']) ?></small><?php endif; ?>

    <label for="price">Price <span class="muted">(&#8369;, e.g. 99.95)</span></label>
    <input type="text" id="price" name="price" inputmode="decimal" required
           value="<?= e($old['price']) ?>">
    <?php if (!empty($errors['price'])): ?><small class="error"><?= e($errors['price']) ?></small><?php endif; ?>

    <div id="stock-fields"<?= $old['item_type'] === 'service' ? ' hidden' : '' ?>>
      <label for="stock_quantity">Stock quantity <span class="muted">(products only)</span></label>
      <input type="text" id="stock_quantity" name="stock_quantity" inputmode="numeric"
             value="<?= e($old['stock_quantity']) ?>">
      <?php if (!empty($errors['stock_quantity'])): ?><small class="error"><?= e($errors['stock_quantity']) ?></small><?php endif; ?>

      <label for="reorder_level">Reorder level <span class="muted">(optional, products only)</span></label>
      <input type="text" id="reorder_level" name="reorder_level" inputmode="numeric"
             value="<?= e($old['reorder_level']) ?>">
      <?php if (!empty($errors['reorder_level'])): ?><small class="error"><?= e($errors['reorder_level']) ?></small><?php endif; ?>
    </div>

    <button type="submit"><?= $editing ? 'Save changes' : 'Add item' ?></button>
  </form>
</section>
<p><a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Back to inventory</a></p>

<script>
(function () {
  var sel = document.getElementById('item_type');
  var box = document.getElementById('stock-fields');
  if (!sel || !box) { return; }
  function sync() { box.hidden = (sel.value === 'service'); }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
