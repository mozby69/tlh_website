<?php
/**
 * FILE PURPOSE: Edits non-schedule reservation details for an existing active reservation.
 * DEBUGGING: Schedule changes belong in Reschedule. Served/ended reservations are locked by reservation_can_edit()/reservation_has_ended().
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    redirect('reservations.php');
}

try {
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id=?');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
} catch (Throwable $e) {
    $booking = false;
}

if (!$booking) {
    flash('danger', 'Reservation not found.');
    redirect('reservations.php');
}

if (reservation_is_archived($booking)) {
    flash('danger', 'Restore this reservation from Archives before editing it.');
    redirect('reservation-view.php?id=' . $id);
}

if ((string)$booking['status'] === 'cancelled') {
    flash('danger', 'Cancelled reservations are locked to protect the 50% cancellation settlement.');
    redirect('reservation-view.php?id=' . $id);
}

if (reservation_has_ended($booking)) {
    flash('danger', 'This reservation has already ended. Served reservations are locked and can no longer be edited.');
    redirect('reservation-view.php?id=' . $id);
}

$adminPageTitle = 'Edit ' . $booking['reference_no'];
$errors = [];
$startTimeSlots = reservation_start_time_slots();
$endTimeSlots = reservation_end_time_slots();
$pricingConfig = reservation_pricing_config();
$storedEditDiscount = max(0, round((float)($booking['discount_amount'] ?? 0), 2));

$storedStartTime = date('H:i', strtotime($booking['event_start']));
$storedEndTime = date('H:i', strtotime($booking['event_end']));
if (!isset($startTimeSlots[$storedStartTime])) {
    $startTimeSlots[$storedStartTime] = date('g:i A', strtotime($booking['event_start']));
    ksort($startTimeSlots);
}
if (!isset($endTimeSlots[$storedEndTime])) {
    $endTimeSlots[$storedEndTime] = date('g:i A', strtotime($booking['event_end']));
    ksort($endTimeSlots);
}

$form = [
    'reservation_type' => (string)$booking['reservation_type'],
    'source' => (string)$booking['source'],
    'client_name' => (string)$booking['client_name'],
    'organization' => (string)($booking['organization'] ?? ''),
    'email' => $booking['email'] === 'walkin@local.invalid' ? '' : (string)$booking['email'],
    'phone' => (string)$booking['phone'],
    'booking_payment_method' => (string)($booking['booking_payment_method'] ?: 'Cash'),
    'booking_payment_reference' => (string)($booking['booking_payment_reference'] ?? ''),
    'reservation_date' => date('Y-m-d', strtotime($booking['event_start'])),
    'start_time' => $storedStartTime,
    'end_time' => $storedEndTime,
    'purpose' => (string)($booking['purpose'] ?? ''),
    'guest_count' => $booking['guest_count'] ? (string)$booking['guest_count'] : '',
    'cooling_option' => (string)($booking['cooling_option'] ?: 'fan'),
    'shower_room_addon' => !empty($booking['shower_room_addon']) ? '1' : '0',
    'shower_room_complimentary' => reservation_shower_is_complimentary($booking) ? '1' : '0',
    'equipment_bundle_addon' => !empty($booking['equipment_bundle_addon']) ? '1' : '0',
    'equipment_bundle_complimentary' => reservation_equipment_is_complimentary($booking) ? '1' : '0',
    'setup_minutes' => (string)($booking['setup_minutes'] ?? 0),
    'cleanup_minutes' => (string)($booking['cleanup_minutes'] ?? 0),
    'details' => (string)($booking['details'] ?? ''),
    'equipment_requests' => (string)($booking['equipment_requests'] ?? ''),
    'special_instructions' => (string)($booking['special_instructions'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach (array_keys($form) as $key) {
        if (array_key_exists($key, $_POST)) {
            $form[$key] = is_string($_POST[$key]) ? trim($_POST[$key]) : '';
        }
    }
    $form['shower_room_addon'] = isset($_POST['shower_room_addon']) ? '1' : '0';
    $form['shower_room_complimentary'] = isset($_POST['shower_room_complimentary']) && $form['shower_room_addon'] === '1' ? '1' : '0';
    $form['equipment_bundle_addon'] = isset($_POST['equipment_bundle_addon']) ? '1' : '0';
    $form['equipment_bundle_complimentary'] = isset($_POST['equipment_bundle_complimentary']) && $form['equipment_bundle_addon'] === '1' ? '1' : '0';

    // Date and time changes are intentionally handled only by the dedicated
    // Reschedule action, which records an audit trail and requires a reason.
    $form['reservation_date'] = date('Y-m-d', strtotime($booking['event_start']));
    $form['start_time'] = date('H:i', strtotime($booking['event_start']));
    $form['end_time'] = date('H:i', strtotime($booking['event_end']));

    $type = $form['reservation_type'];
    $source = $form['source'];
    $client = $form['client_name'];
    $organization = $form['organization'];
    $email = $form['email'];
    $phone = $form['phone'];
    $bookingPaymentMethod = $form['booking_payment_method'];
    $bookingPaymentReference = $form['booking_payment_reference'];
    $purpose = $form['purpose'];
    $details = $form['details'];
    $equipment = $form['equipment_requests'];
    $instructions = $form['special_instructions'];
    $guestCountRaw = $form['guest_count'];
    $guestCount = ctype_digit($guestCountRaw) ? (int)$guestCountRaw : 0;
    $coolingOption = $form['cooling_option'];
    $showerRoom = $form['shower_room_addon'] === '1';
    $showerRoomComplimentary = $showerRoom && $form['shower_room_complimentary'] === '1';
    $equipmentBundle = $form['equipment_bundle_addon'] === '1';
    $equipmentBundleComplimentary = $equipmentBundle && $form['equipment_bundle_complimentary'] === '1';

    $setupChoices = [0, 30, 60, 120, 180];
    $cleanupChoices = [0, 30, 60, 120];
    $setup = $type === 'event' ? (int)$form['setup_minutes'] : 0;
    $cleanup = $type === 'event' ? (int)$form['cleanup_minutes'] : 0;

    if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
        $errors[] = 'Choose a valid reservation type.';
    }
    if (!in_array($source, ['website', 'walk_in', 'internal'], true)) {
        $errors[] = 'Choose a valid reservation source.';
    }
    if ($client === '') {
        $errors[] = 'Client name is required.';
    } elseif (strlen($client) > 150) {
        $errors[] = 'Client name may not exceed 150 characters.';
    }
    if (strlen($organization) > 150) {
        $errors[] = 'Organization or team may not exceed 150 characters.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    } elseif (strlen($email) > 150) {
        $errors[] = 'Email address may not exceed 150 characters.';
    }
    if ($phone === '') {
        $errors[] = 'Mobile number is required.';
    } elseif (strlen($phone) > 40) {
        $errors[] = 'Mobile number may not exceed 40 characters.';
    }
    if (!in_array($bookingPaymentMethod, booking_payment_methods(), true)) {
        $errors[] = 'Choose a valid payment method.';
    }
    if ($bookingPaymentMethod !== 'Cash' && $bookingPaymentReference === '') {
        $errors[] = 'Enter a transaction reference or OR number for non-cash payments.';
    }
    if (strlen($bookingPaymentReference) > 120) {
        $errors[] = 'The payment reference number may not exceed 120 characters.';
    }
    if (strlen($purpose) > 150) {
        $errors[] = 'Purpose may not exceed 150 characters.';
    }
    if ($guestCount < 1 || reservation_package_for_guests($guestCount) === null) {
        $errors[] = 'Guest count must be between 1 and 400 guests.';
    }
    if (!array_key_exists($coolingOption, reservation_cooling_options())) {
        $errors[] = 'Choose Fan with Lights or Aircon with Lights.';
    }
    if ($type === 'event' && !in_array($setup, $setupChoices, true)) {
        $errors[] = 'Choose a valid setup time.';
    }
    if ($type === 'event' && !in_array($cleanup, $cleanupChoices, true)) {
        $errors[] = 'Choose a valid cleanup time.';
    }

    $reservationDate = $form['reservation_date'];
    $startTimeValue = $form['start_time'];
    $endTimeValue = $form['end_time'];
    $scheduleIsValid = true;

    if (!reservation_date_is_valid($reservationDate)) {
        $errors[] = 'Choose a valid reservation date.';
        $scheduleIsValid = false;
    }
    if (!array_key_exists($startTimeValue, $startTimeSlots)) {
        $errors[] = 'Choose a valid starting time.';
        $scheduleIsValid = false;
    }
    if (!array_key_exists($endTimeValue, $endTimeSlots)) {
        $errors[] = 'Choose a valid ending time.';
        $scheduleIsValid = false;
    }

    if ($scheduleIsValid) {
        try {
            $start = new DateTimeImmutable($reservationDate . ' ' . $startTimeValue . ':00');
            $end = new DateTimeImmutable($reservationDate . ' ' . $endTimeValue . ':00');
            if ($start >= $end) {
                $errors[] = 'End time must be later than start time.';
            }
        } catch (Throwable $e) {
            $start = $end = null;
            $errors[] = 'Choose a valid reservation schedule.';
        }
    } else {
        $start = $end = null;
    }

    $pricing = null;
    if ($start && $end && $start < $end && in_array($type, ['basketball', 'volleyball', 'event'], true) && reservation_package_for_guests($guestCount) !== null && array_key_exists($coolingOption, reservation_cooling_options())) {
        try {
            $pricing = calculate_reservation_pricing(
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $guestCount,
                $coolingOption,
                $showerRoom,
                $equipmentBundle,
                true,
                $showerRoomComplimentary,
                $equipmentBundleComplimentary
            );
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors && $start && $end && $pricing !== null) {
        $blockedStart = $start->modify('-' . $setup . ' minutes');
        $blockedEnd = $end->modify('+' . $cleanup . ' minutes');
        $pdo = db();
        $lockAcquired = false;

        try {
            $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('The reservation calendar is busy. Please try saving again.');
            }

            $pdo->beginTransaction();
            $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
            $bookingStmt->execute([$id]);
            $currentBooking = $bookingStmt->fetch();
            if (!$currentBooking) {
                throw new RuntimeException('Reservation not found.');
            }

            if (in_array($currentBooking['status'], active_reservation_statuses(), true)
                && reservation_conflict($blockedStart->format('Y-m-d H:i:s'), $blockedEnd->format('Y-m-d H:i:s'), $id)) {
                throw new RuntimeException('The selected schedule overlaps another active reservation.');
            }

            $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $paidStmt->execute([$id]);
            $amountPaid = round((float)$paidStmt->fetchColumn(), 2);

            // Repricing an edited reservation must preserve the approved discount
            // AMOUNT, not freeze the old payable total. If the new calculated total
            // becomes smaller than the saved discount, cap the discount so the
            // payable amount remains a positive, internally consistent value.
            $storedDiscount = max(0, round((float)($currentBooking['discount_amount'] ?? 0), 2));
            if ($storedDiscount > 0.001) {
                $discountedPricing = reservation_reprice_with_discount((float)$pricing['total'], $storedDiscount);
                $effectiveDiscount = (float)$discountedPricing['discount_amount'];
                $newFinalAmount = (float)$discountedPricing['payable_total'];
                $targetAmount = $newFinalAmount;
            } else {
                $effectiveDiscount = 0.0;
                $newFinalAmount = ($currentBooking['final_amount'] === null || $currentBooking['final_amount'] === '')
                    ? null
                    : round((float)$currentBooking['final_amount'], 2);
                $targetAmount = $newFinalAmount ?? (float)$pricing['total'];
            }

            $paymentStatus = payment_status_for_amount($amountPaid, $targetAmount);
            $stmt = $pdo->prepare(
                'UPDATE reservations SET reservation_type=?,purpose=?,client_name=?,organization=?,email=?,phone=?,booking_payment_method=?,booking_payment_reference=?,event_start=?,event_end=?,setup_minutes=?,cleanup_minutes=?,blocked_start=?,blocked_end=?,guest_count=?,pricing_package=?,cooling_option=?,rate_period=?,hourly_rate=?,billable_hours=?,shower_room_addon=?,shower_room_fee=?,equipment_bundle_addon=?,equipment_bundle_fee=?,pricing_snapshot=?,details=?,equipment_requests=?,special_instructions=?,estimated_amount=?,final_amount=?,discount_amount=?,amount_paid=?,payment_status=?,source=? WHERE id=?'
            );
            $stmt->execute([
                $type,
                $purpose !== '' ? $purpose : null,
                $client,
                $organization !== '' ? $organization : null,
                $email !== '' ? $email : 'walkin@local.invalid',
                $phone,
                $bookingPaymentMethod,
                $bookingPaymentReference !== '' ? $bookingPaymentReference : null,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $setup,
                $cleanup,
                $blockedStart->format('Y-m-d H:i:s'),
                $blockedEnd->format('Y-m-d H:i:s'),
                $guestCount,
                $pricing['package'],
                $pricing['cooling_option'],
                $pricing['rate_period'],
                $pricing['hourly_rate'],
                $pricing['billable_hours'],
                $pricing['shower_room_addon'] ? 1 : 0,
                $pricing['shower_room_fee'],
                $pricing['equipment_bundle_addon'] ? 1 : 0,
                $pricing['equipment_bundle_fee'],
                reservation_pricing_snapshot($pricing),
                $details !== '' ? $details : null,
                $equipment !== '' ? $equipment : null,
                $instructions !== '' ? $instructions : null,
                $pricing['total'],
                $newFinalAmount,
                $effectiveDiscount,
                $amountPaid,
                $paymentStatus,
                $source,
                $id,
            ]);

            $batchId = (int)($currentBooking['batch_id'] ?? 0);
            if ($batchId > 0) {
                reservation_sync_batch_summary($pdo, $batchId);
            }

            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
            $lockAcquired = false;
            flash('success', 'Reservation ' . $booking['reference_no'] . ' updated successfully.');
            redirect('reservation-view.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($lockAcquired) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                } catch (Throwable $ignore) {
                }
            }
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be updated.';
        }
    }
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-edit-panel">
  <div class="panel-head">
    <div>
      <span class="small muted">Reservation <?= e($booking['reference_no']) ?></span>
      <h2>Edit Reservation</h2>
      <p class="muted">Correct booking information without changing existing payment-history entries.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" style="width:100%;margin:10px 0"><?= e($error) ?></div>
  <?php endforeach; ?>

  <div class="reservation-edit-summary">
    <div><span>Current Status</span><strong><?= e(ucwords(str_replace('_', ' ', $booking['status']))) ?></strong></div>
    <div><span>Amount Paid</span><strong><?= money($booking['amount_paid']) ?></strong></div>
    <div><span>Current Total</span><strong><?= money(reservation_payment_target($booking)) ?></strong></div>
  </div>

  <form method="post" class="form-grid" data-pricing-form data-pricing-config="<?= e(json_encode($pricingConfig)) ?>" data-existing-discount="<?= e(number_format($storedEditDiscount, 2, '.', '')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-group"><label>Reservation Type *</label><select name="reservation_type" id="reservation_type" required><option value="basketball" <?= $form['reservation_type'] === 'basketball' ? 'selected' : '' ?>>Basketball</option><option value="volleyball" <?= $form['reservation_type'] === 'volleyball' ? 'selected' : '' ?>>Volleyball</option><option value="event" <?= $form['reservation_type'] === 'event' ? 'selected' : '' ?>>Events</option></select></div>
    <div class="form-group"><label>Source *</label><select name="source" required><option value="website" <?= $form['source'] === 'website' ? 'selected' : '' ?>>Website</option><option value="walk_in" <?= $form['source'] === 'walk_in' ? 'selected' : '' ?>>Walk-in</option><option value="internal" <?= $form['source'] === 'internal' ? 'selected' : '' ?>>Internal</option></select></div>

    <div class="form-group"><label>Client Name *</label><input name="client_name" maxlength="150" value="<?= e($form['client_name']) ?>" required></div>
    <div class="form-group"><label>Organization / Team</label><input name="organization" maxlength="150" value="<?= e($form['organization']) ?>"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" maxlength="150" value="<?= e($form['email']) ?>"></div>
    <div class="form-group"><label>Phone *</label><input name="phone" maxlength="40" value="<?= e($form['phone']) ?>" required></div>

    <div class="form-group"><label>Payment Method *</label><select name="booking_payment_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= $form['booking_payment_method'] === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Reference / OR Number</label><input name="booking_payment_reference" maxlength="120" value="<?= e($form['booking_payment_reference']) ?>" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional for cash; required for non-cash payments.</span></div>

    <input type="hidden" name="reservation_date" value="<?= e($form['reservation_date']) ?>" data-reservation-date>
    <input type="hidden" name="start_time" value="<?= e($form['start_time']) ?>" data-start-time>
    <input type="hidden" name="end_time" value="<?= e($form['end_time']) ?>" data-end-time>
    <div class="form-group full">
      <div class="schedule-locked-card">
        <div><span>Current Schedule</span><strong><?= date('M j, Y g:i A', strtotime($booking['event_start'])) ?> – <?= date('M j, Y g:i A', strtotime($booking['event_end'])) ?></strong></div>
        <?php if (reservation_can_reschedule($booking)): ?><a class="btn btn-outline btn-sm" href="reservation-reschedule.php?id=<?= $id ?>">Reschedule Date or Time</a><?php endif; ?>
      </div>
      <span class="field-help">Schedule changes use the Reschedule action so the previous date, new date, reason, and staff member are recorded.</span>
    </div>

    <div class="form-group"><label>Purpose</label><input name="purpose" maxlength="150" value="<?= e($form['purpose']) ?>"></div>
    <div class="form-group"><label>Expected Guests *</label><input type="number" min="1" max="400" name="guest_count" value="<?= e($form['guest_count']) ?>" data-guest-count required><span class="field-help">1–400 guests. The package is selected automatically.</span></div>
    <div class="form-group"><label>Cooling Option *</label><select name="cooling_option" data-cooling-option required><?php foreach (reservation_cooling_options() as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['cooling_option'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="form-group full pricing-addons"><label>Additional Services</label><label class="checkbox-option"><input type="checkbox" name="shower_room_addon" value="1" data-shower-room <?= $form['shower_room_addon'] === '1' ? 'checked' : '' ?>><span>Shower Room — <?= money($pricingConfig['shower_room_fee']) ?> per reservation</span></label><label class="checkbox-option"><input type="checkbox" name="shower_room_complimentary" value="1" data-shower-room-complimentary <?= $form['shower_room_complimentary'] === '1' ? 'checked' : '' ?>><span>Complimentary Shower Room — waive the shower fee</span></label><label class="checkbox-option"><input type="checkbox" name="equipment_bundle_addon" value="1" data-equipment-bundle <?= $form['equipment_bundle_addon'] === '1' ? 'checked' : '' ?>><span data-equipment-bundle-label>Shot clocks, scoreboard, controller, and sound system — <?= money($pricingConfig['equipment_bundle_fee']) ?> per hour for regular bookings</span></label><label class="checkbox-option"><input type="checkbox" name="equipment_bundle_complimentary" value="1" data-equipment-bundle-complimentary <?= $form['equipment_bundle_complimentary'] === '1' ? 'checked' : '' ?>><span>Complimentary Equipment Bundle — waive the hourly equipment fee</span></label></div>

    <div class="form-group" data-event-fields><label>Setup Time</label><select name="setup_minutes"><option value="0" <?= $form['setup_minutes'] === '0' ? 'selected' : '' ?>>None</option><option value="30" <?= $form['setup_minutes'] === '30' ? 'selected' : '' ?>>30 minutes</option><option value="60" <?= $form['setup_minutes'] === '60' ? 'selected' : '' ?>>1 hour</option><option value="120" <?= $form['setup_minutes'] === '120' ? 'selected' : '' ?>>2 hours</option><option value="180" <?= $form['setup_minutes'] === '180' ? 'selected' : '' ?>>3 hours</option></select></div>
    <div class="form-group" data-event-fields><label>Cleanup Time</label><select name="cleanup_minutes"><option value="0" <?= $form['cleanup_minutes'] === '0' ? 'selected' : '' ?>>None</option><option value="30" <?= $form['cleanup_minutes'] === '30' ? 'selected' : '' ?>>30 minutes</option><option value="60" <?= $form['cleanup_minutes'] === '60' ? 'selected' : '' ?>>1 hour</option><option value="120" <?= $form['cleanup_minutes'] === '120' ? 'selected' : '' ?>>2 hours</option></select></div>

    <div class="form-group full"><div class="pricing-summary" data-pricing-summary><div><span>Package</span><strong data-price-package>Enter guest count</strong></div><div><span>Hourly Rate</span><strong data-price-hourly>—</strong></div><div><span>Billable Duration</span><strong data-price-hours>—</strong></div><div><span>Add-ons</span><strong data-price-addons>—</strong></div><div class="pricing-total"><span>Calculated Total</span><strong data-price-total>—</strong></div><div data-price-existing-discount-row <?= $storedEditDiscount > 0 ? '' : 'hidden' ?>><span>Existing Discount</span><strong data-price-existing-discount><?= $storedEditDiscount > 0 ? '−' . money($storedEditDiscount) : '—' ?></strong></div><div class="pricing-total" data-price-client-payable-row <?= $storedEditDiscount > 0 ? '' : 'hidden' ?>><span>Client Payable</span><strong data-price-client-payable><?= $storedEditDiscount > 0 ? money(reservation_payment_target($booking)) : '—' ?></strong></div><p class="field-help" data-price-message>Pricing updates automatically. Any saved flexible discount is preserved and applied to the recalculated total.</p></div></div>

    <div class="form-group full"><label>Reservation Details</label><textarea name="details"><?= e($form['details']) ?></textarea></div>
    <div class="form-group full"><label>Equipment or Facility Requests</label><textarea name="equipment_requests"><?= e($form['equipment_requests']) ?></textarea></div>
    <div class="form-group full"><label>Special Instructions</label><textarea name="special_instructions"><?= e($form['special_instructions']) ?></textarea></div>

    <div class="form-group full reservation-edit-actions">
      <a class="btn btn-outline" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Save Reservation Changes</button>
    </div>
  </form>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
