<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) { flash('danger','Administrator access is required.'); redirect('index.php'); }

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$loadRental = static function(int $id): array {
    $stmt = db()->prepare("SELECT r.*,a.full_name AS created_by_name FROM rentals r LEFT JOIN admins a ON a.id=r.created_by WHERE r.id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
};

$rental = $loadRental($id);
if (!$rental) { flash('danger','Rental not found.'); redirect('rentals.php'); }
$existingCancellation = rental_cancellation_for($id);
if ($rental['status'] === 'cancelled') {
    flash('warning','This rental is already cancelled.');
    redirect('rental-view.php?id='.$id);
}
if ($rental['status'] !== 'active') {
    flash('danger','Only an active rental can be cancelled.');
    redirect('rental-view.php?id='.$id);
}
if ((string)$rental['start_date'] < date('Y-m-d')) {
    flash('warning','This rental has already started. Use End Rental Early so used dates and final billing remain accurate.');
    redirect('rental-view.php?id='.$id);
}
if ($existingCancellation) {
    flash('warning','This rental already has a cancellation settlement.');
    redirect('rental-view.php?id='.$id);
}

$originalTotal = rental_total_amount($rental);
$paidBefore = max(0, rental_payment_total($id));
$depositHeld = rental_deposit_held($id);
$adminPageTitle = 'Cancel Rental';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $recordLockHeld = false;
    try {
        if (!rental_record_lock_acquire($id,10)) throw new RuntimeException('This rental is being updated by another administrator. Please try again.');
        $recordLockHeld = true;
        $pdo = db();
        $rental = $loadRental($id);
        if (!$rental || $rental['status'] !== 'active') throw new RuntimeException('This rental can no longer be cancelled.');
        if ((string)$rental['start_date'] < date('Y-m-d')) throw new RuntimeException('This rental has already started. Use End Rental Early instead.');
        if (rental_cancellation_for($id)) throw new RuntimeException('A cancellation settlement already exists for this rental.');

        $originalTotal = rental_total_amount($rental);
        $paidBefore = max(0, rental_payment_total($id));
        $depositHeld = rental_deposit_held($id);
        $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
        $refundAmount = is_numeric($_POST['refund_amount'] ?? null) ? round((float)$_POST['refund_amount'],2) : -1;
        $cancelledAtRaw = trim((string)($_POST['cancelled_at'] ?? ''));
        $refundMethod = trim((string)($_POST['refund_method'] ?? ''));
        $refundReference = trim((string)($_POST['refund_reference'] ?? ''));
        $refundNotes = trim((string)($_POST['refund_notes'] ?? ''));
        $refundAtRaw = trim((string)($_POST['refund_at'] ?? ''));

        if ($reason === '') throw new RuntimeException('Enter the cancellation reason.');
        if ($refundAmount < 0) throw new RuntimeException('Refund amount cannot be negative.');
        if ($refundAmount > $paidBefore + 0.001) throw new RuntimeException('Refund cannot exceed the amount paid of '.money($paidBefore).'.');
        $cancelledTs = strtotime($cancelledAtRaw);
        if (!$cancelledTs) throw new RuntimeException('Enter a valid cancellation date and time.');
        if ($cancelledTs > time() + 60) throw new RuntimeException('Cancellation date cannot be in the future.');

        $refundTs = null;
        if ($refundAmount > 0.009) {
            if (!in_array($refundMethod, booking_payment_methods(), true)) throw new RuntimeException('Choose a valid refund method.');
            if ($refundMethod !== 'Cash' && $refundReference === '') throw new RuntimeException('Enter a reference or transaction number for the non-cash refund.');
            $refundTs = strtotime($refundAtRaw);
            if (!$refundTs) throw new RuntimeException('Enter a valid refund date and time.');
            if ($refundTs > time() + 60) throw new RuntimeException('Refund date cannot be in the future.');
        }

        $retained = round(max(0,$paidBefore-$refundAmount),2);
        $pdo->beginTransaction();
        $refundPaymentId = null;
        if ($refundAmount > 0.009) {
            $stmt = $pdo->prepare("INSERT INTO rental_payments(rental_id,transaction_type,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,'refund',?,?,?,?,?,?)");
            $stmt->execute([
                $id,
                -$refundAmount,
                $refundMethod,
                $refundReference !== '' ? $refundReference : null,
                $refundNotes !== '' ? $refundNotes : 'Rental cancellation refund',
                current_admin()['id'],
                date('Y-m-d H:i:s',$refundTs),
            ]);
            $refundPaymentId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("INSERT INTO rental_cancellations(rental_id,original_total,paid_before_cancellation,refund_amount,retained_amount,security_deposit_held,refund_method,refund_reference,refund_notes,refund_payment_id,cancellation_reason,cancelled_by,cancelled_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $id,
            round($originalTotal,2),
            round($paidBefore,2),
            $refundAmount,
            $retained,
            round($depositHeld,2),
            $refundAmount > 0.009 ? $refundMethod : null,
            $refundAmount > 0.009 && $refundReference !== '' ? $refundReference : null,
            $refundAmount > 0.009 && $refundNotes !== '' ? $refundNotes : null,
            $refundPaymentId,
            $reason,
            current_admin()['id'],
            date('Y-m-d H:i:s',$cancelledTs),
        ]);
        $paymentStatus = $retained > 0.009 ? 'paid' : 'unpaid';
        $stmt = $pdo->prepare("UPDATE rentals SET status='cancelled',cancelled_reason=?,amount_paid=?,payment_status=? WHERE id=?");
        $stmt->execute([$reason,$retained,$paymentStatus,$id]);
        $pdo->commit();
        flash('success','Rental cancelled. Refund settlement has been recorded.');
        redirect('rental-view.php?id='.$id);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        flash('danger',$e instanceof RuntimeException ? $e->getMessage() : 'Unable to cancel rental.');
    } finally {
        if ($recordLockHeld) rental_record_lock_release($id);
    }
}

$items = rental_items_for($id);
include __DIR__ . '/_header.php';
?>
<section class="panel rental-cancel-panel">
  <div class="panel-head">
    <div>
      <span class="eyebrow">Rental Cancellation</span>
      <h2><?= e($rental['reference_no']) ?> · <?= e($rental['client_name']) ?></h2>
      <p class="muted">Admin decides the client refund. The amount TLH keeps becomes the final retained value of this cancelled rental.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="rental-view.php?id=<?= $id ?>">Back to Rental</a>
  </div>

  <div class="rental-detail-grid">
    <div class="rental-info-grid">
      <div><span>Rentables</span><strong><?= e(rental_item_summary($id,5)) ?></strong></div>
      <div><span>Rental Period</span><strong><?= date('M j, Y',strtotime($rental['start_date'])) ?> – <?= date('M j, Y',strtotime($rental['end_date'])) ?></strong></div>
      <div><span>Original Total</span><strong><?= money($originalTotal) ?></strong></div>
      <div><span>Paid Before Cancellation</span><strong><?= money($paidBefore) ?></strong></div>
      <?php if($depositHeld>0.009): ?><div><span>Security Deposit Held</span><strong><?= money($depositHeld) ?></strong></div><?php endif; ?>
    </div>
    <div class="alert alert-warning rental-inline-alert">
      <strong>Refund is flexible.</strong><br>
      Enter any amount from <?= money(0) ?> up to <?= money($paidBefore) ?>. Security Deposit is separate and can still be refunded from the Rental record after cancellation.
    </div>
  </div>

  <form method="post" id="rentalCancellationForm" class="rental-cancellation-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-grid two-col">
      <div class="form-group">
        <label>Cancellation Reason *</label>
        <textarea name="cancellation_reason" required placeholder="Why did the client cancel?"><?= e((string)($_POST['cancellation_reason']??'')) ?></textarea>
      </div>
      <div class="form-group">
        <label>Cancelled At *</label>
        <input type="datetime-local" name="cancelled_at" value="<?= e((string)($_POST['cancelled_at']??date('Y-m-d\TH:i'))) ?>" required>
      </div>
    </div>

    <div class="panel rental-settlement-preview" style="margin-top:14px">
      <div class="panel-head"><div><h3>Refund Settlement</h3><p class="muted">The refund reduces Collections. Any amount retained by TLH remains as the final cancellation value.</p></div></div>
      <div class="rental-financial-summary">
        <div><span>Paid Before Cancellation</span><strong><?= money($paidBefore) ?></strong></div>
        <div><span>Refund to Client</span><strong id="rentalRefundPreview"><?= money(0) ?></strong></div>
        <div class="rental-financial-total"><span>TLH Retains</span><strong id="rentalRetainedPreview"><?= money($paidBefore) ?></strong></div>
      </div>
      <div class="form-group" style="margin-top:12px">
        <label>Refund Amount *</label>
        <input id="rentalRefundAmount" type="number" name="refund_amount" min="0" max="<?= e(number_format($paidBefore,2,'.','')) ?>" step="0.01" value="<?= e((string)($_POST['refund_amount']??'0.00')) ?>" required>
        <span class="field-help">Use 0.00 when no rental-payment refund will be given.</span>
      </div>
      <div id="rentalRefundFields" hidden>
        <div class="form-grid two-col">
          <div class="form-group"><label>Refund Method *</label><select name="refund_method" data-booking-payment-method><?php foreach(booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"<?= (string)($_POST['refund_method']??'Cash')===$method?' selected':'' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Reference / Transaction No.</label><input name="refund_reference" maxlength="120" value="<?= e((string)($_POST['refund_reference']??'')) ?>" data-booking-payment-reference data-allow-cash-reference></div>
          <div class="form-group"><label>Refunded At *</label><input type="datetime-local" name="refund_at" value="<?= e((string)($_POST['refund_at']??date('Y-m-d\TH:i'))) ?>"></div>
          <div class="form-group"><label>Refund Notes</label><input name="refund_notes" maxlength="255" value="<?= e((string)($_POST['refund_notes']??'')) ?>" placeholder="Optional notes"></div>
        </div>
      </div>
    </div>

    <div class="alert alert-danger rental-inline-alert" style="margin-top:14px"><strong>This will cancel the rental and release its dates/availability.</strong> The payment and refund transactions remain in the audit history.</div>
    <div class="rental-form-footer"><a class="btn btn-outline" href="rental-view.php?id=<?= $id ?>">Keep Rental Active</a><button class="btn btn-danger">Confirm Rental Cancellation</button></div>
  </form>
</section>
<script>
(() => {
  const amount = document.getElementById('rentalRefundAmount');
  const fields = document.getElementById('rentalRefundFields');
  const refundPreview = document.getElementById('rentalRefundPreview');
  const retainedPreview = document.getElementById('rentalRetainedPreview');
  const paid = <?= json_encode(round($paidBefore,2)) ?>;
  if (!amount || !fields || !refundPreview || !retainedPreview) return;
  const money = value => '₱' + Number(value || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  const update = () => {
    let refund = Number.parseFloat(amount.value || '0');
    if (!Number.isFinite(refund) || refund < 0) refund = 0;
    if (refund > paid) refund = paid;
    refundPreview.textContent = money(refund);
    retainedPreview.textContent = money(Math.max(0,paid-refund));
    fields.hidden = refund <= 0.009;
    fields.querySelectorAll('input,select,textarea').forEach(el => {
      if (el.name === 'refund_at' || el.name === 'refund_method') el.required = refund > 0.009;
    });
  };
  amount.addEventListener('input',update);
  update();
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
