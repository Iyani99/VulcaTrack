<?php
/**
 * Admin — Inventory (Phase 5, Chunks 3A + 3B).
 *
 * Browsing: server-rendered GET filters (search, item_type, active state,
 * low-stock toggle). Mutations here are limited to activate / deactivate,
 * which are POST + CSRF and redirect afterwards (PRG). Create / edit live on
 * item-edit.php. All persistence goes through ItemRepository.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo  = new ItemRepository(vulcatrack_db());

// --- activate / deactivate (POST only, CSRF, then redirect) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    $target = (int) ($_POST['item_id'] ?? 0);

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        header('Location: ' . vulcatrack_url('/admin/inventory.php?saved=error'));
        exit;
    }
    if ($target > 0 && in_array($action, ['activate', 'deactivate'], true) && $repo->findById($target) !== null) {
        $repo->setActive($target, $action === 'activate');
        header('Location: ' . vulcatrack_url('/admin/inventory.php?saved=' . $action . 'd'));
        exit;
    }
    header('Location: ' . vulcatrack_url('/admin/inventory.php?saved=error'));
    exit;
}

$flash = null;
switch ((string) ($_GET['saved'] ?? '')) {
    case 'created':      $flash = ['notice', 'Item created.']; break;
    case 'updated':      $flash = ['notice', 'Item updated.']; break;
    case 'activated':    $flash = ['notice', 'Item activated.']; break;
    case 'deactivated':  $flash = ['notice', 'Item deactivated.']; break;
    case 'error':        $flash = ['error', 'That action could not be completed. Please try again.']; break;
}

// --- read + normalise the GET filters --------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));

$typeParam = (string) ($_GET['type'] ?? '');
$type = in_array($typeParam, ['product', 'service'], true) ? $typeParam : '';

$statusParam = (string) ($_GET['status'] ?? 'active');
$status = in_array($statusParam, ['active', 'inactive', 'all'], true) ? $statusParam : 'active';

$lowStockOnly = (($_GET['low_stock'] ?? '') === '1');

$filters = [];
if ($search !== '')      { $filters['search'] = $search; }
if ($type !== '')        { $filters['type'] = $type; }
if ($status === 'active')   { $filters['active'] = true; }
if ($status === 'inactive') { $filters['active'] = false; }
if ($lowStockOnly)       { $filters['low_stock_only'] = true; }

$items = $repo->list($filters);

// Which rows carry a low-stock alert. lowStockProducts() already restricts to
// active products at/below their reorder level, so services and inactive rows
// are never flagged.
$lowStockIds = [];
foreach ($repo->lowStockProducts() as $p) {
    $lowStockIds[(int) $p['item_id']] = true;
}

/** A product's stock is shown as a number; a service has no stock at all. */
function inv_is_product(array $row): bool
{
    return $row['item_type'] === 'product';
}

$hasFilters = $search !== '' || $type !== '' || $status !== 'active' || $lowStockOnly;

$pageTitle = 'Inventory';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1>Inventory</h1>
  <a class="btnlink" href="<?= e(vulcatrack_url('/admin/item-edit.php')) ?>">Add item</a>
</div>
<p class="muted">Products and services in one list.</p>

<?php if ($flash !== null): ?>
  <p class="<?= $flash[0] === 'error' ? 'error' : 'notice' ?>"><?= e($flash[1]) ?></p>
<?php endif; ?>

<form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">
  <label>Search
    <input type="text" name="q" value="<?= e($search) ?>" maxlength="150" placeholder="name or category">
  </label>
  <label>Type
    <select name="type">
      <option value=""<?= $type === '' ? ' selected' : '' ?>>All</option>
      <option value="product"<?= $type === 'product' ? ' selected' : '' ?>>Product</option>
      <option value="service"<?= $type === 'service' ? ' selected' : '' ?>>Service</option>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
      <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
    </select>
  </label>
  <label class="filterbar__check">
    <input type="checkbox" name="low_stock" value="1"<?= $lowStockOnly ? ' checked' : '' ?>>
    Low stock only
  </label>
  <button type="submit">Filter</button>
  <?php if ($hasFilters): ?>
    <a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php $n = count($items); ?>
<p class="muted"><?= $n ?> <?= $n === 1 ? 'item' : 'items' ?><?= $hasFilters ? ($n === 1 ? ' matches your filters' : ' match your filters') : '' ?>.</p>

<?php if (!$items): ?>
  <p class="muted">No items to show.</p>
<?php else: ?>
  <table class="datatable">
    <thead>
      <tr>
        <th>Name</th><th>Type</th><th>Category</th><th class="num">Price</th>
        <th class="num">Stock</th><th class="num">Reorder</th><th>State</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <?php $isProduct = inv_is_product($row); $isLow = isset($lowStockIds[(int) $row['item_id']]); ?>
      <tr>
        <td><?= e($row['item_name']) ?></td>
        <td>
          <span class="badge badge--<?= $isProduct ? 'product' : 'service' ?>">
            <?= $isProduct ? 'Product' : 'Service' ?>
          </span>
        </td>
        <td><?= $row['category'] !== null && $row['category'] !== '' ? e($row['category']) : '<span class="muted">—</span>' ?></td>
        <td class="num">&#8369;<?= e(Money::format((int) $row['price_centavos'])) ?></td>
        <td class="num">
          <?php if ($isProduct): ?>
            <?= (int) $row['stock_quantity'] ?><?php if ($isLow): ?> <span class="badge badge--low">Low</span><?php endif; ?>
          <?php else: ?>
            <span class="muted">n/a</span>
          <?php endif; ?>
        </td>
        <td class="num">
          <?php if ($isProduct && $row['reorder_level'] !== null): ?>
            <?= (int) $row['reorder_level'] ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge--<?= (int) $row['is_active'] === 1 ? 'active' : 'inactive' ?>">
            <?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?>
          </span>
        </td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . (int) $row['item_id'])) ?>">Edit</a>
          <form method="post" action="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="item_id" value="<?= (int) $row['item_id'] ?>">
            <?php if ((int) $row['is_active'] === 1): ?>
              <input type="hidden" name="_action" value="deactivate">
              <button type="submit" class="linklike"
                      onclick="return confirm('Deactivate this item? It is hidden from the POS and active inventory but stays on past sales.');">Deactivate</button>
            <?php else: ?>
              <input type="hidden" name="_action" value="activate">
              <button type="submit" class="linklike">Activate</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
