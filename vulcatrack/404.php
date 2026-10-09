<?php
/** Apache ErrorDocument target for unknown application paths. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

http_response_code(404);
$isAdmin = current_admin() !== null;
$isCustomer = current_customer() !== null;
$notFoundTitle = 'Page not found';
$notFoundMessage = 'The page or resource you requested could not be found.';
$notFoundBackUrl = vulcatrack_url($isAdmin ? '/admin/index.php' : ($isCustomer ? '/customer/dashboard.php' : '/'));
$notFoundBackLabel = $isAdmin || $isCustomer ? 'Back to dashboard' : 'Back to home';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page not found &mdash; VulcaTrack</title>
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(vulcatrack_asset('/assets/img/vulcatrack-favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
</head>
<body class="notfound-page">
<main class="notfound-main">
  <a class="notfound-brand" href="<?= e(vulcatrack_url('/')) ?>"><img src="<?= e(vulcatrack_asset('/assets/img/vulcatrack-logo.svg')) ?>" alt="VulcaTrack" width="5117" height="1638"></a>
  <?php require __DIR__ . '/src/Views/partials/not_found.php'; ?>
</main>
</body>
</html>
