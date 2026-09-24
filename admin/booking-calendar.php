<?php
/**
 * FILE PURPOSE: Admin monthly booking calendar and reservation detail modal.
 * DEBUGGING: Calendar occupancy follows reservation_calendar_block_condition(). Quick status/payment updates are sent to calendar-reservation-action.php.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Booking Calendar';
$adminCalendarOnly = true;
$hideAdminTopbar = true;
$hideAdminFlashes = true;
$calendarReadOnly = is_calendar_viewer();

$monthInput = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
    $monthInput = date('Y-m');
}

try {
    $monthStart = new DateTimeImmutable($monthInput . '-01 00:00:00');
} catch (Throwable $e) {
    $monthStart = new DateTimeImmutable(date('Y-m-01 00:00:00'));
    $monthInput = $monthStart->format('Y-m');
}
$monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
$calendarStart = $monthStart->modify('-' . (int)$monthStart->format('w') . ' days')->setTime(0, 0, 0);
$calendarEnd = $monthEnd->modify('+' . (6 - (int)$monthEnd->format('w')) . ' days')->setTime(23, 59, 59);
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

$sql = "SELECT r.*, b.batch_reference FROM reservations r
        LEFT JOIN reservation_batches b ON b.id=r.batch_id
        WHERE r.archived_at IS NULL
          AND r.blocked_start <= :calendar_end
          AND r.blocked_end >= :calendar_start";
$params = [
    ':calendar_start' => $calendarStart->format('Y-m-d H:i:s'),
    ':calendar_end' => $calendarEnd->format('Y-m-d H:i:s'),
];
$sql .= ' ORDER BY r.blocked_start ASC, r.event_start ASC';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {
    $bookings = [];
}

$bookingsByDay = [];
foreach ($bookings as $booking) {
    try {
        $bookingStart = new DateTimeImmutable($booking['blocked_start']);
        $bookingEnd = new DateTimeImmutable($booking['blocked_end']);
        $displayEnd = $bookingEnd;
        if ($bookingEnd > $bookingStart && $bookingEnd->format('H:i:s') === '00:00:00') {
            $displayEnd = $bookingEnd->modify('-1 second');
        }
    } catch (Throwable $e) {
        continue;
    }

    $day = $bookingStart->setTime(0, 0, 0);
    $lastDay = $displayEnd->setTime(0, 0, 0);
    if ($day < $calendarStart) {
        $day = $calendarStart;
    }
    if ($lastDay > $calendarEnd) {
        $lastDay = $calendarEnd->setTime(0, 0, 0);
    }

    while ($day <= $lastDay) {
        $dateKey = $day->format('Y-m-d');
        $bookingsByDay[$dateKey][] = $booking;
        $day = $day->modify('+1 day');
    }
}

$monthBookings = array_values(array_filter($bookings, static function (array $booking) use ($monthStart, $monthEnd): bool {
    $start = strtotime($booking['blocked_start']);
    $end = strtotime($booking['blocked_end']);
    return $start <= $monthEnd->getTimestamp() && $end >= $monthStart->getTimestamp();
}));
function calendar_query(string $month): string
{
    return http_build_query(['month' => $month]);
}

function booking_time_for_day(array $booking, string $dateKey): string
{
    $eventStart = new DateTimeImmutable($booking['event_start']);
    $eventEnd = new DateTimeImmutable($booking['event_end']);
    $dateStart = $eventStart->format('Y-m-d');
    $dateEnd = $eventEnd->format('Y-m-d');

    if ($dateStart === $dateKey && $dateEnd === $dateKey) {
        return $eventStart->format('g:i A') . '–' . $eventEnd->format('g:i A');
    }
    if ($dateStart === $dateKey) {
        return 'Starts ' . $eventStart->format('g:i A');
    }
    if ($dateEnd === $dateKey) {
        return 'Ends ' . $eventEnd->format('g:i A');
    }
    return 'All day';
}

function calendar_booking_modal_attributes(array $booking, bool $readOnly = false): string
{
    $paymentMethod = trim((string)($booking['booking_payment_method'] ?? ''));
    $paymentReference = trim((string)($booking['booking_payment_reference'] ?? ''));
    $organization = trim((string)($booking['organization'] ?? ''));
    $purpose = trim((string)($booking['purpose'] ?? ''));
    $details = trim((string)($booking['details'] ?? ''));
    $targetAmount = reservation_payment_target($booking);
    $remainingBalance = reservation_remaining_balance($booking);
    $excessCredit = reservation_excess_credit($booking);
    $hasEnded = reservation_has_ended($booking);
    $canLateExtend = reservation_can_late_extend($booking);
    $needsResolution = reservation_needs_resolution($booking);
    $lockMessage = $needsResolution
        ? 'The event time passed while this reservation was still Pending / For Review. It is not Completed. Open the reservation and explicitly resolve the event outcome.'
        : ($hasEnded
            ? ($canLateExtend
                ? 'The booked end time has passed. Editing/rescheduling are locked, but a Late Extension can be recorded if the client actually used additional time.'
                : 'The booked end time has passed. Operational changes are locked; payment recording remains available for any balance due.')
        : ((string)($booking['status'] ?? '') === 'cancelled'
            ? 'Cancelled reservations are locked. Use the rebook workflow to create a new active reservation.'
            : ''));

    $attributes = [
        'data-reservation-modal-trigger' => '1',
        'data-reservation-id' => (string)$booking['id'],
        'data-reservation-reference' => (string)$booking['reference_no'],
        'data-reservation-client' => (string)$booking['client_name'],
        'data-reservation-organization' => $organization !== '' ? $organization : '—',
        'data-reservation-batch' => !empty($booking['batch_id']) ? (($booking['batch_reference'] ?? ('Batch #' . (int)$booking['batch_id'])) . ' · Occurrence #' . (int)$booking['batch_occurrence']) : '—',
        'data-reservation-batch-url' => (!$readOnly && !empty($booking['batch_id'])) ? 'batch-reservation-view.php?id=' . (int)$booking['batch_id'] : '',
        'data-reservation-type' => reservation_type_label((string)$booking['reservation_type']),
        'data-reservation-package' => reservation_package_label($booking['pricing_package'] ?? null),
        'data-reservation-inclusions' => '• ' . reservation_inclusions_text($booking, "\n• "),
        'data-reservation-cooling' => reservation_cooling_label($booking['cooling_option'] ?? null),
        'data-reservation-guests' => !empty($booking['guest_count']) ? (string)$booking['guest_count'] : '—',
        'data-reservation-status' => ucwords(str_replace('_', ' ', (string)$booking['status'])),
        'data-reservation-status-value' => (string)$booking['status'],
        'data-reservation-can-update-status' => (!$readOnly && reservation_can_update_status($booking)) ? '1' : '0',
        'data-reservation-lock-message' => $lockMessage,
        'data-reservation-status-class' => badge_class((string)$booking['status']),
        'data-reservation-calendar-hold' => $needsResolution ? 'Needs resolution · Event time passed while Pending' : ($hasEnded ? ($canLateExtend ? 'Event time passed · Late Extension available' : 'Event time passed · Operational changes locked') : (reservation_holds_calendar($booking) ? 'Schedule secured' : 'Pending request · Not holding slot')),
        'data-reservation-start' => date('M j, Y g:i A', strtotime((string)$booking['event_start'])),
        'data-reservation-end' => date('M j, Y g:i A', strtotime((string)$booking['event_end'])),
        'data-reservation-email' => (string)$booking['email'],
        'data-reservation-phone' => (string)$booking['phone'],
        'data-reservation-purpose' => $purpose !== '' ? $purpose : '—',
        'data-reservation-details' => $details !== '' ? $details : 'No additional details provided.',
        'data-reservation-payment-method' => $paymentMethod !== '' ? booking_payment_method_label($paymentMethod) : '—',
        'data-reservation-payment-reference' => $paymentReference !== '' ? $paymentReference : '—',
        'data-reservation-payment-status' => ucfirst((string)($booking['payment_status'] ?? 'unpaid')),
        'data-reservation-payment-status-class' => badge_class((string)($booking['payment_status'] ?? 'unpaid')),
        'data-reservation-payment-remaining' => number_format($remainingBalance, 2, '.', ''),
        'data-reservation-payment-can-add' => (!$readOnly && $remainingBalance > 0.001) ? '1' : '0',
        'data-reservation-total' => money($targetAmount),
        'data-reservation-paid' => money((float)($booking['amount_paid'] ?? 0)),
        'data-reservation-balance' => money($excessCredit > 0 ? $excessCredit : $remainingBalance),
        'data-reservation-balance-label' => (string)$booking['status'] === 'cancelled'
            ? ($excessCredit > 0 ? 'Refund Pending' : 'Cancellation Balance')
            : ($excessCredit > 0 ? 'Excess Payment Credit' : 'Balance'),
        'data-reservation-url' => $readOnly ? '' : 'reservation-view.php?id=' . (int)$booking['id'],
        'data-reservation-print-url' => $readOnly ? '' : 'reservation-print.php?id=' . (int)$booking['id'],
        'data-reservation-edit-url' => (!$readOnly && reservation_can_edit($booking)) ? 'reservation-edit.php?id=' . (int)$booking['id'] : '',
        'data-reservation-reschedule-url' => (!$readOnly && reservation_can_reschedule($booking)) ? 'reservation-reschedule.php?id=' . (int)$booking['id'] : '',
        'data-reservation-extend-url' => (!$readOnly && reservation_can_record_extension($booking)) ? 'reservation-extend.php?id=' . (int)$booking['id'] : '',
        'data-reservation-extend-label' => $canLateExtend ? 'Late Extension' : 'Extend',
        'data-reservation-cancel-url' => (!$readOnly && reservation_can_cancel($booking)) ? 'reservation-cancel.php?id=' . (int)$booking['id'] : '',
    ];

    $html = '';
    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . e($value) . '"';
    }
    return $html;
}

include __DIR__ . '/_header.php';
?>
<section class="panel booking-calendar-panel booking-calendar-only-panel">
  <header class="calendar-only-header calendar-only-header-with-menu" aria-label="Calendar month navigation">
    <button class="admin-menu-toggle admin-menu-toggle-calendar" type="button" data-admin-menu-toggle aria-controls="admin-sidebar" aria-expanded="true" aria-label="Collapse menu" title="Collapse menu">
      <span class="admin-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
    </button>
    <a class="calendar-nav-btn" href="?<?= e(calendar_query($previousMonth)) ?>" aria-label="Previous month">‹</a>
    <div class="calendar-only-title">
      <h2><?= e($monthStart->format('F Y')) ?></h2>
      <a href="?<?= e(calendar_query(date('Y-m'))) ?>">Today</a>
      <?php if ($calendarReadOnly): ?><a href="rental-calendar.php?month=<?= e($monthInput) ?>">Rental Calendar</a><span class="calendar-viewer-readonly-badge">Read only</span><?php endif; ?>
    </div>
    <a class="calendar-nav-btn" href="?<?= e(calendar_query($nextMonth)) ?>" aria-label="Next month">›</a>
    <span class="calendar-only-menu-spacer" aria-hidden="true"></span>
  </header>

  <div class="month-calendar" role="grid" aria-label="<?= e($monthStart->format('F Y')) ?> booking calendar">
    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday): ?>
      <div class="month-calendar-weekday" role="columnheader"><?= e(substr($weekday, 0, 3)) ?></div>
    <?php endforeach; ?>

    <?php
    $cursor = $calendarStart;
    while ($cursor <= $calendarEnd):
        $dateKey = $cursor->format('Y-m-d');
        $dayBookings = $bookingsByDay[$dateKey] ?? [];
        $isCurrentMonth = $cursor->format('Y-m') === $monthInput;
        $isToday = $dateKey === date('Y-m-d');
    ?>
      <div class="month-calendar-day <?= $isCurrentMonth ? '' : 'outside-month' ?> <?= $isToday ? 'is-today' : '' ?> <?= count($dayBookings) >= 3 ? 'has-many-bookings' : '' ?>" role="gridcell" aria-label="<?= e($cursor->format('F j, Y')) ?>">
        <div class="month-day-head">
          <?php if ($calendarReadOnly): ?><span class="calendar-day-number"><?= (int)$cursor->format('j') ?></span><?php else: ?><a href="reservations.php?date=<?= e($dateKey) ?>" title="View reservations for <?= e($cursor->format('F j, Y')) ?>"><?= (int)$cursor->format('j') ?></a><?php endif; ?>
          <?php if ($dayBookings): ?><span><?= count($dayBookings) ?></span><?php endif; ?>
        </div>
        <div class="month-day-bookings">
          <?php foreach ($dayBookings as $booking):
              $inactive = in_array($booking['status'], ['rejected', 'cancelled'], true);
          ?>
            <a
              class="calendar-booking type-<?= e($booking['reservation_type']) ?> status-<?= e($booking['status']) ?> <?= $inactive ? 'is-inactive' : '' ?>"
              href="<?= $calendarReadOnly ? '?month=' . e($monthInput) : 'reservation-view.php?id=' . (int)$booking['id'] ?>"
              title="<?= e($booking['client_name']) ?>"
              <?= calendar_booking_modal_attributes($booking, $calendarReadOnly) ?>
            >
              <strong><?= e($booking['client_name']) ?></strong>
              <span class="calendar-booking-time"><?= e(booking_time_for_day($booking, $dateKey)) ?></span>
              <?php if (!reservation_holds_calendar($booking) && !reservation_has_ended($booking)): ?><span class="calendar-booking-hold-note">Not holding slot</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
          <?php if (!$dayBookings): ?><span class="calendar-empty-day">Available</span><?php endif; ?>
        </div>
      </div>
    <?php
        $cursor = $cursor->modify('+1 day');
    endwhile;
    ?>
  </div>

  <div class="calendar-mobile-list">
    <?php if (!$monthBookings): ?>
      <div class="empty-state">No reservations found for this month.</div>
    <?php else: ?>
      <?php
      $mobileGroups = [];
      foreach ($monthBookings as $booking) {
          $key = date('Y-m-d', strtotime($booking['event_start']));
          $mobileGroups[$key][] = $booking;
      }
      ksort($mobileGroups);
      foreach ($mobileGroups as $dateKey => $items):
      ?>
        <div class="calendar-mobile-day">
          <div class="calendar-mobile-date">
            <strong><?= e(date('D', strtotime($dateKey))) ?></strong>
            <span><?= e(date('M j', strtotime($dateKey))) ?></span>
          </div>
          <div class="calendar-mobile-bookings">
            <?php foreach ($items as $booking): ?>
              <a class="calendar-booking type-<?= e($booking['reservation_type']) ?> status-<?= e($booking['status']) ?>" href="<?= $calendarReadOnly ? '?month=' . e($monthInput) : 'reservation-view.php?id=' . (int)$booking['id'] ?>" <?= calendar_booking_modal_attributes($booking, $calendarReadOnly) ?>>
                <strong><?= e($booking['client_name']) ?></strong>
                <span class="calendar-booking-time"><?= e(booking_time_for_day($booking, $dateKey)) ?></span>
                <?php if (!reservation_holds_calendar($booking) && !reservation_has_ended($booking)): ?><span class="calendar-booking-hold-note">Not holding slot</span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<div class="reservation-modal" id="reservationModal" hidden>
  <div class="reservation-modal-backdrop" data-reservation-modal-close></div>
  <section class="reservation-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="reservationModalTitle" tabindex="-1">
    <header class="reservation-modal-header">
      <div>
        <span class="small muted">Reservation details</span>
        <h2 id="reservationModalTitle">Reservation</h2>
        <p id="reservationModalClient" class="reservation-modal-client"></p>
      </div>
      <button type="button" class="reservation-modal-close" data-reservation-modal-close aria-label="Close reservation details">&times;</button>
    </header>

    <div class="reservation-modal-body">
      <div class="reservation-modal-status-row">
        <span id="reservationModalStatus" class="status-pill status-secondary"></span>
        <span id="reservationModalPaymentStatus" class="status-pill status-secondary"></span>
      </div>

      <div class="reservation-modal-grid">
        <div class="reservation-modal-item"><span>Type</span><strong id="reservationModalType"></strong></div>
        <div class="reservation-modal-item"><span>Organization / Team</span><strong id="reservationModalOrganization"></strong></div>
        <div class="reservation-modal-item"><span>Batch Reservation</span><strong id="reservationModalBatch"></strong></div>
        <div class="reservation-modal-item"><span>Pricing Package</span><strong id="reservationModalPackage"></strong></div>
        <div class="reservation-modal-item"><span>Cooling / Guests</span><strong id="reservationModalCooling"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Reservation Inclusions</span><strong id="reservationModalInclusions"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Calendar Hold</span><strong id="reservationModalCalendarHold"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Starts</span><strong id="reservationModalStart"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Ends</span><strong id="reservationModalEnd"></strong></div>
        <div class="reservation-modal-item"><span>Email</span><strong id="reservationModalEmail"></strong></div>
        <div class="reservation-modal-item"><span>Phone</span><strong id="reservationModalPhone"></strong></div>
        <div class="reservation-modal-item"><span>Payment Method</span><strong id="reservationModalPaymentMethod"></strong></div>
        <div class="reservation-modal-item"><span>Payment Reference</span><strong id="reservationModalPaymentReference"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Purpose</span><strong id="reservationModalPurpose"></strong></div>
        <div class="reservation-modal-item reservation-modal-item-wide"><span>Details</span><p id="reservationModalDetails"></p></div>
      </div>

      <div class="reservation-modal-payment-summary" aria-label="Payment summary">
        <div><span>Total</span><strong id="reservationModalTotal"></strong></div>
        <div><span>Paid</span><strong id="reservationModalPaid"></strong></div>
        <div><span id="reservationModalBalanceLabel">Balance</span><strong id="reservationModalBalance"></strong></div>
      </div>

      <?php if (!$calendarReadOnly): ?>
      <div class="reservation-modal-quick-actions" aria-label="Quick reservation updates">
        <section class="reservation-modal-quick-card">
          <div class="reservation-modal-quick-head">
            <div>
              <span class="small muted">Quick action</span>
              <h3>Update Status</h3>
            </div>
            <span class="reservation-modal-quick-icon" aria-hidden="true">✓</span>
          </div>
          <form id="reservationModalStatusForm" class="reservation-modal-quick-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="reservation_id" id="reservationModalStatusReservationId">
            <div class="form-group">
              <label for="reservationModalStatusSelect">Reservation Status</label>
              <select name="status" id="reservationModalStatusSelect" required>
                <?php foreach (['pending' => 'Pending', 'for_review' => 'For Review', 'approved' => 'Approved', 'completed' => 'Completed', 'rejected' => 'Rejected', 'no_show' => 'No Show'] as $value => $label): ?>
                  <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <p class="field-help" id="reservationModalStatusHelp">Approving a pending website request runs a fresh conflict check before the schedule is secured.</p>
            <div class="reservation-modal-inline-feedback" id="reservationModalStatusFeedback" role="status" aria-live="polite"></div>
            <button class="btn btn-dark btn-block" type="submit" id="reservationModalStatusSave">Save Status</button>
          </form>
          <div class="reservation-modal-quick-unavailable" id="reservationModalStatusUnavailable" hidden>Cancelled reservations are locked. Use the rebook workflow to create a new active reservation.</div>
        </section>

        <section class="reservation-modal-quick-card">
          <div class="reservation-modal-quick-head">
            <div>
              <span class="small muted">Quick action</span>
              <h3>Update Payment</h3>
            </div>
            <span class="reservation-modal-quick-icon" aria-hidden="true">₱</span>
          </div>
          <form id="reservationModalPaymentForm" class="reservation-modal-quick-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="reservation_id" id="reservationModalPaymentReservationId">
            <div class="reservation-modal-quick-grid">
              <div class="form-group">
                <label for="reservationModalPaymentAmount">Amount</label>
                <input type="number" min="0.01" step="0.01" name="amount" id="reservationModalPaymentAmount" required>
                <span class="field-help" id="reservationModalPaymentRemaining"></span>
              </div>
              <div class="form-group">
                <label for="reservationModalPaymentMethodSelect">Payment Method</label>
                <select name="payment_method" id="reservationModalPaymentMethodSelect" required>
                  <?php foreach (booking_payment_methods() as $method): ?>
                    <option value="<?= e($method) ?>"><?= e(booking_payment_method_label($method)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="reservation-modal-quick-grid">
              <div class="form-group">
                <label for="reservationModalPaymentReferenceInput">Reference / OR Number</label>
                <input type="text" maxlength="120" name="payment_reference" id="reservationModalPaymentReferenceInput" placeholder="Transaction reference or OR no.">
                <span class="field-help" id="reservationModalPaymentReferenceHelp">Optional for cash payments.</span>
              </div>
              <div class="form-group">
                <label for="reservationModalPaidAt">Paid At</label>
                <input type="datetime-local" name="paid_at" id="reservationModalPaidAt" value="<?= date('Y-m-d\TH:i') ?>" required>
              </div>
            </div>
            <div class="form-group">
              <label for="reservationModalPaymentNotes">Notes</label>
              <textarea name="payment_notes" id="reservationModalPaymentNotes" rows="2" placeholder="Optional payment note"></textarea>
            </div>
            <div class="reservation-modal-inline-feedback" id="reservationModalPaymentFeedback" role="status" aria-live="polite"></div>
            <button class="btn btn-primary btn-block" type="submit" id="reservationModalPaymentSave">Record Payment</button>
          </form>
          <div class="reservation-modal-quick-unavailable" id="reservationModalPaymentUnavailable" hidden>This reservation has no remaining balance to collect.</div>
        </section>
      </div>
      <?php else: ?>
        <div class="calendar-viewer-readonly-note"><strong>Read-only calendar access</strong><span>You can review reservation details, but changes and payment actions are disabled for this account.</span></div>
      <?php endif; ?>
    </div>

    <footer class="reservation-modal-footer">
      <button type="button" class="btn btn-outline" data-reservation-modal-close>Close</button>
      <a class="btn btn-outline" id="reservationModalPrintLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php" target="_blank" rel="noopener">Print Reservation</a>
      <a class="btn btn-outline" id="reservationModalBatchLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php?view=batches">View Batch</a>
      <a class="btn btn-outline" id="reservationModalRescheduleLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php">Reschedule</a>
      <a class="btn btn-outline" id="reservationModalExtendLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php">Extend</a>
      <a class="btn btn-danger" id="reservationModalCancelLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php">Cancel Reservation</a>
      <a class="btn btn-primary" id="reservationModalEditLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php">Edit Reservation</a>
      <a class="btn btn-dark" id="reservationModalOpenLink"<?= $calendarReadOnly ? ' hidden' : '' ?> href="reservations.php">Open Full Reservation</a>
    </footer>
  </section>
</div>

<script>
(function () {
  const modal = document.getElementById('reservationModal');
  if (!modal) return;

  const dialog = modal.querySelector('.reservation-modal-dialog');
  const openLink = document.getElementById('reservationModalOpenLink');
  const printLink = document.getElementById('reservationModalPrintLink');
  const batchLink = document.getElementById('reservationModalBatchLink');
  const editLink = document.getElementById('reservationModalEditLink');
  const rescheduleLink = document.getElementById('reservationModalRescheduleLink');
  const extendLink = document.getElementById('reservationModalExtendLink');
  const cancelLink = document.getElementById('reservationModalCancelLink');
  const triggers = document.querySelectorAll('[data-reservation-modal-trigger]');
  const closeControls = modal.querySelectorAll('[data-reservation-modal-close]');

  const statusForm = document.getElementById('reservationModalStatusForm');
  const statusReservationId = document.getElementById('reservationModalStatusReservationId');
  const statusSelect = document.getElementById('reservationModalStatusSelect');
  const statusSave = document.getElementById('reservationModalStatusSave');
  const statusFeedback = document.getElementById('reservationModalStatusFeedback');
  const statusUnavailable = document.getElementById('reservationModalStatusUnavailable');

  const paymentForm = document.getElementById('reservationModalPaymentForm');
  const paymentReservationId = document.getElementById('reservationModalPaymentReservationId');
  const paymentAmount = document.getElementById('reservationModalPaymentAmount');
  const paymentMethodSelect = document.getElementById('reservationModalPaymentMethodSelect');
  const paymentReferenceInput = document.getElementById('reservationModalPaymentReferenceInput');
  const paymentReferenceHelp = document.getElementById('reservationModalPaymentReferenceHelp');
  const paymentPaidAt = document.getElementById('reservationModalPaidAt');
  const paymentNotes = document.getElementById('reservationModalPaymentNotes');
  const paymentRemaining = document.getElementById('reservationModalPaymentRemaining');
  const paymentSave = document.getElementById('reservationModalPaymentSave');
  const paymentFeedback = document.getElementById('reservationModalPaymentFeedback');
  const paymentUnavailable = document.getElementById('reservationModalPaymentUnavailable');

  let lastTrigger = null;

  const setText = (id, value) => {
    const element = document.getElementById(id);
    if (element) element.textContent = value || '—';
  };

  const setStatus = (id, label, statusClass) => {
    const element = document.getElementById(id);
    if (!element) return;
    element.textContent = label || '—';
    element.className = 'status-pill status-' + (statusClass || 'secondary');
  };

  const localDateTimeValue = () => {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
  };

  const clearFeedback = (element) => {
    if (!element) return;
    element.textContent = '';
    element.className = 'reservation-modal-inline-feedback';
  };

  const showFeedback = (element, message, type) => {
    if (!element) return;
    element.textContent = message || '';
    element.className = 'reservation-modal-inline-feedback is-' + (type || 'error');
  };

  const updatePaymentReferenceRequirement = () => {
    if (!paymentMethodSelect || !paymentReferenceInput || !paymentReferenceHelp) return;
    const requiresReference = paymentMethodSelect.value !== 'Cash';
    paymentReferenceInput.required = requiresReference;
    paymentReferenceHelp.textContent = requiresReference
      ? 'Required: enter the transaction reference or OR number.'
      : 'Optional: enter the OR / official receipt number for cash payments.';
  };

  const configureQuickActions = (data) => {
    if (statusReservationId) statusReservationId.value = data.reservationId || '';
    if (statusSelect) statusSelect.value = data.reservationStatusValue || 'pending';
    clearFeedback(statusFeedback);
    const canUpdateStatus = data.reservationCanUpdateStatus === '1';
    if (statusForm) statusForm.hidden = !canUpdateStatus;
    if (statusUnavailable) {
      statusUnavailable.hidden = canUpdateStatus;
      if (!canUpdateStatus) statusUnavailable.textContent = data.reservationLockMessage || 'This reservation is locked and its status can no longer be changed.';
    }

    if (paymentReservationId) paymentReservationId.value = data.reservationId || '';
    const remaining = Number.parseFloat(data.reservationPaymentRemaining || '0') || 0;
    const canAddPayment = data.reservationPaymentCanAdd === '1' && remaining > 0.001;
    if (paymentForm) paymentForm.hidden = !canAddPayment;
    if (paymentUnavailable) paymentUnavailable.hidden = canAddPayment;
    if (paymentAmount) {
      paymentAmount.value = '';
      paymentAmount.max = remaining.toFixed(2);
    }
    if (paymentRemaining) paymentRemaining.textContent = 'Remaining balance: ' + (data.reservationBalance || '—');
    if (paymentMethodSelect) paymentMethodSelect.value = 'Cash';
    if (paymentReferenceInput) paymentReferenceInput.value = '';
    if (paymentPaidAt) paymentPaidAt.value = localDateTimeValue();
    if (paymentNotes) paymentNotes.value = '';
    clearFeedback(paymentFeedback);
    updatePaymentReferenceRequirement();
  };

  const populateModal = (trigger, resetQuickActions = true) => {
    const data = trigger.dataset;
    lastTrigger = trigger;

    setText('reservationModalTitle', 'Reservation ' + data.reservationReference);
    setText('reservationModalClient', data.reservationClient);
    setText('reservationModalType', data.reservationType);
    setText('reservationModalCalendarHold', data.reservationCalendarHold);
    setText('reservationModalOrganization', data.reservationOrganization);
    setText('reservationModalBatch', data.reservationBatch);
    setText('reservationModalPackage', data.reservationPackage);
    setText('reservationModalCooling', (data.reservationCooling || '—') + ' · ' + (data.reservationGuests || '—') + ' guests');
    setText('reservationModalInclusions', data.reservationInclusions);
    setText('reservationModalStart', data.reservationStart);
    setText('reservationModalEnd', data.reservationEnd);
    setText('reservationModalEmail', data.reservationEmail);
    setText('reservationModalPhone', data.reservationPhone);
    setText('reservationModalPaymentMethod', data.reservationPaymentMethod);
    setText('reservationModalPaymentReference', data.reservationPaymentReference);
    setText('reservationModalPurpose', data.reservationPurpose);
    setText('reservationModalDetails', data.reservationDetails);
    setText('reservationModalTotal', data.reservationTotal);
    setText('reservationModalPaid', data.reservationPaid);
    setText('reservationModalBalance', data.reservationBalance);
    setText('reservationModalBalanceLabel', data.reservationBalanceLabel || 'Balance');
    setStatus('reservationModalStatus', data.reservationStatus, data.reservationStatusClass);
    setStatus('reservationModalPaymentStatus', 'Payment: ' + data.reservationPaymentStatus, data.reservationPaymentStatusClass);

    if (openLink) { openLink.href = data.reservationUrl || trigger.href; openLink.hidden = !data.reservationUrl; }
    if (printLink) { printLink.href = data.reservationPrintUrl || data.reservationUrl || trigger.href; printLink.hidden = !data.reservationPrintUrl; }
    if (batchLink) { batchLink.href = data.reservationBatchUrl || 'reservations.php?view=batches'; batchLink.hidden = !data.reservationBatchUrl; }
    if (editLink) { editLink.href = data.reservationEditUrl || data.reservationUrl || trigger.href; editLink.hidden = !data.reservationEditUrl; }
    if (cancelLink) { cancelLink.href = data.reservationCancelUrl || data.reservationUrl || trigger.href; cancelLink.hidden = !data.reservationCancelUrl; }
    if (rescheduleLink) { rescheduleLink.href = data.reservationRescheduleUrl || data.reservationUrl || trigger.href; rescheduleLink.hidden = !data.reservationRescheduleUrl; }
    if (extendLink) { extendLink.href = data.reservationExtendUrl || data.reservationUrl || trigger.href; extendLink.textContent = data.reservationExtendLabel || 'Extend'; extendLink.hidden = !data.reservationExtendUrl; }

    if (resetQuickActions) configureQuickActions(data);
  };

  const openModal = (trigger) => {
    populateModal(trigger, true);
    modal.hidden = false;
    document.body.classList.add('reservation-modal-open');
    window.requestAnimationFrame(() => modal.classList.add('is-open'));
    dialog.focus();
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    document.body.classList.remove('reservation-modal-open');
    modal.hidden = true;
    if (lastTrigger) lastTrigger.focus();
  };

  const updateCalendarHoldNote = (trigger, holdsCalendar, hasEnded) => {
    let note = trigger.querySelector('.calendar-booking-hold-note');
    if (holdsCalendar || hasEnded) {
      if (note) note.remove();
      return;
    }
    if (!note) {
      note = document.createElement('span');
      note.className = 'calendar-booking-hold-note';
      trigger.appendChild(note);
    }
    note.textContent = 'Not holding slot';
  };

  const applyReservationUpdate = (reservation) => {
    if (!reservation || !reservation.reservation_id) return;
    const matching = document.querySelectorAll('[data-reservation-id="' + reservation.reservation_id + '"]');
    matching.forEach((trigger) => {
      const oldStatus = trigger.dataset.reservationStatusValue;
      if (oldStatus) trigger.classList.remove('status-' + oldStatus);
      trigger.classList.add('status-' + reservation.status_value);
      trigger.dataset.reservationStatusValue = reservation.status_value;
      trigger.dataset.reservationStatus = reservation.status_label;
      trigger.dataset.reservationStatusClass = reservation.status_class;
      trigger.dataset.reservationCalendarHold = reservation.calendar_hold;
      trigger.dataset.reservationCanUpdateStatus = reservation.can_update_status ? '1' : '0';
      trigger.dataset.reservationLockMessage = reservation.lock_message || '';
      trigger.dataset.reservationPaymentStatus = reservation.payment_status;
      trigger.dataset.reservationPaymentStatusClass = reservation.payment_status_class;
      trigger.dataset.reservationTotal = reservation.total;
      trigger.dataset.reservationPaid = reservation.paid;
      trigger.dataset.reservationBalance = reservation.balance;
      trigger.dataset.reservationBalanceLabel = reservation.balance_label;
      trigger.dataset.reservationPaymentRemaining = Number(reservation.remaining_balance || 0).toFixed(2);
      trigger.dataset.reservationPaymentCanAdd = reservation.can_add_payment ? '1' : '0';
      trigger.dataset.reservationEditUrl = reservation.edit_url || '';
      trigger.dataset.reservationRescheduleUrl = reservation.reschedule_url || '';
      trigger.dataset.reservationExtendUrl = reservation.extend_url || '';
      trigger.dataset.reservationExtendLabel = reservation.extend_label || 'Extend';
      trigger.dataset.reservationCancelUrl = reservation.cancel_url || '';
      updateCalendarHoldNote(trigger, !!reservation.holds_calendar, !!reservation.has_ended);
    });

    if (lastTrigger) {
      const replacement = document.querySelector('[data-reservation-id="' + reservation.reservation_id + '"]');
      if (replacement) lastTrigger = replacement;
      populateModal(lastTrigger, true);
    }
  };

  const showActionSuccess = (title, message) => {
    const existing = document.querySelector('[data-calendar-success-modal]');
    if (existing) existing.remove();

    const wrapper = document.createElement('div');
    wrapper.className = 'action-success-modal';
    wrapper.setAttribute('data-calendar-success-modal', '');
    wrapper.innerHTML = '<button class="action-success-backdrop" type="button" aria-label="Close success message"></button>' +
      '<section class="action-success-dialog" role="dialog" aria-modal="true" aria-labelledby="calendarSuccessTitle" tabindex="-1">' +
      '<div class="action-success-icon" aria-hidden="true">✓</div>' +
      '<div class="action-success-copy"><span class="action-success-kicker">Saved successfully</span><h2 id="calendarSuccessTitle"></h2><p data-calendar-success-message></p></div>' +
      '<button class="btn btn-primary action-success-ok" type="button">OK</button></section>';
    wrapper.querySelector('#calendarSuccessTitle').textContent = title || 'Update Saved';
    wrapper.querySelector('[data-calendar-success-message]').textContent = message || 'The reservation was updated successfully.';
    document.body.appendChild(wrapper);
    document.body.classList.add('action-success-modal-open');

    const successDialog = wrapper.querySelector('.action-success-dialog');
    const ok = wrapper.querySelector('.action-success-ok');
    const backdrop = wrapper.querySelector('.action-success-backdrop');
    const close = () => {
      wrapper.classList.add('is-closing');
      const finish = () => {
        wrapper.remove();
        document.body.classList.remove('action-success-modal-open');
        if (!modal.hidden) dialog.focus();
      };
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) finish();
      else window.setTimeout(finish, 190);
    };
    ok.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    wrapper.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
      }
    });
    window.setTimeout(() => (ok || successDialog).focus(), 0);
  };

  const submitQuickAction = async (form, button, feedback) => {
    clearFeedback(feedback);
    const previousText = button.textContent;
    button.disabled = true;
    button.textContent = 'Saving...';
    try {
      const response = await fetch('calendar-reservation-action.php', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });
      let payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        throw new Error('The server returned an unexpected response. Refresh the calendar and try again.');
      }
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'The update could not be saved.');
      }
      applyReservationUpdate(payload.reservation);
      showActionSuccess(payload.title || 'Update Saved', payload.message);
    } catch (error) {
      showFeedback(feedback, error.message || 'The update could not be saved.', 'error');
    } finally {
      button.disabled = false;
      button.textContent = previousText;
    }
  };

  triggers.forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      openModal(trigger);
    });
  });

  closeControls.forEach((control) => control.addEventListener('click', closeModal));
  if (paymentMethodSelect) paymentMethodSelect.addEventListener('change', updatePaymentReferenceRequirement);

  if (statusForm && statusSave) {
    statusForm.addEventListener('submit', (event) => {
      event.preventDefault();
      submitQuickAction(statusForm, statusSave, statusFeedback);
    });
  }

  if (paymentForm && paymentSave) {
    paymentForm.addEventListener('submit', (event) => {
      event.preventDefault();
      submitQuickAction(paymentForm, paymentSave, paymentFeedback);
    });
  }

  document.addEventListener('keydown', (event) => {
    if (document.querySelector('[data-calendar-success-modal]')) return;
    if (!modal.hidden && event.key === 'Escape') closeModal();
  });
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
