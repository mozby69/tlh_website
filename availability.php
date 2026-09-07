<?php
/**
 * FILE PURPOSE: Public privacy-safe Reservation Calendar.
 * DEBUGGING: Only secured/staff-held schedules should appear. Pending public website requests must remain invisible and non-blocking.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Reservation Calendar';
$bodyClass = 'native-calendar-page';
$currentPage = 'availability.php';
$today = new DateTimeImmutable('today');

$requestedType = (string)($_GET['type'] ?? 'basketball');
if (!in_array($requestedType, ['basketball', 'volleyball', 'event'], true)) {
    $requestedType = 'basketball';
}
$typeQuery = '&type=' . rawurlencode($requestedType);
$typeLabels = ['basketball' => 'Basketball', 'volleyball' => 'Volleyball', 'event' => 'Events'];

$monthInput = (string)($_GET['month'] ?? $today->format('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
    $monthInput = $today->format('Y-m');
}
try {
    $monthStart = new DateTimeImmutable($monthInput . '-01');
} catch (Throwable $e) {
    $monthStart = new DateTimeImmutable($today->format('Y-m-01'));
}
$monthEnd = $monthStart->modify('last day of this month');
$gridStart = $monthStart->modify('-' . (int)$monthStart->format('w') . ' days');
$gridEnd = $monthEnd->modify('+' . (6 - (int)$monthEnd->format('w')) . ' days');

$selectedDate = (string)($_GET['date'] ?? '');
if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}

$bookings = [];
$bookingsByDate = [];
try {
    $calendarBlockCondition = reservation_calendar_block_condition();
    $stmt = db()->prepare(
        "SELECT id, blocked_start, blocked_end
         FROM reservations
         WHERE archived_at IS NULL
           AND {$calendarBlockCondition}
           AND blocked_start < ?
           AND blocked_end > ?
         ORDER BY blocked_start"
    );
    $stmt->execute([
        $gridEnd->modify('+1 day')->format('Y-m-d 00:00:00'),
        $gridStart->format('Y-m-d 00:00:00'),
    ]);
    $bookings = $stmt->fetchAll();

    foreach ($bookings as $booking) {
        try {
            $blockStart = new DateTimeImmutable((string)$booking['blocked_start']);
            $blockEnd = new DateTimeImmutable((string)$booking['blocked_end']);
        } catch (Throwable $e) {
            continue;
        }
        $cursor = $blockStart->setTime(0, 0);
        $lastDay = $blockEnd->modify('-1 second')->setTime(0, 0);
        while ($cursor <= $lastDay) {
            $day = $cursor->format('Y-m-d');
            if ($day >= $gridStart->format('Y-m-d') && $day <= $gridEnd->format('Y-m-d')) {
                $bookingsByDate[$day][] = $booking;
            }
            $cursor = $cursor->modify('+1 day');
        }
    }
} catch (Throwable $e) {
    $bookings = [];
    $bookingsByDate = [];
}

$selectedBookings = $selectedDate !== '' ? ($bookingsByDate[$selectedDate] ?? []) : [];
$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

function public_calendar_time_label(array $booking, string $day): string
{
    try {
        $start = new DateTimeImmutable((string)$booking['blocked_start']);
        $end = new DateTimeImmutable((string)$booking['blocked_end']);
        $dayStart = new DateTimeImmutable($day . ' 00:00:00');
        $dayEnd = $dayStart->modify('+1 day');
        $displayStart = $start < $dayStart ? $dayStart : $start;
        $displayEnd = $end > $dayEnd ? $dayEnd : $end;
        return $displayStart->format('g:i A') . '–' . $displayEnd->format('g:i A');
    } catch (Throwable $e) {
        return 'Reserved time';
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="section public-calendar-section">
  <div class="container">
    <div class="public-calendar-toolbar">
      <a class="calendar-nav-btn" href="availability.php?month=<?= e($prevMonth) ?><?= e($typeQuery) ?>" aria-label="Previous month">←</a>
      <div class="public-calendar-title">
        <span>Reservation Calendar</span>
        <h2><?= e($monthStart->format('F Y')) ?></h2>
      </div>
      <a class="calendar-nav-btn" href="availability.php?month=<?= e($nextMonth) ?><?= e($typeQuery) ?>" aria-label="Next month">→</a>
    </div>

    <div class="public-calendar-book-as" aria-label="Booking type">
      <div><span>Book as</span><small>The venue calendar is shared by all booking types.</small></div>
      <div class="public-calendar-type-chips">
        <?php foreach ($typeLabels as $value => $label): ?>
          <a class="<?= $requestedType === $value ? 'active' : '' ?>" href="availability.php?month=<?= e($monthStart->format('Y-m')) ?>&type=<?= e($value) ?><?= $selectedDate !== '' ? '&date=' . e($selectedDate) : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="public-calendar-legend" aria-label="Calendar legend">
      <span><i class="calendar-legend-dot reserved"></i> Reserved time exists</span>
      <span><i class="calendar-legend-dot available"></i> Date can be checked</span>
    </div>

    <div class="public-calendar-shell">
      <div class="public-calendar-weekdays" aria-hidden="true">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $weekday): ?>
          <div><?= e($weekday) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="public-calendar-grid">
        <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')):
          $dateKey = $day->format('Y-m-d');
          $dayBookings = $bookingsByDate[$dateKey] ?? [];
          $isCurrentMonth = $day->format('Y-m') === $monthStart->format('Y-m');
          $isToday = $dateKey === $today->format('Y-m-d');
          $isPast = $day < $today;
          $hasBookings = count($dayBookings) > 0;
          $classes = ['public-calendar-day'];
          if (!$isCurrentMonth) $classes[] = 'other-month';
          if ($isToday) $classes[] = 'today';
          if ($isPast) $classes[] = 'past';
          if ($hasBookings) $classes[] = 'has-reservation';
          if ($selectedDate === $dateKey) $classes[] = 'is-selected';
        ?>
          <div class="<?= e(implode(' ', $classes)) ?>">
            <div class="public-calendar-date-row">
              <span class="public-calendar-date-number"><?= e($day->format('j')) ?></span>
              <?php if ($isToday): ?><span class="public-calendar-today-label">Today</span><?php endif; ?>
            </div>

            <?php if ($hasBookings): ?>
              <div class="public-calendar-reservations">
                <?php foreach (array_slice($dayBookings, 0, 2) as $booking): ?>
                  <a class="public-calendar-reserved-slot" href="availability.php?month=<?= e($monthStart->format('Y-m')) ?>&date=<?= e($dateKey) ?><?= e($typeQuery) ?>#day-schedule" title="View unavailable times">
                    <strong>Reserved</strong>
                    <span><?= e(public_calendar_time_label($booking, $dateKey)) ?></span>
                  </a>
                <?php endforeach; ?>
                <?php if (count($dayBookings) > 2): ?>
                  <a class="public-calendar-more" href="availability.php?month=<?= e($monthStart->format('Y-m')) ?>&date=<?= e($dateKey) ?><?= e($typeQuery) ?>#day-schedule">+<?= count($dayBookings) - 2 ?> more</a>
                <?php endif; ?>
              </div>
            <?php elseif ($isCurrentMonth && !$isPast): ?>
              <div class="public-calendar-open">No secured booking</div>
            <?php endif; ?>

            <?php if ($isCurrentMonth && !$isPast): ?>
              <a class="public-calendar-day-select" href="availability.php?month=<?= e($monthStart->format('Y-m')) ?>&date=<?= e($dateKey) ?><?= e($typeQuery) ?>#day-schedule" aria-label="Check <?= e($day->format('F j, Y')) ?>"></a>
              <a class="public-calendar-day-action" href="reserve.php?date=<?= e($dateKey) ?><?= e($typeQuery) ?>" aria-label="Request reservation for <?= e($day->format('F j, Y')) ?>">Request this date</a>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="public-calendar-privacy-note">
      <strong>Privacy protected.</strong>
      <span>This calendar shows schedule availability only. It does not reveal who made a reservation or any private reservation information.</span>
    </div>

    <?php if ($selectedDate !== ''):
      try { $selectedDay = new DateTimeImmutable($selectedDate); } catch (Throwable $e) { $selectedDay = null; }
    ?>
      <section class="public-day-schedule" id="day-schedule">
        <div class="public-day-schedule-heading">
          <div>
            <span class="eyebrow">Selected Date</span>
            <h2><?= $selectedDay ? e($selectedDay->format('F j, Y')) : e($selectedDate) ?></h2>
          </div>
          <?php if ($selectedDay && $selectedDay >= $today): ?>
            <a class="btn btn-primary" href="reserve.php?date=<?= e($selectedDate) ?><?= e($typeQuery) ?>">Reserve <?= e($typeLabels[$requestedType]) ?> on This Date</a>
          <?php endif; ?>
        </div>
        <?php if (!$selectedBookings): ?>
          <div class="public-day-open-card">
            <strong>No secured reservation is currently shown for this date.</strong>
            <p>A pending website request may still exist, and availability is only secured after admin approval.</p>
          </div>
        <?php else: ?>
          <div class="public-day-slots">
            <?php foreach ($selectedBookings as $booking): ?>
              <div class="public-day-slot">
                <span class="public-day-slot-status">Reserved</span>
                <strong><?= e(public_calendar_time_label($booking, $selectedDate)) ?></strong>
                <span>Unavailable</span>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="muted public-day-note">Only the unavailable time is displayed. Client and reservation details are kept private.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
