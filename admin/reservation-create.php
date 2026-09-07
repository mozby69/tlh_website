<?php
/**
 * FILE PURPOSE: Three-step staff/walk-in reservation creation workflow.
 * DEBUGGING: Live availability is only a convenience check. The final POST must validate again and acquire the shared booking lock before insert.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'New Reservation';
$errors = [];
$startTimeSlots = reservation_start_time_slots();
$selectedReservationDate = trim($_POST['reservation_date'] ?? '');
$selectedStartTime = trim($_POST['start_time'] ?? '08:00');
$selectedDurationHours = reservation_duration_hours_from_input($_POST['duration_hours'] ?? 2) ?? 2.0;
$selectedCooling = trim($_POST['cooling_option'] ?? 'fan');
$selectedPaymentChoice = trim($_POST['payment_choice'] ?? '');
$allowPastDates = isset($_POST['allow_past_dates']);
$todayDate = date('Y-m-d');
$selectedPaymentPaidAt = trim($_POST['booking_paid_at'] ?? date('Y-m-d\TH:i'));
$pricingConfig = reservation_pricing_config();

// FINAL CREATE PATH: server-side validation below is authoritative. The browser
// live checker improves UX but can be stale by the time the user clicks Create.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = $_POST['reservation_type'] ?? '';
    $client = trim($_POST['client_name'] ?? '');
    $org = trim($_POST['organization'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $source = $_POST['source'] ?? 'walk_in';
    $status = $_POST['status'] ?? 'approved';
    $paymentChoice = trim($_POST['payment_choice'] ?? '');
    $selectedPaymentChoice = $paymentChoice;
    $bookingPaymentMethod = trim($_POST['booking_payment_method'] ?? '');
    $bookingPaymentReference = trim($_POST['booking_payment_reference'] ?? '');
    $paymentAmountRaw = trim($_POST['payment_amount'] ?? '');
    $applyDiscount = isset($_POST['apply_discount']);
    $discountAmountRaw = trim($_POST['flexible_discount_amount'] ?? '');
    $discountReason = trim($_POST['discount_reason'] ?? '');
    $bookingPaidAtRaw = trim($_POST['booking_paid_at'] ?? '');
    $selectedPaymentPaidAt = $bookingPaidAtRaw !== '' ? $bookingPaidAtRaw : date('Y-m-d\TH:i');
    $paymentAmount = 0.0;
    $bookingPaidAt = null;
    $purpose = $type === 'event' ? trim($_POST['event_purpose'] ?? '') : trim($_POST['sport_purpose'] ?? '');
    $setup = $type === 'event' ? max(0, (int)($_POST['setup_minutes'] ?? 0)) : 0;
    $cleanup = $type === 'event' ? max(0, (int)($_POST['cleanup_minutes'] ?? 0)) : 0;
    $additionalRequests = trim($_POST['additional_requests'] ?? '');
    $guestCountRaw = trim($_POST['guest_count'] ?? '');
    $guests = ctype_digit($guestCountRaw) ? (int)$guestCountRaw : 0;
    $coolingOption = trim($_POST['cooling_option'] ?? '');
    $selectedCooling = $coolingOption;
    $showerRoom = isset($_POST['shower_room_addon']);
    $showerRoomComplimentary = $showerRoom && isset($_POST['shower_room_complimentary']);
    $equipmentBundle = isset($_POST['equipment_bundle_addon']);
    $equipmentBundleComplimentary = $equipmentBundle && isset($_POST['equipment_bundle_complimentary']);
    $reservationDate = trim($_POST['reservation_date'] ?? '');
    $startTimeValue = trim($_POST['start_time'] ?? '');
    $durationHoursRaw = trim($_POST['duration_hours'] ?? '');
    $durationHours = reservation_duration_hours_from_input($durationHoursRaw);
    $selectedReservationDate = $reservationDate;
    $selectedStartTime = $startTimeValue;
    $selectedDurationHours = $durationHours ?? 2.0;

    if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
        $errors[] = 'Choose a valid reservation type.';
    }
    if ($client === '') {
        $errors[] = 'Client name is required.';
    }
    if ($phone === '') {
        $errors[] = 'Mobile number is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    if ($guests < 1 || reservation_package_for_guests($guests) === null) {
        $errors[] = 'Guest count must be between 1 and 300 guests.';
    }
    if (!array_key_exists($coolingOption, reservation_cooling_options())) {
        $errors[] = 'Choose Fan with Lights or Aircon with Lights.';
    }
    if (!in_array($source, ['walk_in', 'internal', 'website'], true)) {
        $source = 'walk_in';
    }
    if (!in_array($status, ['pending', 'for_review', 'approved', 'completed'], true)) {
        $status = 'approved';
    }
    if (!in_array($paymentChoice, ['none', 'partial', 'full'], true)) {
        $errors[] = 'Choose No payment yet, Partial payment, or Full payment.';
    }
    if ($paymentChoice !== 'none') {
        if (!in_array($bookingPaymentMethod, booking_payment_methods(), true)) {
            $errors[] = 'Choose a valid payment method.';
        }
        if (in_array($bookingPaymentMethod, booking_payment_methods(), true) && $bookingPaymentMethod !== 'Cash' && $bookingPaymentReference === '') {
            $errors[] = 'Enter a transaction reference or OR number for non-cash payments.';
        }
        if ($paymentChoice === 'partial') {
            if (!is_numeric($paymentAmountRaw) || (float)$paymentAmountRaw <= 0) {
                $errors[] = 'Enter a valid partial payment amount.';
            } else {
                $paymentAmount = round((float)$paymentAmountRaw, 2);
            }
        }
        try {
            $bookingPaidAt = $bookingPaidAtRaw !== '' ? new DateTimeImmutable($bookingPaidAtRaw) : new DateTimeImmutable();
        } catch (Throwable $e) {
            $errors[] = 'Enter a valid payment date and time.';
        }
    } else {
        $bookingPaymentMethod = '';
        $bookingPaymentReference = '';
    }
    if (strlen($bookingPaymentReference) > 120) {
        $errors[] = 'The payment reference number may not exceed 120 characters.';
    }
    if (strlen($discountReason) > 255) {
        $errors[] = 'The discount note may not exceed 255 characters.';
    }

    $scheduleIsValid = true;
    if (!reservation_date_is_valid($reservationDate)) {
        $errors[] = 'Choose a valid reservation date.';
        $scheduleIsValid = false;
    }
    if (!array_key_exists($startTimeValue, $startTimeSlots)) {
        $errors[] = 'Choose a valid 30-minute starting time.';
        $scheduleIsValid = false;
    }
    if ($durationHours === null) {
        $errors[] = 'Choose a valid reservation duration.';
        $scheduleIsValid = false;
    }

    if ($scheduleIsValid) {
        $start = new DateTimeImmutable($reservationDate . ' ' . $startTimeValue . ':00');
        $end = reservation_add_duration($start, $durationHours);
        if (!$allowPastDates && $start < new DateTimeImmutable()) {
            $errors[] = 'Enable Past Date Selection to enter a reservation date or time that has already passed.';
            $scheduleIsValid = false;
            $start = $end = null;
        } elseif ($end->format('Y-m-d') !== $reservationDate || $end->format('H:i') > '22:00') {
            $errors[] = 'The selected duration extends beyond the 10:00 PM closing time.';
            $scheduleIsValid = false;
            $start = $end = null;
        }
    } else {
        $start = $end = null;
    }

    $pricing = null;
    $grossTotal = 0.0;
    $netTotal = 0.0;
    $discountAmount = 0.0;
    if ($start && $end && reservation_package_for_guests($guests) !== null && array_key_exists($coolingOption, reservation_cooling_options())) {
        try {
            $pricing = calculate_reservation_pricing(
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $guests,
                $coolingOption,
                $showerRoom,
                $equipmentBundle,
                false,
                $showerRoomComplimentary,
                $equipmentBundleComplimentary
            );
            $grossTotal = round((float)$pricing['total'], 2);
            $netTotal = $grossTotal;
            $discountAmount = 0.0;
            if ($applyDiscount) {
                if (!is_numeric($discountAmountRaw)) {
                    $errors[] = 'Enter a valid discount amount.';
                } else {
                    $discountAmount = round((float)$discountAmountRaw, 2);
                    if ($discountAmount < 0.01 || $discountAmount + 0.001 >= $grossTotal) {
                        $errors[] = 'The discount must be at least ' . money(0.01) . ' and lower than the calculated total of ' . money($grossTotal) . '.';
                    } else {
                        $netTotal = round($grossTotal - $discountAmount, 2);
                    }
                }
            }
            if ($paymentChoice === 'full') {
                $paymentAmount = $netTotal;
            } elseif ($paymentChoice === 'partial' && $paymentAmount + 0.001 >= $netTotal) {
                $errors[] = 'For the full client payable amount of ' . money($netTotal) . ', choose Full Payment.';
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors && $start && $end && $pricing !== null) {
        // Legacy-entry convenience: an approved reservation whose event has already
        // ended is stored as Completed, while future dates keep the selected status.
        if ($status === 'approved' && $end < new DateTimeImmutable()) {
            $status = 'completed';
        }
        $blockedStart = $start->modify('-' . $setup . ' minutes');
        $blockedEnd = $end->modify('+' . $cleanup . ' minutes');
        $pdo = db();

        try {
            // Concurrency guard: only one final venue-booking write may perform its
            // conflict-check/insert sequence at a time. Never remove this lock.
            $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please try creating the reservation again.');
            }
            if (reservation_conflict($blockedStart->format('Y-m-d H:i:s'), $blockedEnd->format('Y-m-d H:i:s'), null, $end < new DateTimeImmutable())) {
                $errors[] = 'The selected schedule overlaps an active reservation.';
            } else {
                $pdo->beginTransaction();
                $ref = generate_reference();
                $paymentStatus = payment_status_for_amount($paymentAmount, $netTotal);

                $stmt = $pdo->prepare("INSERT INTO reservations(
                    reference_no,reservation_type,purpose,client_name,organization,email,phone,
                    booking_payment_choice,booking_payment_method,booking_payment_intent_amount,booking_payment_reference,event_start,event_end,
                    setup_minutes,cleanup_minutes,blocked_start,blocked_end,guest_count,
                    pricing_package,cooling_option,rate_period,hourly_rate,billable_hours,
                    shower_room_addon,shower_room_fee,equipment_bundle_addon,equipment_bundle_fee,pricing_snapshot,
                    details,equipment_requests,special_instructions,estimated_amount,final_amount,discount_amount,discount_reason,amount_paid,payment_status,status,source,created_by
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $ref, $type, $purpose !== '' ? $purpose : null, $client, $org !== '' ? $org : null,
                    $email ?: 'walkin@local.invalid', $phone,
                    $paymentChoice,
                    $bookingPaymentMethod !== '' ? $bookingPaymentMethod : null,
                    $paymentAmount,
                    $bookingPaymentReference !== '' ? $bookingPaymentReference : null,
                    $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $setup, $cleanup,
                    $blockedStart->format('Y-m-d H:i:s'), $blockedEnd->format('Y-m-d H:i:s'), $guests,
                    $pricing['package'], $pricing['cooling_option'], $pricing['rate_period'], $pricing['hourly_rate'], $pricing['billable_hours'],
                    $pricing['shower_room_addon'] ? 1 : 0, $pricing['shower_room_fee'],
                    $pricing['equipment_bundle_addon'] ? 1 : 0, $pricing['equipment_bundle_fee'], reservation_pricing_snapshot($pricing),
                    $additionalRequests !== '' ? $additionalRequests : null, null, null,
                    $grossTotal, $applyDiscount ? $netTotal : null, $discountAmount, $discountAmount > 0 ? ($discountReason !== '' ? $discountReason : null) : null, $paymentAmount, $paymentStatus,
                    $status, $source, current_admin()['id'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                if ($paymentAmount > 0) {
                    $paymentStmt = $pdo->prepare('INSERT INTO payments(reservation_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)');
                    $paymentStmt->execute([
                        $newId, $paymentAmount, $bookingPaymentMethod, $bookingPaymentReference ?: null,
                        'Initial payment recorded when the reservation was created.',
                        current_admin()['id'], ($bookingPaidAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                }

                $pdo->commit();
                $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                flash('success', 'Reservation ' . $ref . ' created.');
                redirect('reservation-view.php?id=' . $newId);
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
            $errors[] = 'Unable to create the reservation.';
        }
    }
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-step-card admin-reservation-step-card">
<div class="panel-head"><div><h2>Create Walk-in or Internal Booking</h2><p class="muted">Complete three short steps. Pricing and schedule checks are applied automatically.</p></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" style="width:100%;margin:10px 0"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" class="reservation-step-form" data-reservation-stepper data-initial-step="<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) ? '4' : '1' ?>" data-pricing-form data-live-availability data-admin-past-date-control data-admin-allow-past="<?= $allowPastDates ? '1' : '0' ?>" data-today="<?= e($todayDate) ?>" data-availability-url="../reservation-availability-check.php" data-pricing-config="<?= e(json_encode($pricingConfig)) ?>">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="end_time" value="" data-end-time>

<nav class="reservation-steps reservation-steps-four" aria-label="Reservation progress">
<button type="button" class="reservation-step-indicator is-active" data-step-indicator="1"><span>1</span><strong>Booking Type</strong></button>
<button type="button" class="reservation-step-indicator" data-step-indicator="2"><span>2</span><strong>Schedule</strong></button>
<button type="button" class="reservation-step-indicator" data-step-indicator="3"><span>3</span><strong>Details</strong></button>
<button type="button" class="reservation-step-indicator" data-step-indicator="4"><span>4</span><strong>Payment & Review</strong></button>
</nav>

<section class="reservation-step-panel is-active admin-booking-type-screen" data-form-step="1" aria-label="Choose booking type">
<div class="booking-type-picker booking-type-picker-reference" data-booking-type-picker data-auto-advance-booking-type>
<div class="booking-type-intro">
  <span>Reservation Type</span>
  <h2>Choose Your Booking</h2>
  <p>Select a booking category to continue to scheduling and details.</p>
</div>
<select name="reservation_type" id="reservation_type" class="booking-type-native-select" required aria-label="Reservation type">
<option value="basketball" <?= ($_POST['reservation_type'] ?? 'basketball') === 'basketball' ? 'selected' : '' ?>>Basketball Court</option>
<option value="volleyball" <?= ($_POST['reservation_type'] ?? '') === 'volleyball' ? 'selected' : '' ?>>Volleyball Court</option>
<option value="event" <?= ($_POST['reservation_type'] ?? '') === 'event' ? 'selected' : '' ?>>Event / Venue</option>
</select>
<div class="booking-type-grid booking-type-grid-reference admin-booking-type-grid-reference">
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="basketball"><span class="booking-type-icon" aria-hidden="true">🏀</span><strong>Basketball Court</strong></button>
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="volleyball"><span class="booking-type-icon" aria-hidden="true">🏐</span><strong>Volleyball Court</strong></button>
<button type="button" class="booking-type-card booking-type-card-reference" data-booking-type-option="event"><span class="booking-type-icon" aria-hidden="true">★</span><strong>Event / Venue</strong></button>
</div>
<button type="button" data-step-next hidden aria-hidden="true"></button>
</div>
</section>

<section class="reservation-step-panel admin-reference-schedule-panel" data-form-step="2" aria-label="Choose schedule" hidden>
<div class="admin-reference-schedule-toolbar">
  <label class="admin-past-date-switch">
    <input type="checkbox" name="allow_past_dates" value="1" data-past-date-toggle <?= $allowPastDates ? 'checked' : '' ?>>
    <span><strong>Past Date Selection</strong><small>Allow historical schedules</small></span>
  </label>
</div>

<div class="reference-booking-scheduler admin-reference-booking-scheduler" data-visual-scheduler data-reference-slot-cards data-reservation-schedule<?= $allowPastDates ? '' : ' data-future-only' ?> data-grid-availability-url="../reservation-availability-grid.php" data-month-availability-url="../reservation-availability-month.php">
  <div class="reference-booking-calendar-pane">
    <div class="reference-booking-type-summary" data-scheduler-type-summary>
      <span class="reference-booking-type-icon" data-scheduler-type-icon aria-hidden="true">🏀</span>
      <strong data-scheduler-type-title>Basketball Court</strong>
    </div>

    <div class="event-schedule-options reference-event-schedule-options" data-event-fields>
      <div class="form-group"><label>Setup</label><select name="setup_minutes"><?php foreach ([0 => 'None', 30 => '30 min', 60 => '1 hour', 120 => '2 hours', 180 => '3 hours'] as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)($_POST['setup_minutes'] ?? 0) === $minutes ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Cleanup</label><select name="cleanup_minutes"><?php foreach ([0 => 'None', 30 => '30 min', 60 => '1 hour', 120 => '2 hours'] as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)($_POST['cleanup_minutes'] ?? 0) === $minutes ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
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
    <input type="hidden" name="reservation_date" value="<?= e($selectedReservationDate) ?>" data-reservation-date data-admin-past-date-field>
  </div>

  <div class="reference-booking-time-pane">
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

<section class="reservation-step-panel" data-form-step="3" aria-labelledby="adminStepDetailsTitle" hidden>
<div class="reservation-step-title"><span>Step 3 of 4</span><h3 id="adminStepDetailsTitle">Booking details and pricing</h3><p>Enter the client information, commercial options, and any optional services.</p></div>
<div class="form-grid">
<div class="form-group"><label>Expected Guests *</label><input type="number" min="1" max="300" name="guest_count" value="<?= e($_POST['guest_count'] ?? '') ?>" data-guest-count required><span class="field-help">Accepted range: 1–300 guests. The package is selected automatically.</span></div>
<div class="form-group"><label>Cooling Option *</label><select name="cooling_option" data-cooling-option required><?php foreach (reservation_cooling_options() as $value => $label): ?><option value="<?= e($value) ?>" <?= $selectedCooling === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div class="form-group full"><div class="package-preview" data-package-preview aria-live="polite"><span>Package</span><strong data-price-package>Enter guest count</strong><b data-price-total>—</b><p data-price-message>Enter the expected guests to identify the applicable package.</p></div></div>
<div class="form-group"><label>Client / Contact Name *</label><input name="client_name" value="<?= e($_POST['client_name'] ?? '') ?>" required></div>
<div class="form-group"><label>Mobile Number *</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>" required></div>
<div class="form-group"><label>Email Address <span class="optional-label">Optional</span></label><input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
<div class="form-group"><label>Organization / Team <span class="optional-label">Optional</span></label><input name="organization" value="<?= e($_POST['organization'] ?? '') ?>"></div>
<div class="form-group"><label>Source</label><select name="source"><option value="walk_in" <?= ($_POST['source'] ?? 'walk_in') === 'walk_in' ? 'selected' : '' ?>>Walk-in</option><option value="internal" <?= ($_POST['source'] ?? '') === 'internal' ? 'selected' : '' ?>>Internal</option><option value="website" <?= ($_POST['source'] ?? '') === 'website' ? 'selected' : '' ?>>Website Assisted</option></select></div>
<div class="form-group"><label>Initial Status</label><select name="status"><option value="approved" <?= ($_POST['status'] ?? 'approved') === 'approved' ? 'selected' : '' ?>>Approved</option><option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option><option value="for_review" <?= ($_POST['status'] ?? '') === 'for_review' ? 'selected' : '' ?>>For Review</option><option value="pending" <?= ($_POST['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option></select></div>
<div data-sport-fields class="form-group full"><label>Booking Purpose</label><select name="sport_purpose"><?php foreach (['Practice', 'Friendly Game', 'Training', 'League or Tournament', 'Other'] as $purposeOption): ?><option value="<?= e($purposeOption) ?>" <?= ($_POST['sport_purpose'] ?? 'Practice') === $purposeOption ? 'selected' : '' ?>><?= e($purposeOption) ?></option><?php endforeach; ?></select></div>
<div data-event-fields class="form-group full"><label>Event Type</label><input name="event_purpose" value="<?= e($_POST['event_purpose'] ?? '') ?>" placeholder="Birthday, seminar, reception..."></div>
<div class="form-group full pricing-addons"><label>Additional Services</label><div class="addon-grid"><label class="checkbox-option"><input type="checkbox" name="shower_room_addon" value="1" data-shower-room <?= isset($_POST['shower_room_addon']) ? 'checked' : '' ?>><span><strong>Shower Room</strong><small><?= money($pricingConfig['shower_room_fee']) ?> per reservation</small></span></label><label class="checkbox-option"><input type="checkbox" name="shower_room_complimentary" value="1" data-shower-room-complimentary <?= isset($_POST['shower_room_complimentary']) ? 'checked' : '' ?>><span><strong>Complimentary Shower Room</strong><small>Waive the shower fee while keeping access included</small></span></label><label class="checkbox-option"><input type="checkbox" name="equipment_bundle_addon" value="1" data-equipment-bundle <?= isset($_POST['equipment_bundle_addon']) ? 'checked' : '' ?>><span><strong>Equipment Bundle</strong><small data-equipment-bundle-label><?= money($pricingConfig['equipment_bundle_fee']) ?> per hour for regular bookings</small></span></label><label class="checkbox-option"><input type="checkbox" name="equipment_bundle_complimentary" value="1" data-equipment-bundle-complimentary <?= isset($_POST['equipment_bundle_complimentary']) && isset($_POST['equipment_bundle_addon']) ? 'checked' : '' ?>><span><strong>Complimentary Equipment Bundle</strong><small>Waive the hourly equipment fee while keeping the bundle included</small></span></label></div></div>
<div class="form-group full"><label>Additional Requests or Instructions <span class="optional-label">Optional</span></label><textarea name="additional_requests" placeholder="Venue setup, tables, chairs, equipment, or other requests."><?= e($_POST['additional_requests'] ?? '') ?></textarea></div>
</div>
<div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button type="button" class="btn btn-primary" data-step-next>Continue to Payment</button></div>
</section>

<section class="reservation-step-panel" data-form-step="4" aria-labelledby="adminStepPaymentTitle" hidden>
<div class="reservation-step-title"><span>Step 4 of 4</span><h3 id="adminStepPaymentTitle">Payment and review</h3><p>Record no payment, a partial payment, or the full calculated amount.</p></div>
<div class="payment-required-notice" role="alert" aria-live="polite"><span class="payment-required-icon" aria-hidden="true">!</span><div><strong>Required Payment Selection</strong><p>Please choose how this reservation will be paid before creating it.</p></div></div>
<div class="payment-choice-grid" data-payment-choice-group>
<label class="payment-choice"><input type="radio" name="payment_choice" value="none" data-payment-choice required <?= $selectedPaymentChoice === 'none' ? 'checked' : '' ?>><span><strong>No payment yet</strong><small>Create the reservation as unpaid.</small></span></label>
<label class="payment-choice"><input type="radio" name="payment_choice" value="partial" data-payment-choice <?= $selectedPaymentChoice === 'partial' ? 'checked' : '' ?>><span><strong>Partial payment</strong><small>Record an initial amount.</small></span></label>
<label class="payment-choice"><input type="radio" name="payment_choice" value="full" data-payment-choice <?= $selectedPaymentChoice === 'full' ? 'checked' : '' ?>><span><strong>Full payment</strong><small>Use the exact calculated total.</small></span></label>
</div>
<div class="flexible-discount-card" data-discount-control>
  <label class="checkbox-option flexible-discount-toggle"><input type="checkbox" name="apply_discount" value="1" data-apply-discount <?= isset($_POST['apply_discount']) ? 'checked' : '' ?>><span><strong>Apply Flexible Discount</strong><small>Enter the discount amount to subtract from the calculated total.</small></span></label>
  <div class="form-grid flexible-discount-fields" data-discount-fields <?= isset($_POST['apply_discount']) ? '' : 'hidden' ?>>
    <div class="form-group"><label>Discount Amount *</label><input type="number" min="0.01" step="0.01" name="flexible_discount_amount" value="<?= e($_POST['flexible_discount_amount'] ?? '') ?>" data-discount-input placeholder="Example: 500.00"><span class="field-help">Example: calculated total ₱1,500 minus a ₱500 discount = ₱1,000 client payable.</span></div>
    <div class="form-group"><label>Discount Note <span class="optional-label">Optional</span></label><input type="text" maxlength="255" name="discount_reason" value="<?= e($_POST['discount_reason'] ?? '') ?>" placeholder="Courtesy discount, management approval, promo..."></div>
  </div>
  <div class="flexible-discount-summary" data-discount-summary hidden><span>Calculated Total <strong data-discount-gross>—</strong></span><span>Discount <strong data-discount-amount>—</strong></span><span>Client Payable <strong data-discount-net>—</strong></span></div>
</div>
<div class="form-grid payment-fields" data-payment-fields <?= in_array($selectedPaymentChoice, ['partial', 'full'], true) ? '' : 'hidden' ?>>
<div class="form-group"><label>Payment Method *</label><select name="booking_payment_method" data-booking-payment-method><option value="">Select payment method</option><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= ($_POST['booking_payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Reference / OR Number</label><input name="booking_payment_reference" maxlength="120" value="<?= e($_POST['booking_payment_reference'] ?? '') ?>" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional for cash; required for non-cash payments.</span></div>
<div class="form-group"><label>Payment Date &amp; Time *</label><input type="datetime-local" name="booking_paid_at" value="<?= e($selectedPaymentPaidAt) ?>" required><span class="field-help">For older bookings, enter when the payment was actually received.</span></div>
<div class="form-group full" data-partial-payment-field><label>Partial Payment Amount *</label><input type="number" min="0.01" step="0.01" name="payment_amount" value="<?= e($_POST['payment_amount'] ?? '') ?>" data-payment-amount placeholder="Enter the amount already paid"><span class="field-help">Must be lower than the reservation total.</span></div>
<div class="full-payment-note form-group full" data-full-payment-note hidden>The full payment amount will automatically match the client payable amount after discount.</div>
</div>

<div class="reservation-review-card">
<div class="reservation-review-heading"><div><span class="eyebrow">Booking Summary</span><h3>Review before creating</h3></div><strong data-review-total>—</strong></div>
<div class="reservation-charge-breakdown" data-review-charge-breakdown>
<div class="reservation-charge-heading"><div><span>Itemized Reservation Charges</span><small>The venue rental and every chargeable add-on are listed separately.</small></div></div>
<div class="reservation-charge-row">
<div><strong data-review-base-charge-label>Venue Rental</strong><small data-review-base-charge-detail>Choose a valid schedule and guest count.</small></div>
<strong data-review-base-charge-amount>—</strong>
</div>
<div class="reservation-charge-row" data-review-shower-charge-row hidden>
<div><strong>Shower Room</strong><small data-review-shower-charge-detail>One-time fee per reservation</small></div>
<strong data-review-shower-charge-amount>—</strong>
</div>
<div class="reservation-charge-row" data-review-equipment-charge-row hidden>
<div><strong>Equipment Bundle</strong><small data-review-equipment-charge-detail>Hourly equipment fee</small></div>
<strong data-review-equipment-charge-amount>—</strong>
</div>
<div class="reservation-charge-row reservation-charge-total">
<div><strong>Total Reservation Amount</strong><small>Before applying the initial payment</small></div>
<strong data-review-charge-total>—</strong>
</div>
</div>
<div class="reservation-review-grid">
<div><span>Schedule</span><strong data-review-schedule>Choose a date and time</strong></div>
<div><span>Reservation</span><strong data-review-type>Basketball Court</strong></div>
<div><span>Package</span><strong data-review-package>Enter guest count</strong></div>
<div><span>Cooling</span><strong data-review-cooling>Fan with Lights</strong></div>
<div><span>Add-ons</span><strong data-review-addons>None</strong></div>
<div><span>Discount</span><strong data-review-discount>₱0.00</strong></div>
<div><span>Client Payable</span><strong data-review-net-total>—</strong></div>
<div><span>Payment</span><strong data-review-payment>No payment yet</strong></div>
<div><span>Initial Payment</span><strong data-review-paid>₱0.00</strong></div>
<div><span>Remaining Balance</span><strong data-review-balance>—</strong></div>
</div>
<div class="review-inclusions"><span>Package Inclusions</span><ul data-review-inclusions><li>Exclusive venue use during the reserved schedule</li></ul></div>
</div>

<div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button class="btn btn-primary" type="submit">Create Reservation</button></div>
</section>
</form>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
