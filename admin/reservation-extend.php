<?php
/**
 * FILE PURPOSE: Records normal or late reservation extensions in 30-minute increments using the reservation's saved rates.
 * DEBUGGING: Late extensions can be entered after the original end time. Historical overlaps require Administrator acknowledgement; future overlaps remain blocked.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    flash('danger', 'Choose a reservation to extend.');
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
    flash('danger', 'Restore this reservation from Archives before extending it.');
    redirect('reservation-view.php?id=' . $id);
}

$currentStart = new DateTimeImmutable($booking['event_start']);
$currentEnd = new DateTimeImmutable($booking['event_end']);
$pageNow = new DateTimeImmutable();
$isLateExtension = $currentEnd <= $pageNow;
$hourlyRate = round((float)($booking['hourly_rate'] ?? 0), 2);
$equipmentHourlyRate = reservation_equipment_extension_hourly_rate($booking);
$maxHours = reservation_max_extension_hours($booking);

if ($isLateExtension) {
    if (!in_array((string)$booking['status'], late_extendable_reservation_statuses(), true)) {
        $message = in_array((string)$booking['status'], ['pending', 'for_review'], true)
            ? 'Resolve this lapsed Pending / For Review reservation first. Late Extension is available after the event is confirmed as occurred.'
            : 'Only approved or completed reservations can receive a late extension.';
        flash('danger', $message);
        redirect('reservation-view.php?id=' . $id);
    }
} elseif (!in_array((string)$booking['status'], extendable_reservation_statuses(), true)) {
    flash('danger', 'Only pending, for-review, or approved reservations can be extended before the booked end time.');
    redirect('reservation-view.php?id=' . $id);
}
if ($maxHours < 0.5) {
    flash('danger', 'This reservation cannot be extended because it already reaches the 10:00 PM closing time.');
    redirect('reservation-view.php?id=' . $id);
}
if ($hourlyRate <= 0) {
    flash('danger', 'This reservation has no saved hourly rate. Update its pricing details before extending it.');
    redirect('reservation-view.php?id=' . $id);
}

try {
    $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
    $paidStmt->execute([$id]);
    $currentPaid = round((float)$paidStmt->fetchColumn(), 2);
} catch (Throwable $e) {
    $currentPaid = round((float)($booking['amount_paid'] ?? 0), 2);
}

$currentTarget = reservation_payment_target($booking);
$currentPaymentStatus = payment_status_for_amount($currentPaid, $currentTarget);
$adminPageTitle = ($isLateExtension ? 'Late Extension ' : 'Extend ') . $booking['reference_no'];
$errors = [];
$form = [
    'extension_hours' => $_POST['extension_hours'] ?? '1',
    'reason' => $_POST['reason'] ?? '',
    'payment_amount' => $_POST['payment_amount'] ?? '',
    'payment_method' => $_POST['payment_method'] ?? (($booking['booking_payment_method'] ?? '') ?: 'Cash'),
    'payment_reference' => $_POST['payment_reference'] ?? '',
    'paid_at' => $_POST['paid_at'] ?? date('Y-m-d\TH:i'),
    'payment_notes' => $_POST['payment_notes'] ?? '',
];

$selectedHours = reservation_duration_hours_from_input($form['extension_hours']) ?? min(1.0, $maxHours);
$selectedHours = min($maxHours, max(0.5, $selectedHours));
$previewEnd = reservation_add_duration($currentEnd, $selectedHours);
$previewBlockedEnd = $previewEnd->modify('+' . max(0, (int)$booking['cleanup_minutes']) . ' minutes');
$previewVenueCharge = round($hourlyRate * $selectedHours, 2);
$previewEquipmentCharge = round($equipmentHourlyRate * $selectedHours, 2);
$previewCharge = round($previewVenueCharge + $previewEquipmentCharge, 2);
$previewEstimate = round((float)$booking['estimated_amount'] + $previewCharge, 2);
$previewTarget = round($currentTarget + $previewCharge, 2);
$previewBalanceBeforePayment = max(0, round($previewTarget - $currentPaid, 2));
$previewPayment = is_numeric($form['payment_amount']) ? max(0, round((float)$form['payment_amount'], 2)) : 0.0;
$previewBalanceAfterPayment = max(0, round($previewBalanceBeforePayment - $previewPayment, 2));
$previewConflictAnalysis = ['historical' => [], 'future' => []];
try {
    $previewConflictAnalysis = reservation_extension_conflict_analysis($booking, $previewBlockedEnd, $pageNow, $isLateExtension);
} catch (Throwable $e) {
    // The authoritative conflict check runs again under the shared venue lock on submit.
}
$previewHistoricalConflicts = $previewConflictAnalysis['historical'] ?? [];
$previewFutureConflicts = $previewConflictAnalysis['future'] ?? [];
$historicalOverlapAcknowledged = !empty($_POST['historical_overlap_ack']);

// Extension adds billable 30-minute increments at the reservation's SAVED venue
// hourly rate plus any SAVED hourly Equipment Bundle rate. One-time add-ons are
// not repeated. Do not substitute today's rate settings for an existing reservation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($form as $key => $value) {
        if (array_key_exists($key, $_POST) && is_string($_POST[$key])) {
            $form[$key] = trim($_POST[$key]);
        }
    }

    $hours = reservation_duration_hours_from_input($form['extension_hours']);
    $reason = $form['reason'];
    $paymentAmountRaw = $form['payment_amount'];
    $paymentAmount = $paymentAmountRaw === '' ? 0.0 : (is_numeric($paymentAmountRaw) ? round((float)$paymentAmountRaw, 2) : -1.0);
    $paymentMethod = $form['payment_method'];
    $paymentReference = $form['payment_reference'];
    $paymentNotes = $form['payment_notes'];

    if ($hours === null || $hours > $maxHours) {
        $errors[] = 'Choose a valid 30-minute extension.';
    }
    if ($reason === '') {
        $errors[] = 'Enter a reason for the extension.';
    } elseif (strlen($reason) > 1000) {
        $errors[] = 'The extension reason may not exceed 1,000 characters.';
    }
    if ($paymentAmount < 0) {
        $errors[] = 'Enter a valid optional payment amount.';
    }
    if ($paymentAmount > 0) {
        if (!in_array($paymentMethod, booking_payment_methods(), true)) {
            $errors[] = 'Choose a valid payment method.';
        }
        if ($paymentMethod !== 'Cash' && $paymentReference === '') {
            $errors[] = 'Enter a transaction reference or OR number for non-cash payments.';
        }
        if (strlen($paymentReference) > 120) {
            $errors[] = 'The payment reference may not exceed 120 characters.';
        }
        try {
            $paidAt = new DateTimeImmutable($form['paid_at']);
        } catch (Throwable $e) {
            $errors[] = 'Enter a valid payment date and time.';
            $paidAt = null;
        }
    } else {
        $paidAt = null;
    }

    if (!$errors) {
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
            $transactionNow = new DateTimeImmutable();
            $lockedEnd = new DateTimeImmutable($currentBooking['event_end']);
            $lockedIsLateExtension = $lockedEnd <= $transactionNow;
            if ($lockedIsLateExtension) {
                if (!in_array((string)$currentBooking['status'], late_extendable_reservation_statuses(), true)) {
                    throw new RuntimeException('This reservation can no longer receive a late extension because its status changed.');
                }
            } elseif (!in_array((string)$currentBooking['status'], extendable_reservation_statuses(), true)) {
                throw new RuntimeException('This reservation can no longer be extended because its status changed.');
            }

            $lockedMaxHours = reservation_max_extension_hours($currentBooking);
            if ($hours > $lockedMaxHours) {
                throw new RuntimeException('The selected extension would go beyond the 10:00 PM closing time.');
            }
            $lockedHourlyRate = round((float)($currentBooking['hourly_rate'] ?? 0), 2);
            $lockedEquipmentHourlyRate = reservation_equipment_extension_hourly_rate($currentBooking);
            if ($lockedHourlyRate <= 0) {
                throw new RuntimeException('This reservation has no saved hourly rate.');
            }

            $newEnd = reservation_add_duration($lockedEnd, $hours);
            $newBlockedEnd = $newEnd->modify('+' . max(0, (int)$currentBooking['cleanup_minutes']) . ' minutes');
            $conflictAnalysis = reservation_extension_conflict_analysis(
                $currentBooking,
                $newBlockedEnd,
                $transactionNow,
                $lockedIsLateExtension
            );
            $futureConflicts = $conflictAnalysis['future'] ?? [];
            $historicalConflicts = $conflictAnalysis['historical'] ?? [];
            if ($futureConflicts) {
                $summary = reservation_extension_conflict_summary($futureConflicts);
                throw new RuntimeException('The extension would overlap another reservation in time that has not fully passed' . ($summary !== '' ? ': ' . $summary : '') . '. Choose a shorter extension or resolve the schedule conflict first.');
            }
            if ($historicalConflicts) {
                if (!$lockedIsLateExtension) {
                    throw new RuntimeException('The extension overlaps another reservation.');
                }
                if (!is_admin()) {
                    throw new RuntimeException('A historical overlap was detected. Only an Administrator can record a late extension that overlaps another reservation.');
                }
                if (!$historicalOverlapAcknowledged) {
                    throw new RuntimeException('Confirm the Historical Overlap acknowledgement before recording this late extension.');
                }
            }

            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $totalStmt->execute([$id]);
            $amountPaid = round((float)$totalStmt->fetchColumn(), 2);
            $previousTarget = reservation_payment_target($currentBooking);
            $previousPaymentStatus = payment_status_for_amount($amountPaid, $previousTarget);
            $additionalVenueCharge = round($lockedHourlyRate * $hours, 2);
            $additionalEquipmentCharge = round($lockedEquipmentHourlyRate * $hours, 2);
            $additionalCharge = round($additionalVenueCharge + $additionalEquipmentCharge, 2);
            $newEstimate = round((float)$currentBooking['estimated_amount'] + $additionalCharge, 2);
            $hasFinalAmount = $currentBooking['final_amount'] !== null && $currentBooking['final_amount'] !== '';
            $newFinalAmount = $hasFinalAmount ? round((float)$currentBooking['final_amount'] + $additionalCharge, 2) : null;
            $newTarget = $newFinalAmount ?? $newEstimate;
            $remainingAfterExtension = max(0, round($newTarget - $amountPaid, 2));
            if ($paymentAmount > $remainingAfterExtension + 0.001) {
                throw new RuntimeException('The payment cannot exceed the new remaining balance of ' . money($remainingAfterExtension) . '.');
            }

            if ($paymentAmount > 0) {
                $paymentStmt = $pdo->prepare('INSERT INTO payments(reservation_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)');
                $paymentStmt->execute([
                    $id,
                    $paymentAmount,
                    $paymentMethod,
                    $paymentReference !== '' ? $paymentReference : null,
                    $paymentNotes !== '' ? $paymentNotes : ($lockedIsLateExtension ? 'Payment recorded during late reservation extension.' : 'Payment recorded during reservation extension.'),
                    current_admin()['id'],
                    $paidAt->format('Y-m-d H:i:s'),
                ]);
            }

            $newAmountPaid = round($amountPaid + $paymentAmount, 2);
            $newPaymentStatus = payment_status_for_amount($newAmountPaid, $newTarget);
            $currentBillableHours = (float)($currentBooking['billable_hours'] ?? 0);
            if ($currentBillableHours < 0.5) {
                $lockedStart = new DateTimeImmutable($currentBooking['event_start']);
                $currentBillableHours = max(0.5, ($lockedEnd->getTimestamp() - $lockedStart->getTimestamp()) / 3600);
            }
            $newBillableHours = $currentBillableHours + $hours;
            $newEquipmentBundleFee = round((float)($currentBooking['equipment_bundle_fee'] ?? 0) + $additionalEquipmentCharge, 2);

            $snapshot = json_decode((string)($currentBooking['pricing_snapshot'] ?? ''), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }
            $snapshot['hourly_rate'] = $lockedHourlyRate;
            $snapshot['billable_hours'] = $newBillableHours;
            $snapshot['base_amount'] = round($lockedHourlyRate * $newBillableHours, 2);
            if ($lockedEquipmentHourlyRate > 0) {
                $snapshot['equipment_bundle_rate_unit'] = 'hour';
                $snapshot['equipment_bundle_hourly_rate'] = $lockedEquipmentHourlyRate;
                $snapshot['equipment_bundle_fee'] = $newEquipmentBundleFee;
            }
            $snapshot['total'] = $newEstimate;
            $snapshot['last_extension'] = [
                'added_hours' => $hours,
                'additional_charge' => $additionalCharge,
                'venue_charge' => $additionalVenueCharge,
                'equipment_charge' => $additionalEquipmentCharge,
                'previous_event_end' => $lockedEnd->format('Y-m-d H:i:s'),
                'new_event_end' => $newEnd->format('Y-m-d H:i:s'),
                'late_extension' => $lockedIsLateExtension,
                'historical_overlap_acknowledged' => !empty($historicalConflicts),
                'recorded_at' => $transactionNow->format('Y-m-d H:i:s'),
            ];

            $auditReason = $reason;
            if ($lockedIsLateExtension) {
                $auditReason .= "\n\nSystem audit: Late extension recorded on " . $transactionNow->format('M j, Y g:i A')
                    . ' after the original end time of ' . $lockedEnd->format('M j, Y g:i A') . '.';
            }
            if ($historicalConflicts) {
                $summary = reservation_extension_conflict_summary($historicalConflicts);
                $auditReason .= "\nSystem audit: Historical overlap acknowledged by " . (current_admin()['name'] ?? 'Administrator')
                    . ($summary !== '' ? '. Conflicting reservation(s): ' . $summary . '.' : '.');
            }

            $historyStmt = $pdo->prepare(
                'INSERT INTO reservation_extensions(
                    reservation_id,previous_event_end,new_event_end,previous_blocked_end,new_blocked_end,
                    added_hours,hourly_rate,additional_charge,previous_total,new_total,
                    previous_payment_status,new_payment_status,reason,changed_by
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $historyStmt->execute([
                $id,
                $currentBooking['event_end'],
                $newEnd->format('Y-m-d H:i:s'),
                $currentBooking['blocked_end'],
                $newBlockedEnd->format('Y-m-d H:i:s'),
                $hours,
                $lockedHourlyRate,
                $additionalCharge,
                $previousTarget,
                $newTarget,
                $previousPaymentStatus,
                $newPaymentStatus,
                $auditReason,
                current_admin()['id'],
            ]);

            $newReservationStatus = (string)$currentBooking['status'];
            if ($lockedIsLateExtension) {
                // If the revised end time is still historical, record the event as Completed.
                // If the extension reaches back into the future, reopen it as Approved so it
                // again holds the live calendar until the new end time.
                $newReservationStatus = $newEnd <= $transactionNow ? 'completed' : 'approved';
            }

            $updateStmt = $pdo->prepare(
                'UPDATE reservations SET status=?,event_end=?,blocked_end=?,billable_hours=?,estimated_amount=?,final_amount=?,equipment_bundle_fee=?,amount_paid=?,payment_status=?,pricing_snapshot=? WHERE id=?'
            );
            $updateStmt->execute([
                $newReservationStatus,
                $newEnd->format('Y-m-d H:i:s'),
                $newBlockedEnd->format('Y-m-d H:i:s'),
                $newBillableHours,
                $newEstimate,
                $newFinalAmount,
                $newEquipmentBundleFee,
                $newAmountPaid,
                $newPaymentStatus,
                reservation_pricing_snapshot($snapshot),
                $id,
            ]);

            $batchId = (int)($currentBooking['batch_id'] ?? 0);
            if ($batchId > 0) {
                reservation_sync_batch_summary($pdo, $batchId);
            }

            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
            $lockAcquired = false;

            $newBalance = max(0, round($newTarget - $newAmountPaid, 2));
            $message = ($lockedIsLateExtension ? 'Late extension recorded for reservation ' : 'Reservation ')
                . $currentBooking['reference_no']
                . ($lockedIsLateExtension ? ': ' : ' was extended by ')
                . ($lockedIsLateExtension ? reservation_duration_label($hours) . ' added, new actual end ' : reservation_duration_label($hours) . ' to ')
                . $newEnd->format('g:i A') . '. Additional charge: ' . money($additionalCharge) . '.';
            if ($historicalConflicts) {
                $message .= ' Historical overlap acknowledgement was saved to the extension audit history.';
            }
            if ($paymentAmount > 0) {
                $message .= ' Payment recorded: ' . money($paymentAmount) . '.';
            }
            $message .= $newBalance > 0 ? ' Remaining balance: ' . money($newBalance) . '.' : ' The reservation is fully paid.';
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
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be extended.';
        }
    }

    $selectedHours = reservation_duration_hours_from_input($form['extension_hours']) ?? min(1.0, $maxHours);
    $selectedHours = min($maxHours, max(0.5, $selectedHours));
    $previewEnd = reservation_add_duration($currentEnd, $selectedHours);
    $previewBlockedEnd = $previewEnd->modify('+' . max(0, (int)$booking['cleanup_minutes']) . ' minutes');
    $previewVenueCharge = round($hourlyRate * $selectedHours, 2);
    $previewEquipmentCharge = round($equipmentHourlyRate * $selectedHours, 2);
    $previewCharge = round($previewVenueCharge + $previewEquipmentCharge, 2);
    $previewEstimate = round((float)$booking['estimated_amount'] + $previewCharge, 2);
    $previewTarget = round($currentTarget + $previewCharge, 2);
    $previewBalanceBeforePayment = max(0, round($previewTarget - $currentPaid, 2));
    $previewPayment = is_numeric($form['payment_amount']) ? max(0, round((float)$form['payment_amount'], 2)) : 0.0;
    $previewBalanceAfterPayment = max(0, round($previewBalanceBeforePayment - $previewPayment, 2));
    try {
        $previewConflictAnalysis = reservation_extension_conflict_analysis($booking, $previewBlockedEnd, new DateTimeImmutable(), $isLateExtension);
    } catch (Throwable $e) {
        $previewConflictAnalysis = ['historical' => [], 'future' => []];
    }
    $previewHistoricalConflicts = $previewConflictAnalysis['historical'] ?? [];
    $previewFutureConflicts = $previewConflictAnalysis['future'] ?? [];
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-edit-panel extension-panel" data-extension-form data-current-end="<?= e($currentEnd->format(DateTimeInterface::ATOM)) ?>" data-hourly-rate="<?= e(number_format($hourlyRate, 2, '.', '')) ?>" data-equipment-hourly-rate="<?= e(number_format($equipmentHourlyRate, 2, '.', '')) ?>" data-current-target="<?= e(number_format($currentTarget, 2, '.', '')) ?>" data-current-paid="<?= e(number_format($currentPaid, 2, '.', '')) ?>" data-cleanup-minutes="<?= (int)$booking['cleanup_minutes'] ?>">
  <div class="panel-head">
    <div>
      <span class="small muted">Reservation <?= e($booking['reference_no']) ?></span>
      <h2><?= $isLateExtension ? 'Record Late Extension' : 'Extend Reservation' ?></h2>
      <p class="muted"><?= $isLateExtension ? 'Record extra venue time after the original booked end time has already passed. The original reference, client details, payments, discounts, and one-time add-ons remain attached.' : 'Add paid time in 30-minute increments to the existing reservation. Its reference number, client details, payments, and one-time add-ons remain unchanged.' ?></p>
    </div>
    <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" style="width:100%;margin:10px 0"><?= e($error) ?></div>
  <?php endforeach; ?>

  <?php if ($isLateExtension): ?>
    <div class="alert alert-warning extension-note"><strong>Late Extension:</strong> the original booked end time has already passed. Enter the additional time the client actually used. If the revised schedule still ends in the past, TLH will keep the reservation Completed. If it reaches into future time, TLH will reopen it as Approved and protect the remaining schedule.</div>
  <?php endif; ?>

  <div class="reservation-edit-summary extension-current-summary">
    <div><span>Current Schedule</span><strong><?= e($currentStart->format('M j, Y g:i A')) ?><br>to <?= e($currentEnd->format('g:i A')) ?></strong></div>
    <div><span>Reservation Status</span><strong><?= e(ucwords(str_replace('_', ' ', (string)$booking['status']))) ?><?= $isLateExtension ? '<br>Original end passed' : '' ?></strong></div>
    <div><span>Saved Venue Rate</span><strong><?= money($hourlyRate) ?>/hour</strong></div>
    <?php if ($equipmentHourlyRate > 0): ?><div><span>Equipment Bundle</span><strong><?= money($equipmentHourlyRate) ?>/hour</strong></div><?php endif; ?>
    <div><span>Current Total</span><strong><?= money($currentTarget) ?></strong></div>
    <div><span>Payment Position</span><strong><?= money($currentPaid) ?> paid<br><?= $currentPaid + 0.001 >= $currentTarget ? 'Fully paid' : money(max(0, $currentTarget - $currentPaid)) . ' balance' ?></strong></div>
  </div>

  <div class="alert alert-info extension-note">One-time add-ons are not charged again.<?php if ($equipmentHourlyRate > 0): ?> The Equipment Bundle continues at <?= money($equipmentHourlyRate) ?>/hour for the added billable time.<?php endif; ?> The cleanup period automatically moves after the new end time and remains calendar-blocking only.</div>

  <form method="post" class="form-grid" id="reservationExtensionForm" data-extension-live-conflict data-conflict-url="reservation-conflict-check.php" data-booking-id="<?= $id ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-group">
      <label>Additional Duration *</label>
      <select name="extension_hours" id="extensionHours" required>
        <?php foreach (reservation_duration_options($maxHours) as $hoursValue => $hoursLabel): ?>
          <option value="<?= e($hoursValue) ?>" <?= abs($selectedHours - (float)$hoursValue) < 0.001 ? 'selected' : '' ?>><?= e($hoursLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field-help">The reservation must finish by 10:00 PM.</span>
    </div>

    <div class="form-group">
      <label>New End Time</label>
      <input id="extensionNewEnd" value="<?= e($previewEnd->format('M j, Y g:i A')) ?>" readonly>
      <span class="field-help">Cleanup blocks the calendar until <strong id="extensionBlockedEnd"><?= e($previewBlockedEnd->format('g:i A')) ?></strong>.</span>
    </div>

    <div class="form-group full">
      <div class="schedule-availability" data-extension-live-status data-state="idle" role="status" aria-live="polite">
        <span class="schedule-availability-icon" aria-hidden="true">○</span>
        <div><strong><?= $isLateExtension ? 'Late extension conflict check' : 'Live conflict check' ?></strong><p><?= $isLateExtension ? 'Checking historical and future overlaps for the added time.' : 'Checking whether the additional time is still available.' ?></p></div>
      </div>
    </div>

    <?php if ($isLateExtension): ?>
      <div class="form-group full" data-historical-overlap-ack-wrap <?= $previewHistoricalConflicts && !$previewFutureConflicts && is_admin() ? '' : 'hidden' ?>>
        <div class="alert alert-warning" style="margin:0">
          <label style="display:flex;gap:10px;align-items:flex-start;margin:0">
            <input type="checkbox" name="historical_overlap_ack" value="1" data-historical-overlap-ack <?= $historicalOverlapAcknowledged ? 'checked' : '' ?> style="width:auto;margin-top:3px">
            <span><strong>Record Historical Overlap</strong><br><span class="small">I confirm that this late extension records what actually happened even though the added historical time overlaps another reservation. This acknowledgement and the conflict references will be saved in Extension History.</span></span>
          </label>
          <?php if ($previewHistoricalConflicts): ?><div class="small" style="margin-top:8px"><strong>Detected:</strong> <?= e(reservation_extension_conflict_summary($previewHistoricalConflicts)) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="form-group full">
      <label><?= $isLateExtension ? 'Reason for Late Extension *' : 'Reason for Extension *' ?></label>
      <textarea name="reason" maxlength="1000" required placeholder="<?= $isLateExtension ? 'Example: Client informed us late that they used one additional hour.' : 'Example: Client requested an additional hour to finish the event.' ?>"><?= e($form['reason']) ?></textarea>
      <span class="field-help">This reason will appear in the reservation’s extension history.</span>
    </div>

    <div class="form-group full">
      <div class="reschedule-preview extension-preview">
        <div><span>Additional Charge</span><strong id="extensionCharge"><?= money($previewCharge) ?></strong></div>
        <div><span>New Payable Total</span><strong id="extensionNewTotal"><?= money($previewTarget) ?></strong></div>
        <div><span>Balance Before New Payment</span><strong id="extensionBalanceBeforePayment"><?= money($previewBalanceBeforePayment) ?></strong></div>
      </div>
    </div>

    <div class="form-group full extension-payment-card">
      <div class="extension-payment-heading">
        <div><h3>Optional Payment</h3><p class="muted">Record a partial or full payment together with the extension.</p></div>
        <span class="status-pill status-<?= badge_class($currentPaymentStatus) ?>"><?= e(ucfirst($currentPaymentStatus)) ?></span>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Payment Amount</label><input id="extensionPaymentAmount" type="number" min="0" max="<?= e(number_format($previewBalanceBeforePayment, 2, '.', '')) ?>" step="0.01" name="payment_amount" value="<?= e($form['payment_amount']) ?>" placeholder="0.00"><span class="field-help">Leave blank if payment will be recorded later.</span></div>
        <div class="form-group"><label>Balance After Payment</label><input id="extensionBalanceAfterPayment" value="<?= e(money($previewBalanceAfterPayment)) ?>" readonly></div>
      </div>
      <div class="form-grid extension-payment-fields" id="extensionPaymentFields" <?= $previewPayment > 0 ? '' : 'hidden' ?>>
        <div class="form-group"><label>Payment Method</label><select name="payment_method" data-booking-payment-method <?= $previewPayment > 0 ? '' : 'disabled' ?>><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= $form['payment_method'] === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference / OR Number</label><input name="payment_reference" maxlength="120" value="<?= e($form['payment_reference']) ?>" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no." <?= $previewPayment > 0 ? '' : 'disabled' ?>><span class="field-help" data-booking-payment-reference-help>Optional: enter the OR / official receipt number for cash payments.</span></div>
        <div class="form-group"><label>Paid At</label><input type="datetime-local" name="paid_at" value="<?= e($form['paid_at']) ?>" <?= $previewPayment > 0 ? '' : 'disabled' ?>></div>
        <div class="form-group"><label>Payment Notes</label><input name="payment_notes" maxlength="500" value="<?= e($form['payment_notes']) ?>" placeholder="Optional" <?= $previewPayment > 0 ? '' : 'disabled' ?>></div>
      </div>
    </div>

    <div class="form-group full reservation-edit-actions">
      <a class="btn btn-outline" href="reservation-view.php?id=<?= $id ?>">Cancel</a>
      <button class="btn btn-primary" type="submit"><?= $isLateExtension ? 'Record Late Extension' : 'Confirm Extension' ?></button>
    </div>
  </form>
</section>
<script>
(function () {
  const panel = document.querySelector('[data-extension-form]');
  const form = document.getElementById('reservationExtensionForm');
  if (!panel || !form) return;
  const hours = document.getElementById('extensionHours');
  const paymentAmount = document.getElementById('extensionPaymentAmount');
  const paymentFields = document.getElementById('extensionPaymentFields');
  const currentEnd = new Date(panel.dataset.currentEnd);
  const hourlyRate = Number(panel.dataset.hourlyRate || 0);
  const equipmentHourlyRate = Number(panel.dataset.equipmentHourlyRate || 0);
  const currentTarget = Number(panel.dataset.currentTarget || 0);
  const currentPaid = Number(panel.dataset.currentPaid || 0);
  const cleanupMinutes = Number(panel.dataset.cleanupMinutes || 0);
  const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  const dateTime = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  const timeOnly = new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' });

  const update = () => {
    const addedHours = Math.max(0.5, Number(hours.value || 0.5));
    const newEnd = new Date(currentEnd.getTime() + addedHours * 3600000);
    const blockedEnd = new Date(newEnd.getTime() + cleanupMinutes * 60000);
    const charge = (hourlyRate + equipmentHourlyRate) * addedHours;
    const total = currentTarget + charge;
    const balanceBefore = Math.max(0, total - currentPaid);
    const payment = Math.max(0, Number(paymentAmount.value || 0));
    const balanceAfter = Math.max(0, balanceBefore - payment);
    document.getElementById('extensionNewEnd').value = dateTime.format(newEnd);
    document.getElementById('extensionBlockedEnd').textContent = timeOnly.format(blockedEnd);
    document.getElementById('extensionCharge').textContent = currency.format(charge);
    document.getElementById('extensionNewTotal').textContent = currency.format(total);
    document.getElementById('extensionBalanceBeforePayment').textContent = currency.format(balanceBefore);
    document.getElementById('extensionBalanceAfterPayment').value = currency.format(balanceAfter);
    paymentAmount.max = balanceBefore.toFixed(2);

    const hasPayment = payment > 0;
    paymentFields.hidden = !hasPayment;
    paymentFields.querySelectorAll('input, select').forEach((field) => { field.disabled = !hasPayment; });
    const method = paymentFields.querySelector('[data-booking-payment-method]');
    if (hasPayment && method) method.dispatchEvent(new Event('change', { bubbles: true }));
  };
  hours.addEventListener('change', update);
  paymentAmount.addEventListener('input', update);
  update();
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
