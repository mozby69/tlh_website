<?php
/**
 * FILE PURPOSE: Shared public document head, brand navigation, and opening page layout.
 * DEBUGGING: Global public metadata, styles, and navigation changes should be made here.
 */
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? setting('site_name', 'The Leisure Hub');
$bodyClass = $bodyClass ?? '';
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$compactReservationNav = $compactReservationNav ?? false;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="<?= e(site_tagline() . '. ' . setting('hero_text')) ?>">
  <meta name="theme-color" content="#06172e">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Leisure Hub">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
  <title><?= e($pageTitle) ?> | <?= e(setting('site_name', 'The Leisure Hub')) ?></title>
  <script>
    (function () {
      if (window.matchMedia && window.matchMedia('(max-width: 760px)').matches) {
        document.documentElement.classList.add('tlh-standalone-app', 'tlh-mobile-browser-parity');
      }
    }());
  </script>
  <script src="assets/js/launch-v142.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/launch-v142.js') ?>" data-logo="assets/img/tlh-logo.png" data-tagline="<?= e(site_tagline()) ?>"></script>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/tokens.css') ?>">
  <link rel="stylesheet" href="assets/css/modern.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/modern.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<header class="site-header">
  <div class="container nav-wrap">
    <a class="brand" href="index.php" aria-label="The Leisure Hub home">
      <img class="brand-logo" src="assets/img/tlh-logo.png" width="1091" height="722" decoding="async" alt="<?= e(setting('site_name', 'The Leisure Hub')) ?> logo">
    </a>
    <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">☰</button>
    <nav class="site-nav<?= $compactReservationNav ? ' site-nav-reservation' : '' ?>">
      <?php if ($compactReservationNav): ?>
        <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">Home</a>
        <a class="<?= $currentPage === 'venue.php' ? 'active' : '' ?>" href="venue.php">Venue</a>
        <a class="<?= $currentPage === 'sports.php' ? 'active' : '' ?>" href="sports.php">Sports</a>
        <a class="<?= $currentPage === 'events.php' ? 'active' : '' ?>" href="events.php">Events</a>
        <a class="<?= $currentPage === 'gallery.php' ? 'active' : '' ?>" href="gallery.php">Gallery</a>
        <a class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>" href="contact.php">Contact</a>
        <a class="desktop-reservation-calendar-link <?= $currentPage === 'availability.php' ? 'active' : '' ?>" href="availability.php">Reservation Calendar</a>
        <a class="btn btn-primary nav-cta reservation-nav-cta" href="reserve.php">Reserve Now <span aria-hidden="true">→</span></a>
      <?php else: ?>
        <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">Home</a>
        <a class="<?= $currentPage === 'venue.php' ? 'active' : '' ?>" href="venue.php">Venue</a>
        <a class="<?= $currentPage === 'sports.php' ? 'active' : '' ?>" href="sports.php">Sports</a>
        <a class="<?= $currentPage === 'events.php' ? 'active' : '' ?>" href="events.php">Events</a>
        <a class="<?= $currentPage === 'stores.php' ? 'active' : '' ?>" href="stores.php">Stores</a>
        <a class="<?= $currentPage === 'leasing.php' ? 'active' : '' ?>" href="leasing.php">For Lease</a>
        <a class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>" href="contact.php">Contact</a>
        <a class="<?= $currentPage === 'availability.php' ? 'active' : '' ?>" href="availability.php">Reservation Calendar</a>
        <a class="btn btn-primary nav-cta" href="reserve.php">Reserve Now</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main>
<?php render_flashes(); ?>
