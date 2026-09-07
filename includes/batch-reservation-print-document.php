<?php
/**
 * FILE PURPOSE: Shared printable document template for a Batch Group.
 * DEBUGGING: Keep calculations sourced from saved reservation/payment data; this template should not recalculate historical prices.
 */
/**
 * Printable client batch-reservation document.
 *
 * Expected variables:
 * - $batch (array)
 * - $items (array)
 * - $batchPayments (array)
 * - $individualPayments (array)
 * - $printAssetPrefix (string)
 * - $printBackUrl (string)
 * - $printPreparedBy (string)
 */
$printAssetPrefix = $printAssetPrefix ?? '';
$printBackUrl = $printBackUrl ?? 'index.php';
$printPreparedBy = trim((string)($printPreparedBy ?? 'The Leisure Hub Administration'));
$items = $items ?? [];
$batchPayments = $batchPayments ?? [];
$individualPayments = $individualPayments ?? [];

$siteName = setting('site_name', 'The Leisure Hub');
$issuedAt = new DateTimeImmutable('now');
$isSpecificBatch = trim((string)($batch['weekdays'] ?? '')) === 'specific';
$weekdays = $isSpecificBatch ? [] : array_filter(array_map('intval', explode(',', (string)($batch['weekdays'] ?? ''))));
$pattern = $isSpecificBatch
    ? 'Specific dates · Individual times and durations'
    : reservation_batch_pattern_text(
        (string)$batch['range_start'],
        (string)$batch['range_end'],
        $weekdays,
        substr((string)$batch['start_time'], 0, 5),
        (float)$batch['duration_hours']
    );

$total = 0.0;
$paid = 0.0;
$payableBalance = 0.0;
$excludedBalance = 0.0;
$activeCount = 0;
$archivedCount = 0;
$packageValues = [];
$coolingValues = [];
$guestValues = [];
$setupValues = [];
$cleanupValues = [];
$detailsValues = [];
$inclusionCounts = [];
$showerCount = 0;
$complimentaryShowerCount = 0;
$equipmentCount = 0;
$equipmentPaidCount = 0;
$complimentaryEquipmentCount = 0;

foreach ($items as &$item) {
    $target = reservation_payment_target($item);
    $actualPaid = round((float)($item['ledger_amount_paid'] ?? $item['amount_paid'] ?? 0), 2);
    $remaining = max(0, round($target - $actualPaid, 2));
    $excess = max(0, round($actualPaid - $target, 2));
    $paymentStatus = payment_status_for_amount($actualPaid, $target);
    $eligible = empty($item['archived_at']) && !in_array((string)$item['status'], ['rejected', 'cancelled', 'no_show'], true);

    $item['_target'] = $target;
    $item['_actual_paid'] = $actualPaid;
    $item['_remaining'] = $remaining;
    $item['_excess'] = $excess;
    $item['_payment_status'] = $paymentStatus;
    $item['_eligible'] = $eligible;
    $item['_charge_itemization'] = reservation_charge_itemization($item);

    $total = round($total + $target, 2);
    $paid = round($paid + $actualPaid, 2);
    if ($eligible) {
        $payableBalance = round($payableBalance + $remaining, 2);
    } else {
        $excludedBalance = round($excludedBalance + $remaining, 2);
    }

    if (!empty($item['archived_at'])) {
        $archivedCount++;
    } else {
        $activeCount++;
    }

    $packageValues[] = reservation_package_label($item['pricing_package'] ?? null);
    $coolingValues[] = reservation_cooling_label($item['cooling_option'] ?? null);
    $guestValues[] = !empty($item['guest_count']) ? (string)(int)$item['guest_count'] : 'Not recorded';
    $setupValues[] = (int)($item['setup_minutes'] ?? 0);
    $cleanupValues[] = (int)($item['cleanup_minutes'] ?? 0);

    $details = trim((string)($item['details'] ?? ''));
    if ($details === '') {
        $details = trim((string)($item['special_instructions'] ?? ''));
    }
    if ($details !== '') {
        $detailsValues[] = $details;
    }

    foreach (reservation_inclusions_for_booking($item) as $inclusion) {
        $inclusionCounts[$inclusion] = ($inclusionCounts[$inclusion] ?? 0) + 1;
    }
    if (!empty($item['shower_room_addon'])) {
        $showerCount++;
        if (reservation_shower_is_complimentary($item)) {
            $complimentaryShowerCount++;
        }
    }
    if (!empty($item['equipment_bundle_addon'])) {
        $equipmentCount++;
        if (reservation_equipment_is_complimentary($item)) {
            $complimentaryEquipmentCount++;
        } elseif ((float)($item['equipment_bundle_fee'] ?? 0) > 0) {
            $equipmentPaidCount++;
        }
    }
}
unset($item);

$batchChargeBreakdown = reservation_batch_charge_breakdown($items);
$occurrenceCount = count($items);
$overallBalance = max(0, round($total - $paid, 2));
$overallExcess = max(0, round($paid - $total, 2));
$batchPaymentStatus = payment_status_for_amount($paid, $total);

$summaryValue = static function (array $values, ?callable $formatter = null): string {
    $normalized = [];
    foreach ($values as $value) {
        $formatted = $formatter ? $formatter($value) : (string)$value;
        if (!in_array($formatted, $normalized, true)) {
            $normalized[] = $formatted;
        }
    }
    if (!$normalized) {
        return 'Not recorded';
    }
    return count($normalized) === 1 ? $normalized[0] : 'Varies by reservation date';
};

$packageSummary = $summaryValue($packageValues);
$coolingSummary = $summaryValue($coolingValues);
$guestSummary = $summaryValue($guestValues);
$setupSummary = $summaryValue($setupValues, static fn($value): string => (int)$value > 0 ? (int)$value . ' minutes' : 'None');
$cleanupSummary = $summaryValue($cleanupValues, static fn($value): string => (int)$value > 0 ? (int)$value . ' minutes' : 'None');
$detailsValues = array_values(array_unique($detailsValues));
$addonSummaries = [];
if ($showerCount > 0) {
    $showerSummary = 'Shower room selected on ' . $showerCount . ' of ' . $occurrenceCount . ' reservation date' . ($occurrenceCount === 1 ? '' : 's');
    if ($complimentaryShowerCount > 0) {
        $showerSummary .= ' (' . $complimentaryShowerCount . ' complimentary)';
    }
    $addonSummaries[] = $showerSummary;
}
if ($equipmentCount > 0) {
    $equipmentLabel = ($equipmentPaidCount > 0 || $complimentaryEquipmentCount > 0) ? 'Equipment bundle' : 'Equipment bundle (included)';
    $equipmentSummary = $equipmentLabel . ' on ' . $equipmentCount . ' of ' . $occurrenceCount . ' reservation date' . ($occurrenceCount === 1 ? '' : 's');
    if ($complimentaryEquipmentCount > 0) {
        $equipmentSummary .= ' (' . $complimentaryEquipmentCount . ' complimentary)';
    }
    $addonSummaries[] = $equipmentSummary;
}

$batchPaymentTotal = 0.0;
foreach ($batchPayments as $payment) {
    $batchPaymentTotal = round($batchPaymentTotal + (float)$payment['amount'], 2);
}
$individualPaymentTotal = 0.0;
foreach ($individualPayments as $payment) {
    $individualPaymentTotal = round($individualPaymentTotal + (float)$payment['amount'], 2);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Batch Reservation <?= e($batch['batch_reference']) ?> | <?= e($siteName) ?></title>
  <style>
    :root{--ink:#10273e;--muted:#607080;--line:#d7dfe6;--soft:#f3f6f8;--accent:#7ec900;--success:#267245;--warning:#8a5b00;--danger:#9b2c2c;--paper-w:8.5in}
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{background:#edf1f4;color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-size:10.5px;line-height:1.25}
    .print-toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;align-items:center;gap:10px;padding:11px;background:rgba(16,39,62,.97)}
    .print-toolbar a,.print-toolbar button{border:1px solid rgba(255,255,255,.42);border-radius:7px;padding:9px 14px;background:#fff;color:var(--ink);font:700 13px Arial,sans-serif;text-decoration:none;cursor:pointer}
    .print-toolbar .primary{border-color:var(--accent);background:var(--accent)}
    .print-toolbar .paper-note{color:#fff;font-size:11px;font-weight:700;opacity:.9}
    .document{width:var(--paper-w);margin:12px auto;padding:.34in;background:#fff;box-shadow:0 10px 32px rgba(16,39,62,.15)}
    .document-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding-bottom:10px;border-bottom:2px solid var(--ink)}
    .brand{display:flex;gap:10px;align-items:center;min-width:0}.brand img{width:56px;height:56px;object-fit:contain;flex:0 0 auto}.brand h1{margin:0;font-size:20px;line-height:1.05}.brand p{margin:2px 0 0;color:var(--muted);font-size:8.8px;line-height:1.18}
    .document-title{text-align:right}.document-title span{display:block;color:var(--muted);font-size:8px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.document-title h2{margin:2px 0 3px;font-size:20px;line-height:1.05}.document-title strong{font-size:12px}
    .status-row{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 2px}.status{display:inline-flex;border-radius:999px;padding:3px 7px;background:var(--soft);font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.035em}.status-paid,.status-approved,.status-completed{background:#e2f4e8;color:var(--success)}.status-unpaid,.status-partial,.status-pending,.status-for_review{background:#fff1cd;color:var(--warning)}.status-rejected,.status-cancelled,.status-no_show{background:#fde2e2;color:var(--danger)}.status-archived{background:#e8ebee;color:#4f5b66}
    .section{margin-top:10px}.section-title{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-bottom:4px;padding-bottom:3px;border-bottom:1px solid var(--line)}.section-title h3{margin:0;font-size:9px;letter-spacing:.055em;text-transform:uppercase}.section-title span{color:var(--muted);font-size:8px}
    .info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 10px}.info{padding:4px 0;border-bottom:1px solid #edf0f2;min-width:0}.info span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.info strong{display:block;margin-top:1px;font-size:9.5px;line-height:1.2;overflow-wrap:anywhere}.info.wide{grid-column:1/-1}
    .pattern-card{display:grid;grid-template-columns:1.45fr repeat(3,.65fr);gap:8px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:var(--soft)}.pattern-card div{min-width:0}.pattern-card span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.pattern-card strong{display:block;margin-top:2px;font-size:10px;line-height:1.16}.pattern-card .main strong{font-size:12px}
    .money-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid var(--line);border-radius:7px;overflow:hidden}.money-box{padding:7px 9px;background:#fff}.money-box+.money-box{border-left:1px solid var(--line)}.money-box span{display:block;color:var(--muted);font-size:7px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}.money-box strong{display:block;margin-top:2px;font-size:12.5px}.money-box.balance{background:var(--ink);color:#fff}.money-box.balance span{color:#c8d1da}.money-box.balance strong{color:var(--accent)}
    .two-column{display:grid;grid-template-columns:1.25fr .75fr;gap:16px}.clean-list{margin:0;padding:0;list-style:none;columns:2;column-gap:14px}.clean-list li{position:relative;padding:0 0 3px 11px;font-size:8.8px;line-height:1.18;break-inside:avoid}.clean-list li:before{content:'✓';position:absolute;left:0;color:var(--success);font-weight:900}.two-column>div:last-child .clean-list{columns:1}.muted{margin:0;color:var(--muted);font-size:8.8px}.notes-list{margin:0;padding-left:17px}.notes-list li{margin:0 0 3px}
    table{width:100%;border-collapse:collapse;font-size:8px;line-height:1.14}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}th,td{padding:4px 4px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--soft);font-size:6.6px;letter-spacing:.045em;text-transform:uppercase}td.number,th.number{text-align:center;white-space:nowrap}td.amount,th.amount{text-align:right;white-space:nowrap}.reference{font-weight:700}.subtext{display:block;margin-top:1px;color:var(--muted);font-size:7px}.empty{padding:8px;text-align:center;color:var(--muted)}
    .charge-table td:first-child strong{display:block;font-size:8.8px}.charge-table .charge-detail{display:block;margin-top:1px;color:var(--muted);font-size:7.1px}.charge-table .charge-adjustment td{background:#fff8e6}.charge-table tfoot td{border-top:1.5px solid var(--ink);border-bottom:0;background:var(--soft);font-weight:800}.charge-lines{margin-top:2px}.charge-lines span{display:block;color:var(--muted);font-size:6.7px;line-height:1.14}.charge-lines .adjustment{color:var(--warning)}
    .charge-table td:first-child{width:31%}.charge-table td:nth-child(2){color:var(--muted)}.charge-table td strong{color:var(--ink)}.charge-table .charge-total td{border-top:1px solid var(--ink);border-bottom:0;background:var(--soft);font-size:8.4px}.charge-table .charge-total td:last-child{font-size:9.3px}
    .payment-split{display:grid;grid-template-columns:1fr 1fr;gap:14px}.payment-box{min-width:0}.payment-total-note{margin:4px 0 0;color:var(--muted);font-size:7.8px;text-align:right}
    .payment-refund-row td{background:#fff4ec}.refund-amount{color:var(--danger)}
    .client-note{padding:6px 8px;border-left:3px solid var(--accent);background:var(--soft);font-size:8.4px;line-height:1.2}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:46px;margin-top:31px}.signature{padding-top:4px;border-top:1px solid var(--ink);font-size:8.4px}.signature strong,.signature span{display:block}.signature span{margin-top:1px;color:var(--muted)}
    .document-footer{display:flex;justify-content:space-between;gap:12px;margin-top:12px;padding-top:5px;border-top:1px solid var(--line);color:var(--muted);font-size:7.2px}.document-footer p{margin:0}.document-footer p:last-child{text-align:right}
    .keep-together{break-inside:avoid;page-break-inside:avoid}
    @media screen and (max-width:850px){body{background:#fff}.print-toolbar{position:relative;flex-wrap:wrap}.print-toolbar .paper-note{width:100%;text-align:center}.document{width:100%;margin:0;padding:18px;box-shadow:none}.document-header,.two-column,.payment-split{display:block}.document-title{text-align:left;margin-top:10px}.info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pattern-card{grid-template-columns:1fr 1fr}.pattern-card .main{grid-column:1/-1}.two-column>div+div,.payment-box+.payment-box{margin-top:12px}.clean-list{columns:1}.money-grid{grid-template-columns:1fr 1fr}.money-box:nth-child(3){border-left:0;border-top:1px solid var(--line)}.money-box:nth-child(4){border-top:1px solid var(--line)}.document-footer{display:block}.document-footer p:last-child{text-align:left;margin-top:3px}}
    @page{size:Letter portrait;margin:.34in}
    @media print{html,body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.print-toolbar{display:none!important}.document{width:auto;margin:0;padding:0;box-shadow:none}.section,.keep-together{break-inside:avoid;page-break-inside:avoid}.schedule-section{break-inside:auto;page-break-inside:auto}.schedule-section table{break-inside:auto;page-break-inside:auto}.schedule-section tbody{break-inside:auto;page-break-inside:auto}}
  </style>
</head>
<body>
  <div class="print-toolbar">
    <a href="<?= e($printBackUrl) ?>">Back to Batch</a>
    <span class="paper-note">Letter-size batch client copy · may use multiple pages</span>
    <button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>
  </div>

  <main class="document">
    <header class="document-header keep-together">
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
        <h2>Batch Reservation Confirmation</h2>
        <strong><?= e($batch['batch_reference']) ?></strong>
      </div>
    </header>

    <div class="status-row keep-together">
      <span class="status status-<?= e($batchPaymentStatus) ?>">Batch Payment: <?= e(ucfirst($batchPaymentStatus)) ?></span>
      <span class="status">Reservations: <?= $occurrenceCount ?> total</span>
      <?php if ($archivedCount > 0): ?><span class="status status-archived"><?= $activeCount ?> active · <?= $archivedCount ?> archived</span><?php endif; ?>
    </div>

    <section class="section keep-together">
      <div class="section-title"><h3>Client Information</h3></div>
      <div class="info-grid">
        <div class="info"><span>Client Name</span><strong><?= e($batch['client_name']) ?></strong></div>
        <div class="info"><span>Organization / Team</span><strong><?= e($batch['organization'] ?: '—') ?></strong></div>
        <div class="info"><span>Email Address</span><strong><?= e($batch['email']) ?></strong></div>
        <div class="info"><span>Phone Number</span><strong><?= e($batch['phone']) ?></strong></div>
      </div>
    </section>

    <section class="section keep-together">
      <div class="section-title"><h3><?= $isSpecificBatch ? 'Specific Date Reservations' : 'Recurring Reservation' ?></h3></div>
      <div class="pattern-card">
        <div class="main"><span><?= $isSpecificBatch ? 'Schedule Type' : 'Recurring Pattern' ?></span><strong><?= e($pattern) ?></strong></div>
        <div><span>Date Range</span><strong><?= e(date('M j, Y', strtotime((string)$batch['range_start']))) ?> – <?= e(date('M j, Y', strtotime((string)$batch['range_end']))) ?></strong></div>
        <div><span>Duration</span><strong><?= $isSpecificBatch ? 'Varies by date' : (reservation_duration_label((float)$batch['duration_hours']) . ' per date') ?></strong></div>
        <div><span>Occurrences</span><strong><?= $occurrenceCount ?></strong></div>
      </div>
      <div class="info-grid" style="margin-top:7px">
        <div class="info"><span>Reservation Type</span><strong><?= e(reservation_type_label((string)$batch['reservation_type'])) ?></strong></div>
        <div class="info"><span>Purpose</span><strong><?= e($batch['purpose'] ?: '—') ?></strong></div>
        <div class="info"><span>Package</span><strong><?= e($packageSummary) ?></strong></div>
        <div class="info"><span>Cooling Option</span><strong><?= e($coolingSummary) ?></strong></div>
        <div class="info"><span>Expected Guests</span><strong><?= e($guestSummary) ?></strong></div>
        <div class="info"><span>Setup Block</span><strong><?= e($setupSummary) ?></strong></div>
        <div class="info"><span>Cleanup Block</span><strong><?= e($cleanupSummary) ?></strong></div>
        <div class="info"><span>Created By</span><strong><?= e($batch['created_by_name'] ?: 'The Leisure Hub Administration') ?></strong></div>
      </div>
    </section>

    <section class="section keep-together">
      <div class="section-title"><h3>Batch Payment Summary</h3><span>Amounts are totaled across all reservation dates.</span></div>
      <div class="money-grid">
        <div class="money-box"><span>Batch Total</span><strong><?= money($total) ?></strong></div>
        <div class="money-box"><span>Total Paid</span><strong><?= money($paid) ?></strong></div>
        <div class="money-box"><span>Payable Active Balance</span><strong><?= money($payableBalance) ?></strong></div>
        <div class="money-box balance"><span><?= $overallExcess > 0 ? 'Excess Credit' : 'Overall Balance' ?></span><strong><?= money($overallExcess > 0 ? $overallExcess : $overallBalance) ?></strong></div>
      </div>
      <?php if ($excludedBalance > 0): ?><p class="payment-total-note"><?= money($excludedBalance) ?> of the overall balance belongs to archived, rejected, cancelled, or no-show dates and is excluded from the batch-payment action.</p><?php endif; ?>
    </section>

    <section class="section keep-together">
      <div class="section-title"><h3>Itemized Batch Charges</h3><span>Grouped from the saved charge breakdown of every reservation date.</span></div>
      <table class="charge-table">
        <thead><tr><th>Item</th><th>Calculation / Description</th><th class="amount">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($batchChargeBreakdown['rows'] as $charge): ?>
            <tr>
              <td><strong><?= e($charge['label']) ?></strong></td>
              <td><?= e($charge['detail']) ?></td>
              <td class="amount"><strong><?= in_array(($charge['type'] ?? ''), ['shower_complimentary', 'equipment_complimentary'], true) ? 'Complimentary' : reservation_charge_money($charge['amount']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$batchChargeBreakdown['rows']): ?><tr><td class="empty" colspan="3">No itemized reservation charges are available.</td></tr><?php endif; ?>
          <tr class="charge-total"><td colspan="2"><strong>Total Batch Amount</strong></td><td class="amount"><strong><?= money($batchChargeBreakdown['total']) ?></strong></td></tr>
        </tbody>
      </table>
    </section>

    <section class="section two-column keep-together">
      <div>
        <div class="section-title"><h3>Reservation Inclusions</h3></div>
        <?php if ($inclusionCounts): ?>
          <ul class="clean-list">
            <?php foreach ($inclusionCounts as $inclusion => $count): ?>
              <li><?= e($inclusion) ?><?= $count < $occurrenceCount ? ' · ' . (int)$count . ' date' . ($count === 1 ? '' : 's') : '' ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="muted">No inclusion information is available.</p><?php endif; ?>
      </div>
      <div>
        <div class="section-title"><h3>Selected Add-ons</h3></div>
        <?php if ($addonSummaries): ?><ul class="clean-list"><?php foreach ($addonSummaries as $addon): ?><li><?= e($addon) ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">No paid add-ons selected.</p><?php endif; ?>
      </div>
    </section>

    <?php if ($detailsValues): ?>
      <section class="section keep-together">
        <div class="section-title"><h3>Additional Requests or Instructions</h3></div>
        <?php if (count($detailsValues) === 1): ?><p class="muted" style="color:var(--ink)"><?= nl2br(e($detailsValues[0])) ?></p><?php else: ?><ul class="notes-list"><?php foreach ($detailsValues as $details): ?><li><?= nl2br(e($details)) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="section schedule-section">
      <div class="section-title"><h3>Reservation Dates</h3><span>Each row remains an individual reservation record.</span></div>
      <table>
        <thead><tr><th class="number">#</th><th>Date / Time</th><th>Reservation Reference</th><th>Package</th><th>Status</th><th class="amount">Total</th><th class="amount">Paid</th><th class="amount">Balance</th></tr></thead>
        <tbody>
          <?php foreach ($items as $index => $item): ?>
            <?php
              $statusLabel = ucwords(str_replace('_', ' ', (string)$item['status']));
              if (!empty($item['archived_at'])) {
                  $statusLabel .= ' · Archived';
              }
            ?>
            <tr>
              <td class="number"><?= (int)($item['batch_occurrence'] ?: ($index + 1)) ?></td>
              <td><strong><?= e(date('D, M j, Y', strtotime((string)$item['event_start']))) ?></strong><span class="subtext"><?= e(date('g:i A', strtotime((string)$item['event_start']))) ?> – <?= e(date('g:i A', strtotime((string)$item['event_end']))) ?></span></td>
              <td><span class="reference"><?= e($item['reference_no']) ?></span><span class="subtext"><?= e(reservation_cooling_label($item['cooling_option'] ?? null)) ?></span></td>
              <td>
                <?= e(reservation_package_label($item['pricing_package'] ?? null)) ?>
                <div class="charge-lines">
                  <span><?= e($item['_charge_itemization']['base_detail']) ?> = <?= money($item['_charge_itemization']['base_amount']) ?></span>
                  <?php if (!empty($item['shower_room_addon'])): ?><span>Shower Room = <?= !empty($item['_charge_itemization']['shower_complimentary']) ? 'Complimentary' : money($item['_charge_itemization']['shower_fee']) ?></span><?php endif; ?>
                  <?php if (!empty($item['equipment_bundle_addon']) && ($item['pricing_package'] ?? '') === 'regular'): ?><span>Equipment Bundle = <?= !empty($item['_charge_itemization']['equipment_complimentary']) ? 'Complimentary' : money($item['_charge_itemization']['equipment_fee']) ?></span><?php endif; ?>
                  <?php if (abs((float)$item['_charge_itemization']['adjustment']) >= 0.005): ?><span class="adjustment">Adjustment = <?= $item['_charge_itemization']['adjustment'] < 0 ? '−' : '+' ?><?= money(abs((float)$item['_charge_itemization']['adjustment'])) ?></span><?php endif; ?>
                </div>
              </td>
              <td><strong><?= e($statusLabel) ?></strong><span class="subtext">Payment: <?= e(ucfirst($item['_payment_status'])) ?></span></td>
              <td class="amount"><?= money($item['_target']) ?></td>
              <td class="amount"><?= money($item['_actual_paid']) ?></td>
              <td class="amount"><?= $item['_excess'] > 0 ? money($item['_excess']) . ' credit' : money($item['_remaining']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?><tr><td class="empty" colspan="8">This batch does not contain reservation dates.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="section payment-split">
      <div class="payment-box">
        <div class="section-title"><h3>Batch Payment Transactions</h3><span><?= money($batchPaymentTotal) ?> total</span></div>
        <table>
          <thead><tr><th>Date</th><th>Transaction / Method</th><th class="amount">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($batchPayments as $payment): ?>
              <?php $paymentScopes = $payment['_scopes'] ?? []; $scopeDateSummary = batch_payment_scope_date_summary($paymentScopes, 4); $coverageCount = (int)($payment['coverage_count'] ?? 0); if ($coverageCount < 1) { $coverageCount = count($paymentScopes) ?: (int)$payment['allocation_count']; } ?>
              <tr>
                <td><?= e(date('M j, Y', strtotime((string)$payment['paid_at']))) ?><span class="subtext"><?= e(date('g:i A', strtotime((string)$payment['paid_at']))) ?></span></td>
                <td><strong><?= e($payment['batch_payment_no']) ?></strong><span class="subtext"><?= e(booking_payment_method_label((string)$payment['payment_method'])) ?> · <?= e($payment['payment_reference'] ?: 'No external reference') ?></span><span class="subtext">Coverage: <?= e(batch_payment_coverage_description($payment)) ?></span><?php if ($scopeDateSummary !== ''): ?><span class="subtext">Dates: <?= e($scopeDateSummary) ?></span><?php endif; ?></td>
                <td class="amount"><strong><?= money($payment['amount']) ?></strong><span class="subtext"><?= (int)$payment['allocation_count'] ?> allocation<?= (int)$payment['allocation_count'] === 1 ? '' : 's' ?> · <?= $coverageCount ?> selected</span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$batchPayments): ?><tr><td class="empty" colspan="3">No batch-level payment transactions recorded.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="payment-box">
        <div class="section-title"><h3>Individual Date Payments</h3><span><?= money($individualPaymentTotal) ?> total</span></div>
        <table>
          <thead><tr><th>Paid At</th><th>Reservation / Method</th><th class="amount">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($individualPayments as $payment): $isRefund = payment_transaction_type($payment) === 'refund'; ?>
              <tr class="<?= $isRefund ? 'payment-refund-row' : '' ?>">
                <td><?= e(date('M j, Y', strtotime((string)$payment['paid_at']))) ?><span class="subtext"><?= e(date('g:i A', strtotime((string)$payment['paid_at']))) ?></span></td>
                <td><strong><?= e($payment['reference_no']) ?></strong><span class="subtext"><?= e(payment_transaction_label($payment)) ?> · <?= e(booking_payment_method_label((string)$payment['payment_method'])) ?> · <?= e($payment['payment_reference'] ?: 'No external reference') ?></span></td>
                <td class="amount"><strong class="<?= $isRefund ? 'refund-amount' : '' ?>"><?= $isRefund ? '−' . money(abs((float)$payment['amount'])) : money($payment['amount']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$individualPayments): ?><tr><td class="empty" colspan="3">No one-date payments recorded outside the batch-payment action.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="section keep-together">
      <div class="client-note">Please review every reservation date before signing. Setup and cleanup periods block the calendar but are not billable. Any later rescheduling, extension, cancellation, payment, or archive action will be reflected only on a newly printed copy.</div>
      <div class="signature-grid">
        <div class="signature"><strong><?= e($printPreparedBy !== '' ? $printPreparedBy : 'The Leisure Hub Administration') ?></strong><span>Prepared by / Authorized Representative</span></div>
        <div class="signature"><strong><?= e($batch['client_name']) ?></strong><span>Client Acknowledgement / Date</span></div>
      </div>
    </section>

    <footer class="document-footer keep-together">
      <p>Issued <?= e($issuedAt->format('F j, Y g:i A')) ?> · Batch <?= e($batch['batch_reference']) ?></p>
      <p>This document is a consolidated batch-reservation summary and client copy.</p>
    </footer>
  </main>
</body>
</html>
