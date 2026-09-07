<?php
/**
 * FILE PURPOSE: Public events information page.
 * DEBUGGING: Content is primarily presentation; shared header/footer supply global navigation and branding.
 */
$pageTitle = 'Events';
include __DIR__ . '/includes/header.php';
$rates = reservation_pricing_config();
?>
<section class="page-hero"><div class="container"><span class="eyebrow">Events at The Leisure Hub</span><h1>Your occasion, your setup</h1><p>Reserve the venue for celebrations, corporate gatherings, seminars, receptions, launches, tournaments, and community activities.</p></div></section>
<section class="section"><div class="container center"><span class="eyebrow">Event Possibilities</span><h2>Built for meaningful gatherings</h2></div><div class="container grid-3" style="margin-top:34px"><div class="card"><div class="icon-box">🎉</div><h3>Regular Bookings</h3><p class="muted">For 1–29 guests, using the introductory or original regular rate based on the reservation date.</p></div><div class="card"><div class="icon-box">🏆</div><h3>Tournaments</h3><p class="muted">For 30–200 guests, including the shot clocks, scoreboard, controller, and complete sound system.</p></div><div class="card"><div class="icon-box">🎤</div><h3>Big Events</h3><p class="muted">For 201–300 guests, including the equipment bundle and free carpet installation.</p></div></div></section>
<section class="section surface"><div class="container split"><div><span class="eyebrow">Published Rates</span><h2>Rates based on guests and cooling</h2><div class="rate-card"><span>Regular introductory, 1–29 guests</span><strong><?= money($rates['intro_fan_rate']) ?> fan · <?= money($rates['intro_aircon_rate']) ?> aircon / hour</strong></div><div class="rate-card"><span>Tournament, 30–200 guests</span><strong><?= money($rates['tournament_fan_rate']) ?> fan · <?= money($rates['tournament_aircon_rate']) ?> aircon / hour</strong></div><div class="rate-card"><span>Big event, 201–300 guests</span><strong><?= money($rates['big_event_fan_rate']) ?> fan · <?= money($rates['big_event_aircon_rate']) ?> aircon / hour</strong></div><p class="small muted">Setup and cleanup periods block the calendar but are not billed. Shower room is <?= money($rates['shower_room_fee']) ?> per reservation. Equipment is <?= money($rates['equipment_bundle_fee']) ?> per hour for regular bookings and included in tournament and big-event packages.</p><a class="btn btn-primary" href="reserve.php?type=event">Reserve for an Event</a></div><div class="visual-panel"></div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
