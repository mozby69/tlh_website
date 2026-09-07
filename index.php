<?php
/**
 * FILE PURPOSE: Professional reservation-first public landing page.
 * DESIGN GOAL: Present Basketball, Volleyball, and Events as the three primary
 * booking paths while using authentic venue imagery and restrained brand styling.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Home';
$bodyClass = 'home-page home-reservation-gateway home-reservation-premium';
$compactReservationNav = true;

try {
    $announcements = db()->query(
        "SELECT * FROM announcements
         WHERE is_active=1
           AND (publish_start IS NULL OR publish_start<=CURDATE())
           AND (publish_end IS NULL OR publish_end>=CURDATE())
         ORDER BY created_at DESC
         LIMIT 2"
    )->fetchAll();
} catch (Throwable $e) {
    $announcements = [];
}

$bookingMenus = [
    [
        'type' => 'basketball',
        'eyebrow' => 'Court Reservation',
        'title' => 'Basketball Court',
        'description' => 'Reserve the full indoor court for games, practice, training, and tournaments.',
        'image' => 'assets/img/landing-basketball.webp',
        'cta' => 'Reserve Basketball',
    ],
    [
        'type' => 'volleyball',
        'eyebrow' => 'Court Reservation',
        'title' => 'Volleyball Court',
        'description' => 'Book an indoor volleyball schedule for team training, matches, and competitive play.',
        'image' => 'assets/img/landing-volleyball.webp',
        'cta' => 'Reserve Volleyball',
    ],
    [
        'type' => 'event',
        'eyebrow' => 'Venue Reservation',
        'title' => 'Events Venue',
        'description' => 'Host private functions, sports events, training sessions, and special gatherings.',
        'image' => 'assets/img/landing-events.webp',
        'cta' => 'Reserve Event Venue',
    ],
];

include __DIR__ . '/includes/header.php';
?>

<section class="reservation-gateway-hero" aria-labelledby="reservationGatewayTitle">
  <div class="reservation-gateway-bg" aria-hidden="true">
    <img src="assets/img/landing-venue.webp" alt="" fetchpriority="high" decoding="async">
  </div>
  <div class="reservation-gateway-shade" aria-hidden="true"></div>

  <div class="container reservation-gateway-inner">
    <header class="reservation-gateway-heading reservation-gateway-heading-minimal">
      <h1 id="reservationGatewayTitle"><span>THE LEISURE HUB</span><strong>Designed for Premium Experience</strong></h1>
    </header>

    <div class="reservation-gateway-mobile-feature">
      <img src="assets/img/landing-venue.webp" alt="" loading="eager" decoding="async">
      <span class="reservation-gateway-mobile-feature-shade" aria-hidden="true"></span>
      <div class="reservation-gateway-native-appbar">
        <a class="reservation-gateway-native-brand" href="index.php" aria-label="The Leisure Hub home">
          <img src="assets/img/tlh-logo-mark-mobile-hero.png" alt="" decoding="async">
          <span>THE LEISURE HUB</span>
        </a>
        <a class="reservation-gateway-native-calendar" href="availability.php" aria-label="Open Reservation Calendar">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="15" rx="3"/><path d="M8 3.5v4M16 3.5v4M3.5 9.5h17"/></svg>
        </a>
      </div>
      <div class="reservation-gateway-mobile-feature-copy" role="heading" aria-level="1">
        <span class="reservation-gateway-mobile-title"><b>DESIGNED FOR</b><b>PREMIUM EXPERIENCE</b></span>
        <strong>Courts &amp; venue reservations</strong>
        <a class="reservation-gateway-mobile-book-now" href="#bookExperience" aria-label="Book your experience now">
          <span>BOOK NOW!</span>
          <i aria-hidden="true">→</i>
        </a>
      </div>
    </div>

    <div class="reservation-gateway-menu-heading" id="bookExperience" role="heading" aria-level="2">
      <span class="reservation-gateway-menu-heading-desktop">Book Your Experience Now!</span>
      <span class="reservation-gateway-menu-heading-mobile"><b>Book Your Experience Now!</b></span>
    </div>
    <p class="reservation-gateway-menu-subcopy"><span class="reservation-gateway-menu-subcopy-desktop">Select a court or venue to view available schedules and submit your request in a few simple steps.</span><span class="reservation-gateway-menu-subcopy-mobile">Choose a reservation type</span></p>

    <div class="reservation-gateway-grid" aria-label="Reservation choices">
      <?php foreach ($bookingMenus as $menuIndex => $menu): ?>
        <a class="reservation-gateway-card reservation-gateway-card-<?= e($menu['type']) ?>" data-booking-index="<?= e(str_pad((string)($menuIndex + 1), 2, '0', STR_PAD_LEFT)) ?>" href="reserve.php?type=<?= e($menu['type']) ?>" aria-label="<?= e($menu['cta']) ?>">
          <span class="reservation-gateway-card-media">
            <img src="<?= e($menu['image']) ?>" alt="" loading="eager" decoding="async">
            <span class="reservation-gateway-card-media-shade" aria-hidden="true"></span>
            <span class="reservation-gateway-card-icon" aria-hidden="true">
              <?php if ($menu['type'] === 'basketball'): ?>
                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5"/><path d="M3.7 10.6c4.5.1 8.2 3.8 8.3 8.3M20.3 13.4c-4.5-.1-8.2-3.8-8.3-8.3M12 3.5v17M4.5 7.2h15"/></svg>
              <?php elseif ($menu['type'] === 'volleyball'): ?>
                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5"/><path d="M10.2 3.8c2.9 2.1 4.6 4.8 5 8.2M19.4 8.4c-3.1.8-5.5 2.5-7.2 5.2M16.9 18.7c-2.1-2.6-4.8-4.2-8.1-4.8M4.7 16.5c.6-3.4 2.2-6 4.9-7.9M7.1 5.3c3.1 1.1 5.6 3.1 7.4 5.9"/></svg>
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5.5" width="16" height="14" rx="2"/><path d="M8 3.5v4M16 3.5v4M4 9.5h16M8 13h3M13 13h3M8 16h3"/></svg>
              <?php endif; ?>
            </span>
          </span>
          <span class="reservation-gateway-card-shine" aria-hidden="true"></span>
          <span class="reservation-gateway-card-content">
            <span class="reservation-gateway-card-eyebrow"><?= e($menu['eyebrow']) ?></span>
            <strong><?= e($menu['title']) ?></strong>
            <small><?= e($menu['description']) ?></small>
            <span class="reservation-gateway-card-link"><span class="reservation-gateway-card-link-text"><?= e($menu['cta']) ?></span><i aria-hidden="true">→</i></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="reservation-gateway-experience" aria-labelledby="experienceHubTitle">
  <div class="container reservation-gateway-experience-inner">
    <header class="reservation-gateway-experience-heading" data-experience-reveal>
      <span>Experience the Hub</span>
      <div>
        <h2 id="experienceHubTitle">See the energy.<br>Feel the space.</h2>
        <p>Designed for play, training, competition, and memorable moments.</p>
      </div>
    </header>

    <div class="reservation-gateway-experience-stories" aria-label="The Leisure Hub experience">
      <article class="reservation-gateway-experience-card reservation-gateway-experience-card-feature" data-experience-reveal>
        <img src="assets/img/experience/experience-volleyball.webp" alt="Volleyball player standing on The Leisure Hub indoor court" loading="lazy" decoding="async">
        <span class="reservation-gateway-experience-shade" aria-hidden="true"></span>
        <div class="reservation-gateway-experience-copy">
          <span>Ready to play</span>
          <strong>Make the court yours.</strong>
          <p>Step into a premium indoor space built for games, team sessions, and competitive play.</p>
        </div>
      </article>

      <article class="reservation-gateway-experience-card reservation-gateway-experience-card-ready" data-experience-reveal>
        <img src="assets/img/experience/experience-ready.webp" alt="Athlete preparing on The Leisure Hub indoor court" loading="lazy" decoding="async">
        <span class="reservation-gateway-experience-shade" aria-hidden="true"></span>
        <div class="reservation-gateway-experience-copy">
          <span>Train with purpose</span>
          <strong>Focus on the next play.</strong>
          <p>A comfortable setting for practice, drills, and focused training.</p>
        </div>
      </article>

      <article class="reservation-gateway-experience-card reservation-gateway-experience-card-training" data-experience-reveal>
        <img src="assets/img/experience/experience-training.webp" alt="Basketball training session inside The Leisure Hub" loading="lazy" decoding="async">
        <span class="reservation-gateway-experience-shade" aria-hidden="true"></span>
        <div class="reservation-gateway-experience-copy">
          <span>Make it count</span>
          <strong>Built for every session.</strong>
          <p>From individual development to team preparation, bring your game here.</p>
        </div>
      </article>
    </div>

    <article class="reservation-gateway-experience-panorama" data-experience-reveal>
      <img src="assets/img/experience/experience-court.webp" alt="Wide view of The Leisure Hub multipurpose indoor court" loading="lazy" decoding="async">
      <span class="reservation-gateway-experience-panorama-shade" aria-hidden="true"></span>
      <div class="reservation-gateway-experience-panorama-copy">
        <span>One venue. Multiple experiences.</span>
        <h3>Basketball. Volleyball. Training. Events.</h3>
        <p>Choose how you want to use the space, then find a schedule that works for you.</p>
        <div class="reservation-gateway-experience-actions">
          <a class="reservation-gateway-experience-primary" href="availability.php">Check Availability <i aria-hidden="true">→</i></a>
          <a class="reservation-gateway-experience-secondary" href="venue.php">Explore Venue <i aria-hidden="true">→</i></a>
        </div>
      </div>
    </article>
  </div>
</section>

<section class="reservation-gateway-venue">
  <div class="container reservation-gateway-venue-grid">
    <div class="reservation-gateway-venue-image">
      <img src="assets/img/leisure-hub-hero-aerial.webp" alt="The Leisure Hub venue at night" loading="lazy" decoding="async">
    </div>
    <div class="reservation-gateway-venue-copy">
      <span class="eyebrow">The Leisure Hub</span>
      <h2><span class="reservation-gateway-venue-title-desktop">Built for games, training, and gatherings.</span><span class="reservation-gateway-venue-title-mobile">BUILT TO PLAY.<br>MADE TO GATHER.</span></h2>
      <p><span class="reservation-gateway-venue-copy-desktop">One indoor venue designed to support everyday court bookings, organized sports, and flexible event setups. Start with the type of reservation you need and the system will guide you through available schedules and options.</span><span class="reservation-gateway-venue-copy-mobile">Games, training, events, and gatherings&mdash;all under one roof.</span></p>
      <div class="reservation-gateway-venue-links">
        <a href="venue.php">Explore the Venue <span aria-hidden="true">→</span></a>
        <a href="gallery.php">View Gallery <span aria-hidden="true">→</span></a>
      </div>
    </div>
  </div>
</section>

<?php if ($announcements): ?>
<section class="section-sm reservation-gateway-updates">
  <div class="container reservation-gateway-updates-inner">
    <div class="reservation-gateway-updates-title"><span>Latest Updates</span><h2>Announcements</h2></div>
    <div class="reservation-gateway-update-list">
      <?php foreach ($announcements as $item): ?>
        <article>
          <time><?= e(date('M j', strtotime((string)$item['created_at']))) ?></time>
          <div><h3><?= e($item['title']) ?></h3><p><?= nl2br(e($item['body'])) ?></p></div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
