<?php
/**
 * FILE PURPOSE: Summary-first Batch Group page, occurrence list, batch payment allocation, and payment history.
 * DEBUGGING: Batch payments are allocated to eligible reservations only. Individual reservation ledgers remain the financial source of truth.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$batchId = (int)($_GET['id'] ?? 0);
$returnUrl = 'batch-reservation-view.php?id=' . $batchId;

$stmt = db()->prepare('SELECT b.*, a.full_name AS created_by_name FROM reservation_batches b LEFT JOIN admins a ON a.id=b.created_by WHERE b.id=? LIMIT 1');
$stmt->execute([$batchId]);
$batch = $stmt->fetch();
if (!$batch) {
    flash('error', 'Batch reservation not found.');
    redirect('reservations.php?view=batches');
}

// Batch payment writes are allocated chronologically to eligible occurrence
// reservations. Each allocation becomes an individual ledger transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $paymentActionSucceeded = false;

    try {
        if ($action !== 'add_batch_payment') {
            throw new RuntimeException('Invalid batch payment action.');
        }

        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $method = trim((string)($_POST['payment_method'] ?? ''));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $notes = trim((string)($_POST['payment_notes'] ?? ''));
        $paidAtRaw = trim((string)($_POST['paid_at'] ?? ''));
        $coverageType = trim((string)($_POST['coverage_type'] ?? 'entire_batch'));

        if (!array_key_exists($coverageType, batch_payment_coverage_types())) {
            throw new RuntimeException('Choose a valid payment coverage.');
        }
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            throw new RuntimeException('Enter a valid batch payment amount.');
        }
        $amount = round((float)$amountRaw, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter a payment amount of at least 0.01.');
        }
        if (!in_array($method, booking_payment_methods(), true)) {
            throw new RuntimeException('Choose a valid payment method.');
        }
        if ($method !== 'Cash' && $reference === '') {
            throw new RuntimeException('Enter a transaction reference or OR number for non-cash payments.');
        }
        if (strlen($reference) > 120) {
            throw new RuntimeException('The payment reference may not exceed 120 characters.');
        }
        if (strlen($notes) > 2000) {
            throw new RuntimeException('The payment notes may not exceed 2,000 characters.');
        }

        try {
            $paidAt = $paidAtRaw !== '' ? new DateTimeImmutable($paidAtRaw) : new DateTimeImmutable();
        } catch (Throwable $e) {
            throw new RuntimeException('Enter a valid payment date and time.');
        }

        $parseDate = static function (string $value, string $label): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0);
            if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
                throw new RuntimeException('Choose a valid ' . $label . '.');
            }
            return $date;
        };

        $pdo = db();
        $pdo->beginTransaction();

        $batchStmt = $pdo->prepare('SELECT * FROM reservation_batches WHERE id=? FOR UPDATE');
        $batchStmt->execute([$batchId]);
        $lockedBatch = $batchStmt->fetch();
        if (!$lockedBatch) {
            throw new RuntimeException('Batch reservation not found.');
        }

        // Archived and terminal cancelled/rejected/no-show dates remain protected.
        // Completed but unarchived dates can still receive a legitimate final payment.
        $reservationStmt = $pdo->prepare("SELECT * FROM reservations
            WHERE batch_id=?
              AND archived_at IS NULL
              AND status NOT IN ('rejected','cancelled','no_show')
            ORDER BY event_start ASC, id ASC
            FOR UPDATE");
        $reservationStmt->execute([$batchId]);
        $eligibleReservations = $reservationStmt->fetchAll();
        if (!$eligibleReservations) {
            throw new RuntimeException('This batch has no active reservations eligible for a batch payment.');
        }

        $eligibleById = [];
        foreach ($eligibleReservations as $reservation) {
            $eligibleById[(int)$reservation['id']] = $reservation;
        }

        $reservationIds = array_keys($eligibleById);
        $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
        $paidStmt = $pdo->prepare("SELECT reservation_id, COALESCE(SUM(amount),0) AS paid_total FROM payments WHERE reservation_id IN ($placeholders) GROUP BY reservation_id");
        $paidStmt->execute($reservationIds);
        $paidByReservation = [];
        foreach ($paidStmt->fetchAll() as $paidRow) {
            $paidByReservation[(int)$paidRow['reservation_id']] = round((float)$paidRow['paid_total'], 2);
        }

        $outstandingRows = [];
        $outstandingById = [];
        foreach ($eligibleReservations as $reservation) {
            $reservationId = (int)$reservation['id'];
            $currentPaid = $paidByReservation[$reservationId] ?? 0.0;
            $target = reservation_payment_target($reservation);
            $balance = max(0, round($target - $currentPaid, 2));
            if ($balance <= 0) {
                continue;
            }
            $row = [
                'reservation' => $reservation,
                'current_paid' => $currentPaid,
                'target' => $target,
                'balance' => $balance,
                'event_date' => substr((string)$reservation['event_start'], 0, 10),
            ];
            $outstandingRows[] = $row;
            $outstandingById[$reservationId] = $row;
        }

        if (!$outstandingRows) {
            throw new RuntimeException('All eligible reservations in this batch are already fully paid.');
        }

        $selectedRows = [];
        $coverageInputStart = null;
        $coverageInputEnd = null;

        switch ($coverageType) {
            case 'specific_week':
                $weekStartRaw = trim((string)($_POST['week_start'] ?? ''));
                $weekStart = $parseDate($weekStartRaw, 'payment week');
                if ((int)$weekStart->format('N') !== 1) {
                    throw new RuntimeException('The selected payment week must begin on a Monday.');
                }
                $weekEnd = $weekStart->modify('+6 days');
                $coverageInputStart = $weekStart->format('Y-m-d');
                $coverageInputEnd = $weekEnd->format('Y-m-d');
                foreach ($outstandingRows as $row) {
                    if ($row['event_date'] >= $coverageInputStart && $row['event_date'] <= $coverageInputEnd) {
                        $selectedRows[] = $row;
                    }
                }
                break;

            case 'date_range':
                $rangeStart = $parseDate(trim((string)($_POST['coverage_start'] ?? '')), 'coverage start date');
                $rangeEnd = $parseDate(trim((string)($_POST['coverage_end'] ?? '')), 'coverage end date');
                if ($rangeEnd < $rangeStart) {
                    throw new RuntimeException('The coverage end date must be on or after the start date.');
                }
                if ($rangeEnd->getTimestamp() - $rangeStart->getTimestamp() > 366 * 86400) {
                    throw new RuntimeException('A payment date range may cover at most one year.');
                }
                $coverageInputStart = $rangeStart->format('Y-m-d');
                $coverageInputEnd = $rangeEnd->format('Y-m-d');
                foreach ($outstandingRows as $row) {
                    if ($row['event_date'] >= $coverageInputStart && $row['event_date'] <= $coverageInputEnd) {
                        $selectedRows[] = $row;
                    }
                }
                break;

            case 'selected_dates':
                $requestedIds = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['selected_reservation_ids'] ?? [])),
                    static fn(int $id): bool => $id > 0
                )));
                if (!$requestedIds) {
                    throw new RuntimeException('Select at least one reservation date to pay.');
                }
                if (count($requestedIds) > 200) {
                    throw new RuntimeException('A batch payment may cover at most 200 reservation dates.');
                }
                foreach ($requestedIds as $reservationId) {
                    if (!isset($eligibleById[$reservationId])) {
                        throw new RuntimeException('One of the selected reservation dates is not eligible for a batch payment.');
                    }
                    if (!isset($outstandingById[$reservationId])) {
                        throw new RuntimeException('One of the selected reservation dates is already fully paid. Refresh the page and try again.');
                    }
                    $selectedRows[] = $outstandingById[$reservationId];
                }
                break;

            case 'single_date':
                $reservationId = (int)($_POST['single_reservation_id'] ?? 0);
                if ($reservationId < 1 || !isset($eligibleById[$reservationId])) {
                    throw new RuntimeException('Choose a valid reservation date to pay.');
                }
                if (!isset($outstandingById[$reservationId])) {
                    throw new RuntimeException('The selected reservation date is already fully paid. Refresh the page and try again.');
                }
                $selectedRows[] = $outstandingById[$reservationId];
                break;

            default:
                $selectedRows = $outstandingRows;
                break;
        }

        usort($selectedRows, static function (array $a, array $b): int {
            $dateCompare = strcmp((string)$a['reservation']['event_start'], (string)$b['reservation']['event_start']);
            return $dateCompare !== 0 ? $dateCompare : ((int)$a['reservation']['id'] <=> (int)$b['reservation']['id']);
        });

        if (!$selectedRows) {
            throw new RuntimeException('The selected payment coverage has no outstanding eligible reservation dates.');
        }

        $selectedOutstanding = 0.0;
        foreach ($selectedRows as $row) {
            $selectedOutstanding = round($selectedOutstanding + $row['balance'], 2);
        }
        if ($amount > $selectedOutstanding + 0.001) {
            throw new RuntimeException('The payment cannot exceed the selected coverage balance of ' . money($selectedOutstanding) . '.');
        }

        $selectedStart = $selectedRows[0]['event_date'];
        $selectedEnd = $selectedRows[count($selectedRows) - 1]['event_date'];
        $coverageStart = $coverageInputStart ?? $selectedStart;
        $coverageEnd = $coverageInputEnd ?? $selectedEnd;
        $coverageCount = count($selectedRows);
        $coverageRangeLabel = batch_payment_date_range_label($coverageStart, $coverageEnd);
        $coverageLabel = match ($coverageType) {
            'specific_week' => 'Week of ' . $coverageRangeLabel,
            'date_range' => 'Date range: ' . $coverageRangeLabel,
            'selected_dates' => $coverageCount . ' selected date' . ($coverageCount === 1 ? '' : 's') . ' · ' . batch_payment_date_range_label($selectedStart, $selectedEnd),
            'single_date' => 'Single date: ' . batch_payment_date_range_label($selectedStart, $selectedEnd),
            default => 'Entire payable batch',
        };

        $remainingToAllocate = $amount;
        $allocationCount = 0;
        foreach ($selectedRows as &$row) {
            $allocation = $remainingToAllocate > 0.001
                ? round(min($row['balance'], $remainingToAllocate), 2)
                : 0.0;
            $row['allocation'] = $allocation;
            if ($allocation > 0) {
                $remainingToAllocate = round($remainingToAllocate - $allocation, 2);
                $allocationCount++;
            }
        }
        unset($row);
        if ($remainingToAllocate > 0.009) {
            throw new RuntimeException('The batch payment could not be fully allocated. No changes were saved.');
        }


        $batchPaymentNo = generate_batch_payment_no();
        $admin = current_admin();
        $masterStmt = $pdo->prepare('INSERT INTO batch_payments(batch_id,batch_payment_no,amount,payment_method,payment_reference,notes,coverage_type,coverage_start,coverage_end,coverage_label,coverage_count,allocation_count,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $masterStmt->execute([
            $batchId,
            $batchPaymentNo,
            $amount,
            $method,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $coverageType,
            $coverageStart,
            $coverageEnd,
            $coverageLabel,
            $coverageCount,
            $allocationCount,
            $admin['id'],
            $paidAt->format('Y-m-d H:i:s'),
        ]);
        $batchPaymentId = (int)$pdo->lastInsertId();

        $scopeStmt = $pdo->prepare('INSERT INTO batch_payment_scopes(batch_payment_id,reservation_id,reservation_reference,event_start_snapshot,event_end_snapshot,balance_before,allocated_amount) VALUES(?,?,?,?,?,?,?)');
        $paymentStmt = $pdo->prepare('INSERT INTO payments(reservation_id,batch_payment_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?,?)');
        $updateStmt = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');

        foreach ($selectedRows as $row) {
            $reservation = $row['reservation'];
            $allocation = (float)$row['allocation'];
            $scopeStmt->execute([
                $batchPaymentId,
                (int)$reservation['id'],
                (string)$reservation['reference_no'],
                (string)$reservation['event_start'],
                (string)$reservation['event_end'],
                $row['balance'],
                $allocation,
            ]);

            if ($allocation <= 0) {
                continue;
            }

            $allocationNote = 'Allocated from batch payment ' . $batchPaymentNo . ' for ' . $lockedBatch['batch_reference'] . '. Coverage: ' . $coverageLabel . '.';
            if ($notes !== '') {
                $allocationNote .= ' ' . $notes;
            }

            $paymentStmt->execute([
                (int)$reservation['id'],
                $batchPaymentId,
                $allocation,
                $method,
                $reference !== '' ? $reference : null,
                $allocationNote,
                $admin['id'],
                $paidAt->format('Y-m-d H:i:s'),
            ]);

            $newPaid = round($row['current_paid'] + $allocation, 2);
            $newStatus = payment_status_for_amount($newPaid, $row['target']);
            $updateStmt->execute([$newPaid, $newStatus, (int)$reservation['id']]);
        }

        $pdo->commit();
    
        $newCoverageBalance = max(0, round($selectedOutstanding - $amount, 2));
        $message = 'Batch payment ' . $batchPaymentNo . ' recorded for ' . $coverageLabel . '. ' . $allocationCount . ' reservation' . ($allocationCount === 1 ? '' : 's') . ' updated.';
        if ($newCoverageBalance > 0.001) {
            $message .= ' Remaining balance in this coverage: ' . money($newCoverageBalance) . '.';
        } else {
            $message .= ' The selected coverage is fully paid.';
        }
        flash('success', $message);
        $paymentActionSucceeded = true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }

    redirect($returnUrl . ($paymentActionSucceeded ? '' : '#batch-payment'));
}

$adminPageTitle = 'Batch ' . $batch['batch_reference'];
$stmt = db()->prepare("SELECT r.*,
    (SELECT c.id FROM reservation_cancellations c WHERE c.reservation_id=r.id LIMIT 1) AS cancellation_record_id,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
    FROM reservations r WHERE r.batch_id=? ORDER BY r.event_start ASC, r.id ASC");
$stmt->execute([$batchId]);
$items = $stmt->fetchAll();

$isSpecificBatch = trim((string)$batch['weekdays']) === 'specific';
$weekdays = $isSpecificBatch ? [] : array_filter(array_map('intval', explode(',', (string)$batch['weekdays'])));
$pattern = $isSpecificBatch
    ? 'Specific dates · Individual times and durations'
    : reservation_batch_pattern_text((string)$batch['range_start'], (string)$batch['range_end'], $weekdays, substr((string)$batch['start_time'], 0, 5), (float)$batch['duration_hours']);
$total = 0.0;
$batchDiscountTotal = 0.0;
$paid = 0.0;
$activeCount = 0;
$archivedCount = 0;
$eligibleOutstanding = 0.0;
$eligibleOutstandingCount = 0;
$excludedOutstanding = 0.0;
$defaultMethod = 'Cash';
$defaultMethodSet = false;

foreach ($items as &$item) {
    $target = reservation_payment_target($item);
    $actualPaid = round((float)$item['ledger_amount_paid'], 2);
    $paymentStatus = payment_status_for_amount($actualPaid, $target);
    $remaining = max(0, round($target - $actualPaid, 2));

    $item['_target'] = $target;
    $item['_actual_paid'] = $actualPaid;
    $item['_payment_status'] = $paymentStatus;
    $item['_remaining'] = $remaining;

    $total = round($total + $target, 2);
    $batchDiscountTotal = round($batchDiscountTotal + max(0, (float)($item['discount_amount'] ?? 0)), 2);
    $paid = round($paid + $actualPaid, 2);
    if (!empty($item['archived_at'])) {
        $archivedCount++;
    } else {
        $activeCount++;
    }

    $eligible = empty($item['archived_at']) && !in_array((string)$item['status'], ['rejected', 'cancelled', 'no_show'], true);
    $item['_eligible_for_batch_payment'] = $eligible;
    if ($remaining > 0) {
        if ($eligible) {
            $eligibleOutstanding = round($eligibleOutstanding + $remaining, 2);
            $eligibleOutstandingCount++;
        } else {
            $excludedOutstanding = round($excludedOutstanding + $remaining, 2);
        }
    }

    if (!$defaultMethodSet && in_array((string)($item['booking_payment_method'] ?? ''), booking_payment_methods(), true)) {
        $defaultMethod = (string)$item['booking_payment_method'];
        $defaultMethodSet = true;
    }
}
unset($item);

$paymentScopeItems = [];
$paymentWeekOptions = [];
foreach ($items as $item) {
    if (empty($item['_eligible_for_batch_payment']) || (float)$item['_remaining'] <= 0) {
        continue;
    }
    $eventDate = substr((string)$item['event_start'], 0, 10);
    $dateObject = new DateTimeImmutable($eventDate . ' 00:00:00');
    $weekStartObject = $dateObject->modify('-' . ((int)$dateObject->format('N') - 1) . ' days');
    $weekEndObject = $weekStartObject->modify('+6 days');
    $weekStart = $weekStartObject->format('Y-m-d');
    $scopeItem = [
        'id' => (int)$item['id'],
        'occurrence' => (int)($item['batch_occurrence'] ?: 0),
        'reference_no' => (string)$item['reference_no'],
        'date' => $eventDate,
        'date_label' => date('D, M j, Y', strtotime((string)$item['event_start'])),
        'time_label' => date('g:i A', strtotime((string)$item['event_start'])) . ' - ' . date('g:i A', strtotime((string)$item['event_end'])),
        'week_start' => $weekStart,
        'balance' => round((float)$item['_remaining'], 2),
    ];
    $paymentScopeItems[] = $scopeItem;

    if (!isset($paymentWeekOptions[$weekStart])) {
        $paymentWeekOptions[$weekStart] = [
            'start' => $weekStart,
            'end' => $weekEndObject->format('Y-m-d'),
            'label' => batch_payment_date_range_label($weekStart, $weekEndObject->format('Y-m-d')),
            'count' => 0,
            'balance' => 0.0,
        ];
    }
    $paymentWeekOptions[$weekStart]['count']++;
    $paymentWeekOptions[$weekStart]['balance'] = round($paymentWeekOptions[$weekStart]['balance'] + $scopeItem['balance'], 2);
}
ksort($paymentWeekOptions);
$scopeMinDate = $paymentScopeItems ? $paymentScopeItems[0]['date'] : '';
$scopeMaxDate = $paymentScopeItems ? $paymentScopeItems[count($paymentScopeItems) - 1]['date'] : '';

$batchCalculatedTotal = round($total + $batchDiscountTotal, 2);
$overallBalance = max(0, round($total - $paid, 2));
$batchPaymentStatus = payment_status_for_amount($paid, $total);

try {
    $historyStmt = db()->prepare('SELECT bp.*, a.full_name AS recorded_by_name FROM batch_payments bp LEFT JOIN admins a ON a.id=bp.recorded_by WHERE bp.batch_id=? ORDER BY bp.paid_at DESC, bp.id DESC');
    $historyStmt->execute([$batchId]);
    $batchPayments = $historyStmt->fetchAll();

    $scopeMap = [];
    if ($batchPayments) {
        try {
            $batchPaymentIds = array_map(static fn(array $row): int => (int)$row['id'], $batchPayments);
            $historyPlaceholders = implode(',', array_fill(0, count($batchPaymentIds), '?'));
            $scopeHistoryStmt = db()->prepare("SELECT bps.*, COALESCE(bps.reservation_reference,r.reference_no) AS reference_no, COALESCE(bps.event_start_snapshot,r.event_start) AS event_start, COALESCE(bps.event_end_snapshot,r.event_end) AS event_end, r.batch_occurrence
                FROM batch_payment_scopes bps
                INNER JOIN reservations r ON r.id=bps.reservation_id
                WHERE bps.batch_payment_id IN ($historyPlaceholders)
                ORDER BY COALESCE(bps.event_start_snapshot,r.event_start) ASC, r.id ASC");
            $scopeHistoryStmt->execute($batchPaymentIds);
            foreach ($scopeHistoryStmt->fetchAll() as $scopeRow) {
                $scopeMap[(int)$scopeRow['batch_payment_id']][] = $scopeRow;
            }
        } catch (Throwable $e) {
            $scopeMap = [];
        }
    }
    foreach ($batchPayments as &$payment) {
        $payment['_scopes'] = $scopeMap[(int)$payment['id']] ?? [];
    }
    unset($payment);
} catch (Throwable $e) {
    $batchPayments = [];
}

include __DIR__ . '/_header.php';
?>
<div class="batch-detail-page">
  <section class="panel reservation-overview-panel batch-overview-panel">
    <div class="reservation-overview-top">
      <div class="reservation-overview-copy">
        <div class="reservation-overview-statuses">
          <span class="status-pill status-secondary"><?= $isSpecificBatch ? 'Specific Dates' : 'Recurring Batch' ?></span>
          <span class="status-pill status-<?= badge_class($batchPaymentStatus) ?>"><?= e($batchPaymentStatus) ?></span>
        </div>
        <h2><?= e($batch['batch_reference']) ?></h2>
        <p class="reservation-overview-client"><strong><?= e($batch['client_name']) ?></strong><?= $batch['organization'] ? ' · ' . e($batch['organization']) : '' ?></p>
        <p class="reservation-overview-meta"><?= e(date('M j, Y', strtotime($batch['range_start']))) ?> – <?= e(date('M j, Y', strtotime($batch['range_end']))) ?> · <?= e($pattern) ?></p>
      </div>
      <div class="reservation-overview-actions batch-overview-actions">
        <a class="btn btn-outline btn-sm" href="reservations.php?view=batches">Back</a>
        <a class="btn btn-outline btn-sm" href="batch-reservation-print.php?id=<?= (int)$batchId ?>" target="_blank" rel="noopener">Print Batch</a>
        <?php if ($eligibleOutstanding > 0): ?>
          <button class="btn btn-primary btn-sm" type="button" data-open-batch-payment-dialog>Record Payment</button>
        <?php elseif ($overallBalance <= 0.009): ?>
          <span class="btn btn-sm payment-complete-label">Fully Paid</span>
        <?php elseif ($excludedOutstanding > 0): ?>
          <span class="btn btn-outline btn-sm" aria-disabled="true">No Eligible Batch Balance · Review Individual Balances</span>
        <?php endif; ?>
        <details class="reservation-action-menu">
          <summary class="btn btn-outline btn-sm">More</summary>
          <div class="reservation-action-menu-popover">
            <a href="batch-reservation-create.php">Create New Batch</a>
            <a href="batch-reservation-create.php?batch_id=<?= (int)$batchId ?>">Add Missing / Past Dates</a>
            <button type="button" data-open-batch-details>View Batch Details</button>
            <button type="button" data-open-batch-history>View Payment History</button>
            <?php if (is_admin()): ?>
              <a class="is-danger" href="batch-reservation-delete.php?id=<?= (int)$batchId ?>&amp;return_to=<?= rawurlencode('reservations.php?view=batches') ?>">Delete batch permanently</a>
            <?php endif; ?>
          </div>
        </details>
      </div>
    </div>

    <div class="reservation-finance-strip batch-finance-strip<?= $batchDiscountTotal > 0 ? ' has-discount' : '' ?>">
      <div><span>Occurrences</span><strong><?= count($items) ?></strong><small><?= $activeCount ?> active<?= $archivedCount ? ' · ' . $archivedCount . ' archived' : '' ?></small></div>
      <?php if ($batchDiscountTotal > 0): ?>
        <div><span>Calculated Total</span><strong><?= money($batchCalculatedTotal) ?></strong></div>
        <div class="reservation-finance-discount"><span>Discount</span><strong>−<?= money($batchDiscountTotal) ?></strong></div>
        <div><span>Client Payable</span><strong><?= money($total) ?></strong></div>
      <?php else: ?>
        <div><span>Batch Total</span><strong><?= money($total) ?></strong></div>
      <?php endif; ?>
      <div><span>Paid</span><strong><?= money($paid) ?></strong></div>
      <div class="<?= $overallBalance > 0 ? 'has-attention' : '' ?>"><span>Balance</span><strong><?= money($overallBalance) ?></strong></div>
    </div>
  </section>

  <?php if ($excludedOutstanding > 0): ?>
    <div class="alert alert-warning reservation-detail-notice">There is <?= money($excludedOutstanding) ?> outstanding on archived, rejected, cancelled, or no-show dates. Review those reservation dates individually before recording payment against them.</div>
  <?php endif; ?>

  <section class="panel batch-occurrences-panel">
    <div class="panel-head batch-occurrences-head">
      <div><h2>Reservation Dates</h2><span class="small muted">Each date remains an individual reservation under this batch.</span></div>
      <span class="small muted"><?= count($items) ?> occurrence<?= count($items) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
      <table class="admin-table batch-occurrence-table mobile-card-table">
        <thead><tr><th>Reservation</th><th>Schedule</th><th>Booking</th><th>Financials</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($items as $index => $item): $itemNeedsResolution = reservation_needs_resolution($item); ?>
          <tr>
            <td data-label="Reservation" data-priority="primary"><strong>#<?= (int)($item['batch_occurrence'] ?: ($index + 1)) ?> · <?= e($item['reference_no']) ?></strong><?php if (!empty($item['archived_at'])): ?><span class="batch-occurrence-note">Archived</span><?php endif; ?></td>
            <td data-label="Schedule"><strong><?= e(date('D, M j, Y', strtotime($item['event_start']))) ?></strong><span class="batch-occurrence-note"><?= e(date('g:i A', strtotime($item['event_start']))) ?> – <?= e(date('g:i A', strtotime($item['event_end']))) ?></span></td>
            <td data-label="Booking"><strong><?= e(reservation_package_label($item['pricing_package'] ?? null)) ?></strong><span class="batch-occurrence-note"><?= e(reservation_cooling_label($item['cooling_option'] ?? null)) ?></span></td>
            <td class="batch-occurrence-finance" data-label="Financials"><strong><?= money($item['_target']) ?></strong><span><?= money($item['_actual_paid']) ?> paid · <?= money($item['_remaining']) ?> balance</span><span class="status-pill status-<?= badge_class($item['_payment_status']) ?>"><?= e($item['_payment_status']) ?></span></td>
            <td data-label="Status"><span class="status-pill status-<?= badge_class($item['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $item['status']))) ?></span><?php if ($itemNeedsResolution): ?><span class="batch-occurrence-note"><span class="status-pill status-warning">Needs Resolution</span></span><?php endif; ?></td>
            <td data-label="Actions"><div class="batch-occurrence-actions"><a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a><?php if ($itemNeedsResolution): ?><a class="btn btn-primary btn-sm" href="needs-resolution.php?search=<?= urlencode((string)$item['reference_no']) ?>">Resolve</a><?php endif; ?><a class="batch-occurrence-print" href="reservation-print.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">Print</a></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="6" class="empty-state">This batch does not contain any reservation dates.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <details class="panel reservation-detail-collapse" id="batch-details">
    <summary><span><strong>Batch Details</strong><small>Client contact, schedule pattern, reservation type, purpose, and creation details</small></span><span class="reservation-collapse-icon" aria-hidden="true">+</span></summary>
    <div class="reservation-detail-collapse-body">
      <div class="reservation-secondary-grid batch-detail-grid">
        <div><span>Client</span><strong><?= e($batch['client_name']) ?></strong></div>
        <div><span>Organization / Team</span><strong><?= e($batch['organization'] ?: '—') ?></strong></div>
        <div><span>Phone</span><strong><?= e($batch['phone']) ?></strong></div>
        <div><span>Email</span><strong class="reservation-break-text"><?= e($batch['email']) ?></strong></div>
        <div><span>Reservation Type</span><strong><?= e(reservation_type_label((string)$batch['reservation_type'])) ?></strong></div>
        <div><span>Purpose</span><strong><?= e($batch['purpose'] ?: '—') ?></strong></div>
        <div><span>Date Range</span><strong><?= e(date('M j, Y', strtotime($batch['range_start']))) ?> – <?= e(date('M j, Y', strtotime($batch['range_end']))) ?></strong></div>
        <div class="batch-detail-pattern"><span>Schedule Pattern</span><strong><?= e($pattern) ?></strong></div>
        <div><span>Created By</span><strong><?= e($batch['created_by_name'] ?: 'Unknown administrator') ?></strong></div>
        <div><span>Created</span><strong><?= e(date('M j, Y g:i A', strtotime($batch['created_at']))) ?></strong></div>
      </div>
    </div>
  </details>

  <details class="panel reservation-detail-collapse" id="batch-payment-history">
    <summary><span><strong>Batch Payment History</strong><small><?= count($batchPayments) ?> batch transaction<?= count($batchPayments) === 1 ? '' : 's' ?> · <?= money($paid) ?> paid</small></span><span class="reservation-collapse-icon" aria-hidden="true">+</span></summary>
    <div class="reservation-detail-collapse-body">
      <div class="batch-history-note">Batch payments can cover the entire batch, a week, one date, a date range, or selected dates. Individual allocations also remain visible inside each reservation.</div>
      <div class="table-wrap">
        <table class="admin-table batch-payment-history-table mobile-card-table">
          <thead><tr><th>Transaction</th><th>Paid At</th><th>Method / Reference</th><th>Coverage</th><th>Allocations</th><th>Recorded By</th><th>Amount</th></tr></thead>
          <tbody>
          <?php foreach ($batchPayments as $payment): ?>
            <?php $paymentScopes = $payment['_scopes'] ?? []; $coverageCount = (int)($payment['coverage_count'] ?? 0); if ($coverageCount < 1) { $coverageCount = count($paymentScopes) ?: (int)$payment['allocation_count']; } ?>
            <tr>
              <td data-label="Transaction" data-priority="primary"><strong><?= e($payment['batch_payment_no']) ?></strong><?php if (!empty($payment['notes'])): ?><br><span class="small muted"><?= e($payment['notes']) ?></span><?php endif; ?></td>
              <td data-label="Paid At"><?= e(date('M j, Y g:i A', strtotime($payment['paid_at']))) ?></td>
              <td data-label="Method / Reference"><?= e(booking_payment_method_label((string)$payment['payment_method'])) ?><br><span class="small muted"><?= e($payment['payment_reference'] ?: 'No external reference') ?></span></td>
              <td class="batch-payment-history-scope" data-label="Coverage"><strong><?= e(batch_payment_coverage_type_label($payment['coverage_type'] ?? 'entire_batch')) ?></strong><span><?= e(batch_payment_coverage_description($payment)) ?></span><?php if ($paymentScopes): ?><details><summary>View <?= count($paymentScopes) ?> selected date<?= count($paymentScopes) === 1 ? '' : 's' ?></summary><ul><?php foreach ($paymentScopes as $scope): ?><li><strong><?= e(date('M j, Y', strtotime($scope['event_start']))) ?></strong> · <?= e($scope['reference_no']) ?> · <?= (float)$scope['allocated_amount'] > 0 ? money($scope['allocated_amount']) . ' allocated of ' . money($scope['balance_before']) : 'No amount allocated · ' . money($scope['balance_before']) . ' balance' ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
              <td data-label="Allocations"><strong><?= (int)$payment['allocation_count'] ?> allocation<?= (int)$payment['allocation_count'] === 1 ? '' : 's' ?></strong><br><span class="small muted">across <?= $coverageCount ?> selected</span></td>
              <td data-label="Recorded By"><?= e($payment['recorded_by_name'] ?: 'Unknown administrator') ?></td>
              <td data-label="Amount"><strong><?= money($payment['amount']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$batchPayments): ?><tr><td colspan="7" class="empty-state">No batch-level payments have been recorded yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </details>
</div>

<?php if ($eligibleOutstanding > 0): ?>
<dialog class="reservation-detail-dialog batch-payment-dialog" id="batch-payment" data-batch-payment-dialog aria-labelledby="batchPaymentDialogTitle">
  <div class="reservation-detail-dialog-shell">
    <div class="reservation-detail-dialog-head">
      <div><span class="small muted"><?= e($batch['batch_reference']) ?> · <?= $eligibleOutstandingCount ?> outstanding date<?= $eligibleOutstandingCount === 1 ? '' : 's' ?></span><h2 id="batchPaymentDialogTitle">Record Batch Payment</h2><p class="batch-payment-dialog-subtitle">Payable balance <?= money($eligibleOutstanding) ?> · Choose exactly which reservation dates this payment covers.</p></div>
      <button class="reservation-detail-dialog-close" type="button" data-close-batch-payment-dialog aria-label="Close batch payment">&times;</button>
    </div>

    <form method="post" action="<?= e($returnUrl) ?>#batch-payment" class="reservation-detail-dialog-form batch-payment-form batch-payment-dialog-form" data-batch-payment-form>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_batch_payment">

      <div class="batch-payment-coverage-card">
        <div class="batch-payment-coverage-heading"><div><h3>1. Payment Coverage</h3><p class="small muted">Only active reservation dates with an outstanding balance are selectable.</p></div></div>
        <div class="form-grid batch-payment-coverage-grid">
          <div class="form-group form-group-wide">
            <label for="batchPaymentCoverage">Payment Coverage</label>
            <select id="batchPaymentCoverage" name="coverage_type" data-batch-payment-coverage required>
              <option value="entire_batch" data-short-label="Entire batch">Entire Batch — all outstanding dates</option>
              <option value="specific_week" data-short-label="Specific week">Specific Week — Monday through Sunday</option>
              <option value="date_range" data-short-label="Date range">Date Range — choose start and end dates</option>
              <option value="selected_dates" data-short-label="Selected dates">Selected Dates — check any reservation dates</option>
              <option value="single_date" data-short-label="Single date">Single Reservation Date — daily payment</option>
            </select>
          </div>

          <div class="form-group form-group-wide batch-payment-scope-panel" data-batch-payment-scope-panel="specific_week" hidden>
            <label for="batchPaymentWeek">Week to Pay</label>
            <select id="batchPaymentWeek" name="week_start" data-batch-payment-week>
              <?php foreach ($paymentWeekOptions as $week): ?>
                <option value="<?= e($week['start']) ?>"><?= e($week['label']) ?> · <?= (int)$week['count'] ?> date<?= (int)$week['count'] === 1 ? '' : 's' ?> · <?= money($week['balance']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="batch-payment-scope-panel batch-payment-range-fields" data-batch-payment-scope-panel="date_range" hidden>
            <div class="form-group"><label for="batchPaymentRangeStart">From Date</label><input id="batchPaymentRangeStart" type="date" name="coverage_start" min="<?= e($scopeMinDate) ?>" max="<?= e($scopeMaxDate) ?>" value="<?= e($scopeMinDate) ?>" data-batch-payment-range-start></div>
            <div class="form-group"><label for="batchPaymentRangeEnd">To Date</label><input id="batchPaymentRangeEnd" type="date" name="coverage_end" min="<?= e($scopeMinDate) ?>" max="<?= e($scopeMaxDate) ?>" value="<?= e($scopeMaxDate) ?>" data-batch-payment-range-end></div>
          </div>

          <div class="form-group form-group-wide batch-payment-scope-panel" data-batch-payment-scope-panel="single_date" hidden>
            <label for="batchPaymentSingleDate">Reservation Date</label>
            <select id="batchPaymentSingleDate" name="single_reservation_id" data-batch-payment-single-date>
              <?php foreach ($paymentScopeItems as $scopeItem): ?>
                <option value="<?= (int)$scopeItem['id'] ?>"><?= e($scopeItem['date_label']) ?> · <?= e($scopeItem['time_label']) ?> · <?= e($scopeItem['reference_no']) ?> · <?= money($scopeItem['balance']) ?> balance</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group form-group-wide batch-payment-scope-panel" data-batch-payment-scope-panel="selected_dates" hidden>
            <div class="batch-payment-date-options-head"><label>Select Reservation Dates</label><button class="link-button" type="button" data-batch-payment-select-all>Select all outstanding dates</button></div>
            <div class="batch-payment-date-options">
              <?php foreach ($paymentScopeItems as $scopeItem): ?>
                <label class="batch-payment-date-option" data-batch-payment-date-row data-reservation-id="<?= (int)$scopeItem['id'] ?>" data-date="<?= e($scopeItem['date']) ?>" data-week-start="<?= e($scopeItem['week_start']) ?>" data-balance="<?= e(number_format($scopeItem['balance'], 2, '.', '')) ?>" data-date-label="<?= e($scopeItem['date_label']) ?>" data-time-label="<?= e($scopeItem['time_label']) ?>" data-reference="<?= e($scopeItem['reference_no']) ?>">
                  <input type="checkbox" name="selected_reservation_ids[]" value="<?= (int)$scopeItem['id'] ?>" data-batch-payment-date-checkbox>
                  <span><strong><?= e($scopeItem['date_label']) ?></strong><small><?= e($scopeItem['time_label']) ?> · <?= e($scopeItem['reference_no']) ?></small></span>
                  <b><?= money($scopeItem['balance']) ?></b>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="batch-payment-selection-summary">
          <div><span>Selected Dates</span><strong data-batch-payment-selected-count><?= count($paymentScopeItems) ?></strong></div>
          <div><span>Selected Balance</span><strong data-batch-payment-selected-balance><?= money($eligibleOutstanding) ?></strong></div>
          <div><span>Allocation</span><strong data-batch-payment-allocation-label>Earliest selected date first</strong></div>
        </div>
        <div class="batch-payment-scope-preview">
          <div><span>Coverage Preview</span><strong data-batch-payment-scope-label>Entire batch</strong></div>
          <ul data-batch-payment-scope-preview-list>
            <?php foreach (array_slice($paymentScopeItems, 0, 6) as $scopeItem): ?><li><?= e($scopeItem['date_label']) ?> · <?= e($scopeItem['reference_no']) ?> · <?= money($scopeItem['balance']) ?></li><?php endforeach; ?>
            <?php if (count($paymentScopeItems) > 6): ?><li>and <?= count($paymentScopeItems) - 6 ?> more date<?= count($paymentScopeItems) - 6 === 1 ? '' : 's' ?></li><?php endif; ?>
          </ul>
        </div>
      </div>

      <div class="batch-payment-details-card">
        <div class="batch-payment-coverage-heading"><div><h3>2. Payment Details</h3><p class="small muted">The amount defaults to the selected balance. A smaller partial payment is allowed.</p></div></div>
        <div class="form-grid batch-payment-form-grid">
          <div class="form-group"><label for="batchPaymentAmount">Payment Amount</label><input id="batchPaymentAmount" type="number" name="amount" min="0.01" max="<?= e(number_format($eligibleOutstanding, 2, '.', '')) ?>" step="0.01" value="<?= e(number_format($eligibleOutstanding, 2, '.', '')) ?>" data-batch-payment-amount required><span class="field-help" data-batch-payment-amount-help>Defaults to the selected coverage balance.</span></div>
          <div class="form-group"><label for="batchPaymentMethod">Payment Method</label><select id="batchPaymentMethod" name="payment_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= $defaultMethod === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label for="batchPaymentReference">Reference / OR Number</label><input id="batchPaymentReference" name="payment_reference" maxlength="120" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional: enter the OR / official receipt number for cash payments.</span></div>
          <div class="form-group"><label for="batchPaymentPaidAt">Paid At</label><input id="batchPaymentPaidAt" type="datetime-local" name="paid_at" value="<?= e(date('Y-m-d\TH:i')) ?>" required></div>
          <div class="form-group form-group-wide"><label for="batchPaymentNotes">Notes</label><textarea id="batchPaymentNotes" name="payment_notes" maxlength="2000" placeholder="Optional note for the allocated payments"></textarea></div>
        </div>
      </div>
      <div class="reservation-detail-dialog-actions"><button class="btn btn-outline" type="button" data-close-batch-payment-dialog>Cancel</button><button class="btn btn-primary" type="submit" data-batch-payment-submit>Record Payment</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>

<script>
(() => {
  const paymentDialog = document.querySelector('[data-batch-payment-dialog]');
  const openDialog = () => {
    if (!paymentDialog || paymentDialog.open) return;
    if (typeof paymentDialog.showModal === 'function') paymentDialog.showModal();
    else paymentDialog.setAttribute('open', '');
  };
  const closeDialog = () => {
    if (!paymentDialog) return;
    if (paymentDialog.open && typeof paymentDialog.close === 'function') paymentDialog.close();
    else paymentDialog.removeAttribute('open');
  };

  document.querySelectorAll('[data-open-batch-payment-dialog]').forEach((button) => button.addEventListener('click', openDialog));
  document.querySelectorAll('[data-close-batch-payment-dialog]').forEach((button) => button.addEventListener('click', closeDialog));
  if (paymentDialog) {
    paymentDialog.addEventListener('click', (event) => {
      if (event.target === paymentDialog) closeDialog();
    });
  }

  const openDetails = (id) => {
    const details = document.getElementById(id);
    if (!details) return;
    details.open = true;
    details.scrollIntoView({behavior: 'smooth', block: 'start'});
  };
  document.querySelectorAll('[data-open-batch-details]').forEach((button) => button.addEventListener('click', () => openDetails('batch-details')));
  document.querySelectorAll('[data-open-batch-history]').forEach((button) => button.addEventListener('click', () => openDetails('batch-payment-history')));

  if (window.location.hash === '#batch-payment') openDialog();
  if (window.location.hash === '#batch-payment-history') openDetails('batch-payment-history');
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
