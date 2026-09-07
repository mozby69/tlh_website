<?php
/**
 * FILE PURPOSE: Reservation cancellation and cancellation-finance settlement workflow.
 * DEBUGGING: The cancellation charge is calculated by shared helpers; refunds are negative payment-ledger transactions and must not be double-counted.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? $_POST['reservation_id'] ?? 0);
if ($id < 1) {
    redirect('reservations.php');
}

try {
    $stmt = db()->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.id=? LIMIT 1');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
} catch (Throwable $e) {
    $booking = false;
}
if (!$booking) {
    flash('danger', 'Reservation not found.');
    redirect('reservations.php');
}

$cancellation = reservation_cancellation_record($id);
$ledgerTotals = reservation_payment_ledger_totals($id);
$calculation = $cancellation
    ? [
        'rate' => (float)$cancellation['cancellation_rate'],
        'original_total' => (float)$cancellation['original_total'],
        'cancellation_fee' => (float)$cancellation['cancellation_fee'],
        'policy_refundable_portion' => max(0, round((float)$cancellation['original_total'] - (float)$cancellation['cancellation_fee'], 2)),
        'amount_paid' => (float)$cancellation['paid_before_cancellation'],
        'refund_due' => (float)$cancellation['refund_due'],
        'balance_due' => max(0, round((float)$cancellation['cancellation_fee'] - $ledgerTotals['net_paid'], 2)),
      ]
    : reservation_cancellation_calculation($booking, $ledgerTotals['net_paid']);

// Cancellation settlement is financial as well as operational. The original
// cancellation charge is preserved; later refund settlements update the ledger.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'cancel_reservation');
    $pdo = null;

    try {
        if ($action === 'cancel_reservation') {
            $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
            $refundHandling = (string)($_POST['refund_handling'] ?? 'pending');
            $refundMethod = trim((string)($_POST['refund_method'] ?? ''));
            $refundReference = trim((string)($_POST['refund_reference'] ?? ''));
            $refundNotes = trim((string)($_POST['refund_notes'] ?? ''));
            $refundAtRaw = trim((string)($_POST['refund_at'] ?? ''));

            if ($reason === '') {
                throw new RuntimeException('Enter the client cancellation reason.');
            }
            if (strlen($reason) > 2000) {
                throw new RuntimeException('The cancellation reason may not exceed 2,000 characters.');
            }
            if (!in_array($refundHandling, ['record_now', 'pending'], true)) {
                throw new RuntimeException('Choose how the refund will be handled.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
            $bookingStmt->execute([$id]);
            $currentBooking = $bookingStmt->fetch();
            if (!$currentBooking) {
                throw new RuntimeException('Reservation not found.');
            }
            if (!reservation_can_cancel($currentBooking)) {
                throw new RuntimeException('Only active, non-archived reservations can be cancelled through this policy workflow.');
            }

            $existingStmt = $pdo->prepare('SELECT id FROM reservation_cancellations WHERE reservation_id=? LIMIT 1 FOR UPDATE');
            $existingStmt->execute([$id]);
            if ($existingStmt->fetchColumn()) {
                throw new RuntimeException('This reservation already has a cancellation settlement.');
            }

            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $totalStmt->execute([$id]);
            $paidBeforeCancellation = round((float)$totalStmt->fetchColumn(), 2);
            $settlement = reservation_cancellation_calculation($currentBooking, $paidBeforeCancellation);
            $refundDue = (float)$settlement['refund_due'];

            $refundStatus = $refundDue > 0 ? ($refundHandling === 'record_now' ? 'refunded' : 'pending') : 'not_applicable';
            $refundedAmount = 0.0;
            $refundPaymentId = null;
            $refundedAt = null;
            $refundedBy = null;

            if ($refundDue > 0 && $refundHandling === 'record_now') {
                if (!in_array($refundMethod, booking_payment_methods(), true)) {
                    throw new RuntimeException('Choose a valid refund method.');
                }
                if ($refundMethod !== 'Cash' && $refundReference === '') {
                    throw new RuntimeException('Enter a refund reference for non-cash refunds.');
                }
                if (strlen($refundReference) > 120) {
                    throw new RuntimeException('The refund reference may not exceed 120 characters.');
                }
                try {
                    $refundAt = $refundAtRaw !== '' ? new DateTimeImmutable($refundAtRaw) : new DateTimeImmutable();
                } catch (Throwable $e) {
                    throw new RuntimeException('Enter a valid refund date and time.');
                }

                $paymentNote = '50% client-cancellation refund.';
                if ($refundNotes !== '') {
                    $paymentNote .= ' ' . $refundNotes;
                }
                $refundStmt = $pdo->prepare("INSERT INTO payments(reservation_id,batch_payment_id,transaction_type,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,NULL,'refund',?,?,?,?,?,?)");
                $refundStmt->execute([
                    $id,
                    -$refundDue,
                    $refundMethod,
                    $refundReference !== '' ? $refundReference : null,
                    $paymentNote,
                    current_admin()['id'],
                    $refundAt->format('Y-m-d H:i:s'),
                ]);
                $refundPaymentId = (int)$pdo->lastInsertId();
                $refundedAmount = $refundDue;
                $refundedAt = $refundAt->format('Y-m-d H:i:s');
                $refundedBy = current_admin()['id'];
            }

            $netPaid = round($paidBeforeCancellation - $refundedAmount, 2);
            $paymentStatus = payment_status_for_amount($netPaid, (float)$settlement['cancellation_fee']);
            $updateStmt = $pdo->prepare("UPDATE reservations SET status='cancelled',final_amount=?,amount_paid=?,payment_status=? WHERE id=?");
            $updateStmt->execute([
                $settlement['cancellation_fee'],
                $netPaid,
                $paymentStatus,
                $id,
            ]);

            $cancelStmt = $pdo->prepare('INSERT INTO reservation_cancellations(reservation_id,previous_status,original_total,cancellation_rate,cancellation_fee,paid_before_cancellation,refund_due,refund_status,refunded_amount,refund_method,refund_reference,refund_notes,refund_payment_id,cancellation_reason,cancelled_by,cancelled_at,refunded_by,refunded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $cancelStmt->execute([
                $id,
                $currentBooking['status'],
                $settlement['original_total'],
                $settlement['rate'],
                $settlement['cancellation_fee'],
                $paidBeforeCancellation,
                $refundDue,
                $refundStatus,
                $refundedAmount,
                $refundDue > 0 && $refundHandling === 'record_now' ? $refundMethod : null,
                $refundDue > 0 && $refundHandling === 'record_now' && $refundReference !== '' ? $refundReference : null,
                $refundNotes !== '' ? $refundNotes : null,
                $refundPaymentId,
                $reason,
                current_admin()['id'],
                date('Y-m-d H:i:s'),
                $refundedBy,
                $refundedAt,
            ]);

            $batchId = (int)($currentBooking['batch_id'] ?? 0);
            if ($batchId > 0) {
                reservation_sync_batch_summary($pdo, $batchId);
            }

            $pdo->commit();
            if ($refundStatus === 'refunded') {
                flash('success', 'Reservation cancelled. The 50% cancellation charge and ' . money($refundDue) . ' refund were recorded.');
            } elseif ($refundStatus === 'pending') {
                flash('success', 'Reservation cancelled. A refund of ' . money($refundDue) . ' is pending.');
            } elseif ((float)$settlement['balance_due'] > 0) {
                flash('success', 'Reservation cancelled. The client still has a cancellation balance of ' . money($settlement['balance_due']) . '.');
            } else {
                flash('success', 'Reservation cancelled and the 50% cancellation charge is settled.');
            }
            redirect('reservation-view.php?id=' . $id);
        }

        if ($action === 'record_refund') {
            $refundMethod = trim((string)($_POST['refund_method'] ?? ''));
            $refundReference = trim((string)($_POST['refund_reference'] ?? ''));
            $refundNotes = trim((string)($_POST['refund_notes'] ?? ''));
            $refundAtRaw = trim((string)($_POST['refund_at'] ?? ''));

            if (!in_array($refundMethod, booking_payment_methods(), true)) {
                throw new RuntimeException('Choose a valid refund method.');
            }
            if ($refundMethod !== 'Cash' && $refundReference === '') {
                throw new RuntimeException('Enter a refund reference for non-cash refunds.');
            }
            if (strlen($refundReference) > 120) {
                throw new RuntimeException('The refund reference may not exceed 120 characters.');
            }
            try {
                $refundAt = $refundAtRaw !== '' ? new DateTimeImmutable($refundAtRaw) : new DateTimeImmutable();
            } catch (Throwable $e) {
                throw new RuntimeException('Enter a valid refund date and time.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $cancelStmt = $pdo->prepare('SELECT * FROM reservation_cancellations WHERE reservation_id=? FOR UPDATE');
            $cancelStmt->execute([$id]);
            $currentCancellation = $cancelStmt->fetch();
            if (!$currentCancellation) {
                throw new RuntimeException('Cancellation settlement not found.');
            }
            if ((string)$currentCancellation['refund_status'] !== 'pending') {
                throw new RuntimeException('This cancellation has no pending refund.');
            }

            $remainingRefund = max(0, round((float)$currentCancellation['refund_due'] - (float)$currentCancellation['refunded_amount'], 2));
            if ($remainingRefund <= 0) {
                throw new RuntimeException('This cancellation refund is already complete.');
            }

            $paymentNote = '50% client-cancellation refund.';
            if ($refundNotes !== '') {
                $paymentNote .= ' ' . $refundNotes;
            }
            $refundStmt = $pdo->prepare("INSERT INTO payments(reservation_id,batch_payment_id,transaction_type,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,NULL,'refund',?,?,?,?,?,?)");
            $refundStmt->execute([
                $id,
                -$remainingRefund,
                $refundMethod,
                $refundReference !== '' ? $refundReference : null,
                $paymentNote,
                current_admin()['id'],
                $refundAt->format('Y-m-d H:i:s'),
            ]);
            $refundPaymentId = (int)$pdo->lastInsertId();

            $updateCancellation = $pdo->prepare("UPDATE reservation_cancellations SET refund_status='refunded',refunded_amount=refund_due,refund_method=?,refund_reference=?,refund_notes=?,refund_payment_id=?,refunded_by=?,refunded_at=? WHERE id=?");
            $updateCancellation->execute([
                $refundMethod,
                $refundReference !== '' ? $refundReference : null,
                $refundNotes !== '' ? $refundNotes : null,
                $refundPaymentId,
                current_admin()['id'],
                $refundAt->format('Y-m-d H:i:s'),
                $currentCancellation['id'],
            ]);

            $netStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
            $netStmt->execute([$id]);
            $netPaid = round((float)$netStmt->fetchColumn(), 2);
            $paymentStatus = payment_status_for_amount($netPaid, (float)$currentCancellation['cancellation_fee']);
            $bookingUpdate = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');
            $bookingUpdate->execute([$netPaid, $paymentStatus, $id]);

            $pdo->commit();
            flash('success', 'The cancellation refund of ' . money($remainingRefund) . ' was recorded successfully.');
            redirect('reservation-view.php?id=' . $id);
        }

        throw new RuntimeException('Invalid cancellation action.');
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The cancellation could not be processed.');
        redirect('reservation-cancel.php?id=' . $id);
    }
}

if (!$cancellation && reservation_has_ended($booking)) {
    flash('danger', 'This reservation has already ended. Served reservations are locked and can no longer be cancelled.');
    redirect('reservation-view.php?id=' . $id);
}
if (!$cancellation && !reservation_can_cancel($booking)) {
    flash('danger', 'This reservation cannot be cancelled through the client-cancellation workflow.');
    redirect('reservation-view.php?id=' . $id);
}

$adminPageTitle = $cancellation ? 'Cancellation Settlement' : 'Cancel Reservation';
$bookingIsCancelled = $cancellation || (string)($booking['status'] ?? '') === 'cancelled';
$remainingRefund = $cancellation ? max(0, round((float)$cancellation['refund_due'] - (float)$cancellation['refunded_amount'], 2)) : (float)$calculation['refund_due'];
include __DIR__ . '/_header.php';
?>
<div class="admin-grid cancellation-workflow-grid">
  <section class="panel">
    <div class="panel-head">
      <div>
        <span class="small muted">Reservation <?= e($booking['reference_no']) ?></span>
        <h2><?= $cancellation ? 'Cancellation Settlement' : 'Confirm Client Cancellation' ?></h2>
      </div>
      <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= $id ?>">Back to Reservation</a>
    </div>

    <div class="alert alert-warning cancellation-policy-notice">
      <strong>50% cancellation policy:</strong> The venue retains 50% of the reservation price. A fully paid client receives the other 50% back. For a partially paid reservation, only the amount paid above the 50% charge is refundable; if the payment is below the charge, the difference remains due.
    </div>

    <div class="cancellation-settlement-grid">
      <div><span>Original Reservation Price</span><strong><?= money($calculation['original_total']) ?></strong></div>
      <div><span>Cancellation Charge (<?= e(rtrim(rtrim(number_format((float)$calculation['rate'], 2, '.', ''), '0'), '.')) ?>%)</span><strong><?= money($calculation['cancellation_fee']) ?></strong></div>
      <div><span>Payments Received Before Cancellation</span><strong><?= money($calculation['amount_paid']) ?></strong></div>
      <div><span>Policy Refundable Portion</span><strong><?= money($calculation['policy_refundable_portion']) ?></strong></div>
      <div class="<?= (float)$calculation['refund_due'] > 0 ? 'is-refund' : '' ?>"><span>Refund Due From Amount Paid</span><strong><?= money($calculation['refund_due']) ?></strong></div>
      <div class="<?= (float)$calculation['balance_due'] > 0 ? 'is-balance' : '' ?>"><span>Cancellation Balance Still Due</span><strong><?= money($calculation['balance_due']) ?></strong></div>
    </div>

    <?php if (!$cancellation): ?>
      <form method="post" class="cancellation-form" data-cancellation-form>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="cancel_reservation">
        <input type="hidden" name="reservation_id" value="<?= $id ?>">

        <div class="form-group">
          <label for="cancellationReason">Cancellation Reason</label>
          <textarea id="cancellationReason" name="cancellation_reason" rows="5" maxlength="2000" required placeholder="Explain why the client cancelled the reservation."><?= e($_POST['cancellation_reason'] ?? '') ?></textarea>
        </div>

        <?php if ((float)$calculation['refund_due'] > 0): ?>
          <fieldset class="cancellation-refund-options">
            <legend>Refund Handling</legend>
            <label><input type="radio" name="refund_handling" value="pending" checked data-refund-handling> <span><strong>Mark refund as pending</strong><small>Cancel now, return the money through the agreed payment channel, and record the completed refund afterward.</small></span></label>
            <label><input type="radio" name="refund_handling" value="record_now" data-refund-handling> <span><strong>Record a completed refund now</strong><small>Choose this only when the full <?= money($calculation['refund_due']) ?> has already been returned to the client.</small></span></label>
          </fieldset>
          <div class="alert alert-info">This website records refund accounting and reference details only. It does not automatically send money through GCash, bank transfer, card, or another payment provider.</div>
          <div class="form-grid cancellation-refund-fields" data-refund-fields>
            <div class="form-group"><label for="refundMethod">Refund Method</label><select id="refundMethod" name="refund_method" data-booking-payment-method><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="refundReference">Refund Reference</label><input id="refundReference" name="refund_reference" maxlength="120" data-booking-payment-reference><span class="field-help" data-booking-payment-reference-help>Optional for cash refunds.</span></div>
            <div class="form-group"><label for="refundAt">Refunded At</label><input id="refundAt" type="datetime-local" name="refund_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
            <div class="form-group form-group-wide"><label for="refundNotes">Refund Notes</label><textarea id="refundNotes" name="refund_notes" rows="3" placeholder="Optional refund details"></textarea></div>
          </div>
        <?php elseif ((float)$calculation['balance_due'] > 0): ?>
          <div class="alert alert-info">No refund is due yet. After cancellation, the client will still owe <?= money($calculation['balance_due']) ?> to complete the 50% cancellation charge.</div>
        <?php else: ?>
          <div class="alert alert-success">The payments already received exactly cover the 50% cancellation charge. No refund or additional payment is required.</div>
        <?php endif; ?>

        <div class="cancellation-form-actions">
          <a class="btn btn-outline" href="reservation-view.php?id=<?= $id ?>">Keep Reservation</a>
          <button class="btn btn-danger" type="submit" onclick="return confirm('Cancel this reservation and apply the 50% cancellation policy?');">Confirm Cancellation</button>
        </div>
      </form>
    <?php else: ?>
      <div class="cancellation-existing-summary">
        <h3>Cancellation Record</h3>
        <p><strong>Reason:</strong> <?= nl2br(e((string)$cancellation['cancellation_reason'])) ?></p>
        <p class="small muted">Cancelled by <?= e($cancellation['cancelled_by_name'] ?: 'Unknown administrator') ?> on <?= e(date('M j, Y g:i A', strtotime((string)$cancellation['cancelled_at']))) ?>.</p>
      </div>

      <?php if ((string)$cancellation['refund_status'] === 'pending' && $remainingRefund > 0): ?>
        <div class="alert alert-warning"><strong>Refund pending:</strong> <?= money($remainingRefund) ?> still needs to be returned to the client.</div>
        <form method="post" class="cancellation-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="record_refund">
          <input type="hidden" name="reservation_id" value="<?= $id ?>">
          <div class="form-grid cancellation-refund-fields">
            <div class="form-group"><label for="pendingRefundMethod">Refund Method</label><select id="pendingRefundMethod" name="refund_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="pendingRefundReference">Refund Reference</label><input id="pendingRefundReference" name="refund_reference" maxlength="120" data-booking-payment-reference><span class="field-help" data-booking-payment-reference-help>Optional for cash refunds.</span></div>
            <div class="form-group"><label for="pendingRefundAt">Refunded At</label><input id="pendingRefundAt" type="datetime-local" name="refund_at" value="<?= date('Y-m-d\TH:i') ?>" required></div>
            <div class="form-group form-group-wide"><label for="pendingRefundNotes">Refund Notes</label><textarea id="pendingRefundNotes" name="refund_notes" rows="3"></textarea></div>
          </div>
          <div class="cancellation-form-actions"><a class="btn btn-outline" href="reservation-view.php?id=<?= $id ?>">Back</a><button class="btn btn-primary" type="submit" onclick="return confirm('Record the full refund of <?= e(money($remainingRefund)) ?>?');">Record <?= money($remainingRefund) ?> Refund</button></div>
        </form>
      <?php elseif ((string)$cancellation['refund_status'] === 'refunded'): ?>
        <div class="alert alert-success"><strong>Refund completed:</strong> <?= money($cancellation['refunded_amount']) ?> was recorded via <?= e(booking_payment_method_label((string)$cancellation['refund_method'])) ?><?= !empty($cancellation['refund_reference']) ? ' · Reference ' . e((string)$cancellation['refund_reference']) : '' ?> on <?= e(date('M j, Y g:i A', strtotime((string)$cancellation['refunded_at']))) ?>.</div>
      <?php elseif ((float)$calculation['balance_due'] > 0): ?>
        <div class="alert alert-info">The client has no refund due and still owes <?= money($calculation['balance_due']) ?> toward the cancellation charge. Use the reservation's payment action to record it.</div>
      <?php else: ?>
        <div class="alert alert-success">No refund was required and the cancellation charge is settled.</div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <aside class="panel cancellation-client-card">
    <h2>Reservation Summary</h2>
    <div class="detail-grid cancellation-client-grid">
      <div class="detail-item"><span>Client</span><strong><?= e($booking['client_name']) ?></strong></div>
      <div class="detail-item"><span>Schedule</span><strong><?= e(date('M j, Y g:i A', strtotime((string)$booking['event_start']))) ?> – <?= e(date('g:i A', strtotime((string)$booking['event_end']))) ?></strong></div>
      <div class="detail-item"><span>Package</span><strong><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?></strong></div>
      <div class="detail-item"><span>Payment Status</span><strong><?= e(ucfirst((string)$booking['payment_status'])) ?></strong></div>
      <?php if (!empty($booking['batch_id'])): ?><div class="detail-item"><span>Batch</span><strong><a href="batch-reservation-view.php?id=<?= (int)$booking['batch_id'] ?>"><?= e($booking['batch_reference'] ?: ('Batch #' . (int)$booking['batch_id'])) ?></a></strong></div><?php endif; ?>
    </div>
  </aside>
</div>

<script>
(function () {
  const form = document.querySelector('[data-cancellation-form]');
  if (!form) return;
  const radios = form.querySelectorAll('[data-refund-handling]');
  const fields = form.querySelector('[data-refund-fields]');
  if (!radios.length || !fields) return;

  const sync = () => {
    const selected = form.querySelector('[data-refund-handling]:checked');
    const active = selected && selected.value === 'record_now';
    fields.hidden = !active;
    fields.querySelectorAll('input,select,textarea').forEach((control) => {
      control.disabled = !active;
    });
  };
  radios.forEach((radio) => radio.addEventListener('change', sync));
  sync();
}());
</script>
<?php include __DIR__ . '/_footer.php'; ?>
