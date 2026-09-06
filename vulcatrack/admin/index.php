<?php
/**
 * Admin dashboard — landing page for the signed-in admin.
 *
 * Phase 5, Chunk 1: the minimal real admin shell + dashboard. It greets the
 * admin and links to the POS and Inventory modules. Reporting, OTG / rescue
 * management, Tireman management, customer management and analytics are all
 * out of scope for this chunk and are not shown here.
 */

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<h1>Admin Dashboard</h1>
<p>Signed in as <?= e($admin['name']) ?>.</p>

<div class="cardgrid">
  <section class="card">
    <p class="card__label">Point of Sale</p>
    <p>Record an in-shop sale and print a receipt.</p>
    <p><a href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">Open POS</a></p>
  </section>

  <section class="card">
    <p class="card__label">Inventory</p>
    <p>Manage products and services — stock, prices, and availability.</p>
    <p><a href="<?= e(vulcatrack_url('/admin/inventory.php')) ?>">Open Inventory</a></p>
  </section>
</div>

<p class="muted">POS and Inventory are being built in the current development phase.</p>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
