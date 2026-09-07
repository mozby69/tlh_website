<?php
/**
 * FILE PURPOSE: Public reservation tracking page by reference number.
 * DEBUGGING: Do not require or echo email addresses in the URL. Client-facing data should remain privacy-safe.
 */
require_once __DIR__ . '/includes/functions.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pageTitle = 'Track Reservation';
$bodyClass = 'native-track-page';
$booking = null;
$cancellation = null;
$ledgerTotals = null;
$error = '';
$reference = trim($_POST['reference'] ?? ($_GET['reference'] ?? ''));
$contact = trim($_POST['contact'] ?? ($_POST['email'] ?? ($_POST['phone'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reference !== '' && $contact !== '') {
    try {
        $stmt = db()->prepare('SELECT * FROM reservations WHERE reference_no=? LIMIT 1');
        $stmt->execute([$reference]);
        $candidate = $stmt->fetch();
        $storedEmail = is_array($candidate) ? trim((string)($candidate['email'] ?? '')) : '';
        $storedPhone = is_array($candidate) ? preg_replace('/\D+/', '', (string)($candidate['phone'] ?? '')) : '';
        $contactPhone = preg_replace('/\D+/', '', $contact);
        $emailMatches = $storedEmail !== '' && strcasecmp($storedEmail, $contact) === 0;
        $phoneMatches = $storedPhone !== '' && $contactPhone !== '' && hash_equals($storedPhone, $contactPhone);
        if (!$candidate || (!$emailMatches && !$phoneMatches)) {
            $booking = null;
            $error = 'No reservation matched that reference number and contact information.';
        } else {
            $booking = $candidate;
            $cancellation = reservation_cancellation_record((int)$booking['id']);
            $ledgerTotals = reservation_payment_ledger_totals((int)$booking['id']);
            $booking['amount_paid'] = $ledgerTotals['net_paid'];
        }
    } catch (Throwable $e) {
        $booking = null;
        $cancellation = null;
        $error = 'The reservation lookup is currently unavailable.';
    }
}
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="eyebrow">Reservation Status</span><h1>Track your reservation</h1><p>Enter your reference number and either the email address or mobile number used for the reservation.</p></div></section>
<section class="section"><div class="container" style="max-width:850px"><div class="form-card"><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-group"><label>Reference Number</label><input name="reference" value="<?= e($reference) ?>" placeholder="e.g. TLH12" required></div><div class="form-group"><label>Email or Mobile Number</label><input name="contact" value="<?= e($contact) ?>" autocomplete="email" required></div><div class="form-group full"><button class="btn btn-dark" type="submit">Check Status</button></div></form></div>
<?php if ($error): ?><div class="alert alert-danger" style="width:100%;margin:24px 0"><?= e($error) ?></div><?php endif; ?>
<?php if ($booking):
  $target = reservation_payment_target($booking);
  $balance = reservation_remaining_balance($booking);
  $credit = reservation_excess_credit($booking);
  $reservationInclusions = reservation_inclusions_for_booking($booking);
  $calculatedPaymentStatus = payment_status_for_amount((float)$booking['amount_paid'], reservation_payment_target($booking));
  $trackStatus = (string)($booking['status'] ?? 'pending');
  $terminalStatus = in_array($trackStatus, ['rejected','cancelled','no_show'], true);
  $workflowCurrent = match ($trackStatus) {
      'pending' => 1,
      'for_review' => 2,
      'approved' => $calculatedPaymentStatus === 'paid' ? 4 : 3,
      'completed' => 5,
      default => 1,
  };
  $workflowSteps = [
      1 => ['Submitted', 'Request received'],
      2 => ['For Review', 'Administrator review'],
      3 => ['Approved', 'Schedule secured'],
      4 => ['Payment', $calculatedPaymentStatus === 'paid' ? 'Payment recorded' : ($calculatedPaymentStatus === 'partial' ? 'Partial payment recorded' : 'Payment pending')],
      5 => ['Completed', 'Event completed'],
  ];
  $refundPending = !empty($cancellation) && (string)$cancellation['refund_status'] === 'pending'
      ? max(0, round((float)$cancellation['refund_due'] - (float)$cancellation['refunded_amount'], 2))
      : 0.0;
?>
<div class="card native-tracking-card" style="margin-top:24px">
  <div class="tracking-status-hero status-<?= badge_class($booking['status']) ?>">
    <span class="tracking-status-label">Reservation Status</span>
    <strong class="tracking-status-value"><?= e(strtoupper(str_replace('_', ' ', $booking['status']))) ?></strong>
    <span class="tracking-status-reference">Reference: <?= e($booking['reference_no']) ?></span>
  </div>

  <div class="native-tracking-timeline" aria-label="Reservation progress">
    <?php foreach ($workflowSteps as $stepNumber => [$stepLabel, $stepNote]):
      if ($terminalStatus) {
          $stepClass = $stepNumber === 1 ? 'is-complete' : 'is-muted';
      } elseif ($stepNumber === 1) {
          $stepClass = $trackStatus === 'pending' ? 'is-current' : 'is-complete';
      } elseif ($stepNumber === 2) {
          $stepClass = $trackStatus === 'for_review' ? 'is-current' : (in_array($trackStatus, ['approved','completed'], true) ? 'is-complete' : '');
      } elseif ($stepNumber === 3) {
          $stepClass = in_array($trackStatus, ['approved','completed'], true) ? 'is-complete' : '';
      } elseif ($stepNumber === 4) {
          $stepClass = $calculatedPaymentStatus === 'paid' ? 'is-complete' : (in_array($trackStatus, ['approved','completed'], true) ? 'is-current' : '');
      } else {
          $stepClass = $trackStatus === 'completed' ? 'is-complete' : '';
      }
    ?>
      <div class="native-tracking-step <?= e($stepClass) ?>">
        <span class="native-tracking-step-dot"><?= $stepClass === 'is-complete' ? '✓' : e((string)$stepNumber) ?></span>
        <div><strong><?= e($stepLabel) ?></strong><small><?= e($stepNote) ?></small></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ((string)$booking['source'] === 'website' && empty($booking['created_by']) && !reservation_holds_calendar($booking) && in_array((string)$booking['status'], ['pending','for_review'], true)): ?>
    <div class="alert alert-warning" style="width:100%;margin:20px 0 0"><strong>This request is not holding the schedule yet.</strong> Your selected time becomes reserved only after an administrator approves the request. Another request may be submitted for the same schedule before approval.</div>
  <?php endif; ?>

  <?php if (!empty($cancellation)): ?>
    <div class="alert alert-warning" style="width:100%;margin:20px 0 0">
      <strong>This reservation was cancelled under the 50% cancellation policy.</strong>
      <?php if ($refundPending > 0): ?> A refund of <?= money($refundPending) ?> is pending.<?php elseif ((string)$cancellation['refund_status'] === 'refunded'): ?> A refund of <?= money($cancellation['refunded_amount']) ?> has been recorded.<?php elseif ($balance > 0): ?> A cancellation balance of <?= money($balance) ?> remains due.<?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="grid-2" style="margin-top:20px">
    <div><span class="small muted">Reservation</span><h3><?= e(reservation_type_label($booking['reservation_type'])) ?></h3></div>
    <div><span class="small muted">Schedule</span><h3><?= date('M j, Y g:i A', strtotime($booking['event_start'])) ?> – <?= date('M j, Y g:i A', strtotime($booking['event_end'])) ?></h3></div>
    <div><span class="small muted">Pricing Package</span><h3><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?></h3></div>
    <div><span class="small muted">Cooling Option</span><h3><?= e(reservation_cooling_label($booking['cooling_option'] ?? null)) ?></h3></div>
    <div><span class="small muted">Reservation Inclusions</span><ul class="public-inclusions-list"><?php foreach ($reservationInclusions as $inclusion): ?><li><?= e($inclusion) ?></li><?php endforeach; ?></ul></div>
    <div><span class="small muted">Guests</span><h3><?= e((string)($booking['guest_count'] ?: 'Not recorded')) ?></h3></div>
    <div><span class="small muted">Billable Duration</span><h3><?= !empty($booking['billable_hours']) ? e(reservation_duration_label((float)$booking['billable_hours'])) : 'Not recorded' ?></h3></div>
    <?php if (!empty($cancellation)): ?>
      <div><span class="small muted">Original Reservation Price</span><h3><?= money($cancellation['original_total']) ?></h3></div>
      <div><span class="small muted">50% Cancellation Charge</span><h3><?= money($cancellation['cancellation_fee']) ?></h3></div>
      <div><span class="small muted">Payments Before Cancellation</span><h3><?= money($cancellation['paid_before_cancellation']) ?></h3></div>
      <div><span class="small muted">Refunded</span><h3><?= money($cancellation['refunded_amount']) ?></h3></div>
      <div><span class="small muted"><?= $refundPending > 0 ? 'Refund Pending' : 'Cancellation Balance' ?></span><h3><?= money($refundPending > 0 ? $refundPending : $balance) ?></h3></div>
      <div><span class="small muted">Cancellation Reason</span><h3><?= e($cancellation['cancellation_reason']) ?></h3></div>
    <?php else: ?>
      <div><span class="small muted">Reservation Amount</span><h3><?= money($target) ?></h3></div>
      <div><span class="small muted">Payment Status</span><h3><?= e(ucfirst($calculatedPaymentStatus)) ?></h3></div>
      <div><span class="small muted">Total Paid</span><h3><?= money($booking['amount_paid']) ?></h3></div>
      <div><span class="small muted"><?= $credit > 0 ? 'Excess Payment Credit' : 'Remaining Balance' ?></span><h3><?= money($credit > 0 ? $credit : $balance) ?></h3></div>
    <?php endif; ?>
    <div><span class="small muted">Client Payment Selection</span><h3><?= e(match ((string)($booking['booking_payment_choice'] ?? 'none')) { 'full' => 'Full payment', 'partial' => 'Partial payment', default => 'No payment yet' }) ?></h3></div>
    <div><span class="small muted">Selected Payment Method</span><h3><?= e($booking['booking_payment_method'] ? booking_payment_method_label($booking['booking_payment_method']) : 'Not specified') ?></h3></div>
    <?php if (in_array((string)($booking['booking_payment_choice'] ?? ''), ['partial','full'], true)): ?><div><span class="small muted">Intended Payment Amount</span><h3><?= money((float)($booking['booking_payment_intent_amount'] ?? 0)) ?></h3></div><?php endif; ?>
  </div>
  <?php if ((float)$booking['amount_paid'] > 0): ?><p class="small muted">Payments shown as paid are entries encoded and verified by venue administration.</p><?php endif; ?>
  <p class="small muted">Please contact <?= e(setting('phone')) ?> for questions or changes. Status changes are controlled by venue administration.</p>
  <form method="post" action="reservation-print.php" target="_blank" style="margin-top:20px">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="reference" value="<?= e($reference) ?>">
    <input type="hidden" name="contact" value="<?= e($contact) ?>">
    <button class="btn btn-primary" type="submit">Print Reservation</button>
  </form>
</div>
<?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
