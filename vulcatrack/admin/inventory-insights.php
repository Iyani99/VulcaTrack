<?php
/** Current shop stock and recorded Sales inactivity for Admin attention. */

use VulcaTrack\Repository\InventorySettingsRepository;
use VulcaTrack\Repository\ItemRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$pdo = vulcatrack_db();
$inventoryRepo = new ItemRepository($pdo);
$settingsRepo = new InventorySettingsRepository($pdo);
$asOf = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$lowStock = $inventoryRepo->lowStockProducts();
$slowMoving = [];
$settings = null;
$settingError = null;
try {
    $settings = $settingsRepo->current();
    $slowMoving = $inventoryRepo->slowMovingProducts($asOf->format('Y-m-d H:i:s'));
} catch (\PDOException | \RuntimeException $e) {
    $settingError = 'Inventory insights are unavailable until their settings are restored.';
}

$pageTitle = 'Inventory Insights';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Inventory Insights</h1>
    <p class="pagehead__meta">Current stock and recorded Sales inactivity as of <?= e($asOf->format('M j, Y g:i A')) ?> Asia/Manila.</p>
  </div>
</div>
<?php $inventoryActive = 'insights'; require __DIR__ . '/../src/Views/partials/inventory_nav.php'; ?>
<?php if ($settingError !== null): ?><p class="error" role="alert"><?= e($settingError) ?></p><?php endif; ?>

<div class="panelgrid inventory-insights__grid">
  <section class="panel" aria-labelledby="inventory-low-h">
    <header class="panel__head inventory-insights__panelhead"><h2 id="inventory-low-h">Low stock <span class="inventory-insights__count"><?= count($lowStock) ?></span></h2><a href="<?= e(vulcatrack_url('/admin/inventory.php?low_stock=1')) ?>">View low-stock items</a></header>
    <p class="panel__note muted">Active Products at or below their own reorder level, including zero stock.</p>
    <?php if (!$lowStock): ?>
      <p class="panel__note muted">No low-stock Products.</p>
    <?php else: ?>
      <div class="table-scroll"><table class="datatable mobile-records">
        <thead><tr><th>Product</th><th class="num">Shop stock</th><th class="num">Reorder level</th></tr></thead>
        <tbody><?php foreach ($lowStock as $product): ?>
          <tr><td data-label="Product" data-record-primary><a href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . (int) $product['item_id'])) ?>"><?= e($product['item_name']) ?></a></td><td class="num" data-label="Shop stock"><?= (int) $product['stock_quantity'] ?></td><td class="num" data-label="Reorder level"><?= (int) $product['reorder_level'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
</div>

<section class="panel inventory-insights__slow" aria-labelledby="inventory-slow-h">
  <header class="panel__head"><h2 id="inventory-slow-h">Slow-moving Products<?= $settings !== null ? ' · ' . count($slowMoving) : '' ?></h2></header>
  <?php if ($settings !== null): ?>
    <p class="panel__note muted">Shop default: <strong><?= (int) $settings['slow_moving_days'] ?> days</strong>. Products may use their own threshold in Product Edit; each row shows the effective value.</p>
    <p class="panel__note">Products shown have no recorded Sales within their effective threshold. This is a Sales-performance signal, not Product age or deterioration. Previous stockout periods are not measured.</p>
    <?php if (!$slowMoving): ?>
      <p class="panel__note muted">No slow-moving Products at their effective thresholds.</p>
    <?php else: ?>
      <div class="table-scroll"><table class="datatable mobile-records">
        <thead><tr><th>Product</th><th class="num">Shop stock</th><th>Threshold</th><th>Last recorded Sale</th><th>Latest recorded Restock</th></tr></thead>
        <tbody><?php foreach ($slowMoving as $product): ?>
          <tr>
            <td data-label="Product" data-record-primary><a href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . (int) $product['item_id'])) ?>"><?= e($product['item_name']) ?></a></td>
            <td class="num" data-label="Shop stock"><?= (int) $product['stock_quantity'] ?></td>
            <td data-label="Threshold"><strong><?= (int) $product['effective_days'] ?> days</strong><br><small class="muted"><?= $product['slow_moving_days_override'] === null ? 'Default' : 'Item override' ?></small></td>
            <td data-label="Last recorded Sale"><?= $product['last_sale_at'] === null ? 'Never sold' : e((new DateTimeImmutable($product['last_sale_at']))->format('M j, Y g:i A')) ?></td>
            <td data-label="Latest recorded Restock"><?= $product['last_restock_at'] === null ? 'No recorded restock since tracking began' : e((new DateTimeImmutable($product['last_restock_at']))->format('M j, Y g:i A')) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <p class="panel__note muted">Suggested action: review demand, display, promotion, or price for flagged Products.</p>
  <?php else: ?>
    <p class="panel__note muted">Slow-moving results are unavailable.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
