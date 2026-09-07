<?php
/**
 * FILE PURPOSE: Moves an existing reservation to a new date/time while preserving its reference and payment history.
 * DEBUGGING: Always perform a fresh conflict check; pricing can either be retained or recalculated according to the selected option.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    flash('danger', 'Choose a reservation to reschedule.');
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
    flash('danger', 'Restore this reservation from Archives before rescheduling it.');
    redirect('reservation-view.php?id=' . $id);
}

if (reservation_has_ended($booking)) {
    flash('danger', 'This reservation has already ended. Served reservations are locked and can no longer be rescheduled.');
    redirect('reservation-view.php?id=' . $id);
}

if (!in_array($booking['status'], reschedulable_reservation_statuses(), true)) {
    flash('danger', 'Only pending, for-review, or approved reservations can be rescheduled.');
    redirect('reservation-view.php?id=' . $id);
}

$adminPageTitle = 'Reschedule ' . $booking['reference_no'];
$errors = [];
$startTimeSlots = reservation_start_time_slots();
$endTimeSlots = reservation_end_time_slots();
$currentStart = new DateTimeImmutable($booking['event_start']);
$currentEnd = new DateTimeImmutable($booking['event_end']);
$currentTarget = reservation_payment_target($booking);
$currentPaid = round((float)$booking['amount_paid'], 2);
$currentBalance = reservation_remaining_balance($booking);
$currentCredit = reservation_excess_credit($booking);
$currentGuestCount = (int)($booking['guest_count'] ?? 0);
$currentCoolingOption = (string)($booking['cooling_option'] ?? '');
$hasAutomaticPricing = reservation_package_for_guests($currentGuestCount) !== null
    && array_key_exists($currentCoolingOption, reservation_cooling_options());
$bookingPricingSnapshot = json_decode((string)($booking['pricing_snapshot'] ?? ''), true);
if (!is_array($bookingPricingSnapshot)) {
    $bookingPricingSnapshot = [];
}
if (($booking['hourly_rate'] ?? null) !== null && ($booking['hourly_rate'] ?? '') !== '') {
    $storedHourlyRate = max(0, round((float)$booking['hourly_rate'], 2));
} elseif (isset($bookingPricingSnapshot['hourly_rate']) && is_numeric($bookingPricingSnapshot['hourly_rate'])) {
    $storedHourlyRate = max(0, round((float)$bookingPricingSnapshot['hourly_rate'], 2));
} else {
    $storedHourlyRate = null;
}
$hasSavedHourlyRate = $hasAutomaticPricing && $storedHourlyRate !== null;
$isBatchOccurrence = (int)($booking['batch_id'] ?? 0) > 0;
$currentRatePeriod = (string)($booking['rate_period'] ?? ($bookingPricingSnapshot['rate_period'] ?? ''));
$currentRatePeriodLabel = reservation_rate_period_label($currentRatePeriod);
$defaultPricingMode = $hasSavedHourlyRate ? 'original_rate' : 'keep_total';
$rescheduleRateConfig = reservation_pricing_config();
$regularIntroRate = $currentCoolingOption === 'aircon'
    ? (float)$rescheduleRateConfig['intro_aircon_rate']
    : (float)$rescheduleRateConfig['intro_fan_rate'];
$regularNewDateRate = $currentCoolingOption === 'aircon'
    ? (float)$rescheduleRateConfig['regular_aircon_rate']
    : (float)$rescheduleRateConfig['regular_fan_rate'];
$packageNewDateRate = match ((string)($booking['pricing_package'] ?? '')) {
    'tournament' => $currentCoolingOption === 'aircon'
        ? (float)$rescheduleRateConfig['tournament_aircon_rate']
        : (float)$rescheduleRateConfig['tournament_fan_rate'],
    'big_event' => $currentCoolingOption === 'aircon'
        ? (float)$rescheduleRateConfig['big_event_aircon_rate']
        : (float)$rescheduleRateConfig['big_event_fan_rate'],
    default => 0.0,
};

$storedStartTime = $currentStart->format('H:i');
$storedEndTime = $currentEnd->format('H:i');
if (!isset($startTimeSlots[$storedStartTime])) {
    $startTimeSlots[$storedStartTime] = $currentStart->format('g:i A');
    ksort($startTimeSlots);
}
if (!isset($endTimeSlots[$storedEndTime])) {
    $endTimeSlots[$storedEndTime] = $currentEnd->format('g:i A');
    ksort($endTimeSlots);
}

$form = [
    'reservation_date' => $_POST['reservation_date'] ?? $currentStart->format('Y-m-d'),
    'start_time' => $_POST['start_time'] ?? $storedStartTime,
    'end_time' => $_POST['end_time'] ?? $storedEndTime,
    'pricing_mode' => $_POST['pricing_mode'] ?? $defaultPricingMode,
    'reason' => $_POST['reason'] ?? '',
];

$previewEstimate = null;
$previewTarget = null;
$previewDiscount = 0.0;
$previewBalance = null;
$previewCredit = null;
$previewHourlyRate = null;
$previewRatePeriod = null;
$previewPricingTreatment = '';

// Rescheduling preserves the same reservation/reference/payment history.
// Only the schedule (and optionally recalculated price) changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($form as $key => $value) {
        if (array_key_exists($key, $_POST) && is_string($_POST[$key])) {
            $form[$key] = trim($_POST[$key]);
        }
    }

    $reservationDate = $form['reservation_date'];
    $startTimeValue = $form['start_time'];
    $endTimeValue = $form['end_time'];
    $pricingMode = $form['pricing_mode'];
    $reason = $form['reason'];
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
    if (!in_array($pricingMode, ['original_rate', 'new_date_rate', 'keep_total'], true)) {
        $errors[] = 'Choose how pricing should be handled for the new schedule.';
    }
    if (in_array($pricingMode, ['original_rate', 'new_date_rate'], true) && !$hasAutomaticPricing) {
        $errors[] = 'This older reservation needs a valid guest count and cooling option in Edit Reservation before its price can be recalculated.';
    }
    if ($pricingMode === 'original_rate' && !$hasSavedHourlyRate) {
        $errors[] = 'This reservation has no saved hourly rate. Keep the current payable total or apply the rate for the new date.';
    }
    if ($reason === '') {
        $errors[] = 'Enter a reason for rescheduling.';
    } elseif (strlen($reason) > 1000) {
        $errors[] = 'The rescheduling reason may not exceed 1,000 characters.';
    }

    if ($scheduleIsValid) {
        try {
            $newStart = new DateTimeImmutable($reservationDate . ' ' . $startTimeValue . ':00');
            $newEnd = new DateTimeImmutable($reservationDate . ' ' . $endTimeValue . ':00');
            if ($newStart >= $newEnd) {
                $errors[] = 'End time must be later than start time.';
            }
            if ($newStart <= new DateTimeImmutable()) {
                $errors[] = 'Choose a future reservation date and time.';
            }
            if ($newStart->format('Y-m-d H:i:s') === $currentStart->format('Y-m-d H:i:s')
                && $newEnd->format('Y-m-d H:i:s') === $currentEnd->format('Y-m-d H:i:s')) {
                $errors[] = 'Choose a different date or time from the current schedule.';
            }
        } catch (Throwable $e) {
            $newStart = $newEnd = null;
            $errors[] = 'Choose a valid reservation schedule.';
        }
    } else {
        $newStart = $newEnd = null;
    }

    if ($newStart && $newEnd && $newStart < $newEnd) {
        if ($pricingMode === 'keep_total') {
            $previewTarget = $currentTarget;
            $previewPricingTreatment = 'Current payable total kept unchanged';
            if ($hasAutomaticPricing) {
                try {
                    $previewRepriceMode = $hasSavedHourlyRate ? 'original_rate' : 'new_date_rate';
                    $previewPricing = reservation_reprice_for_reschedule(
                        $booking,
                        $newStart->format('Y-m-d H:i:s'),
                        $newEnd->format('Y-m-d H:i:s'),
                        $previewRepriceMode
                    );
                    $previewEstimate = (float)$previewPricing['total'];
                    $previewHourlyRate = (float)$previewPricing['hourly_rate'];
                    $previewRatePeriod = (string)$previewPricing['rate_period'];
                    $previewDiscount = reservation_discount_for_payable($previewEstimate, $previewTarget);
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            } else {
                $previewEstimate = round((float)$booking['estimated_amount'], 2);
                $previewDiscount = reservation_discount_for_payable($previewEstimate, $previewTarget);
                $previewHourlyRate = $storedHourlyRate;
                $previewRatePeriod = (string)($booking['rate_period'] ?? '');
            }
        } elseif ($hasAutomaticPricing) {
            try {
                $previewPricing = reservation_reprice_for_reschedule(
                    $booking,
                    $newStart->format('Y-m-d H:i:s'),
                    $newEnd->format('Y-m-d H:i:s'),
                    $pricingMode
                );
                $previewEstimate = (float)$previewPricing['total'];
                $previewHourlyRate = (float)$previewPricing['hourly_rate'];
                $previewRatePeriod = (string)$previewPricing['rate_period'];
                $previewPricingTreatment = $pricingMode === 'original_rate'
                    ? ($isBatchOccurrence ? 'Original batch occurrence rate retained' : 'Original booked rate retained')
                    : 'Rate for the new date applied';

                $storedPreviewDiscount = max(0, round((float)($booking['discount_amount'] ?? 0), 2));
                if ($storedPreviewDiscount > 0.001) {
                    $previewDiscountedPricing = reservation_reprice_with_discount($previewEstimate, $storedPreviewDiscount);
                    $previewDiscount = (float)$previewDiscountedPricing['discount_amount'];
                    $previewTarget = (float)$previewDiscountedPricing['payable_total'];
                } else {
                    $previewTarget = $previewEstimate;
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($previewTarget !== null) {
            $previewBalance = max(0, round($previewTarget - $currentPaid, 2));
            $previewCredit = max(0, round($currentPaid - $previewTarget, 2));
        }
    }

    if (!$errors && $newStart && $newEnd && $previewEstimate !== null && $previewTarget !== null) {
        $setup = max(0, (int)$booking['setup_minutes']);
        $cleanup = max(0, (int)$booking['cleanup_minutes']);
        $newBlockedStart = $newStart->modify('-' . $setup . ' minutes');
        $newBlockedEnd = $newEnd->modify('+' . $cleanup . ' minutes');
        $pdo = db();
        $lockAcquired = false;

        try {
            $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please try again.');
            }

            $pdo->beginTransaction();
            $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
            $bookingStmt->execute([$id]);
            $currentBooking = $bookingStmt->fetch();
            if (!$currentBooking) {
                throw new RuntimeException('Reservation not found.');
            }
            if (reservation_has_ended($currentBooking)) {
                throw new RuntimeException('This reservation ended while the reschedule was being processed. It is now locked.');
            }
            if (!in_array($currentBooking['status'], reschedulable_reservation_statuses(), true)) {
                throw new RuntimeException('This reservation can no longer be rescheduled because its status changed.');
            }
            if ($newStart->format('Y-m-d H:i:s') === (string)$currentBooking['event_start']
                && $newEnd->format('Y-m-d H:i:s') === (string)$currentBooking['event_end']) {
                throw new RuntimeException('The reservation is already using that schedule.');
            }

            if (reservation_conflict(
                $newBlockedStart->format('Y-m-d H:i:s'),
                $newBlockedEnd->format('Y-m-d H:i:s'),
                $id
            )) {
                throw new RuntimeException('The selected schedule overlaps another active reservation.');
            }

            $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $paidStmt->execute([$id]);
            $amountPaid = round((float)$paidStmt->fetchColumn(), 2);
            $lockedGuestCount = (int)($currentBooking['guest_count'] ?? 0);
            $lockedCoolingOption = (string)($currentBooking['cooling_option'] ?? '');
            $lockedHasAutomaticPricing = reservation_package_for_guests($lockedGuestCount) !== null
                && array_key_exists($lockedCoolingOption, reservation_cooling_options());
            $lockedPricing = null;
            if ($lockedHasAutomaticPricing) {
                $repriceMode = $pricingMode;
                if ($pricingMode === 'keep_total') {
                    $lockedSnapshot = json_decode((string)($currentBooking['pricing_snapshot'] ?? ''), true);
                    if (!is_array($lockedSnapshot)) {
                        $lockedSnapshot = [];
                    }
                    $hasLockedSavedRate = (($currentBooking['hourly_rate'] ?? null) !== null && ($currentBooking['hourly_rate'] ?? '') !== '')
                        || (isset($lockedSnapshot['hourly_rate']) && is_numeric($lockedSnapshot['hourly_rate']));
                    $repriceMode = $hasLockedSavedRate ? 'original_rate' : 'new_date_rate';
                }
                $lockedPricing = reservation_reprice_for_reschedule(
                    $currentBooking,
                    $newStart->format('Y-m-d H:i:s'),
                    $newEnd->format('Y-m-d H:i:s'),
                    $repriceMode
                );
            } elseif ($pricingMode !== 'keep_total') {
                throw new RuntimeException('Set a valid guest count and cooling option before repricing this reservation.');
            }
            $lockedEstimate = $lockedPricing !== null
                ? (float)$lockedPricing['total']
                : round((float)$currentBooking['estimated_amount'], 2);
            $previousTarget = reservation_payment_target($currentBooking);
            $storedDiscount = max(0, round((float)($currentBooking['discount_amount'] ?? 0), 2));
            if ($pricingMode === 'keep_total') {
                $newTarget = $previousTarget;
                $newFinalAmount = $previousTarget;
                // The payable stays fixed, but the underlying gross may change
                // with the new duration/schedule. Derive the effective discount
                // from that new gross so financial summaries remain truthful.
                $newDiscountAmount = reservation_discount_for_payable($lockedEstimate, $newTarget);
            } elseif ($storedDiscount > 0.001) {
                // Preserve the approved discount amount when repricing, but never
                // allow it to exceed the newly calculated gross total.
                $discountedPricing = reservation_reprice_with_discount($lockedEstimate, $storedDiscount);
                $newDiscountAmount = (float)$discountedPricing['discount_amount'];
                $newTarget = (float)$discountedPricing['payable_total'];
                $newFinalAmount = $newTarget;
            } else {
                $newDiscountAmount = 0.0;
                $newFinalAmount = null;
                $newTarget = $lockedEstimate;
            }
            $newPaymentStatus = payment_status_for_amount($amountPaid, $newTarget);

            $oldHourlyRate = max(0, round((float)($currentBooking['hourly_rate'] ?? 0), 2));
            $oldRateLabel = reservation_rate_period_label((string)($currentBooking['rate_period'] ?? ''));
            $newHourlyRate = $lockedPricing !== null ? max(0, round((float)$lockedPricing['hourly_rate'], 2)) : $oldHourlyRate;
            $newRateLabel = $lockedPricing !== null
                ? reservation_rate_period_label((string)($lockedPricing['rate_period'] ?? ''))
                : $oldRateLabel;
            if ($pricingMode === 'original_rate') {
                $pricingAudit = 'Pricing treatment: ' . ($isBatchOccurrence ? 'Original batch occurrence rate' : 'Original booked rate')
                    . ' retained at ' . money($newHourlyRate) . '/hour (' . $newRateLabel . ').';
            } elseif ($pricingMode === 'new_date_rate') {
                $pricingAudit = 'Pricing treatment: Rate for the new date applied; venue rate ' . money($oldHourlyRate)
                    . '/hour (' . $oldRateLabel . ') → ' . money($newHourlyRate) . '/hour (' . $newRateLabel . ').';
            } else {
                $pricingAudit = 'Pricing treatment: Current payable total kept unchanged at ' . money($newTarget)
                    . '; recalculated gross ' . money($lockedEstimate)
                    . ($newDiscountAmount > 0.001 ? ' with effective discount ' . money($newDiscountAmount) . '.' : '.');
            }
            $historyReason = $reason . "\n" . $pricingAudit;

            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_reschedules(
                    reservation_id,previous_event_start,previous_event_end,new_event_start,new_event_end,
                    previous_blocked_start,previous_blocked_end,new_blocked_start,new_blocked_end,
                    previous_total,new_total,previous_payment_status,new_payment_status,reason,changed_by
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $historyStmt->execute([
                $id,
                $currentBooking['event_start'],
                $currentBooking['event_end'],
                $newStart->format('Y-m-d H:i:s'),
                $newEnd->format('Y-m-d H:i:s'),
                $currentBooking['blocked_start'],
                $currentBooking['blocked_end'],
                $newBlockedStart->format('Y-m-d H:i:s'),
                $newBlockedEnd->format('Y-m-d H:i:s'),
                $previousTarget,
                $newTarget,
                $currentBooking['payment_status'],
                $newPaymentStatus,
                $historyReason,
                current_admin()['id'],
            ]);

            $updateStmt = $pdo->prepare(
                'UPDATE reservations SET event_start=?,event_end=?,blocked_start=?,blocked_end=?,estimated_amount=?,final_amount=?,discount_amount=?,amount_paid=?,payment_status=?,pricing_package=?,cooling_option=?,rate_period=?,hourly_rate=?,billable_hours=?,shower_room_addon=?,shower_room_fee=?,equipment_bundle_addon=?,equipment_bundle_fee=?,pricing_snapshot=? WHERE id=?'
            );
            $updateStmt->execute([
                $newStart->format('Y-m-d H:i:s'),
                $newEnd->format('Y-m-d H:i:s'),
                $newBlockedStart->format('Y-m-d H:i:s'),
                $newBlockedEnd->format('Y-m-d H:i:s'),
                $lockedEstimate,
                $newFinalAmount,
                $newDiscountAmount,
                $amountPaid,
                $newPaymentStatus,
                $lockedPricing['package'] ?? $currentBooking['pricing_package'],
                $lockedPricing['cooling_option'] ?? $currentBooking['cooling_option'],
                $lockedPricing['rate_period'] ?? $currentBooking['rate_period'],
                $lockedPricing['hourly_rate'] ?? $currentBooking['hourly_rate'],
                $lockedPricing['billable_hours'] ?? $currentBooking['billable_hours'],
                $lockedPricing !== null ? ($lockedPricing['shower_room_addon'] ? 1 : 0) : $currentBooking['shower_room_addon'],
                $lockedPricing['shower_room_fee'] ?? $currentBooking['shower_room_fee'],
                $lockedPricing !== null ? ($lockedPricing['equipment_bundle_addon'] ? 1 : 0) : $currentBooking['equipment_bundle_addon'],
                $lockedPricing['equipment_bundle_fee'] ?? $currentBooking['equipment_bundle_fee'],
                $lockedPricing !== null ? reservation_pricing_snapshot($lockedPricing) : $currentBooking['pricing_snapshot'],
                $id,
            ]);

            $batchId = (int)($currentBooking['batch_id'] ?? 0);
            if ($batchId > 0) {
                reservation_sync_batch_summary($pdo, $batchId);
            }

            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
            $lockAcquired = false;

            $message = 'Reservation ' . $booking['reference_no'] . ' was moved to ' . $newStart->format('M j, Y g:i A') . '.';
            $newBalance = max(0, round($newTarget - $amountPaid, 2));
            $newCredit = max(0, round($amountPaid - $newTarget, 2));
            if ($newBalance > 0) {
                $message .= ' Remaining balance: ' . money($newBalance) . '.';
            } elseif ($newCredit > 0) {
                $message .= ' Excess payment credit: ' . money($newCredit) . '.';
            } else {
                $message .= ' Existing payments remain fully applied.';
            }
            flash('success', $message);
            redirect('reservation-view.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($lockAcquired) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                } catch (Throwable $ignored) {
                }
            }
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be rescheduled.';
        }
    }
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-edit-panel">
  <div class="panel-head">
    <div>
      <span class="small muted">Reservation <?= e($booking['reference_no']) ?></span>
      <h2>Reschedule Reservation</h2>
      <p class="muted">Move this same reservation to a new date or time. Its reference number, client details, status, and complete payment history will stay attached.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" style="width:100%;margin:10px 0"><?= e($error) ?></div>
  <?php endforeach; ?>

  <div class="reservation-edit-summary">
    <div><span>Current Schedule</span><strong><?= e($currentStart->format('M j, Y g:i A')) ?><br><?= e($currentEnd->format('M j, Y g:i A')) ?></strong></div>
    <div><span>Current Total</span><strong><?= money($currentTarget) ?></strong></div>
    <div><span>Pricing Package</span><strong><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?><br><?= e(reservation_cooling_label($booking['cooling_option'] ?? null)) ?><?php if ($storedHourlyRate !== null): ?><br><?= e($currentRatePeriodLabel) ?> · <?= money($storedHourlyRate) ?>/hr<?php endif; ?></strong></div>
    <div><span>Payment Position</span><strong><?= money($currentPaid) ?> paid<?php if ($currentBalance > 0): ?><br><?= money($currentBalance) ?> balance<?php elseif ($currentCredit > 0): ?><br><?= money($currentCredit) ?> credit<?php else: ?><br>Fully paid<?php endif; ?></strong></div>
  </div>

  <div class="alert alert-info rebook-payment-note">
    No payment is copied or recreated. The existing payment records remain on this reservation and will be applied to its updated total.
  </div>

  <form method="post" class="form-grid" data-reschedule-live-conflict data-reschedule-pricing data-conflict-url="reservation-conflict-check.php" data-booking-id="<?= $id ?>" data-pricing-package="<?= e((string)($booking['pricing_package'] ?? '')) ?>" data-intro-start="<?= e((string)$rescheduleRateConfig['intro_start']) ?>" data-intro-end="<?= e((string)$rescheduleRateConfig['intro_end']) ?>" data-intro-rate="<?= e((string)$regularIntroRate) ?>" data-regular-rate="<?= e((string)$regularNewDateRate) ?>" data-package-rate="<?= e((string)$packageNewDateRate) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-group full"><div class="schedule-picker" data-reservation-schedule data-future-only>
      <div class="form-group"><label>New Reservation Date *</label><input type="date" name="reservation_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['reservation_date']) ?>" data-reservation-date required></div>
      <div class="form-group"><label>Start Time *</label><select name="start_time" data-start-time required><?php foreach ($startTimeSlots as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['start_time'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>End Time *</label><select name="end_time" data-end-time required><?php foreach ($endTimeSlots as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['end_time'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    </div></div>

    <div class="form-group full">
      <div class="schedule-availability" data-reschedule-live-status data-state="idle" role="status" aria-live="polite">
        <span class="schedule-availability-icon" aria-hidden="true">○</span>
        <div><strong>Live conflict check</strong><p>Change the date or time to check the proposed schedule.</p></div>
      </div>
    </div>

    <div class="form-group full">
      <label>Pricing Treatment *</label>
      <select name="pricing_mode" required>
        <?php if ($hasSavedHourlyRate): ?>
          <option value="original_rate" <?= $form['pricing_mode'] === 'original_rate' ? 'selected' : '' ?>><?= $isBatchOccurrence ? 'Keep Original Batch Occurrence Rate' : 'Keep Original Booked Rate' ?> — <?= money($storedHourlyRate) ?>/hour (<?= e($currentRatePeriodLabel) ?>)</option>
        <?php endif; ?>
        <?php if ($hasAutomaticPricing): ?>
          <option value="new_date_rate" <?= $form['pricing_mode'] === 'new_date_rate' ? 'selected' : '' ?>>Apply Rate for the New Date</option>
        <?php endif; ?>
        <option value="keep_total" <?= $form['pricing_mode'] === 'keep_total' ? 'selected' : '' ?>>Keep Current Payable Total — <?= money($currentTarget) ?></option>
      </select>
      <span class="field-help"><?= $isBatchOccurrence ? 'A moved batch occurrence stays in the same batch. By default, its original booked rate follows it to the new date.' : 'By default, the original booked rate follows the reservation to the new date.' ?> Choose “Apply Rate for the New Date” only when you intentionally want the venue rate to change. Existing add-on terms, discounts, and payments remain attached.</span>
      <span class="field-help" data-new-date-rate-preview></span>
    </div>

    <div class="form-group full">
      <label>Reason for Rescheduling *</label>
      <textarea name="reason" maxlength="1000" required placeholder="Example: Client requested a new date due to a schedule conflict."><?= e($form['reason']) ?></textarea>
      <span class="field-help">This reason will appear in the reservation’s rescheduling history.</span>
    </div>

    <?php if ($previewEstimate !== null && $previewTarget !== null): ?>
      <div class="form-group full">
        <div class="reschedule-preview">
          <?php if ($previewPricingTreatment !== ''): ?><div><span>Pricing Treatment</span><strong><?= e($previewPricingTreatment) ?></strong></div><?php endif; ?>
          <?php if ($previewHourlyRate !== null): ?><div><span>Venue Rate</span><strong><?= money($previewHourlyRate) ?>/hour<?php if ($previewRatePeriod): ?><br><?= e(reservation_rate_period_label($previewRatePeriod)) ?><?php endif; ?></strong></div><?php endif; ?>
          <div><span><?= $form['pricing_mode'] === 'keep_total' ? 'Saved Calculated Total' : 'New Calculated Total' ?></span><strong><?= money($previewEstimate) ?></strong></div>
          <?php if ($previewDiscount > 0): ?><div><span>Existing Discount</span><strong>−<?= money($previewDiscount) ?></strong></div><?php endif; ?>
          <div><span>New Payable Total</span><strong><?= money($previewTarget) ?></strong></div>
          <div><span>After Existing Payments</span><strong><?php if ($previewBalance > 0): ?><?= money($previewBalance) ?> balance<?php elseif ($previewCredit > 0): ?><?= money($previewCredit) ?> excess credit<?php else: ?>Fully paid<?php endif; ?></strong></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="form-group full reservation-edit-actions">
      <a class="btn btn-outline" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Confirm Reschedule</button>
    </div>
  </form>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
