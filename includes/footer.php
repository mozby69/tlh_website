<?php
/**
 * FILE PURPOSE: Shared public footer, reservation notice modal, scripts, and global public closing markup.
 * DEBUGGING: Public modal behavior is initialized in assets/js/app.js.
 */
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$mobileMorePages = ['venue.php', 'sports.php', 'events.php', 'stores.php', 'leasing.php', 'contact.php', 'gallery.php', 'terms.php'];
$mobileMoreActive = in_array($currentPage, $mobileMorePages, true);
?></main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <a class="brand footer-brand" href="index.php"><img class="brand-logo footer-logo" src="assets/img/tlh-logo.png" width="1091" height="722" loading="lazy" decoding="async" alt="<?= e(setting('site_name')) ?> logo"></a>
      <p class="footer-main-tagline"><?= e(site_tagline()) ?></p>
      <p class="footer-brand-copy"><?= e(setting('hero_text')) ?></p>
    </div>
    <div><h4>Explore</h4><a href="venue.php">Our Venue</a><a href="sports.php">Sports Court</a><a href="events.php">Events</a><a href="gallery.php">Gallery</a></div>
    <div><h4>Visit & Grow</h4><a href="stores.php">Stores & Tenants</a><a href="leasing.php">Commercial Leasing</a><a href="contact.php">Contact Us</a><a href="terms.php">Policies</a></div>
    <div><h4>Reservations</h4><a href="reserve.php">Reserve Now</a><a href="track.php">Track Reservation</a><a href="availability.php">Availability</a><button class="pwa-install-button" type="button" data-pwa-install hidden>Install App</button><p><?= e(setting('phone')) ?></p></div>
  </div>
  <div class="container footer-bottom"><span>© <?= date('Y') ?> <?= e(setting('site_name')) ?>. All rights reserved.</span><span><?= e(setting('address')) ?></span></div>
</footer>
<div class="reserve-notice-modal" data-reserve-notice-modal hidden aria-hidden="true">
  <div class="reserve-notice-backdrop" data-reserve-notice-close></div>
  <section class="reserve-notice-dialog" role="dialog" aria-modal="true" aria-labelledby="reserveNoticeTitle" aria-describedby="reserveNoticeText">
    <button class="reserve-notice-close" type="button" data-reserve-notice-close aria-label="Close reservation notice">×</button>

    <div class="reserve-notice-heading">
      <span class="reserve-notice-kicker">Before You Reserve</span>
      <h2 id="reserveNoticeTitle">Check availability first</h2>
      <p id="reserveNoticeText">Your preferred schedule is <strong>secured only after The Leisure Hub approves your request.</strong></p>
    </div>

    <div class="reserve-notice-steps" aria-label="Reservation process">
      <div class="reserve-notice-step">
        <span class="reserve-notice-step-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none"><rect x="3.5" y="5.5" width="17" height="15" rx="2.5"/><path d="M7.5 3.5v4M16.5 3.5v4M3.5 9.5h17"/></svg>
        </span>
        <div><strong>Check availability</strong><small>Make sure your preferred date and time are open.</small></div>
      </div>
      <div class="reserve-notice-step">
        <span class="reserve-notice-step-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none"><path d="M5 4.5h10l4 4v11H5z"/><path d="M15 4.5v4h4M8 13h8M8 16h5"/></svg>
        </span>
        <div><strong>Submit your request</strong><small>Complete the reservation details and send them for review.</small></div>
      </div>
      <div class="reserve-notice-step">
        <span class="reserve-notice-step-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5"/><path d="m8.3 12.1 2.3 2.3 5.2-5.2"/></svg>
        </span>
        <div><strong>Get confirmation</strong><small>Your schedule is secured once the request is approved.</small></div>
      </div>
    </div>

    <div class="reserve-notice-actions">
      <a class="btn reserve-calendar-cta" href="availability.php" data-reserve-calendar-cta>
        <svg class="reserve-calendar-cta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="15" rx="2.5"/><path d="M7.5 3.5v4M16.5 3.5v4M3.5 9.5h17"/></svg>
        <strong>View Reservation Calendar</strong>
      </a>
      <button class="btn reserve-notice-continue" type="button" data-reserve-notice-continue>Continue Anyway <span aria-hidden="true">→</span></button>
    </div>
  </section>
</div>

<nav class="mobile-app-nav" aria-label="Primary mobile navigation">
  <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php" data-page-swipe-index="0" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-5h5v5"/></svg><span>Home</span>
  </a>
  <a class="<?= $currentPage === 'availability.php' ? 'active' : '' ?>" href="availability.php" data-page-swipe-index="1" <?= $currentPage === 'availability.php' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg><span>Calendar</span>
  </a>
  <a class="mobile-app-nav-reserve <?= $currentPage === 'reserve.php' ? 'active' : '' ?>" href="reserve.php" data-page-swipe-index="2" <?= $currentPage === 'reserve.php' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Reserve</span>
  </a>
  <a class="<?= $currentPage === 'track.php' ? 'active' : '' ?>" href="track.php" data-page-swipe-index="3" <?= $currentPage === 'track.php' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Track</span>
  </a>
  <button class="<?= $mobileMoreActive ? 'active' : '' ?>" type="button" data-page-swipe-index="4" data-mobile-more-open aria-controls="mobile-more-sheet" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg><span>More</span>
  </button>
</nav>
<div class="mobile-more-sheet" id="mobile-more-sheet" data-mobile-more-sheet hidden aria-hidden="true">
  <button class="mobile-more-backdrop" type="button" data-mobile-more-close aria-label="Close more navigation"></button>
  <section class="mobile-more-panel" role="dialog" aria-modal="true" aria-labelledby="mobileMoreTitle">
    <div class="mobile-more-head"><div><p class="eyebrow">Explore</p><h2 id="mobileMoreTitle">More from The Leisure Hub</h2></div><button class="mobile-more-close" type="button" data-mobile-more-close aria-label="Close">×</button></div>
    <nav class="mobile-more-links" aria-label="More pages">
      <a href="venue.php">Our Venue</a><a href="sports.php">Sports Court</a><a href="events.php">Events</a><a href="stores.php">Stores &amp; Tenants</a><a href="leasing.php">Commercial Leasing</a><a href="gallery.php">Gallery</a><a href="contact.php">Contact Us</a><a href="terms.php">Policies</a>
    </nav>
  </section>
</div>

<script src="assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="assets/js/modern.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/modern.js') ?>"></script>
</body>
</html>
