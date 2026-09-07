<?php
/**
 * Admin — Inventory browsing (Phase 5, Chunk 3A).
 *
 * A read-only view of the unified `items` table with server-rendered GET
 * filters: text search, item_type, active state, and a low-stock-only toggle.
 * All data access goes through ItemRepository; this page performs NO writes.
 * Add / edit / activate arrive in a later chunk.
 */

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo  = new ItemRepository(vulcatrack_db());

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
</div>
<p class="muted">Products and services in one list. Adding and editing items arrives in a later update.</p>

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

<p class="muted"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?><?= $hasFilters ? ' match these filters' : '' ?>.</p>

<?php if (!$items): ?>
  <p class="muted">No items to show.</p>
<?php else: ?>
  <table class="datatable">
    <thead>
      <tr>
        <th>Name</th><th>Type</th><th>Category</th><th class="num">Price</th>
        <th class="num">Stock</th><th class="num">Reorder</th><th>State</th>
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
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
