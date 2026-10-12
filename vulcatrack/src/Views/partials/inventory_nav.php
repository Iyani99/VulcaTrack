<?php
/** Inventory workspace navigation. $inventoryActive: items|insights|settings. */
$inventoryDestinations = [
    'items' => ['Items', '/admin/inventory.php'],
    'insights' => ['Insights', '/admin/inventory-insights.php'],
    'settings' => ['Settings', '/admin/inventory-settings.php'],
];
?>
<nav class="tabs inventory-tabs" aria-label="Inventory">
  <?php foreach ($inventoryDestinations as $key => [$label, $path]): ?>
    <a class="tab<?= $inventoryActive === $key ? ' is-active' : '' ?>" href="<?= e(vulcatrack_url($path)) ?>"<?= $inventoryActive === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
