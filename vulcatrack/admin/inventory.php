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

/**
 * The list filters, normalised from GET (or carried through an activate /
 * deactivate POST) — only non-default values are kept, so they can be put
 * straight back into a URL.
 */
function inv_filters(array $src): array
{
    $f = [];
    $q = mb_substr(trim((string) ($src['q'] ?? '')), 0, 150);
    if ($q !== '') { $f['q'] = $q; }
    $type = (string) ($src['type'] ?? '');
    if (in_array($type, ['product', 'all', 'service'], true)) { $f['type'] = $type; }
    $status = (string) ($src['status'] ?? '');
    if (in_array($status, ['inactive', 'all'], true)) { $f['status'] = $status; }
    if (($src['low_stock'] ?? '') === '1') { $f['low_stock'] = '1'; }
    return $f;
}

// --- activate / deactivate (POST only, CSRF, then redirect) ----------------
// The redirect returns to the same filtered list the admin was looking at.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    $target = (int) ($_POST['item_id'] ?? 0);
    $back   = inv_filters($_POST);

    $saved = 'error';
    if (Csrf::check($_POST['_csrf'] ?? null)
        && $target > 0 && in_array($action, ['activate', 'deactivate'], true)
        && $repo->findById($target) !== null) {
        $repo->setActive($target, $action === 'activate');
        $saved = $action . 'd';
    }
    header('Location: ' . vulcatrack_url('/admin/inventory.php?' . http_build_query($back + ['saved' => $saved])));
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

$typeParam = (string) ($_GET['type'] ?? 'product');
$type = in_array($typeParam, ['product', 'service', 'all'], true) ? $typeParam : 'product';

$statusParam = (string) ($_GET['status'] ?? 'active');
$status = in_array($statusParam, ['active', 'inactive', 'all'], true) ? $statusParam : 'active';

$lowStockOnly = (($_GET['low_stock'] ?? '') === '1');

$filters = [];
if ($search !== '')      { $filters['search'] = $search; }
if ($type !== 'all')     { $filters['type'] = $type; }
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

/**
 * The stock chip for a row: [modifier, label]. Presentation only — derived
 * from the stored stock + the existing low-stock rule ($isLow comes from
 * lowStockProducts()); no new stock state exists anywhere else.
 *   service                       -> n/a
 *   product, stock 0              -> Out: 0   (red when it is also a live low-stock alert)
 *   low by the existing rule      -> Low: N
 *   active, not low               -> OK: N    (above the reorder level, or none set)
 *   inactive, not zero            -> N        (not a live alert, so no status word)
 */
function inv_stock_chip(array $row, bool $isLow): array
{
    if (!inv_is_product($row)) {
        return ['na', 'n/a'];
    }
    $stock = (int) $row['stock_quantity'];
    if ($stock === 0) {
        return [$isLow ? 'low' : 'out', 'Out: 0'];
    }
    if ($isLow) {
        return ['low', 'Low: ' . $stock];
    }
    return (int) $row['is_active'] === 1 ? ['ok', 'OK: ' . $stock] : ['plain', (string) $stock];
}

$hasFilters = $search !== '' || $type !== 'product' || $status !== 'active' || $lowStockOnly;

/** Local inline icons follow the SVG stroke style already used in Admin navigation. */
function inv_icon(string $name): string
{
    $paths = [
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L9 17l-4 1 1-4Z"/>',
        'restock' => '<path d="M3 8.5 12 4l9 4.5v9L12 22l-9-4.5Z"/><path d="M3 8.5 12 13l9-4.5M12 13v9M16.5 3v5M14 5.5h5"/>',
        'deactivate' => '<path d="M12 3v9"/><path d="M6.2 6.2a8 8 0 1 0 11.6 0"/>',
        'activate' => '<circle cx="12" cy="12" r="9"/><path d="m7.5 12 3 3 6-6"/>',
        'more' => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
}

/** Render the same authorized actions as compact desktop icons or labelled mobile links. */
function inv_actions(array $row, bool $isProduct, array $back, bool $mobile): void
{
    $id = (int) $row['item_id'];
    $active = (int) $row['is_active'] === 1;
    $toggle = $active ? 'Deactivate' : 'Reactivate';
    $label = $toggle . ' item';
    ?>
    <a class="inventory-action<?= $mobile ? ' inventory-action--labelled' : '' ?>" href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . $id)) ?>" aria-label="Edit item" data-tip="Edit item"><?= inv_icon('edit') ?><?= $mobile ? '<span>Edit</span>' : '' ?></a>
    <?php if ($isProduct && $active): ?>
      <a class="inventory-action<?= $mobile ? ' inventory-action--labelled' : '' ?>" href="<?= e(vulcatrack_url('/admin/restock.php?id=' . $id)) ?>" aria-label="Restock product" data-tip="Restock product"><?= inv_icon('restock') ?><?= $mobile ? '<span>Restock</span>' : '' ?></a>
    <?php endif; ?>
    <form method="post" action="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">
      <?= Csrf::field() ?>
      <?php foreach ($back as $fName => $fValue): ?>
        <input type="hidden" name="<?= e($fName) ?>" value="<?= e($fValue) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="item_id" value="<?= $id ?>">
      <input type="hidden" name="_action" value="<?= $active ? 'deactivate' : 'activate' ?>">
      <button type="submit" class="inventory-action<?= $mobile ? ' inventory-action--labelled' : '' ?>" aria-label="<?= e($label) ?>" data-tip="<?= e($label) ?>"<?= $active ? ' onclick="return confirm(\'Deactivate this item? It is hidden from the POS and active inventory but stays on past sales.\');"' : '' ?>><?= inv_icon($active ? 'deactivate' : 'activate') ?><?= $mobile ? '<span>' . e($toggle) . '</span>' : '' ?></button>
    </form>
    <?php
}

$pageTitle = 'Inventory';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Inventory</h1>
    <p class="pagehead__meta">Products and services in one list.</p>
  </div>
  <a class="btnlink btnlink--new-sale" href="<?= e(vulcatrack_url('/admin/item-edit.php')) ?>">Add item</a>
</div>

<?php if ($flash !== null): ?>
  <p class="<?= $flash[0] === 'error' ? 'error' : 'notice' ?>"><?= e($flash[1]) ?></p>
<?php endif; ?>

<form class="filterbar inventory-filterbar" method="get" action="<?= e(vulcatrack_url('/admin/inventory.php')) ?>" data-inventory-filters>
  <label>Search
    <input type="text" name="q" value="<?= e($search) ?>" maxlength="150" placeholder="name or category">
  </label>
  <label>Type
    <select name="type">
      <option value="product"<?= $type === 'product' ? ' selected' : '' ?>>Product</option>
      <option value="service"<?= $type === 'service' ? ' selected' : '' ?>>Service</option>
      <option value="all"<?= $type === 'all' ? ' selected' : '' ?>>All</option>
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
  <button type="submit" class="filterbar__submit">Filter</button>
  <?php if ($hasFilters): ?>
    <a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php $n = count($items); ?>
<?php $countText = $n . ' ' . ($n === 1 ? 'item' : 'items') . ($hasFilters ? ($n === 1 ? ' matches your filters' : ' match your filters') : '') . '.'; ?>

<?php if (!$items): ?>
  <p class="muted"><?= e($countText) ?></p>
  <p class="muted">No items to show.</p>
<?php else: ?>
  <div class="tablepanel inventory-tablepanel">
  <div class="table-scroll">
  <table class="datatable mobile-records">
    <thead>
      <tr>
        <th>Name</th><th>Type</th><th>Category</th><th class="num">Price</th>
        <th>Stock</th><th class="num">Reorder</th><th>State</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <?php $isProduct = inv_is_product($row); $isLow = isset($lowStockIds[(int) $row['item_id']]); ?>
      <?php [$chip, $chipLabel] = inv_stock_chip($row, $isLow); ?>
      <tr>
        <td class="cell-strong" data-label="Item" data-record-primary><?= e($row['item_name']) ?></td>
        <td data-label="Type">
          <span class="badge badge--<?= $isProduct ? 'product' : 'service' ?>">
            <?= $isProduct ? 'Product' : 'Service' ?>
          </span>
        </td>
        <td data-label="Category"><?= $row['category'] !== null && $row['category'] !== '' ? e($row['category']) : '<span class="muted">—</span>' ?></td>
        <td class="num cell-money" data-label="Price">&#8369;<?= e(Money::formatDisplay((int) $row['price_centavos'])) ?></td>
        <td data-label="Stock"><span class="stock stock--<?= $chip ?>"><?php if ($chip === 'ok'): ?><span class="stock__text"><span class="stock__state">OK:</span> <span class="stock__amount"><?= (int) $row['stock_quantity'] ?></span></span><?php else: ?><?= e($chipLabel) ?><?php endif; ?></span></td>
        <td class="num" data-label="Reorder">
          <?php if ($isProduct && $row['reorder_level'] !== null): ?>
            <?= (int) $row['reorder_level'] ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td data-label="State" data-record-status>
          <span class="badge badge--<?= (int) $row['is_active'] === 1 ? 'active' : 'inactive' ?>">
            <?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?>
          </span>
        </td>
        <td class="rowactions inventory-rowactions" data-label="Actions" data-record-actions>
          <div class="inventory-actions-desktop"><?php inv_actions($row, $isProduct, inv_filters($_GET), false); ?></div>
          <details class="inventory-actions-mobile">
            <summary aria-label="Actions for <?= e($row['item_name']) ?>"><?= inv_icon('more') ?><span>Actions</span></summary>
            <div class="inventory-actions-menu"><?php inv_actions($row, $isProduct, inv_filters($_GET), true); ?></div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="tablepanel__foot"><?= e($countText) ?></p>
  </div>
<?php endif; ?>

<script src="<?= e(vulcatrack_url('/assets/js/inventory-filters.js')) ?>" defer></script>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
