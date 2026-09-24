<?php
/**
 * FILE PURPOSE: Builds the shared authenticated admin shell, sidebar, top bar, notifications, and page heading.
 * DEBUGGING: Admin access/session failures usually originate in admin_required() inside includes/functions.php; navigation issues usually start here or in app.js.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
$adminPageTitle = $adminPageTitle ?? 'Dashboard';
$currentAdminPage = basename($_SERVER['PHP_SELF']);
$adminBodyPageClass = 'admin-page-' . preg_replace('/[^a-z0-9]+/i', '-', pathinfo($currentAdminPage, PATHINFO_FILENAME));
$admin = current_admin();
$adminIsCalendarViewer = is_calendar_viewer();
$adminNotificationUnread = $adminIsCalendarViewer ? 0 : admin_notification_unread_count();
$adminNotificationRecent = $adminIsCalendarViewer ? [] : admin_recent_notifications(8);
$adminName = trim((string)($admin['name'] ?? 'Administrator'));
$adminInitial = strtoupper(substr($adminName, 0, 1));
if ($adminInitial === '') {
    $adminInitial = 'A';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06172e">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Leisure Hub">
  <link rel="manifest" href="../manifest.webmanifest">
  <link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
  <title><?= e($adminPageTitle) ?> | The Leisure Hub Admin</title>
  <script>
    (function () {
      if (window.matchMedia && window.matchMedia('(max-width: 760px)').matches) {
        document.documentElement.classList.add('tlh-standalone-app', 'tlh-mobile-browser-parity');
      }
    }());
  </script>
  <script src="../assets/js/launch-v142.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/launch-v142.js') ?>" data-logo="../assets/img/tlh-logo.png" data-tagline="<?= e(site_tagline()) ?>"></script>
  <script>
    (function () {
      document.documentElement.classList.add('admin-menu-js');
      try {
        if (window.matchMedia('(min-width: 901px)').matches && localStorage.getItem('tlhAdminMenuCollapsed') === '1') {
          document.documentElement.classList.add('admin-menu-precollapsed');
        }
      } catch (error) {
        // Keep the default expanded menu when browser storage is unavailable.
      }
    }());
  </script>
  <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <link rel="stylesheet" href="../assets/css/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/tokens.css') ?>">
  <link rel="stylesheet" href="../assets/css/modern-admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/modern-admin.css') ?>">
</head>
<body class="admin-body <?= e($adminBodyPageClass) ?><?= $adminIsCalendarViewer ? ' admin-calendar-viewer' : '' ?>" data-admin-page-title="<?= e($adminPageTitle) ?>">
<div class="admin-shell<?= !empty($adminCalendarOnly) ? ' admin-shell-calendar-only' : '' ?>" data-admin-shell>
  <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar aria-label="Admin menu">
    <div class="admin-sidebar-head">
      <a class="brand admin-brand" href="<?= $adminIsCalendarViewer ? 'booking-calendar.php' : 'index.php' ?>" title="<?= $adminIsCalendarViewer ? 'Booking Calendar' : 'Admin Dashboard' ?>">
        <img class="brand-logo brand-logo-admin" src="../assets/img/tlh-logo.png" width="1091" height="722" decoding="async" alt="The Leisure Hub logo">
        <span class="admin-brand-copy"><strong>Admin Portal</strong><small>The Leisure Hub</small></span>
      </a>
      <button class="admin-sidebar-close" type="button" data-admin-menu-close aria-label="Close menu">&times;</button>
    </div>

    <nav class="admin-nav" aria-label="Admin navigation">
      <?php if ($adminIsCalendarViewer): ?>
        <div class="admin-nav-group" aria-labelledby="admin-nav-calendar-viewer">
          <span class="admin-nav-group-title" id="admin-nav-calendar-viewer">Calendar Access</span>
          <a class="<?= $currentAdminPage === 'booking-calendar.php' ? 'active' : '' ?>" href="booking-calendar.php" title="Booking Calendar"><span class="admin-nav-icon" aria-hidden="true">▦</span><span class="admin-nav-label">Booking Calendar</span></a>
          <a class="<?= in_array($currentAdminPage, ['rental-calendar.php','rental-view-readonly.php'], true) ? 'active' : '' ?>" href="rental-calendar.php" title="Rental Calendar"><span class="admin-nav-icon" aria-hidden="true">▦</span><span class="admin-nav-label">Rental Calendar</span></a>
        </div>
        <div class="admin-nav-group" aria-labelledby="admin-nav-calendar-viewer-links">
          <span class="admin-nav-group-title" id="admin-nav-calendar-viewer-links">Website</span>
          <a href="../index.php" target="_blank" rel="noopener" title="View Website"><span class="admin-nav-icon" aria-hidden="true">↗</span><span class="admin-nav-label">View Website</span></a>
        </div>
      <?php else: ?>
      <div class="admin-nav-group" aria-labelledby="admin-nav-main">
        <span class="admin-nav-group-title" id="admin-nav-main">Main</span>
        <a class="<?= $currentAdminPage === 'index.php' ? 'active' : '' ?>" href="index.php" title="Dashboard"><span class="admin-nav-icon" aria-hidden="true">▦</span><span class="admin-nav-label">Dashboard</span></a>
        <a class="<?= $currentAdminPage === 'booking-calendar.php' ? 'active' : '' ?>" href="booking-calendar.php" title="Booking Calendar"><span class="admin-nav-icon" aria-hidden="true">▦</span><span class="admin-nav-label">Booking Calendar</span></a>
        <?php if (is_admin()): ?>
          <a class="<?= $currentAdminPage === 'rental-calendar.php' ? 'active' : '' ?>" href="rental-calendar.php" title="Rental Calendar"><span class="admin-nav-icon" aria-hidden="true">▦</span><span class="admin-nav-label">Rental Calendar</span></a>
        <?php endif; ?>
        <a class="admin-nav-primary <?= in_array($currentAdminPage, ['reservation-create.php','batch-reservation-create.php'], true) ? 'active' : '' ?>" href="reservation-create.php" title="Reserve"><span class="admin-nav-icon" aria-hidden="true">＋</span><span class="admin-nav-label">Reserve</span></a>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-reservations">
        <span class="admin-nav-group-title" id="admin-nav-reservations">Reservations</span>
        <a class="<?= in_array($currentAdminPage, ['reservations.php','reservation-view.php','reservation-edit.php','reservation-reschedule.php','reservation-extend.php','reservation-cancel.php','reservation-delete.php','reservation-print.php'], true) && empty($bookingIsArchived) && empty($bookingIsCancelled) && empty($bookingNeedsResolution) ? 'active' : '' ?>" href="reservations.php" title="Reservations"><span class="admin-nav-icon" aria-hidden="true">◷</span><span class="admin-nav-label">Reservations</span></a>
        <a class="<?= in_array($currentAdminPage, ['batch-reservations.php','batch-reservation-view.php','batch-reservation-print.php'], true) ? 'active' : '' ?>" href="batch-reservations.php" title="Batch Reservations"><span class="admin-nav-icon" aria-hidden="true">≋</span><span class="admin-nav-label">Batch Reservations</span></a>
        <?php if (is_admin()): ?>
          <a class="<?= in_array($currentAdminPage, ['rentals.php','rental-create.php','rental-view.php','rental-cancel.php','rental-delete.php','rental-extend.php','rental-print.php'], true) ? 'active' : '' ?>" href="rentals.php" title="Rentals"><span class="admin-nav-icon" aria-hidden="true">▤</span><span class="admin-nav-label">Rentals</span></a>
        <?php endif; ?>
        <a class="<?= $currentAdminPage === 'needs-resolution.php' || !empty($bookingNeedsResolution) ? 'active' : '' ?>" href="needs-resolution.php" title="Needs Resolution"><span class="admin-nav-icon" aria-hidden="true">!</span><span class="admin-nav-label">Needs Resolution</span></a>
        <a class="<?= $currentAdminPage === 'cancelled.php' || !empty($bookingIsCancelled) ? 'active' : '' ?>" href="cancelled.php" title="Cancelled"><span class="admin-nav-icon" aria-hidden="true">⊘</span><span class="admin-nav-label">Cancelled</span></a>
        <a class="<?= in_array($currentAdminPage, ['archives.php'], true) || (!empty($bookingIsArchived) && empty($bookingIsCancelled)) ? 'active' : '' ?>" href="archives.php" title="Archives"><span class="admin-nav-icon" aria-hidden="true">▧</span><span class="admin-nav-label">Archives</span></a>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-finance">
        <span class="admin-nav-group-title" id="admin-nav-finance">Payments &amp; Finance</span>
        <a class="<?= $currentAdminPage === 'payments.php' ? 'active' : '' ?>" href="payments.php" title="Payment & Collections"><span class="admin-nav-icon" aria-hidden="true">₱</span><span class="admin-nav-label">Payment &amp; Collections</span></a>
        <a class="<?= $currentAdminPage === 'reports.php' ? 'active' : '' ?>" href="reports.php" title="Reports Center"><span class="admin-nav-icon" aria-hidden="true">&#9638;</span><span class="admin-nav-label">Reports Center</span></a>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-communications">
        <span class="admin-nav-group-title" id="admin-nav-communications">Communications</span>
        <a class="<?= $currentAdminPage === 'notifications.php' ? 'active' : '' ?>" href="notifications.php" title="Notifications"><span class="admin-nav-icon" aria-hidden="true">♢</span><span class="admin-nav-label">Notifications<?= $adminNotificationUnread > 0 ? ' (' . $adminNotificationUnread . ')' : '' ?></span></a>
        <a class="<?= $currentAdminPage === 'inquiries.php' ? 'active' : '' ?>" href="inquiries.php" title="Inquiries"><span class="admin-nav-icon" aria-hidden="true">✉</span><span class="admin-nav-label">Inquiries</span></a>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-venue">
        <span class="admin-nav-group-title" id="admin-nav-venue">Venue Management</span>
        <a class="<?= $currentAdminPage === 'tenants.php' ? 'active' : '' ?>" href="tenants.php" title="Stores & Tenants"><span class="admin-nav-icon" aria-hidden="true">▥</span><span class="admin-nav-label">Stores &amp; Tenants</span></a>
        <?php if (is_admin()): ?>
          <a class="<?= $currentAdminPage === 'rates-settings.php' ? 'active' : '' ?>" href="rates-settings.php" title="Rates Settings"><span class="admin-nav-icon" aria-hidden="true">₱</span><span class="admin-nav-label">Rates Settings</span></a>
          <a class="<?= in_array($currentAdminPage, ['rentables.php','rental-categories.php','rental-charge-types.php'], true) ? 'active' : '' ?>" href="rentables.php" title="Manage Rentables"><span class="admin-nav-icon" aria-hidden="true">▤</span><span class="admin-nav-label">Manage Rentables</span></a>
        <?php endif; ?>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-content">
        <span class="admin-nav-group-title" id="admin-nav-content">Website Content</span>
        <a class="<?= $currentAdminPage === 'hero-slides.php' ? 'active' : '' ?>" href="hero-slides.php" title="Hero Carousel"><span class="admin-nav-icon" aria-hidden="true">▤</span><span class="admin-nav-label">Hero Carousel</span></a>
        <a class="<?= $currentAdminPage === 'announcements.php' ? 'active' : '' ?>" href="announcements.php" title="Announcements"><span class="admin-nav-icon" aria-hidden="true">◉</span><span class="admin-nav-label">Announcements</span></a>
        <a class="<?= $currentAdminPage === 'gallery.php' ? 'active' : '' ?>" href="gallery.php" title="Gallery"><span class="admin-nav-icon" aria-hidden="true">▣</span><span class="admin-nav-label">Gallery</span></a>
        <?php if (is_admin()): ?>
          <a class="<?= $currentAdminPage === 'settings.php' ? 'active' : '' ?>" href="settings.php" title="Website Settings"><span class="admin-nav-icon" aria-hidden="true">⚙</span><span class="admin-nav-label">Website Settings</span></a>
        <?php endif; ?>
      </div>

      <div class="admin-nav-group" aria-labelledby="admin-nav-system">
        <span class="admin-nav-group-title" id="admin-nav-system">System</span>
        <?php if (is_admin()): ?>
          <a class="<?= $currentAdminPage === 'users.php' ? 'active' : '' ?>" href="users.php" title="Users"><span class="admin-nav-icon" aria-hidden="true">♟</span><span class="admin-nav-label">Users</span></a>
          <a class="<?= $currentAdminPage === 'database-export.php' ? 'active' : '' ?>" href="database-export.php" title="Database Backup"><span class="admin-nav-icon" aria-hidden="true">&#128190;</span><span class="admin-nav-label">Database Backup</span></a>
        <?php endif; ?>
        <a href="../index.php" target="_blank" rel="noopener" title="View Website"><span class="admin-nav-icon" aria-hidden="true">↗</span><span class="admin-nav-label">View Website</span></a>
      </div>
      <?php endif; ?>
    </nav>

    <div class="admin-user">
      <div class="admin-user-profile">
        <span class="admin-user-avatar" aria-hidden="true"><?= e($adminInitial) ?></span>
        <span class="admin-user-copy"><strong><?= e($adminName) ?></strong><span><?= e(admin_role_label((string)$admin['role'])) ?></span></span>
      </div>
      <form class="admin-signout-form" method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="admin-signout" type="submit" title="Sign out"><span class="admin-signout-icon" aria-hidden="true">↪</span><span class="admin-signout-label">Sign out</span></button>
      </form>
    </div>
  </aside>

  <button class="admin-menu-backdrop" type="button" data-admin-menu-backdrop data-admin-menu-close aria-label="Close menu" tabindex="-1"></button>

  <div class="admin-main<?= !empty($adminCalendarOnly) ? ' admin-main-calendar-only' : '' ?>">
    <?php if (empty($hideAdminTopbar)): ?>
      <header class="admin-topbar">
        <div class="admin-topbar-start">
          <button class="admin-menu-toggle" type="button" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="true" aria-label="Collapse menu" title="Collapse menu">
            <span class="admin-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
          </button>
          <div><span class="small muted">The Leisure Hub</span><h1><?= e($adminPageTitle) ?></h1></div>
        </div>
        <div class="admin-topbar-actions">
          <div class="admin-notification-wrap" data-admin-notifications>
            <button class="admin-notification-bell" type="button" data-notification-toggle aria-expanded="false" aria-label="Notifications<?= $adminNotificationUnread > 0 ? ', ' . $adminNotificationUnread . ' unread' : '' ?>" title="Notifications">
              <span aria-hidden="true">&#128276;</span><strong class="admin-notification-count<?= $adminNotificationUnread > 0 ? '' : ' is-empty' ?>" data-notification-count><?= $adminNotificationUnread ?></strong>
            </button>
            <div class="admin-notification-popover" data-notification-popover hidden>
              <div class="admin-notification-popover-head"><div><strong>Website Requests</strong><span data-notification-summary><?= $adminNotificationUnread > 0 ? $adminNotificationUnread . ' unread' : 'No unread alerts' ?></span></div><a href="notifications.php">View all</a></div>
              <div class="admin-notification-list" data-notification-list>
                <?php foreach ($adminNotificationRecent as $notice): ?>
                  <a class="admin-notification-item<?= empty($notice['is_read']) ? ' is-unread' : '' ?>" href="notification-open.php?id=<?= (int)$notice['id'] ?>"><span class="admin-notification-dot" aria-hidden="true"></span><span><strong><?= e($notice['title']) ?></strong><small><?= e($notice['message'] ?? '') ?></small><time><?= date('M j, g:i A', strtotime($notice['created_at'])) ?></time></span></a>
                <?php endforeach; ?>
                <?php if (!$adminNotificationRecent): ?><div class="admin-notification-empty">No website reservation alerts yet.</div><?php endif; ?>
              </div>
            </div>
          </div>
          <a class="btn btn-primary" href="reservation-create.php">+ New Reservation</a>
        </div>
      </header>
      <div class="admin-notification-wrap admin-notification-standalone" data-admin-notifications>
        <button class="admin-notification-bell" type="button" data-notification-toggle aria-expanded="false" aria-label="Notifications<?= $adminNotificationUnread > 0 ? ', ' . $adminNotificationUnread . ' unread' : '' ?>" title="Notifications"><span aria-hidden="true">&#128276;</span><strong class="admin-notification-count<?= $adminNotificationUnread > 0 ? '' : ' is-empty' ?>" data-notification-count><?= $adminNotificationUnread ?></strong></button>
        <div class="admin-notification-popover" data-notification-popover hidden>
          <div class="admin-notification-popover-head"><div><strong>Website Requests</strong><span data-notification-summary><?= $adminNotificationUnread > 0 ? $adminNotificationUnread . ' unread' : 'No unread alerts' ?></span></div><a href="notifications.php">View all</a></div>
          <div class="admin-notification-list" data-notification-list>
            <?php foreach ($adminNotificationRecent as $notice): ?><a class="admin-notification-item<?= empty($notice['is_read']) ? ' is-unread' : '' ?>" href="notification-open.php?id=<?= (int)$notice['id'] ?>"><span class="admin-notification-dot" aria-hidden="true"></span><span><strong><?= e($notice['title']) ?></strong><small><?= e($notice['message'] ?? '') ?></small><time><?= date('M j, g:i A', strtotime($notice['created_at'])) ?></time></span></a><?php endforeach; ?>
            <?php if (!$adminNotificationRecent): ?><div class="admin-notification-empty">No website reservation alerts yet.</div><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <?php if (!empty($hideAdminTopbar) && !$adminIsCalendarViewer): ?>
      <div class="admin-notification-wrap admin-notification-floating" data-admin-notifications>
        <button class="admin-notification-bell" type="button" data-notification-toggle aria-expanded="false" aria-label="Notifications<?= $adminNotificationUnread > 0 ? ', ' . $adminNotificationUnread . ' unread' : '' ?>" title="Notifications"><span aria-hidden="true">&#128276;</span><strong class="admin-notification-count<?= $adminNotificationUnread > 0 ? '' : ' is-empty' ?>" data-notification-count><?= $adminNotificationUnread ?></strong></button>
        <div class="admin-notification-popover" data-notification-popover hidden>
          <div class="admin-notification-popover-head"><div><strong>Website Requests</strong><span data-notification-summary><?= $adminNotificationUnread > 0 ? $adminNotificationUnread . ' unread' : 'No unread alerts' ?></span></div><a href="notifications.php">View all</a></div>
          <div class="admin-notification-list" data-notification-list>
            <?php foreach ($adminNotificationRecent as $notice): ?><a class="admin-notification-item<?= empty($notice['is_read']) ? ' is-unread' : '' ?>" href="notification-open.php?id=<?= (int)$notice['id'] ?>"><span class="admin-notification-dot" aria-hidden="true"></span><span><strong><?= e($notice['title']) ?></strong><small><?= e($notice['message'] ?? '') ?></small><time><?= date('M j, g:i A', strtotime($notice['created_at'])) ?></time></span></a><?php endforeach; ?>
            <?php if (!$adminNotificationRecent): ?><div class="admin-notification-empty">No website reservation alerts yet.</div><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <main class="admin-content<?= !empty($adminCalendarOnly) ? ' admin-content-calendar-only' : '' ?>"><?php render_flashes(empty($hideAdminFlashes)); ?>
