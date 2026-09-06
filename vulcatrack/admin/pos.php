<?php
/**
 * POS — navigation placeholder.
 *
 * This page exists only so the admin shell's "POS" link resolves. The POS
 * module (cart, SaleService, checkout, receipt) is implemented in a later
 * Phase 5 chunk. There is deliberately no business logic here — no cart, no
 * database access, no form handling.
 */

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

$pageTitle = 'POS';
$navActive = 'pos';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<h1>Point of Sale</h1>
<p class="muted">This module is not available yet — it is being built in the current development phase.</p>
<p><a href="<?= e(vulcatrack_url('/admin/index.php')) ?>">Back to the dashboard</a></p>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
