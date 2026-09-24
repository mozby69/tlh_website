<?php
/**
 * FILE PURPOSE: Closes the shared admin layout, renders the mobile admin quick navigation, and loads shared JavaScript.
 * DEBUGGING: If an admin-side interaction does not initialize, confirm this footer is included and app.js loads without errors.
 */
$currentAdminPage = $currentAdminPage ?? basename($_SERVER['PHP_SELF']);
$adminBottomReservationPages = [
    'reservations.php', 'reservation-view.php', 'reservation-edit.php',
    'reservation-reschedule.php', 'reservation-extend.php', 'reservation-cancel.php',
    'reservation-delete.php', 'reservation-print.php', 'batch-reservations.php', 'batch-reservation-view.php',
    'batch-reservation-print.php'
];
$adminBottomCreatePages = ['reservation-create.php', 'batch-reservation-create.php'];
$adminBottomCalendarPages = ['booking-calendar.php'];
$adminBottomDashboardActive = $currentAdminPage === 'index.php';
$adminBottomReservationsActive = in_array($currentAdminPage, $adminBottomReservationPages, true) && empty($bookingIsArchived) && empty($bookingIsCancelled) && empty($bookingNeedsResolution);
$adminBottomCreateActive = in_array($currentAdminPage, $adminBottomCreatePages, true);
$adminBottomCalendarActive = in_array($currentAdminPage, $adminBottomCalendarPages, true);
$adminBottomMoreActive = !$adminBottomDashboardActive && !$adminBottomReservationsActive && !$adminBottomCreateActive && !$adminBottomCalendarActive;
?>
</main></div></div>
<?php if (!is_calendar_viewer()): ?>
<nav class="admin-bottom-nav" aria-label="Admin quick navigation">
  <a class="admin-bottom-nav-item<?= $adminBottomDashboardActive ? ' active' : '' ?>" href="index.php" data-page-swipe-index="0"<?= $adminBottomDashboardActive ? ' aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-5h5v5"/></svg>
    <span>Dashboard</span>
  </a>
  <a class="admin-bottom-nav-item<?= $adminBottomCalendarActive ? ' active' : '' ?>" href="booking-calendar.php" data-page-swipe-index="1"<?= $adminBottomCalendarActive ? ' aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg>
    <span>Calendar</span>
  </a>
  <a class="admin-bottom-nav-item admin-bottom-nav-create<?= $adminBottomCreateActive ? ' active' : '' ?>" href="reservation-create.php" data-page-swipe-index="2"<?= $adminBottomCreateActive ? ' aria-current="page"' : '' ?>>
    <svg class="admin-bottom-nav-create-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
    <span>Reserve</span>
  </a>
  <a class="admin-bottom-nav-item<?= $adminBottomReservationsActive ? ' active' : '' ?>" href="reservations.php" data-page-swipe-index="3"<?= $adminBottomReservationsActive ? ' aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg>
    <span>Reservations</span>
  </a>
  <button class="admin-bottom-nav-item<?= $adminBottomMoreActive ? ' active' : '' ?>" type="button" data-page-swipe-index="4" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true" class="admin-bottom-nav-more-icon"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
    <span>More</span>
  </button>
</nav>
<?php endif; ?>
<script src="../assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="../assets/js/modern.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/modern.js') ?>"></script>
</body></html>
