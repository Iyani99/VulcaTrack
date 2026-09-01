<?php
/**
 * Back-compat entry point. The customer's home is now the dashboard.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

require_customer();
header('Location: ' . vulcatrack_url('/customer/dashboard.php'));
exit;
