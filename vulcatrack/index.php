<?php
/**
 * VulcaTrack -- placeholder landing page.
 * Phase 3 adds an authentication-status strip. Feature pages come later.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

$customer = current_customer();
$admin = current_admin();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VulcaTrack</title>
<style>
  body{font-family:system-ui,"Segoe UI",Arial,sans-serif;margin:3rem auto;max-width:40rem;padding:0 1rem;line-height:1.55;color:#1a1a1a}
  h1{margin-bottom:.2rem}
  .muted{color:#666}
  a{color:#0a58ca}
  form{display:inline}
  button{font:inherit;padding:.15rem .5rem;cursor:pointer}
</style>
</head>
<body>
  <h1>VulcaTrack</h1>
  <p class="muted">Sales and Inventory with On-the-Go Services</p>
  <p>Customer accounts, vehicles and On-the-Go rescue requests are available. POS, inventory and admin rescue management are later phases.</p>

  <?php if ($customer !== null): ?>
    <p>Signed in as <strong><?= e($customer['name']) ?></strong> (customer) &mdash;
      <a href="customer/dashboard.php">dashboard</a>
      <form method="post" action="logout.php">
        <?= \VulcaTrack\Auth\Csrf::field() ?>
        <button type="submit">Log out</button>
      </form>
    </p>
  <?php elseif ($admin !== null): ?>
    <p>Signed in as <strong><?= e($admin['name']) ?></strong> (admin) &mdash;
      <a href="admin/index.php">admin area</a>
      <form method="post" action="admin/logout.php">
        <?= \VulcaTrack\Auth\Csrf::field() ?>
        <button type="submit">Log out</button>
      </form>
    </p>
  <?php else: ?>
    <p>
      <a href="login.php">Customer login</a> &middot;
      <a href="register.php">Register</a> &middot;
      <a href="admin/login.php">Admin login</a>
    </p>
  <?php endif; ?>

  <ul>
    <li>Environment &amp; database check: <a href="health.php">health.php</a></li>
  </ul>
  <p class="muted">Design decisions: <code>C:\IPT102\docs\decisions\project-decisions.md</code></p>
</body>
</html>
