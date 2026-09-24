<?php
/**
 * FILE PURPOSE: Summary-first Reservation Details page with view, status/payment dialogs, actions, and history.
 * DEBUGGING: This page should remain view-first. Served/archived records must not expose operational edit actions.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('reservations.php');
}

try {
    $stmt = db()->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.id=?');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
} catch (Throwable $e) {
    $booking = false;
}
if (!$booking) {
    flash('danger', 'Reservation not found.');
    redirect('reservations.php');
}

$viewer = current_admin();
mark_admin_notifications_for_reservation_read($id, isset($viewer['id']) ? (int)$viewer['id'] : null);
$bookingHasEnded = reservation_has_ended($booking);

// Compact dialog actions (status/notes/payment) post back here. Keep the same
// served/archived locks used by the dedicated action pages.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if (reservation_is_archived($booking) && $action !== 'add_payment') {
        flash('danger', 'Restore this reservation from Archives before changing its operational status. Financial payments may still be recorded while archived.');
        redirect('reservation-view.php?id=' . $id);
    }

    try {
        if ($action === 'update_status' && reservation_has_ended($booking)) {
            throw new RuntimeException('This reservation has already ended. Operational details and status are locked; payments may still be recorded.');
        }
        if ($action === 'update_status') {
            $status = $_POST['status'] ?? '';
            $notes = trim($_POST['admin_notes'] ?? '');
            $final = trim($_POST['final_amount'] ?? '');

            if (!in_array($status, ['pending', 'for_review', 'approved', 'rejected', 'cancelled', 'completed', 'no_show'], true)) {
                throw new RuntimeException('Invalid status.');
            }
            if ($status === 'cancelled' && (string)$booking['status'] !== 'cancelled') {
                throw new RuntimeException('Use the Cancel Reservation action so the 50% cancellation charge and refund are calculated correctly.');
            }
            if ((string)$booking['status'] === 'cancelled' && $status !== 'cancelled') {
                throw new RuntimeException('A cancelled reservation cannot be reactivated from the status form. Create a new reservation or rebook it instead.');
            }
            if ($final !== '' && (!is_numeric($final) || (float)$final < 0)) {
                throw new RuntimeException('Enter a valid final amount.');
            }

            $finalAmount = (string)$booking['status'] === 'cancelled'
                ? reservation_payment_target($booking)
                : ($final === '' ? null : round((float)$final, 2));
            $pdo = db();
            $approvalLockAcquired = false;
            if ($status === 'approved' && (string)$booking['status'] !== 'approved') {
                $approvalLockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
                if (!$approvalLockAcquired) {
                    throw new RuntimeException('The booking calendar is busy. Please try approving the reservation again.');
                }
                if (reservation_conflict((string)$booking['blocked_start'], (string)$booking['blocked_end'], $id)) {
                    $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                    $approvalLockAcquired = false;
                    throw new RuntimeException('This request cannot be approved because another approved or staff-held reservation now overlaps the selected schedule. Reschedule or reject this request first.');
                }
            }
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
            $lockStmt->execute([$id]);
            $currentBooking = $lockStmt->fetch();
            if (!$currentBooking || reservation_is_archived($currentBooking)) {
                throw new RuntimeException('This reservation is no longer available for updating.');
            }
            if (reservation_has_ended($currentBooking)) {
                throw new RuntimeException('This reservation ended while the update was being processed. Operational changes are now locked.');
            }
            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $totalStmt->execute([$id]);
            $totalPaid = round((float)$totalStmt->fetchColumn(), 2);
            $grossTotal = max(0, round((float)$currentBooking['estimated_amount'], 2));
            $target = $finalAmount ?? $grossTotal;
            $paymentStatus = payment_status_for_amount($totalPaid, $target);

            // Final Amount and Flexible Discount are two views of the same net
            // price. Keep them synchronized so summaries/printouts cannot show
            // a stale discount after a manual final-amount adjustment.
            $syncedDiscount = (string)$currentBooking['status'] === 'cancelled'
                ? max(0, round((float)($currentBooking['discount_amount'] ?? 0), 2))
                : ($finalAmount === null ? 0.0 : reservation_discount_for_payable($grossTotal, $target));
            $syncedDiscountReason = $syncedDiscount > 0.001
                ? ((string)($currentBooking['discount_reason'] ?? '') !== '' ? (string)$currentBooking['discount_reason'] : null)
                : null;

            $stmt = $pdo->prepare('UPDATE reservations SET status=?,admin_notes=?,final_amount=?,discount_amount=?,discount_reason=?,amount_paid=?,payment_status=? WHERE id=?');
            $stmt->execute([$status, $notes, $finalAmount, $syncedDiscount, $syncedDiscountReason, $totalPaid, $paymentStatus, $id]);
            $batchId = (int)($currentBooking['batch_id'] ?? 0);
            if ($batchId > 0) {
                reservation_sync_batch_summary($pdo, $batchId);
            }
            $pdo->commit();
            if (!empty($approvalLockAcquired)) {
                $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                $approvalLockAcquired = false;
            }
            flash('success', $status === 'approved' ? 'Reservation approved. The selected schedule is now secured on the calendar.' : 'Reservation details updated.');
        } elseif ($action === 'add_payment') {
            $amountRaw = trim($_POST['amount'] ?? '');
            $method = trim($_POST['payment_method'] ?? '');
            $reference = trim($_POST['payment_reference'] ?? '');
            $notes = trim($_POST['payment_notes'] ?? '');
            $paidAt = $_POST['paid_at'] ?? date('Y-m-d\TH:i');

            if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
                throw new RuntimeException('Enter a valid payment amount.');
            }
            $amount = round((float)$amountRaw, 2);
            if (!in_array($method, booking_payment_methods(), true)) {
                throw new RuntimeException('Choose a valid payment method.');
            }
            if ($method !== 'Cash' && $reference === '') {
                throw new RuntimeException('Enter a transaction reference or OR number for non-cash payments.');
            }
            if (strlen($reference) > 120) {
                throw new RuntimeException('The payment reference may not exceed 120 characters.');
            }

            $paid = new DateTimeImmutable($paidAt);
            $pdo = db();
            $pdo->beginTransaction();

            $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
            $bookingStmt->execute([$id]);
            $currentBooking = $bookingStmt->fetch();
            if (!$currentBooking) {
                throw new RuntimeException('Reservation not found.');
            }
            $paymentEligibility = reservation_payment_eligibility($currentBooking, (string)($currentBooking['status'] ?? '') === 'cancelled');
            if (!$paymentEligibility['allowed']) {
                throw new RuntimeException($paymentEligibility['message']);
            }
            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $totalStmt->execute([$id]);
            $currentTotal = round((float)$totalStmt->fetchColumn(), 2);
            $target = reservation_payment_target($currentBooking);
            $remaining = max(0, round($target - $currentTotal, 2));
            if ($amount > $remaining + 0.001) {
                throw new RuntimeException('The payment cannot exceed the remaining balance of ' . money($remaining) . '.');
            }

            $stmt = $pdo->prepare('INSERT INTO payments(reservation_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$id, $amount, $method, $reference ?: null, $notes, current_admin()['id'], $paid->format('Y-m-d H:i:s')]);

            $total = round($currentTotal + $amount, 2);
            $paymentStatus = payment_status_for_amount($total, $target);
            $stmt = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');
            $stmt->execute([$total, $paymentStatus, $id]);
            $pdo->commit();
            flash('success', 'Payment recorded successfully.');
        }

        redirect('reservation-view.php?id=' . $id);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($approvalLockAcquired) && isset($pdo)) {
            try { $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')"); } catch (Throwable $ignore) {}
            $approvalLockAcquired = false;
        }
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The update could not be saved.');
        redirect('reservation-view.php?id=' . $id);
    }
}

$adminPageTitle = 'Reservation ' . $booking['reference_no'];
$bookingIsArchived = reservation_is_archived($booking);
$bookingIsCancelled = (string)($booking['status'] ?? '') === 'cancelled';
$bookingCanLateExtend = !$bookingIsArchived && reservation_can_late_extend($booking);
$bookingNeedsResolution = reservation_needs_resolution($booking);
try {
    $stmt = db()->prepare('SELECT p.*,a.full_name recorder,bp.batch_payment_no,bp.batch_id AS payment_batch_id FROM payments p LEFT JOIN admins a ON a.id=p.recorded_by LEFT JOIN batch_payments bp ON bp.id=p.batch_payment_id WHERE p.reservation_id=? ORDER BY p.paid_at DESC');
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();
} catch (Throwable $e) {
    $payments = [];
}

try {
    $stmt = db()->prepare('SELECT r.*,a.full_name changed_by_name FROM reservation_reschedules r LEFT JOIN admins a ON a.id=r.changed_by WHERE r.reservation_id=? ORDER BY r.created_at DESC, r.id DESC');
    $stmt->execute([$id]);
    $reschedules = $stmt->fetchAll();
} catch (Throwable $e) {
    $reschedules = [];
}

try {
    $stmt = db()->prepare('SELECT x.*,a.full_name changed_by_name FROM reservation_extensions x LEFT JOIN admins a ON a.id=x.changed_by WHERE x.reservation_id=? ORDER BY x.created_at DESC, x.id DESC');
    $stmt->execute([$id]);
    $extensions = $stmt->fetchAll();
} catch (Throwable $e) {
    $extensions = [];
}

$cancellation = reservation_cancellation_record($id);
try {
    $ledgerTotals = reservation_payment_ledger_totals($id);
} catch (Throwable $e) {
    $ledgerTotals = [
        'gross_paid' => max(0, (float)($booking['amount_paid'] ?? 0)),
        'refunded' => 0.0,
        'net_paid' => (float)($booking['amount_paid'] ?? 0),
    ];
}
$booking['amount_paid'] = $ledgerTotals['net_paid'];
$refundPending = $cancellation && (string)$cancellation['refund_status'] === 'pending'
    ? max(0, round((float)$cancellation['refund_due'] - (float)$cancellation['refunded_amount'], 2))
    : 0.0;

$paymentTarget = reservation_payment_target($booking);
$reservationDiscountAmount = max(0, round((float)($booking['discount_amount'] ?? 0), 2));
$originalClientPayable = $cancellation
    ? max(0, round((float)($cancellation['original_total'] ?? 0), 2))
    : $paymentTarget;
$reservationCalculatedTotal = round($originalClientPayable + $reservationDiscountAmount, 2);
$remainingBalance = reservation_remaining_balance($booking);
$excessCredit = reservation_excess_credit($booking);
$reservationInclusions = reservation_inclusions_for_booking($booking);
$baseRentalAmount = $booking['hourly_rate'] !== null && $booking['billable_hours'] !== null
    ? (float)$booking['hourly_rate'] * (float)$booking['billable_hours']
    : (float)$booking['estimated_amount'];
$additionalServiceLabels = [];
if (!empty($booking['shower_room_addon'])) {
    $additionalServiceLabels[] = reservation_shower_is_complimentary($booking) ? 'Shower Room (Complimentary)' : 'Shower Room';
}
if (!empty($booking['equipment_bundle_addon'])) {
    $additionalServiceLabels[] = ($booking['pricing_package'] ?? '') === 'regular'
        ? (reservation_equipment_is_complimentary($booking) ? 'Equipment Bundle (Complimentary)' : 'Equipment Bundle')
        : 'Equipment Bundle (Included)';
}
$statusLabel = ucwords(str_replace('_', ' ', (string)$booking['status']));
$sourceLabel = ucwords(str_replace('_', ' ', (string)$booking['source']));
$paymentMethodLabel = $booking['booking_payment_method']
    ? booking_payment_method_label((string)$booking['booking_payment_method'])
    : '—';
$financialAttentionLabel = $cancellation && $refundPending > 0
    ? 'Refund Pending'
    : ($excessCredit > 0 ? 'Credit' : 'Balance');
$financialAttentionAmount = $cancellation && $refundPending > 0
    ? $refundPending
    : ($excessCredit > 0 ? $excessCredit : $remainingBalance);
$canUpdateReservation = !$bookingIsArchived && !$bookingHasEnded;
// Payment is a financial event, not an operational schedule change. A balance
// can be collected after the event and even after the reservation is archived.
$canRecordPayment = $remainingBalance > 0 && reservation_can_receive_payment($booking, $bookingIsCancelled);
$hasMoreActions = $canUpdateReservation
    || !empty($booking['batch_id'])
    || (!$bookingIsArchived && reservation_can_cancel($booking))
    || (!$bookingIsArchived && reservation_can_archive($booking))
    || $bookingIsArchived
    || is_admin();
include __DIR__ . '/_header.php';
?>
<div class="reservation-detail-page">
  <section class="panel reservation-overview-panel">
    <div class="reservation-overview-top">
      <div class="reservation-overview-copy">
        <div class="reservation-overview-statuses">
          <span class="status-pill status-<?= badge_class($booking['status']) ?>"><?= e($statusLabel) ?></span>
          <?php if (str_starts_with((string)($booking['admin_notes'] ?? ''), '[HISTORICAL BACKFILL]')): ?><span class="status-pill status-secondary">Historical Backfill</span><?php endif; ?>
          <?php if ($bookingIsArchived): ?><span class="status-pill status-secondary">Archived</span><?php endif; ?>
          <?php if ($bookingNeedsResolution): ?><span class="status-pill status-warning">Needs Resolution</span><?php elseif ($bookingHasEnded && !$bookingIsArchived): ?><span class="status-pill status-secondary"><?= $bookingCanLateExtend ? 'Ended · Late Extension Available' : 'Ended · Locked' ?></span><?php endif; ?>
          <?php if (!empty($booking['batch_id'])): ?><a class="reservation-batch-chip" href="batch-reservation-view.php?id=<?= (int)$booking['batch_id'] ?>"><?= e($booking['batch_reference'] ?: ('Batch #' . (int)$booking['batch_id'])) ?> · #<?= (int)$booking['batch_occurrence'] ?></a><?php endif; ?>
        </div>
        <h2><?= date('M j, Y', strtotime($booking['event_start'])) ?> · <?= date('g:i A', strtotime($booking['event_start'])) ?>–<?= date('g:i A', strtotime($booking['event_end'])) ?></h2>
        <p class="reservation-overview-client"><strong><?= e($booking['client_name']) ?></strong><?= !empty($booking['organization']) ? ' · ' . e($booking['organization']) : '' ?></p>
        <p class="reservation-overview-meta"><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?> · <?= (int)$booking['guest_count'] ?> guest<?= (int)$booking['guest_count'] === 1 ? '' : 's' ?> · <?= e(reservation_cooling_label($booking['cooling_option'] ?? null)) ?></p>
      </div>

      <div class="reservation-overview-actions" aria-label="Reservation actions">
        <a class="btn btn-outline btn-sm" href="reservation-print.php?id=<?= $id ?>" target="_blank" rel="noopener">Print</a>
        <?php if (!$bookingIsArchived && reservation_can_edit($booking)): ?><a class="btn btn-primary btn-sm" href="reservation-edit.php?id=<?= $id ?>">Edit</a><?php endif; ?>
        <?php if (!$bookingIsArchived && reservation_can_reschedule($booking)): ?><a class="btn btn-outline btn-sm" href="reservation-reschedule.php?id=<?= $id ?>">Reschedule</a><?php endif; ?>
        <?php if (!$bookingIsArchived && reservation_can_extend($booking)): ?><a class="btn btn-outline btn-sm" href="reservation-extend.php?id=<?= $id ?>">Extend</a><?php elseif ($bookingCanLateExtend): ?><a class="btn btn-outline btn-sm" href="reservation-extend.php?id=<?= $id ?>">Late Extension</a><?php endif; ?>
        <?php if ($hasMoreActions): ?><details class="reservation-action-menu">
          <summary class="btn btn-outline btn-sm">More <span aria-hidden="true">▾</span></summary>
          <div class="reservation-action-menu-popover">
            <?php if ($canUpdateReservation): ?><button type="button" data-open-reservation-dialog="update">Update status &amp; notes</button><?php endif; ?>
            <?php if (!empty($booking['batch_id'])): ?><a href="batch-reservation-view.php?id=<?= (int)$booking['batch_id'] ?>">View batch group</a><?php endif; ?>
            <?php if (!$bookingIsArchived && reservation_can_cancel($booking)): ?><a class="is-danger" href="reservation-cancel.php?id=<?= $id ?>">Cancel reservation</a><?php endif; ?>
            <?php if (!$bookingIsArchived && reservation_can_archive($booking)): ?>
              <form method="post" action="reservation-archive.php" onsubmit="return confirm('Mark this reservation as Done and move it to Archives?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <input type="hidden" name="return_to" value="reservations.php">
                <button type="submit">Done · Move to Archives</button>
              </form>
            <?php elseif ($bookingIsArchived): ?>
              <form method="post" action="reservation-archive.php" onsubmit="return confirm('Restore this reservation to the active reservation list?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="reservation_id" value="<?= $id ?>">
                <input type="hidden" name="return_to" value="archives.php">
                <button type="submit">Restore reservation</button>
              </form>
            <?php endif; ?>
            <?php if (is_admin()): ?>
              <a class="is-danger" href="reservation-delete.php?id=<?= $id ?>&amp;return_to=<?= e(rawurlencode($bookingIsArchived ? 'archives.php' : ($bookingIsCancelled ? 'cancelled.php' : 'reservations.php'))) ?>">Delete reservation permanently</a>
            <?php endif; ?>
          </div>
        </details><?php endif; ?>
      </div>
    </div>

    <div class="reservation-finance-strip<?= $reservationDiscountAmount > 0 ? ' has-discount' : '' ?>" aria-label="Reservation financial summary">
      <?php if ($cancellation): ?>
        <?php if ($reservationDiscountAmount > 0): ?>
          <div><span>Original Calculated Total</span><strong><?= money($reservationCalculatedTotal) ?></strong></div>
          <div class="reservation-finance-discount"><span>Discount</span><strong>−<?= money($reservationDiscountAmount) ?></strong></div>
        <?php endif; ?>
        <div><span>Original Client Payable</span><strong><?= money($originalClientPayable) ?></strong></div>
        <div><span>Cancellation Charge</span><strong><?= money($paymentTarget) ?></strong></div>
      <?php elseif ($reservationDiscountAmount > 0): ?>
        <div><span>Calculated Total</span><strong><?= money($reservationCalculatedTotal) ?></strong></div>
        <div class="reservation-finance-discount"><span>Discount</span><strong>−<?= money($reservationDiscountAmount) ?></strong></div>
        <div><span>Client Payable</span><strong><?= money($paymentTarget) ?></strong></div>
      <?php else: ?>
        <div><span>Total</span><strong><?= money($paymentTarget) ?></strong></div>
      <?php endif; ?>
      <div><span><?= $cancellation ? 'Net Paid' : 'Paid' ?></span><strong><?= money($booking['amount_paid']) ?></strong></div>
      <div class="<?= $financialAttentionAmount > 0 ? 'has-attention' : '' ?>"><span><?= e($financialAttentionLabel) ?></span><strong><?= money($financialAttentionAmount) ?></strong></div>
      <div><span>Payment</span><strong><?= e(ucwords(str_replace('_', ' ', (string)$booking['payment_status']))) ?></strong></div>
    </div>
  </section>

  <?php if ($bookingNeedsResolution): ?>
    <div class="alert alert-warning reservation-detail-notice reservation-resolution-notice">
      <div><strong>Needs resolution · This reservation is not completed.</strong> The event end time passed while the status remained <?= e(ucwords(str_replace('_', ' ', (string)$booking['status']))) ?>. Choose what actually happened; time alone will never complete this booking.</div>
      <div class="reservation-resolution-actions">
        <form method="post" action="reservation-resolve.php" onsubmit="return confirm('Confirm that this event actually occurred? The reservation will become Completed.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= $id ?>"><input type="hidden" name="outcome" value="occurred"><input type="hidden" name="return_to" value="reservation-view.php?id=<?= $id ?>"><button class="btn btn-primary btn-sm" type="submit">Event Occurred</button></form>
        <form method="post" action="reservation-resolve.php" onsubmit="return confirm('Mark this reservation as No Show?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= $id ?>"><input type="hidden" name="outcome" value="no_show"><input type="hidden" name="return_to" value="reservation-view.php?id=<?= $id ?>"><button class="btn btn-outline btn-sm" type="submit">No Show</button></form>
        <form method="post" action="reservation-resolve.php" onsubmit="return confirm('Mark this pending request as Did Not Proceed?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= $id ?>"><input type="hidden" name="outcome" value="did_not_proceed"><input type="hidden" name="return_to" value="reservation-view.php?id=<?= $id ?>"><button class="btn btn-outline btn-sm" type="submit">Did Not Proceed</button></form>
      </div>
    </div>
  <?php elseif ($bookingIsArchived): ?>
    <div class="alert alert-info reservation-detail-notice"><strong>Archived event record.</strong> Operational details and status are locked, but any outstanding balance can still be recorded using the actual payment date and time.</div>
  <?php elseif ($bookingHasEnded): ?>
    <div class="alert alert-info reservation-detail-notice"><strong>Event time has passed · Operational changes locked.</strong> Outstanding payments can still be recorded later using the actual payment date. Approved bookings can be marked Done and moved to Archives when appropriate.</div>
  <?php endif; ?>

  <div class="reservation-detail-sections">
    <section class="panel reservation-info-card">
      <div class="reservation-card-head"><div><span class="reservation-card-kicker">Schedule</span><h2>Reservation</h2></div><?php if ($canUpdateReservation): ?><button class="reservation-card-link" type="button" data-open-reservation-dialog="update">Update status</button><?php endif; ?></div>
      <dl class="reservation-info-list">
        <div><dt>Date &amp; Time</dt><dd><?= date('M j, Y', strtotime($booking['event_start'])) ?><br><span><?= date('g:i A', strtotime($booking['event_start'])) ?> – <?= date('g:i A', strtotime($booking['event_end'])) ?></span></dd></div>
        <div><dt>Type</dt><dd><?= e(reservation_type_label($booking['reservation_type'])) ?></dd></div>
        <div><dt>Package</dt><dd><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?></dd></div>
        <div><dt>Guests</dt><dd><?= e((string)($booking['guest_count'] ?: '—')) ?></dd></div>
        <div><dt>Cooling</dt><dd><?= e(reservation_cooling_label($booking['cooling_option'] ?? null)) ?></dd></div>
        <div><dt>Purpose</dt><dd><?= e($booking['purpose'] ?: '—') ?></dd></div>
        <div><dt>Add-ons</dt><dd><?= e($additionalServiceLabels ? implode(', ', $additionalServiceLabels) : 'None') ?></dd></div>
      </dl>
    </section>

    <section class="panel reservation-info-card">
      <div class="reservation-card-head"><div><span class="reservation-card-kicker">Contact</span><h2>Client</h2></div></div>
      <dl class="reservation-info-list">
        <div><dt>Name</dt><dd><?= e($booking['client_name']) ?></dd></div>
        <div><dt>Organization / Team</dt><dd><?= e($booking['organization'] ?: '—') ?></dd></div>
        <div><dt>Email</dt><dd class="reservation-break-text"><?= e($booking['email']) ?></dd></div>
        <div><dt>Phone</dt><dd><?= e($booking['phone']) ?></dd></div>
        <div><dt>Source</dt><dd><?= e($sourceLabel) ?></dd></div>
      </dl>
    </section>

    <section class="panel reservation-info-card reservation-pricing-card">
      <div class="reservation-card-head"><div><span class="reservation-card-kicker">Financials</span><h2>Pricing &amp; Payment</h2></div><span class="status-pill status-<?= badge_class($booking['payment_status']) ?>"><?= e(ucwords(str_replace('_', ' ', (string)$booking['payment_status']))) ?></span></div>
      <dl class="reservation-info-list reservation-money-list">
        <div><dt>Base Rental</dt><dd><?= money($baseRentalAmount) ?><span><?= $booking['hourly_rate'] !== null && $booking['billable_hours'] !== null ? reservation_duration_label((float)$booking['billable_hours']) . ' × ' . money($booking['hourly_rate']) : 'Saved estimate' ?></span></dd></div>
        <?php if (!empty($booking['shower_room_addon'])): ?><div><dt>Shower Room</dt><dd><?= reservation_shower_is_complimentary($booking) ? 'Complimentary' : money($booking['shower_room_fee'] ?? 0) ?></dd></div><?php endif; ?>
        <?php if (!empty($booking['equipment_bundle_addon'])): ?><div><dt>Equipment Bundle</dt><dd><?php if (($booking['pricing_package'] ?? '') !== 'regular'): ?>Included<?php elseif (reservation_equipment_is_complimentary($booking)): ?>Complimentary<?php else: ?><?= money($booking['equipment_bundle_fee'] ?? 0) ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($cancellation): ?>
          <?php if ($reservationDiscountAmount > 0): ?>
            <div><dt>Original Calculated Total</dt><dd><?= money($reservationCalculatedTotal) ?></dd></div>
            <div class="reservation-money-discount"><dt>Discount</dt><dd>−<?= money($reservationDiscountAmount) ?><?php if (!empty($booking['discount_reason'])): ?><span><?= e((string)$booking['discount_reason']) ?></span><?php endif; ?></dd></div>
          <?php endif; ?>
          <div><dt>Original Client Payable</dt><dd><?= money($originalClientPayable) ?></dd></div>
        <?php elseif ($reservationDiscountAmount > 0): ?>
          <div><dt>Calculated Total</dt><dd><?= money($reservationCalculatedTotal) ?></dd></div>
          <div class="reservation-money-discount"><dt>Discount</dt><dd>−<?= money($reservationDiscountAmount) ?><?php if (!empty($booking['discount_reason'])): ?><span><?= e((string)$booking['discount_reason']) ?></span><?php endif; ?></dd></div>
        <?php endif; ?>
        <div class="reservation-money-total"><dt><?= $cancellation ? 'Cancellation Charge' : ($reservationDiscountAmount > 0 ? 'Client Payable' : 'Total') ?></dt><dd><?= money($paymentTarget) ?></dd></div>
        <div><dt><?= $cancellation ? 'Net Paid' : 'Paid' ?></dt><dd><?= money($booking['amount_paid']) ?></dd></div>
        <div class="<?= $financialAttentionAmount > 0 ? 'reservation-money-attention' : '' ?>"><dt><?= e($financialAttentionLabel) ?></dt><dd><?= money($financialAttentionAmount) ?></dd></div>
        <div><dt>Selected Method</dt><dd><?= e($paymentMethodLabel) ?></dd></div>
        <div><dt>Client Payment Selection</dt><dd><?= e(match ((string)($booking['booking_payment_choice'] ?? 'none')) { 'full' => 'Full payment', 'partial' => 'Partial payment', default => 'No payment yet' }) ?></dd></div>
        <?php if (in_array((string)($booking['booking_payment_choice'] ?? ''), ['partial','full'], true)): ?><div><dt>Intended Amount</dt><dd><?= money((float)($booking['booking_payment_intent_amount'] ?? 0)) ?></dd></div><?php endif; ?>
      </dl>
      <div class="reservation-payment-actions">
        <?php if ($refundPending > 0): ?><a class="btn btn-primary btn-sm" href="reservation-cancel.php?id=<?= $id ?>">Record Refund</a>
        <?php elseif ($canRecordPayment): ?><button class="btn btn-primary btn-sm" type="button" data-open-reservation-dialog="payment"><?= $cancellation ? 'Record Cancellation Payment' : 'Record Payment' ?></button>
        <?php elseif ($excessCredit > 0): ?><span class="small muted">Excess payment credit: <?= money($excessCredit) ?></span>
        <?php else: ?><span class="small reservation-paid-note">✓ Fully paid</span><?php endif; ?>
      </div>
    </section>
  </div>

  <details class="panel reservation-detail-collapse">
    <summary><span><strong>More Reservation Details</strong><small>Inclusions, internal schedule, notes, requests, and creation details</small></span><span class="reservation-collapse-icon" aria-hidden="true">+</span></summary>
    <div class="reservation-detail-collapse-body">
      <div class="reservation-secondary-grid">
        <div><span>Blocked Schedule</span><strong><?= date('M j, Y g:i A', strtotime($booking['blocked_start'])) ?> – <?= date('M j, Y g:i A', strtotime($booking['blocked_end'])) ?></strong></div>
        <div><span>Rate Period</span><strong><?= e(!empty($booking['rate_period']) ? ucwords(str_replace('_', ' ', $booking['rate_period'])) : '—') ?></strong></div>
        <div><span>Hourly Rate</span><strong><?= $booking['hourly_rate'] !== null ? money($booking['hourly_rate']) : '—' ?></strong></div>
        <div><span>Billable Duration</span><strong><?= !empty($booking['billable_hours']) ? e(reservation_duration_label((float)$booking['billable_hours'])) : '—' ?></strong></div>
        <div><span>Created</span><strong><?= date('M j, Y g:i A', strtotime($booking['created_at'])) ?></strong></div>
        <div><span>Reference</span><strong><?= e($booking['reference_no']) ?></strong></div>
      </div>

      <div class="reservation-expanded-grid">
        <div class="reservation-inclusions-card" aria-labelledby="reservationInclusionsTitle">
          <h3 id="reservationInclusionsTitle">Reservation Inclusions</h3>
          <ul class="reservation-inclusions-list">
            <?php foreach ($reservationInclusions as $inclusion): ?><li><?= e($inclusion) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <div class="reservation-text-details">
          <div><h3>Details</h3><p><?= nl2br(e($booking['details'] ?: 'No details provided.')) ?></p></div>
          <div><h3>Equipment Requests</h3><p><?= nl2br(e($booking['equipment_requests'] ?: 'None specified.')) ?></p></div>
          <div><h3>Special Instructions</h3><p><?= nl2br(e($booking['special_instructions'] ?: 'None specified.')) ?></p></div>
          <div><h3>Admin Notes</h3><p><?= nl2br(e($booking['admin_notes'] ?: 'No admin notes.')) ?></p></div>
        </div>
      </div>
    </div>
  </details>

  <?php if ($cancellation): ?>
    <section class="panel cancellation-record-panel reservation-cancellation-panel">
      <div class="panel-head"><h2>Cancellation Settlement</h2><span class="status-pill status-<?= (string)$cancellation['refund_status'] === 'refunded' ? 'success' : ((string)$cancellation['refund_status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= e(ucwords(str_replace('_', ' ', (string)$cancellation['refund_status']))) ?></span></div>
      <div class="cancellation-settlement-grid">
        <?php if ($reservationDiscountAmount > 0): ?><div><span>Original Calculated Total</span><strong><?= money($reservationCalculatedTotal) ?></strong></div><div><span>Flexible Discount</span><strong>−<?= money($reservationDiscountAmount) ?></strong></div><?php endif; ?>
        <div><span>Original Client Payable</span><strong><?= money($cancellation['original_total']) ?></strong></div>
        <div><span>50% Cancellation Charge</span><strong><?= money($cancellation['cancellation_fee']) ?></strong></div>
        <div><span>Gross Payment Received</span><strong><?= money($cancellation['paid_before_cancellation']) ?></strong></div>
        <div><span>Refund Required</span><strong><?= money($cancellation['refund_due']) ?></strong></div>
        <div class="<?= (float)$cancellation['refunded_amount'] > 0 ? 'is-refund' : '' ?>"><span>Refund Recorded</span><strong><?= (float)$cancellation['refunded_amount'] > 0 ? '−' . money($cancellation['refunded_amount']) : money(0) ?></strong></div>
        <div class="is-net"><span>Net Amount Retained</span><strong><?= money($ledgerTotals['net_paid']) ?></strong></div>
        <div class="<?= $remainingBalance > 0 ? 'is-balance' : '' ?>"><span>Cancellation Balance</span><strong><?= money($remainingBalance) ?></strong></div>
      </div>
      <div class="cancellation-record-reason"><strong>Reason:</strong> <?= nl2br(e((string)$cancellation['cancellation_reason'])) ?><div class="small muted">Cancelled by <?= e($cancellation['cancelled_by_name'] ?: 'Unknown administrator') ?> on <?= date('M j, Y g:i A', strtotime((string)$cancellation['cancelled_at'])) ?>.</div></div>
      <?php if ($refundPending > 0): ?><div class="alert alert-warning cancellation-pending-alert"><strong>Refund pending:</strong> <?= money($refundPending) ?> still needs to be returned to the client. <a class="btn btn-primary btn-sm" href="reservation-cancel.php?id=<?= $id ?>">Record Refund</a></div><?php elseif ((string)$cancellation['refund_status'] === 'refunded'): ?><div class="alert alert-success cancellation-pending-alert">Refund of <?= money($cancellation['refunded_amount']) ?> recorded via <?= e(booking_payment_method_label((string)$cancellation['refund_method'])) ?><?= !empty($cancellation['refund_reference']) ? ' · Reference ' . e((string)$cancellation['refund_reference']) : '' ?>.</div><?php endif; ?>
    </section>
  <?php endif; ?>

  <details class="panel reservation-detail-collapse" id="payment-history">
    <summary><span><strong>Payment History</strong><small><?= count($payments) ?> transaction<?= count($payments) === 1 ? '' : 's' ?> · <?= e(ucwords(str_replace('_', ' ', (string)$booking['payment_status']))) ?></small></span><span class="reservation-collapse-icon" aria-hidden="true">+</span></summary>
    <div class="reservation-detail-collapse-body">
      <div class="payment-ledger-summary<?= $ledgerTotals['refunded'] > 0 ? ' has-refund' : '' ?>" aria-label="Payment ledger totals">
        <div><span>Gross Payments</span><strong><?= money($ledgerTotals['gross_paid']) ?></strong></div>
        <div class="payment-ledger-refund"><span>Refunds</span><strong><?= $ledgerTotals['refunded'] > 0 ? '−' . money($ledgerTotals['refunded']) : money(0) ?></strong></div>
        <div class="payment-ledger-net"><span>Net Collected</span><strong><?= money($ledgerTotals['net_paid']) ?></strong></div>
      </div>
      <?php if ($ledgerTotals['refunded'] > 0): ?><p class="payment-ledger-note">Refunds are stored as negative transactions. Original payments remain unchanged for a complete audit trail.</p><?php endif; ?>
      <div class="table-wrap"><table class="admin-table mobile-card-table reservation-payment-history-table"><thead><tr><th>Date</th><th>Type</th><th>Method</th><th>Reference</th><th>Recorded By</th><th>Amount</th></tr></thead><tbody>
      <?php foreach ($payments as $payment): $isRefund = payment_transaction_type($payment) === 'refund'; ?><tr class="<?= $isRefund ? 'payment-refund-row' : '' ?>"><td data-label="Date"><?= date('M j, Y g:i A', strtotime($payment['paid_at'])) ?></td><td data-label="Type"><span class="status-pill status-<?= $isRefund ? 'warning' : 'success' ?>"><?= e(payment_transaction_label($payment)) ?></span></td><td data-label="Method"><?= e(booking_payment_method_label((string)$payment['payment_method'])) ?></td><td data-label="Reference"><?= e($payment['payment_reference'] ?: '—') ?><?php if (!empty($payment['batch_payment_no'])): ?><br><a class="small batch-reference-link" href="batch-reservation-view.php?id=<?= (int)$payment['payment_batch_id'] ?>#batch-payment-history">Batch <?= e($payment['batch_payment_no']) ?></a><?php endif; ?></td><td data-label="Recorded By"><?= e($payment['recorder'] ?: 'Client online submission') ?></td><td data-label="Amount"><strong class="<?= $isRefund ? 'refund-amount' : '' ?>"><?= $isRefund ? '−' . money(abs((float)$payment['amount'])) : money($payment['amount']) ?></strong></td></tr><?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="6" class="empty-state">No payments recorded.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </details>

  <details class="panel reservation-detail-collapse">
    <summary><span><strong>Reservation History</strong><small><?= count($reschedules) ?> reschedule<?= count($reschedules) === 1 ? '' : 's' ?> · <?= count($extensions) ?> extension<?= count($extensions) === 1 ? '' : 's' ?></small></span><span class="reservation-collapse-icon" aria-hidden="true">+</span></summary>
    <div class="reservation-detail-collapse-body reservation-history-body">
      <section>
        <div class="reservation-history-heading"><h3>Rescheduling History</h3><span><?= count($reschedules) ?> change<?= count($reschedules) === 1 ? '' : 's' ?></span></div>
        <?php if ($reschedules): ?>
          <div class="reschedule-history-list">
          <?php foreach ($reschedules as $change): ?>
            <article class="reschedule-history-item">
              <div class="reschedule-history-schedules">
                <div><span>Previous</span><strong><?= date('M j, Y g:i A', strtotime($change['previous_event_start'])) ?> – <?= date('M j, Y g:i A', strtotime($change['previous_event_end'])) ?></strong></div>
                <div><span>New</span><strong><?= date('M j, Y g:i A', strtotime($change['new_event_start'])) ?> – <?= date('M j, Y g:i A', strtotime($change['new_event_end'])) ?></strong></div>
              </div>
              <p><strong>Reason:</strong> <?= nl2br(e($change['reason'])) ?></p>
              <div class="small muted">Total: <?= money($change['previous_total']) ?> → <?= money($change['new_total']) ?> · Payment: <?= e(ucfirst($change['previous_payment_status'])) ?> → <?= e(ucfirst($change['new_payment_status'])) ?> · Changed by <?= e($change['changed_by_name'] ?: 'Unknown administrator') ?> on <?= date('M j, Y g:i A', strtotime($change['created_at'])) ?></div>
            </article>
          <?php endforeach; ?>
          </div>
        <?php else: ?><div class="empty-state">This reservation has not been rescheduled.</div><?php endif; ?>
      </section>

      <section>
        <div class="reservation-history-heading"><h3>Extension History</h3><span><?= count($extensions) ?> extension<?= count($extensions) === 1 ? '' : 's' ?></span></div>
        <?php if ($extensions): ?>
          <div class="reschedule-history-list">
          <?php foreach ($extensions as $change): ?>
            <article class="reschedule-history-item extension-history-item">
              <?php
                $extensionWasLate = strtotime((string)$change['created_at']) > strtotime((string)$change['previous_event_end']);
                $extensionHadHistoricalOverlap = str_contains((string)$change['reason'], 'System audit: Historical overlap acknowledged by');
              ?>
              <?php if ($extensionWasLate || $extensionHadHistoricalOverlap): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                  <?php if ($extensionWasLate): ?><span class="status-pill status-warning">Late Extension</span><?php endif; ?>
                  <?php if ($extensionHadHistoricalOverlap): ?><span class="status-pill status-secondary">Historical Overlap Acknowledged</span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="reschedule-history-schedules">
                <div><span>Previous End</span><strong><?= date('M j, Y g:i A', strtotime($change['previous_event_end'])) ?></strong></div>
                <div><span>New End</span><strong><?= date('M j, Y g:i A', strtotime($change['new_event_end'])) ?></strong></div>
              </div>
              <p><strong>Reason:</strong> <?= nl2br(e($change['reason'])) ?></p>
              <?php
                $extensionHours = max(0.0, (float)$change['added_hours']);
                $extensionVenueRate = max(0.0, (float)$change['hourly_rate']);
                $extensionEffectiveRate = $extensionHours > 0 ? round((float)$change['additional_charge'] / $extensionHours, 2) : $extensionVenueRate;
                $extensionHasHourlyAddon = $extensionEffectiveRate > $extensionVenueRate + 0.001;
              ?>
              <div class="small muted"><?= e(reservation_duration_label($extensionHours)) ?> added · Venue rate: <?= money($extensionVenueRate) ?>/hour<?= $extensionHasHourlyAddon ? ' + hourly add-on(s)' : '' ?> · Additional charge: <?= money($change['additional_charge']) ?> · Total: <?= money($change['previous_total']) ?> → <?= money($change['new_total']) ?> · Payment: <?= e(ucfirst($change['previous_payment_status'])) ?> → <?= e(ucfirst($change['new_payment_status'])) ?> · Changed by <?= e($change['changed_by_name'] ?: 'Unknown administrator') ?> on <?= date('M j, Y g:i A', strtotime($change['created_at'])) ?></div>
            </article>
          <?php endforeach; ?>
          </div>
        <?php else: ?><div class="empty-state">This reservation has not been extended.</div><?php endif; ?>
      </section>
    </div>
  </details>
</div>

<?php if ($canUpdateReservation): ?>
<dialog class="reservation-detail-dialog" data-reservation-detail-dialog="update" aria-labelledby="reservationUpdateTitle">
  <div class="reservation-detail-dialog-shell">
    <div class="reservation-detail-dialog-head">
      <div><span class="small muted">Reservation <?= e($booking['reference_no']) ?></span><h2 id="reservationUpdateTitle">Update Reservation</h2></div>
      <button class="reservation-detail-dialog-close" type="button" data-close-reservation-dialog aria-label="Close update reservation">&times;</button>
    </div>
    <form method="post" class="reservation-detail-dialog-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_status">
      <?php if ((string)$booking['status'] === 'cancelled'): ?>
        <input type="hidden" name="status" value="cancelled">
        <input type="hidden" name="final_amount" value="<?= e(number_format($paymentTarget, 2, '.', '')) ?>">
        <div class="form-grid">
          <div class="form-group"><label>Status</label><input value="Cancelled" readonly><span class="field-help">Cancellation status and charge are locked by the settlement.</span></div>
          <div class="form-group"><label>Cancellation Charge</label><input value="<?= e(money($paymentTarget)) ?>" readonly></div>
        </div>
      <?php else: ?>
        <div class="form-grid">
          <div class="form-group"><label>Status</label><select name="status"><?php foreach (['pending', 'for_review', 'approved', 'completed', 'rejected', 'no_show'] as $status): ?><option value="<?= $status ?>" <?= $booking['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select><span class="field-help"><?php if ((string)$booking['source'] === 'website' && empty($booking['created_by']) && !reservation_holds_calendar($booking)): ?><strong>Pending website request:</strong> approval runs a fresh conflict check and secures the slot.<?php endif; ?></span></div>
          <div class="form-group"><label>Final Amount</label><input type="number" min="0" step="0.01" name="final_amount" value="<?= e((string)($booking['final_amount'] ?? '')) ?>" placeholder="Use estimate if blank"><span class="field-help">Leave blank to use the saved calculated total. If you enter a lower final amount, TLH automatically synchronizes the flexible discount.</span></div>
        </div>
      <?php endif; ?>
      <div class="form-grid">
      </div>
      <div class="form-group"><label>Admin Notes</label><textarea name="admin_notes" rows="4"><?= e($booking['admin_notes']) ?></textarea></div>
      <?php if (reservation_can_cancel($booking)): ?><div class="reservation-dialog-note">Need to cancel this booking? <a href="reservation-cancel.php?id=<?= $id ?>">Use Cancel Reservation</a> so the 50% charge and refund policy are applied correctly.</div><?php endif; ?>
      <div class="reservation-detail-dialog-actions"><button class="btn btn-outline" type="button" data-close-reservation-dialog>Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>

<?php if ($canRecordPayment): ?>
<dialog class="reservation-detail-dialog reservation-payment-dialog" data-reservation-detail-dialog="payment" aria-labelledby="reservationPaymentTitle">
  <div class="reservation-detail-dialog-shell">
    <div class="reservation-detail-dialog-head">
      <div><span class="small muted">Remaining balance <?= money($remainingBalance) ?></span><h2 id="reservationPaymentTitle"><?= $cancellation ? 'Record Cancellation Payment' : 'Record Payment' ?></h2></div>
      <button class="reservation-detail-dialog-close" type="button" data-close-reservation-dialog aria-label="Close record payment">&times;</button>
    </div>
    <form method="post" class="reservation-detail-dialog-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_payment">
      <div class="payment-update-balance"><span><?= $cancellation ? 'Cancellation Balance' : 'Remaining Balance' ?></span><strong><?= money($remainingBalance) ?></strong></div>
      <div class="form-grid">
        <div class="form-group"><label>Amount</label><input type="number" min="0.01" max="<?= e(number_format($remainingBalance, 2, '.', '')) ?>" step="0.01" name="amount" value="<?= e(number_format(min($remainingBalance, max(0, (float)($booking['booking_payment_intent_amount'] ?? 0))), 2, '.', '')) ?>" required><span class="field-help">Prefilled from the client's intended amount when available.</span></div>
        <div class="form-group"><label>Paid At</label><input type="datetime-local" name="paid_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
        <div class="form-group"><label>Payment Method</label><select name="payment_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= (($booking['booking_payment_method'] ?? '') === $method) ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference / OR Number</label><input name="payment_reference" maxlength="120" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional: enter the OR / official receipt number for cash payments.</span></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="payment_notes" rows="3"></textarea></div>
      <div class="reservation-detail-dialog-actions"><button class="btn btn-outline" type="button" data-close-reservation-dialog>Cancel</button><button class="btn btn-primary" type="submit">Record Payment</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>

<script>
(() => {
  const dialogs = Array.from(document.querySelectorAll('[data-reservation-detail-dialog]'));
  if (!dialogs.length) return;

  const closeDialog = (dialog) => {
    if (dialog && dialog.open) dialog.close();
  };

  document.querySelectorAll('[data-open-reservation-dialog]').forEach((button) => {
    button.addEventListener('click', () => {
      const name = button.getAttribute('data-open-reservation-dialog');
      const dialog = dialogs.find((item) => item.getAttribute('data-reservation-detail-dialog') === name);
      if (!dialog) return;
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    });
  });

  dialogs.forEach((dialog) => {
    dialog.querySelectorAll('[data-close-reservation-dialog]').forEach((button) => button.addEventListener('click', () => closeDialog(dialog)));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
    });
  });
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
