<?php
/**
 * FILE PURPOSE: Public three-step reservation request form and submission handler.
 * DEBUGGING: Public submissions are requests only and do not hold the calendar until approval. Final submission still validates conflicts for currently secured blocks.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Reserve Now';
$errors = [];
$type = $_GET['type'] ?? ($_POST['reservation_type'] ?? 'basketball');
if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
    $type = 'basketball';
}
$explicitTypeSelection = isset($_GET['type']) && in_array((string)$_GET['type'], ['basketball', 'volleyball', 'event'], true);
$selectedTypeIcon = match ($type) { 'volleyball' => '🏐', 'event' => '★', default => '🏀' };
$selectedTypeTitle = match ($type) { 'volleyball' => 'Volleyball Court', 'event' => 'Event / Venue', default => 'Basketball Court' };
$selectedTypeNote = match ($type) {
    'volleyball' => 'Indoor volleyball schedules for training sessions, matches, and competitive play.',
    'event' => 'Flexible venue reservations for private functions, sports events, and gatherings.',
    default => 'Reserve the full indoor court for games, practice, training, and tournaments.',
};
$prefillDate = trim($_GET['date'] ?? '');
if ($prefillDate !== '' && !reservation_date_is_valid($prefillDate)) {
    $prefillDate = '';
}
$startTimeSlots = reservation_start_time_slots();
$selectedReservationDate = trim($_POST['reservation_date'] ?? $prefillDate);
$selectedStartTime = trim($_POST['start_time'] ?? '08:00');
$selectedDurationHours = reservation_duration_hours_from_input($_POST['duration_hours'] ?? 2) ?? 2.0;
$selectedCooling = trim($_POST['cooling_option'] ?? 'fan');
$selectedPaymentChoice = trim($_POST['payment_choice'] ?? '');
$pricingConfig = reservation_pricing_config();

// PUBLIC REQUEST SUBMISSION: this creates a pending website request, not a
// secured calendar hold. Approval later performs another authoritative check.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = $_POST['reservation_type'] ?? '';
    $clientName = trim($_POST['client_name'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $paymentChoice = trim($_POST['payment_choice'] ?? '');
    $selectedPaymentChoice = $paymentChoice;
    $bookingPaymentMethod = trim($_POST['booking_payment_method'] ?? '');
    $paymentAmountRaw = trim($_POST['payment_amount'] ?? '');
    $paymentAmount = 0.0;
    $reservationDate = trim($_POST['reservation_date'] ?? '');
    $startTimeValue = trim($_POST['start_time'] ?? '');
    $durationHoursRaw = trim($_POST['duration_hours'] ?? '');
    $durationHours = reservation_duration_hours_from_input($durationHoursRaw);
    $selectedReservationDate = $reservationDate;
    $selectedStartTime = $startTimeValue;
    $selectedDurationHours = $durationHours ?? 2.0;
    $purpose = $type === 'event' ? trim($_POST['event_purpose'] ?? '') : trim($_POST['sport_purpose'] ?? '');
    $guestCountRaw = trim($_POST['guest_count'] ?? '');
    $guestCount = ctype_digit($guestCountRaw) ? (int)$guestCountRaw : 0;
    $coolingOption = trim($_POST['cooling_option'] ?? '');
    $selectedCooling = $coolingOption;
    $showerRoom = isset($_POST['shower_room_addon']);
    $equipmentBundle = isset($_POST['equipment_bundle_addon']);
    $setupMinutes = $type === 'event' ? max(0, (int)($_POST['setup_minutes'] ?? 0)) : 0;
    $cleanupMinutes = $type === 'event' ? max(0, (int)($_POST['cleanup_minutes'] ?? 0)) : 0;
    $additionalRequests = trim($_POST['additional_requests'] ?? '');
    $termsAccepted = isset($_POST['terms']);

    if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
        $errors[] = 'Please choose a valid reservation type.';
    }
    if ($clientName === '') {
        $errors[] = 'Client or contact name is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address or leave the email field blank.';
    }
    if ($phone === '') {
        $errors[] = 'A mobile number is required.';
    }
    if ($guestCount < 1 || reservation_package_for_guests($guestCount) === null) {
        $errors[] = 'Guest count must be between 1 and 300 guests.';
    }
    if (!array_key_exists($coolingOption, reservation_cooling_options())) {
        $errors[] = 'Please choose Fan with Lights or Aircon with Lights.';
    }
    if (!in_array($paymentChoice, ['none', 'partial', 'full'], true)) {
        $errors[] = 'Please choose No payment yet, Partial payment, or Full payment.';
    }
    if ($paymentChoice !== 'none') {
        if (!in_array($bookingPaymentMethod, booking_payment_methods(), true)) {
            $errors[] = 'Please choose a valid payment method.';
        }
        if ($paymentChoice === 'partial') {
            if (!is_numeric($paymentAmountRaw) || (float)$paymentAmountRaw <= 0) {
                $errors[] = 'Enter a valid partial payment amount.';
            } else {
                $paymentAmount = round((float)$paymentAmountRaw, 2);
            }
        }
    } else {
        $bookingPaymentMethod = '';
    }
    if (!$termsAccepted) {
        $errors[] = 'Please accept the reservation policies.';
    }

    $scheduleIsValid = true;
    if (!reservation_date_is_valid($reservationDate)) {
        $errors[] = 'Please select a valid reservation date.';
        $scheduleIsValid = false;
    }
    if (!array_key_exists($startTimeValue, $startTimeSlots)) {
        $errors[] = 'Please select a valid 30-minute starting time.';
        $scheduleIsValid = false;
    }
    if ($durationHours === null) {
        $errors[] = 'Please choose a valid reservation duration.';
        $scheduleIsValid = false;
    }

    if ($scheduleIsValid) {
        $start = new DateTimeImmutable($reservationDate . ' ' . $startTimeValue . ':00');
        $end = reservation_add_duration($start, $durationHours);
        if ($end->format('Y-m-d') !== $reservationDate || $end->format('H:i') > '22:00') {
            $errors[] = 'The selected duration extends beyond the 10:00 PM closing time.';
            $scheduleIsValid = false;
            $start = $end = null;
        }
    } else {
        $start = $end = null;
    }

    $pricing = null;
    if ($start && $end && reservation_package_for_guests($guestCount) !== null && array_key_exists($coolingOption, reservation_cooling_options())) {
        if ($start < new DateTimeImmutable('-5 minutes')) {
            $errors[] = 'The reservation must be scheduled in the future.';
        }
        try {
            $pricing = calculate_reservation_pricing(
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $guestCount,
                $coolingOption,
                $showerRoom,
                $equipmentBundle
            );

            if ($paymentChoice === 'full') {
                $paymentAmount = $pricing['total'];
            } elseif ($paymentChoice === 'partial' && $paymentAmount + 0.001 >= $pricing['total']) {
                $errors[] = 'For the full reservation amount of ' . money($pricing['total']) . ', choose Full Payment.';
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors && $start && $end && $pricing !== null) {
        $blockedStart = $start->modify('-' . $setupMinutes . ' minutes');
        $blockedEnd = $end->modify('+' . $cleanupMinutes . ' minutes');
        $pdo = db();

        try {
            $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please wait a moment and submit your request again.');
            }
            if (reservation_conflict($blockedStart->format('Y-m-d H:i:s'), $blockedEnd->format('Y-m-d H:i:s'))) {
                $errors[] = 'The venue is already held by an approved or staff-entered reservation during part of the selected time, including setup or cleanup periods.';
            } else {
                $pdo->beginTransaction();
                $reference = generate_reference();
                // Client selections are intent only. Verified payments are recorded by Admin.
                $paymentStatus = 'unpaid';

                $stmt = $pdo->prepare("INSERT INTO reservations(
                    reference_no,reservation_type,purpose,client_name,organization,email,phone,
                    booking_payment_choice,booking_payment_method,booking_payment_intent_amount,booking_payment_reference,event_start,event_end,
                    setup_minutes,cleanup_minutes,blocked_start,blocked_end,guest_count,
                    pricing_package,cooling_option,rate_period,hourly_rate,billable_hours,
                    shower_room_addon,shower_room_fee,equipment_bundle_addon,equipment_bundle_fee,pricing_snapshot,
                    details,equipment_requests,special_instructions,estimated_amount,amount_paid,payment_status,status,source,created_by
                ) VALUES(
                    :reference_no,:reservation_type,:purpose,:client_name,:organization,:email,:phone,
                    :payment_choice,:payment_method,:payment_intent_amount,:payment_reference,:event_start,:event_end,
                    :setup_minutes,:cleanup_minutes,:blocked_start,:blocked_end,:guest_count,
                    :pricing_package,:cooling_option,:rate_period,:hourly_rate,:billable_hours,
                    :shower_room_addon,:shower_room_fee,:equipment_bundle_addon,:equipment_bundle_fee,:pricing_snapshot,
                    :details,:equipment_requests,:special_instructions,:estimated_amount,:amount_paid,:payment_status,:status,:source,:created_by
                )");
                $stmt->execute([
                    ':reference_no' => $reference,
                    ':reservation_type' => $type,
                    ':purpose' => $purpose !== '' ? $purpose : null,
                    ':client_name' => $clientName,
                    ':organization' => $organization !== '' ? $organization : null,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':payment_choice' => $paymentChoice,
                    ':payment_method' => $bookingPaymentMethod !== '' ? $bookingPaymentMethod : null,
                    ':payment_intent_amount' => $paymentAmount,
                    ':payment_reference' => null,
                    ':event_start' => $start->format('Y-m-d H:i:s'),
                    ':event_end' => $end->format('Y-m-d H:i:s'),
                    ':setup_minutes' => $setupMinutes,
                    ':cleanup_minutes' => $cleanupMinutes,
                    ':blocked_start' => $blockedStart->format('Y-m-d H:i:s'),
                    ':blocked_end' => $blockedEnd->format('Y-m-d H:i:s'),
                    ':guest_count' => $guestCount,
                    ':pricing_package' => $pricing['package'],
                    ':cooling_option' => $pricing['cooling_option'],
                    ':rate_period' => $pricing['rate_period'],
                    ':hourly_rate' => $pricing['hourly_rate'],
                    ':billable_hours' => $pricing['billable_hours'],
                    ':shower_room_addon' => $pricing['shower_room_addon'] ? 1 : 0,
                    ':shower_room_fee' => $pricing['shower_room_fee'],
                    ':equipment_bundle_addon' => $pricing['equipment_bundle_addon'] ? 1 : 0,
                    ':equipment_bundle_fee' => $pricing['equipment_bundle_fee'],
                    ':pricing_snapshot' => reservation_pricing_snapshot($pricing),
                    ':details' => $additionalRequests !== '' ? $additionalRequests : null,
                    ':equipment_requests' => null,
                    ':special_instructions' => null,
                    ':estimated_amount' => $pricing['total'],
                    ':amount_paid' => 0,
                    ':payment_status' => $paymentStatus,
                    ':status' => 'pending',
                    ':source' => 'website',
                    ':created_by' => null,
                ]);
                $reservationId = (int)$pdo->lastInsertId();


                create_website_reservation_notification($reservationId, $pdo);
                $pdo->commit();
                $_SESSION['last_booking_reference'] = $reference;
                $_SESSION['last_booking_email'] = $email;
                $_SESSION['last_booking_phone'] = $phone;
                $_SESSION['last_booking_payment_choice'] = $paymentChoice;
                $_SESSION['last_booking_payment_method'] = $bookingPaymentMethod;
                $_SESSION['last_booking_payment_intent_amount'] = $paymentAmount;
                $_SESSION['last_booking_total'] = $pricing['total'];
                $_SESSION['last_booking_reservation_type'] = $type;
                $_SESSION['last_booking_event_start'] = $start->format('Y-m-d H:i:s');
                $_SESSION['last_booking_event_end'] = $end->format('Y-m-d H:i:s');
                $_SESSION['last_booking_duration_hours'] = $durationHours;
                $_SESSION['last_booking_guest_count'] = $guestCount;
                $_SESSION['last_booking_status'] = 'pending';
                flash('success', 'Reservation ' . $reference . ' submitted successfully. Your request is pending administrator approval before the schedule is secured.');
                $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                redirect('booking-success.php');
            }
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
            } catch (Throwable $ignore) {
            }
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be submitted. Please confirm that the database is installed and try again.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="public-booking-modal is-open public-booking-modal-reference" data-public-booking-modal aria-hidden="false">
  <button class="public-booking-modal-backdrop" type="button" data-public-booking-close aria-label="Close booking form"></button>
  <section class="public-booking-dialog public-booking-dialog-reference" role="dialog" aria-modal="true" aria-label="Book The Leisure Hub">
    <button class="public-booking-dialog-close public-booking-dialog-close-reference" type="button" data-public-booking-close aria-label="Close booking form">×</button>
    <div class="public-booking-dialog-scroll public-booking-dialog-scroll-reference" data-public-booking-scroll>
      <div class="form-card reservation-step-card reservation-step-card-modal reservation-step-card-reference">
<?php foreach ($errors as $error): ?><div class="alert alert-danger reservation-form-alert"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" class="reservation-step-form" data-reservation-stepper data-initial-step="<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) ? '4' : ($explicitTypeSelection ? '2' : '1') ?>" data-pricing-form data-live-availability data-public-request data-today="<?= e(date('Y-m-d')) ?>" data-availability-url="reservation-availability-check.php" data-pricing-config="<?= e(json_encode($pricingConfig)) ?>">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="end_time" value="" data-end-time>

<div class="native-booking-progress" data-native-booking-progress aria-label="Reservation progress">
  <div class="native-booking-progress-copy">
    <span data-native-step-count>Step 1 of 4</span>
    <strong data-native-step-title>Choose your booking</strong>
  </div>
  <div class="native-booking-progress-track" aria-hidden="true"><i data-native-progress-bar></i></div>
  <div class="native-booking-progress-tabs">
    <button type="button" data-step-indicator="1"><span>1</span><b>Court</b></button>
    <button type="button" data-step-indicator="2"><span>2</span><b>Schedule</b></button>
    <button type="button" data-step-indicator="3"><span>3</span><b>Details</b></button>
    <button type="button" data-step-indicator="4"><span>4</span><b>Review</b></button>
  </div>
</div>

<div class="native-booking-selection-summary" data-native-booking-selection-summary aria-live="polite">
  <span class="native-booking-selection-icon" data-native-summary-icon aria-hidden="true"><?= e($selectedTypeIcon) ?></span>
  <span><small data-native-summary-type><?= e($selectedTypeTitle) ?></small><strong data-native-summary-schedule>Choose your schedule</strong></span>
  <button type="button" data-native-edit-schedule>Edit</button>
</div>

<section class="reservation-step-panel is-active public-booking-type-screen" data-form-step="1" aria-label="Choose booking type">
<div class="booking-type-picker booking-type-picker-reference" data-booking-type-picker data-auto-advance-booking-type>
<div class="booking-type-intro">
  <span>Start your reservation</span>
  <h2>Choose Your Booking</h2>
  <p>Select the space you want to reserve. You’ll choose your date and time next.</p>
</div>
<select id="reservation_type" name="reservation_type" class="booking-type-native-select" required aria-label="Reservation type">
<option value="basketball" <?= $type === 'basketball' ? 'selected' : '' ?>>Basketball Court</option>
<option value="volleyball" <?= $type === 'volleyball' ? 'selected' : '' ?>>Volleyball Court</option>
<option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Event / Venue</option>
</select>
<div class="booking-type-grid booking-type-grid-reference">
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="basketball"><span class="booking-type-icon" aria-hidden="true">🏀</span><strong>Basketball Court</strong><small>Full indoor court</small><i aria-hidden="true">→</i></button>
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="volleyball"><span class="booking-type-icon" aria-hidden="true">🏐</span><strong>Volleyball Court</strong><small>Indoor court schedule</small><i aria-hidden="true">→</i></button>
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="event"><span class="booking-type-icon" aria-hidden="true">★</span><strong>Event / Venue</strong><small>Functions & gatherings</small><i aria-hidden="true">→</i></button>
</div>
<p class="booking-type-footnote">Tap a booking type to continue to availability.</p>
<button type="button" data-step-next hidden aria-hidden="true"></button>
</div>
</section>

<section class="reservation-step-panel public-reference-schedule-panel" data-form-step="2" aria-label="Choose schedule" hidden>
<div class="reference-booking-scheduler" data-visual-scheduler data-reference-slot-cards data-reservation-schedule data-future-only data-grid-availability-url="reservation-availability-grid.php" data-month-availability-url="reservation-availability-month.php">
  <div class="reference-booking-calendar-pane">
    <button type="button" class="mobile-schedule-step-back" data-step-back aria-label="Back to booking type"><span aria-hidden="true">‹</span><b>Back</b></button>
    <div class="reference-booking-type-summary" data-scheduler-type-summary>
      <span class="reference-booking-type-icon" data-scheduler-type-icon aria-hidden="true"><?= e($selectedTypeIcon) ?></span>
      <strong data-scheduler-type-title><?= e($selectedTypeTitle) ?></strong>
      <small data-scheduler-type-note><?= e($selectedTypeNote) ?></small>
    </div>

    <div class="event-schedule-options reference-event-schedule-options" data-event-fields>
      <div class="form-group"><label>Setup</label><select name="setup_minutes"><?php foreach ([0 => 'None', 30 => '30 min', 60 => '1 hour', 120 => '2 hours', 180 => '3 hours'] as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)($_POST['setup_minutes'] ?? 0) === $minutes ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Cleanup</label><select name="cleanup_minutes"><?php foreach ([0 => 'None', 30 => '30 min', 60 => '1 hour', 120 => '2 hours'] as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)($_POST['cleanup_minutes'] ?? 0) === $minutes ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    </div>

    <div class="mobile-schedule-duration" data-mobile-duration-picker aria-label="Choose duration">
      <label>How long do you need?</label>
      <div class="mobile-schedule-duration-buttons">
        <?php foreach ([1 => '1 hour', 2 => '2 hours', 3 => '3 hours', 4 => '4 hours'] as $durationValue => $durationLabel): ?>
        <button type="button" data-mobile-duration="<?= $durationValue ?>"><?= e($durationLabel) ?></button>
        <?php endforeach; ?>
        <select data-mobile-duration-more aria-label="More duration options">
          <option value="">More options</option>
          <?php foreach (reservation_duration_options() as $hoursValue => $hoursLabel): ?><?php if (!in_array((float)$hoursValue, [1.0,2.0,3.0,4.0], true)): ?><option value="<?= e($hoursValue) ?>"><?= e($hoursLabel) ?></option><?php endif; ?><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="visual-calendar reference-availability-calendar">
      <div class="visual-calendar-header">
        <button type="button" class="visual-calendar-nav" data-calendar-prev aria-label="Previous month">‹</button>
        <strong data-calendar-month-label><?= e($selectedReservationDate !== '' ? date('F Y', strtotime($selectedReservationDate)) : date('F Y')) ?></strong>
        <button type="button" class="visual-calendar-nav" data-calendar-next aria-label="Next month">›</button>
      </div>
      <div class="visual-calendar-weekdays"><span>SU</span><span>MO</span><span>TU</span><span>WE</span><span>TH</span><span>FR</span><span>SA</span></div>
      <div class="visual-calendar-grid" data-calendar-grid></div>
    </div>
    <input type="hidden" name="reservation_date" value="<?= e($selectedReservationDate) ?>" data-reservation-date>
    <p class="mobile-schedule-date-help">Tap an available date to see its available times.</p>
  </div>

  <div class="reference-booking-time-pane">
    <div class="mobile-time-toolbar">
      <button type="button" class="mobile-back-to-dates" data-mobile-back-to-dates><span aria-hidden="true">‹</span> Back to dates</button>
      <h2 data-mobile-time-title>Book your playing time</h2>
      <p data-mobile-time-date></p>
      <div class="mobile-time-type-card">
        <span data-mobile-time-icon aria-hidden="true"><?= e($selectedTypeIcon) ?></span>
        <strong data-mobile-time-venue><?= $type === 'event' ? 'Event Venue' : 'Whole Court' ?></strong>
        <small data-mobile-time-venue-note><?= e($selectedTypeTitle) ?></small>
      </div>
    </div>
    <div class="reference-duration-picker" data-reference-duration-picker>
      <label>How long do you need?</label>
      <select name="duration_hours" class="reference-duration-native" data-duration-hours required aria-label="Duration"><?php foreach (reservation_duration_options() as $hoursValue => $hoursLabel): ?><option value="<?= e($hoursValue) ?>" <?= abs($selectedDurationHours - (float)$hoursValue) < 0.001 ? 'selected' : '' ?>><?= e($hoursLabel) ?></option><?php endforeach; ?></select>
      <div class="reference-duration-buttons">
        <?php foreach ([1 => '1 hour', 2 => '2 hours', 3 => '3 hours', 4 => '4 hours'] as $durationValue => $durationLabel): ?>
        <button type="button" data-reference-duration="<?= $durationValue ?>"><?= e($durationLabel) ?></button>
        <?php endforeach; ?>
        <select data-reference-duration-more aria-label="More duration options">
          <option value="">More</option>
          <?php foreach (reservation_duration_options() as $hoursValue => $hoursLabel): ?><?php if (!in_array((float)$hoursValue, [1.0,2.0,3.0,4.0], true)): ?><option value="<?= e($hoursValue) ?>"><?= e($hoursLabel) ?></option><?php endif; ?><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mobile-time-availability-note" aria-label="Available time information">
      <span class="mobile-time-availability-dot" aria-hidden="true"></span>
      <div>
        <strong>Available start times</strong>
        <small>Only available time slots are shown. Tap a time to select it.</small>
      </div>
    </div>

    <div class="reference-time-list-wrap">
      <span class="reference-time-label">Time</span>
      <div class="reference-time-list" data-reference-time-list>
        <div class="reference-time-empty">Choose a date.</div>
      </div>
      <select name="start_time" class="reference-time-native" data-start-time data-last-chosen-value="<?= e($selectedStartTime) ?>" required aria-label="Start time"><option value="">Choose a date first</option></select>
    </div>

    <div class="schedule-availability booking-live-status-visually-hidden" data-live-availability-status data-state="idle" role="status" aria-live="polite">
      <span class="schedule-availability-icon" aria-hidden="true">○</span>
      <div><strong>Choose your schedule</strong><p>Select an available date and time.</p></div>
    </div>

    <div class="reference-schedule-actions">
      <button type="button" class="reference-back-button" data-step-back aria-label="Back">Back</button>
      <button type="button" class="btn btn-primary" data-step-next>Continue</button>
    </div>
  </div>
</div>
</section>

<section class="reservation-step-panel" data-form-step="3" aria-label="Booking details" hidden>

<div class="form-grid">
<div class="form-group"><label>Expected Guests *</label><input type="number" min="1" max="300" name="guest_count" value="<?= e($_POST['guest_count'] ?? '') ?>" data-guest-count required></div>
<div class="form-group"><label>Cooling Option *</label><select name="cooling_option" data-cooling-option required><?php foreach (reservation_cooling_options() as $value => $label): ?><option value="<?= e($value) ?>" <?= $selectedCooling === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div class="form-group full"><div class="package-preview" data-package-preview aria-live="polite"><span>Package</span><strong data-price-package>Enter guest count</strong><b data-price-total>—</b><p data-price-message>Enter your expected guests to identify the applicable package.</p></div></div>
<div class="form-group"><label>Client / Contact Name *</label><input name="client_name" value="<?= e($_POST['client_name'] ?? '') ?>" autocomplete="name" required></div>
<div class="form-group"><label>Mobile Number *</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>" autocomplete="tel" required></div>
<div class="form-group"><label>Email Address <span class="optional-label">Optional</span></label><input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email"></div>
<div class="form-group"><label>Organization / Team <span class="optional-label">Optional</span></label><input name="organization" value="<?= e($_POST['organization'] ?? '') ?>"></div>
<div data-sport-fields class="form-group full"><label>Booking Purpose</label><select name="sport_purpose"><?php foreach (['Practice', 'Friendly Game', 'Training', 'League or Tournament', 'Other'] as $purposeOption): ?><option value="<?= e($purposeOption) ?>" <?= ($_POST['sport_purpose'] ?? 'Practice') === $purposeOption ? 'selected' : '' ?>><?= e($purposeOption) ?></option><?php endforeach; ?></select></div>
<div data-event-fields class="form-group full"><label>Event Type</label><input name="event_purpose" value="<?= e($_POST['event_purpose'] ?? '') ?>" placeholder="Birthday, seminar, reception..."></div>
<div class="form-group full pricing-addons"><label>Additional Services</label><div class="addon-grid"><label class="checkbox-option"><input type="checkbox" name="shower_room_addon" value="1" data-shower-room <?= isset($_POST['shower_room_addon']) ? 'checked' : '' ?>><span><strong>Shower Room</strong><small><?= money($pricingConfig['shower_room_fee']) ?> per reservation</small></span></label><label class="checkbox-option"><input type="checkbox" name="equipment_bundle_addon" value="1" data-equipment-bundle <?= isset($_POST['equipment_bundle_addon']) ? 'checked' : '' ?>><span><strong>Equipment Bundle</strong><small data-equipment-bundle-label><?= money($pricingConfig['equipment_bundle_fee']) ?> per hour for regular bookings</small></span></label></div></div>
<div class="form-group full"><label>Additional Requests or Instructions <span class="optional-label">Optional</span></label><textarea name="additional_requests" placeholder="Tell us about venue setup, tables, chairs, equipment, or other requests."><?= e($_POST['additional_requests'] ?? '') ?></textarea></div>
</div>
<div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button type="button" class="btn btn-primary" data-step-next>Continue</button></div>
</section>

<section class="reservation-step-panel" data-form-step="4" aria-label="Payment and review" hidden>

<div class="payment-choice-grid" data-payment-choice-group>
<label class="payment-choice"><input type="radio" name="payment_choice" value="none" data-payment-choice required <?= $selectedPaymentChoice === 'none' ? 'checked' : '' ?>><span><strong>No payment yet</strong></span></label>
<label class="payment-choice"><input type="radio" name="payment_choice" value="partial" data-payment-choice <?= $selectedPaymentChoice === 'partial' ? 'checked' : '' ?>><span><strong>Partial payment</strong></span></label>
<label class="payment-choice"><input type="radio" name="payment_choice" value="full" data-payment-choice <?= $selectedPaymentChoice === 'full' ? 'checked' : '' ?>><span><strong>Full payment</strong></span></label>
</div>
<div class="form-grid payment-fields" data-payment-fields <?= in_array($selectedPaymentChoice, ['partial', 'full'], true) ? '' : 'hidden' ?>>
<div class="form-group"><label>Payment Method *</label><select name="booking_payment_method" data-booking-payment-method><option value="">Select payment method</option><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= ($_POST['booking_payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
<div class="form-group full" data-partial-payment-field><label>Partial Payment Amount *</label><input type="number" min="0.01" step="0.01" name="payment_amount" value="<?= e($_POST['payment_amount'] ?? '') ?>" data-payment-amount placeholder="Enter the amount you intend to pay"></div>
<div class="full-payment-note form-group full" data-full-payment-note hidden>The full payment amount will automatically match the calculated reservation total.</div>
</div>

<div class="reservation-review-card">
<div class="reservation-review-heading"><div><strong>Booking Summary</strong></div><strong data-review-total>—</strong></div>
<div class="reservation-charge-breakdown" data-review-charge-breakdown>
<div class="reservation-charge-heading"><div><span>Charges</span></div></div>
<div class="reservation-charge-row">
<div><strong data-review-base-charge-label>Venue Rental</strong><small data-review-base-charge-detail>Choose a valid schedule and guest count.</small></div>
<strong data-review-base-charge-amount>—</strong>
</div>
<div class="reservation-charge-row" data-review-shower-charge-row hidden>
<div><strong>Shower Room</strong><small>One-time fee per reservation</small></div>
<strong data-review-shower-charge-amount>—</strong>
</div>
<div class="reservation-charge-row" data-review-equipment-charge-row hidden>
<div><strong>Equipment Bundle</strong><small data-review-equipment-charge-detail>Hourly equipment fee</small></div>
<strong data-review-equipment-charge-amount>—</strong>
</div>
<div class="reservation-charge-row reservation-charge-total">
<div><strong>Total Reservation Amount</strong><small>Client-selected payment intention</small></div>
<strong data-review-charge-total>—</strong>
</div>
</div>
<div class="reservation-review-grid">
<div><span>Schedule</span><strong data-review-schedule>Choose a date and time</strong></div>
<div><span>Reservation</span><strong data-review-type>Basketball Court</strong></div>
<div><span>Package</span><strong data-review-package>Enter guest count</strong></div>
<div><span>Cooling</span><strong data-review-cooling>Fan with Lights</strong></div>
<div><span>Add-ons</span><strong data-review-addons>None</strong></div>
<div><span>Payment</span><strong data-review-payment>No payment yet</strong></div>
<div><span>Intended Payment</span><strong data-review-paid>₱0.00</strong></div>
<div><span>Balance After Admin Records Payment</span><strong data-review-balance>—</strong></div>
</div>
<div class="review-inclusions"><span>Package Inclusions</span><ul data-review-inclusions><li>Exclusive venue use during the reserved schedule</li></ul></div>
</div>

<div class="form-group terms-check"><label><input type="checkbox" name="terms" value="1" required <?= isset($_POST['terms']) ? 'checked' : '' ?>><span>I understand that submitting this request does not hold the schedule. The time slot is secured only after admin approval and remains subject to availability, payment verification, and the <a href="terms.php" target="_blank" rel="noopener">venue policies, including the 50% client cancellation charge</a>.</span></label></div>
<div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button class="btn btn-primary" type="submit">Submit Request</button></div>
</section>
</form>
      </div>
    </div>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
