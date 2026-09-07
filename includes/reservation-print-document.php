<?php
/**
 * FILE PURPOSE: Shared printable document template for one reservation.
 * DEBUGGING: Use stored pricing snapshots/payment ledger data so historical printouts remain accurate after rate changes.
 */
/**
 * Printable client reservation document.
 *
 * Expected variables:
 * - $booking (array)
 * - $payments (array)
 * - $printAssetPrefix (string)
 * - $printBackUrl (string)
 * - $printPreparedBy (string)
 * - $printContext (string: admin|client)
 */
$printAssetPrefix = $printAssetPrefix ?? '';
$printBackUrl = $printBackUrl ?? 'index.php';
$printPreparedBy = trim((string)($printPreparedBy ?? 'The Leisure Hub Administration'));
$printContext = $printContext ?? 'client';
$payments = $payments ?? [];
$cancellation = $cancellation ?? null;

$paymentTarget = reservation_payment_target($booking);
$reservationDiscountAmount = max(0, round((float)($booking['discount_amount'] ?? 0), 2));
$originalClientPayable = $cancellation
    ? max(0, round((float)($cancellation['original_total'] ?? 0), 2))
    : $paymentTarget;
$originalCalculatedTotal = round($originalClientPayable + $reservationDiscountAmount, 2);
$grossPaid = 0.0;
$refundedTotal = 0.0;
$ledgerPaid = 0.0;
foreach ($payments as $payment) {
    $amount = (float)($payment['amount'] ?? 0);
    $ledgerPaid += $amount;
    if (payment_transaction_type($payment) === 'refund' || $amount < 0) {
        $refundedTotal += abs($amount);
    } elseif ($amount > 0) {
        $grossPaid += $amount;
    }
}
$grossPaid = round($grossPaid, 2);
$refundedTotal = round($refundedTotal, 2);
$ledgerPaid = round($ledgerPaid, 2);
$refundPending = $cancellation && (string)($cancellation['refund_status'] ?? '') === 'pending'
    ? max(0, round((float)$cancellation['refund_due'] - (float)$cancellation['refunded_amount'], 2))
    : 0.0;
$paymentView = $booking;
$paymentView['amount_paid'] = $ledgerPaid;
$remainingBalance = reservation_remaining_balance($paymentView);
$excessCredit = reservation_excess_credit($paymentView);
$paymentStatus = payment_status_for_amount($ledgerPaid, $paymentTarget);
$inclusions = reservation_inclusions_for_booking($booking);
$siteName = setting('site_name', 'The Leisure Hub');
$issuedAt = new DateTimeImmutable('now');
$eventStart = new DateTimeImmutable((string)$booking['event_start']);
$eventEnd = new DateTimeImmutable((string)$booking['event_end']);
$setupMinutes = (int)($booking['setup_minutes'] ?? 0);
$cleanupMinutes = (int)($booking['cleanup_minutes'] ?? 0);
$durationHours = !empty($booking['billable_hours'])
    ? (float)$booking['billable_hours']
    : max(0.0, ($eventEnd->getTimestamp() - $eventStart->getTimestamp()) / 3600);

$booking['_cancellation_policy_applied'] = !empty($cancellation);
$chargeItemization = reservation_charge_itemization($booking);

$addons = [];
if (!empty($booking['shower_room_addon'])) {
    $addons[] = 'Shower room — ' . (reservation_shower_is_complimentary($booking) ? 'Complimentary' : money($booking['shower_room_fee'] ?? 0));
}
if (!empty($booking['equipment_bundle_addon'])) {
    $equipmentFee = (float)($booking['equipment_bundle_fee'] ?? 0);
    if (($booking['pricing_package'] ?? '') !== 'regular') {
        $addons[] = 'Equipment bundle — Included';
    } elseif (reservation_equipment_is_complimentary($booking)) {
        $addons[] = 'Equipment bundle — Complimentary';
    } else {
        $addons[] = 'Equipment bundle — ' . money($equipmentFee);
    }
}

$details = trim((string)($booking['details'] ?? ''));
if ($details === '') {
    $details = trim((string)($booking['special_instructions'] ?? ''));
}

$statusLabel = ucwords(str_replace('_', ' ', (string)$booking['status']));
$paymentStatusLabel = ucfirst($paymentStatus);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Reservation <?= e($booking['reference_no']) ?> | <?= e($siteName) ?></title>
  <style>
    :root{--ink:#10273e;--muted:#607080;--line:#d9e0e7;--soft:#f4f7f9;--accent:#7ec900;--success:#267245;--warning:#8a5b00;--danger:#9b2c2c;--paper-w:8.5in;--paper-h:11in;--paper-pad:.20in}
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{background:#edf1f4;color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-size:11.5px;line-height:1.24}
    .print-toolbar{position:sticky;top:0;z-index:3;display:flex;justify-content:center;align-items:center;gap:10px;padding:11px;background:rgba(16,39,62,.96)}
    .print-toolbar a,.print-toolbar button{border:1px solid rgba(255,255,255,.42);border-radius:7px;padding:9px 14px;background:#fff;color:var(--ink);font:700 13px Arial,sans-serif;text-decoration:none;cursor:pointer}
    .print-toolbar .primary{border-color:var(--accent);background:var(--accent)}
    .print-toolbar .paper-note{color:#fff;font-size:11px;font-weight:700;opacity:.9}
    .sheet{width:var(--paper-w);height:var(--paper-h);margin:12px auto;padding:var(--paper-pad);overflow:hidden;background:#fff;box-shadow:0 10px 32px rgba(16,39,62,.15)}
    .print-content{width:100%;transform-origin:top left}
    .document-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding-bottom:10px;border-bottom:2px solid var(--ink)}
    .brand{display:flex;gap:9px;align-items:center;min-width:0}.brand img{width:52px;height:52px;object-fit:contain;flex:0 0 auto}.brand h1{margin:0;font-size:20px;line-height:1.05}.brand p{margin:2px 0 0;color:var(--muted);font-size:8.9px;line-height:1.18}
    .document-title{text-align:right;white-space:nowrap}.document-title span{display:block;color:var(--muted);font-size:8px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}.document-title h2{margin:2px 0 3px;font-size:20px;line-height:1.05}.document-title strong{font-size:12.5px}
    .status-row{display:flex;gap:6px;flex-wrap:wrap;margin:7px 0 1px}.status{display:inline-flex;border-radius:999px;padding:3px 7px;background:var(--soft);font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.035em}.status-approved,.status-paid,.status-completed{background:#e2f4e8;color:var(--success)}.status-pending,.status-for_review,.status-partial{background:#fff1cd;color:var(--warning)}.status-rejected,.status-cancelled,.status-no_show{background:#fde2e2;color:var(--danger)}
    .section{margin-top:9px}.section h3{margin:0 0 4px;padding-bottom:3px;border-bottom:1px solid var(--line);font-size:9px;letter-spacing:.045em;text-transform:uppercase}
    .info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 10px}.info{padding:3px 0;border-bottom:1px solid #edf0f2;min-width:0}.info span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.info strong{display:block;margin-top:1px;font-size:9.7px;line-height:1.18;overflow-wrap:anywhere}.info.wide{grid-column:1/-1}
    .schedule-card{display:grid;grid-template-columns:1.35fr 1fr;gap:12px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:var(--soft)}.schedule-main span,.schedule-side span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.schedule-main strong{display:block;margin-top:2px;font-size:13.5px;line-height:1.05}.schedule-main div{margin-top:2px;font-size:9.8px}.schedule-side{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;align-items:start}.schedule-side strong{display:block;margin-top:1px;font-size:9px;line-height:1.12}
    .two-column{display:grid;grid-template-columns:1.25fr .75fr;gap:15px}.clean-list{margin:0;padding:0;list-style:none;columns:2;column-gap:13px}.clean-list li{position:relative;padding:0 0 2px 11px;font-size:9px;line-height:1.17;break-inside:avoid}.clean-list li:before{content:'✓';position:absolute;left:0;color:var(--success);font-weight:900}.two-column>div:last-child .clean-list{columns:1}.muted{margin:0;color:var(--muted);font-size:9px}
    .money-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--line);border-radius:7px;overflow:hidden}.money-box{padding:7px 9px;background:#fff}.money-box+.money-box{border-left:1px solid var(--line)}.money-box span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.money-box strong{display:block;margin-top:2px;font-size:13.5px}.money-box.balance{background:var(--ink);color:#fff}.money-box.balance span{color:#c8d1da}.money-box.balance strong{color:var(--accent)}
    table{width:100%;border-collapse:collapse;font-size:8.5px;line-height:1.14}th,td{padding:3px 4px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--soft);font-size:6.8px;letter-spacing:.04em;text-transform:uppercase}td.amount,th.amount{text-align:right;white-space:nowrap}.empty{padding:7px;text-align:center;color:var(--muted)}
    .charge-table td{padding-top:4px;padding-bottom:4px}.charge-table td:first-child strong{display:block;font-size:9px}.charge-table .charge-detail{display:block;margin-top:1px;color:var(--muted);font-size:7.2px}.charge-table .charge-adjustment td{background:#fff8e6}.charge-table tfoot td{border-top:1.5px solid var(--ink);border-bottom:0;background:var(--soft);font-weight:800}.charge-table tfoot td:first-child{font-size:8.7px;text-transform:uppercase;letter-spacing:.035em}
    .payment-refund-row td{background:#fff4ec}.refund-amount{color:var(--danger)}.cancellation-print-section{padding:5px 7px;border:1px solid #f0c7b3;border-radius:6px;background:#fffaf6}
    .client-note{padding:5px 7px;border-left:3px solid var(--accent);background:var(--soft);font-size:8.4px;line-height:1.18}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:42px;margin-top:27px}.signature{padding-top:4px;border-top:1px solid var(--ink);font-size:8.4px}.signature strong,.signature span{display:block}.signature span{margin-top:1px;color:var(--muted)}
    .document-footer{display:flex;justify-content:space-between;gap:12px;margin-top:9px;padding-top:4px;border-top:1px solid var(--line);color:var(--muted);font-size:7.2px}.document-footer p{margin:0}.document-footer p:last-child{text-align:right}
    @media screen and (max-width:850px){body{background:#fff}.print-toolbar{position:relative;flex-wrap:wrap}.print-toolbar .paper-note{width:100%;text-align:center}.sheet{width:100%;height:auto;min-height:0;margin:0;padding:18px;overflow:visible;box-shadow:none}.print-content{width:100%!important;transform:none!important}.document-header,.two-column{display:block}.document-title{text-align:left;margin-top:10px;white-space:normal}.info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.schedule-card{grid-template-columns:1fr}.schedule-side{grid-template-columns:repeat(3,minmax(0,1fr))}.two-column>div+div{margin-top:10px}.clean-list{columns:1}.document-footer{display:block}.document-footer p:last-child{text-align:left;margin-top:3px}}
    @page{size:Letter portrait;margin:0}
    @media print{
      html,body{width:var(--paper-w);height:var(--paper-h);background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .print-toolbar{display:none!important}
      .sheet{width:var(--paper-w)!important;height:var(--paper-h)!important;min-height:var(--paper-h)!important;margin:0!important;padding:var(--paper-pad)!important;overflow:hidden!important;box-shadow:none!important}
      .print-content{transform-origin:top left!important}
    }
    .payment-batch-ref{display:block;margin-top:1px;color:var(--muted);font-size:7px;font-weight:700}
  </style>
</head>
<body>
  <div class="print-toolbar" aria-label="Print controls">
    <a href="<?= e($printBackUrl) ?>">Back</a>
    <button class="primary" type="button" onclick="window.print()">Print / Save PDF</button>
    <span class="paper-note">Maximized Letter size · one-page client copy</span>
  </div>

  <main class="sheet" id="printSheet">
    <div class="print-content" id="printContent">
    <header class="document-header">
      <div class="brand">
        <img src="<?= e($printAssetPrefix) ?>assets/img/tlh-logo.png" alt="<?= e($siteName) ?> logo">
        <div>
          <h1><?= e($siteName) ?></h1>
          <p><?= e(setting('address', '')) ?></p>
          <p><?= e(setting('phone', '')) ?><?= setting('email', '') !== '' ? ' · ' . e(setting('email', '')) : '' ?></p>
        </div>
      </div>
      <div class="document-title">
        <span>Client Copy</span>
        <h2>Reservation Confirmation</h2>
        <strong><?= e($booking['reference_no']) ?></strong>
      </div>
    </header>

    <div class="status-row">
      <span class="status status-<?= e((string)$booking['status']) ?>">Reservation: <?= e($statusLabel) ?></span>
      <span class="status status-<?= e($paymentStatus) ?>">Payment: <?= e($paymentStatusLabel) ?></span>
      <?php if ($cancellation): ?><span class="status status-<?= (string)$cancellation['refund_status'] === 'refunded' ? 'paid' : ((string)$cancellation['refund_status'] === 'pending' ? 'partial' : 'secondary') ?>">Refund: <?= e(ucwords(str_replace('_', ' ', (string)$cancellation['refund_status']))) ?></span><?php endif; ?>
    </div>

    <section class="section">
      <h3>Client Information</h3>
      <div class="info-grid">
        <div class="info"><span>Client Name</span><strong><?= e($booking['client_name']) ?></strong></div>
        <div class="info"><span>Organization / Team</span><strong><?= e($booking['organization'] ?: '—') ?></strong></div>
        <div class="info"><span>Email Address</span><strong><?= e($booking['email']) ?></strong></div>
        <div class="info"><span>Phone Number</span><strong><?= e($booking['phone']) ?></strong></div>
      </div>
    </section>

    <section class="section">
      <h3>Reservation Schedule</h3>
      <div class="schedule-card">
        <div class="schedule-main">
          <span>Event Schedule</span>
          <strong><?= e($eventStart->format('F j, Y')) ?></strong>
          <div><?= e($eventStart->format('g:i A')) ?> – <?= e($eventEnd->format('g:i A')) ?></div>
        </div>
        <div class="schedule-side">
          <div><span>Billable Duration</span><strong><?= e(reservation_duration_label($durationHours)) ?></strong></div>
          <?php if ($setupMinutes > 0): ?><div><span>Setup Block</span><strong><?= e((string)$setupMinutes) ?> minutes</strong></div><?php endif; ?>
          <?php if ($cleanupMinutes > 0): ?><div><span>Cleanup Block</span><strong><?= e((string)$cleanupMinutes) ?> minutes</strong></div><?php endif; ?>
        </div>
      </div>
      <div class="info-grid" style="margin-top:10px">
        <div class="info"><span>Reservation Type</span><strong><?= e(reservation_type_label($booking['reservation_type'])) ?></strong></div>
        <div class="info"><span>Purpose</span><strong><?= e($booking['purpose'] ?: '—') ?></strong></div>
        <div class="info"><span>Package</span><strong><?= e(reservation_package_label($booking['pricing_package'] ?? null)) ?></strong></div>
        <div class="info"><span>Cooling Option</span><strong><?= e(reservation_cooling_label($booking['cooling_option'] ?? null)) ?></strong></div>
        <div class="info"><span>Expected Guests</span><strong><?= e((string)($booking['guest_count'] ?: '—')) ?></strong></div>
        <div class="info"><span>Booking Source</span><strong><?= e(ucfirst((string)$booking['source'])) ?></strong></div>
        <?php if (!empty($booking['batch_id'])): ?><div class="info"><span>Batch Reservation</span><strong><?= e(($booking['batch_reference'] ?? ('Batch #' . (int)$booking['batch_id'])) . ' · Occurrence #' . (int)$booking['batch_occurrence']) ?></strong></div><?php endif; ?>
        <?php if ($details !== ''): ?><div class="info wide"><span>Additional Requests or Instructions</span><strong><?= nl2br(e($details)) ?></strong></div><?php endif; ?>
      </div>
    </section>

    <section class="section two-column">
      <div>
        <h3>Package Inclusions</h3>
        <ul class="clean-list">
          <?php foreach ($inclusions as $inclusion): ?><li><?= e($inclusion) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3>Selected Add-ons</h3>
        <?php if ($addons): ?>
          <ul class="clean-list"><?php foreach ($addons as $addon): ?><li><?= e($addon) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
          <p class="muted">No paid add-ons selected.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="section charge-section">
      <h3>Itemized Reservation Charges</h3>
      <table class="charge-table">
        <thead><tr><th>Charge</th><th class="amount">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($chargeItemization['rows'] as $chargeRow): ?>
            <tr class="<?= in_array($chargeRow['key'], ['adjustment', 'cancellation_adjustment'], true) ? 'charge-adjustment' : '' ?>">
              <td><strong><?= e($chargeRow['label']) ?></strong><span class="charge-detail"><?= e($chargeRow['detail']) ?></span></td>
              <td class="amount"><strong><?php if (in_array($chargeRow['key'], ['shower_complimentary', 'equipment_complimentary'], true)): ?>Complimentary<?php elseif (!empty($chargeRow['signed'])): ?><?= $chargeRow['amount'] < 0 ? '−' : '+' ?><?= money(abs((float)$chargeRow['amount'])) ?><?php else: ?><?= money($chargeRow['amount']) ?><?php endif; ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td><?= $cancellation ? '50% Cancellation Charge' : 'Total Reservation Amount' ?></td><td class="amount"><?= money($chargeItemization['total']) ?></td></tr></tfoot>
      </table>
    </section>

    <?php if ($cancellation): ?>
    <section class="section cancellation-print-section">
      <h3>Cancellation Settlement</h3>
      <div class="info-grid">
        <?php if ($reservationDiscountAmount > 0): ?><div class="info"><span>Original Calculated Total</span><strong><?= money($originalCalculatedTotal) ?></strong></div><div class="info"><span>Flexible Discount</span><strong>−<?= money($reservationDiscountAmount) ?></strong></div><?php endif; ?>
        <div class="info"><span>Original Client Payable</span><strong><?= money($cancellation['original_total']) ?></strong></div>
        <div class="info"><span>Cancellation Charge (<?= e(rtrim(rtrim(number_format((float)$cancellation['cancellation_rate'], 2, '.', ''), '0'), '.')) ?>%)</span><strong><?= money($cancellation['cancellation_fee']) ?></strong></div>
        <div class="info"><span>Payments Before Cancellation</span><strong><?= money($cancellation['paid_before_cancellation']) ?></strong></div>
        <div class="info"><span>Refund Due</span><strong><?= money($cancellation['refund_due']) ?></strong></div>
        <div class="info"><span>Refunded</span><strong><?= money($cancellation['refunded_amount']) ?></strong></div>
        <div class="info"><span><?= $refundPending > 0 ? 'Refund Pending' : 'Cancellation Balance' ?></span><strong><?= money($refundPending > 0 ? $refundPending : $remainingBalance) ?></strong></div>
        <div class="info wide"><span>Cancellation Reason</span><strong><?= nl2br(e((string)$cancellation['cancellation_reason'])) ?></strong></div>
      </div>
    </section>
    <?php endif; ?>

    <section class="section">
      <h3>Payment Summary</h3>
      <div class="money-grid">
        <div class="money-box"><span><?= $cancellation ? 'Cancellation Charge' : 'Reservation Total' ?></span><strong><?= money($paymentTarget) ?></strong></div>
        <div class="money-box"><span><?= $cancellation ? 'Net Amount Retained' : 'Total Paid' ?></span><strong><?= money($ledgerPaid) ?></strong><?php if ($cancellation): ?><small style="display:block;margin-top:2px;color:var(--muted);font-size:7px"><?= money($grossPaid) ?> received · <?= money($refundedTotal) ?> refunded</small><?php endif; ?></div>
        <div class="money-box balance"><span><?= $cancellation && $refundPending > 0 ? 'Refund Pending' : ($excessCredit > 0 ? 'Excess Credit' : ($cancellation ? 'Cancellation Balance' : 'Remaining Balance')) ?></span><strong><?= money($cancellation && $refundPending > 0 ? $refundPending : ($excessCredit > 0 ? $excessCredit : $remainingBalance)) ?></strong></div>
      </div>
      <div class="info-grid" style="margin-top:10px">
        <div class="info"><span>Client Payment Selection</span><strong><?= e(match ((string)($booking['booking_payment_choice'] ?? 'none')) { 'full' => 'Full payment', 'partial' => 'Partial payment', default => 'No payment yet' }) ?></strong></div>
        <div class="info"><span>Selected Payment Method</span><strong><?= e($booking['booking_payment_method'] ? booking_payment_method_label($booking['booking_payment_method']) : 'Not specified') ?></strong></div>
        <?php if (in_array((string)($booking['booking_payment_choice'] ?? ''), ['partial','full'], true)): ?><div class="info"><span>Intended Payment Amount</span><strong><?= money((float)($booking['booking_payment_intent_amount'] ?? 0)) ?></strong></div><?php endif; ?>
      </div>
    </section>

    <section class="section payments-section">
      <h3>Recorded Payment Transactions</h3>
      <table>
        <thead><tr><th>Date</th><th>Type / Method</th><th>Reference</th><th class="amount">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $payment): $isRefund = payment_transaction_type($payment) === 'refund'; ?>
            <tr class="<?= $isRefund ? 'payment-refund-row' : '' ?>">
              <td><?= e(date('M j, Y g:i A', strtotime((string)$payment['paid_at']))) ?></td>
              <td><strong><?= e(payment_transaction_label($payment)) ?></strong><span class="payment-batch-ref"><?= e(booking_payment_method_label((string)$payment['payment_method'])) ?></span></td>
              <td><?= e($payment['payment_reference'] ?: '—') ?><?php if (!empty($payment['batch_payment_no'])): ?><span class="payment-batch-ref">Batch <?= e($payment['batch_payment_no']) ?></span><?php endif; ?></td>
              <td class="amount"><strong><?= $isRefund ? '−' . money(abs((float)$payment['amount'])) : money($payment['amount']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td class="empty" colspan="4">No payment transactions have been recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="section">
      <div class="client-note"><?php if ($cancellation): ?>This reservation was cancelled under the 50% cancellation policy. The settlement above shows the amount retained, any refund recorded or pending, and any cancellation balance still due.<?php elseif ((string)($booking['source'] ?? '') === 'website' && empty($booking['created_by']) && !reservation_holds_calendar($booking)): ?><strong>Reservation request only:</strong> this document does not hold or confirm the schedule. The selected time becomes reserved only after admin approval. Payment entries remain subject to venue verification.<?php else: ?>Please review the reservation schedule and details before signing. Contact <?= e(setting('phone', 'The Leisure Hub')) ?> immediately if a correction is needed. Payment entries remain subject to venue verification.<?php endif; ?></div>
      <div class="signature-grid">
        <div class="signature"><strong><?= e($printPreparedBy !== '' ? $printPreparedBy : 'The Leisure Hub Administration') ?></strong><span>Prepared by / Authorized Representative</span></div>
        <div class="signature"><strong><?= e($booking['client_name']) ?></strong><span>Client Acknowledgement / Date</span></div>
      </div>
    </section>

    <footer class="document-footer">
      <p>Issued <?= e($issuedAt->format('F j, Y g:i A')) ?> · Reference <?= e($booking['reference_no']) ?></p>
      <p>This document is a reservation summary and client copy.</p>
    </footer>
    </div>
  </main>
  <script>
    (function () {
      const sheet = document.getElementById('printSheet');
      const content = document.getElementById('printContent');
      if (!sheet || !content) return;

      function fitToLetterPage() {
        content.style.width = '100%';
        content.style.transform = 'none';

        const sheetStyle = window.getComputedStyle(sheet);
        const availableWidth = sheet.clientWidth - parseFloat(sheetStyle.paddingLeft) - parseFloat(sheetStyle.paddingRight);
        const availableHeight = sheet.clientHeight - parseFloat(sheetStyle.paddingTop) - parseFloat(sheetStyle.paddingBottom);
        const MAX_SCALE = 1.12;
        const widthRatio = availableWidth / Math.max(1, content.scrollWidth);
        const heightRatio = availableHeight / Math.max(1, content.scrollHeight);
        let scale = Math.min(MAX_SCALE, widthRatio, heightRatio);

        // Short reservations are enlarged to use more of the Letter sheet.
        // Long reservations are reduced only as much as needed to remain one page.
        if (heightRatio > 1 && widthRatio >= 0.995) {
          scale = Math.min(MAX_SCALE, heightRatio);
        }

        for (let i = 0; i < 6; i += 1) {
          scale = Math.max(0.1, Math.min(MAX_SCALE, scale));
          content.style.width = (100 / scale) + '%';
          content.style.transform = 'scale(' + scale + ')';
          const effectiveWidth = content.scrollWidth * scale;
          const effectiveHeight = content.scrollHeight * scale;
          const correction = Math.min(availableWidth / Math.max(1, effectiveWidth), availableHeight / Math.max(1, effectiveHeight));
          if (Math.abs(1 - correction) < 0.003) break;
          scale *= correction;
        }

        content.dataset.printScale = scale.toFixed(3);
      }

      function restoreScreenLayout() {
        if (window.matchMedia('(max-width: 850px)').matches) {
          content.style.width = '100%';
          content.style.transform = 'none';
          return;
        }
        fitToLetterPage();
      }

      window.addEventListener('load', restoreScreenLayout);
      window.addEventListener('beforeprint', fitToLetterPage);
      window.addEventListener('afterprint', restoreScreenLayout);
      window.addEventListener('resize', restoreScreenLayout);
      setTimeout(restoreScreenLayout, 150);
    }());
  </script>
</body>
</html>
