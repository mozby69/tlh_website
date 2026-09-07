<?php
/**
 * FILE PURPOSE: Unified reservation list containing individual reservations and Batch Groups.
 * DEBUGGING: Filtering/listing logic should keep pending public requests distinct from schedules that actually hold the calendar.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$cancelledReservationsOnly = !empty($cancelledReservationsOnly);
$listBasePage = $cancelledReservationsOnly ? 'cancelled.php' : 'reservations.php';
$adminPageTitle = $cancelledReservationsOnly ? 'Cancelled' : 'Reservations';

$view = $cancelledReservationsOnly ? 'all' : ($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'batches'], true)) {
    $view = 'all';
}

$status = $cancelledReservationsOnly ? 'cancelled' : ($_GET['status'] ?? '');
$type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');
$date = $_GET['date'] ?? '';

// Reservation-list pagination. Batch Groups keep their existing compact list;
// individual reservations are paged so this screen stays fast as records grow.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$totalItems = 0;
$totalPages = 1;
$pageStart = 0;
$pageEnd = 0;

$allowedStatuses = ['pending', 'for_review', 'approved', 'rejected', 'completed', 'no_show'];
$allowedTypes = ['basketball', 'volleyball', 'event'];
if (!$cancelledReservationsOnly && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}
if (!in_array($type, $allowedTypes, true)) {
    $type = '';
}
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}
if ($view === 'batches') {
    $status = '';
    $type = '';
    $date = '';
    $page = 1;
}

$listQueryValues = [];
if ($view === 'batches') $listQueryValues['view'] = 'batches';
if (!$cancelledReservationsOnly && $status !== '') $listQueryValues['status'] = $status;
if ($type !== '') $listQueryValues['type'] = $type;
if ($search !== '') $listQueryValues['search'] = $search;
if ($date !== '') $listQueryValues['date'] = $date;
if ($view === 'all' && $page > 1) $listQueryValues['page'] = $page;
$listQuery = http_build_query($listQueryValues);
$listUrl = $listBasePage . ($listQuery !== '' ? '?' . $listQuery : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action !== 'add_payment') {
            throw new RuntimeException('Invalid reservation action.');
        }

        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $amountRaw = trim($_POST['amount'] ?? '');
        $method = trim($_POST['payment_method'] ?? '');
        $reference = trim($_POST['payment_reference'] ?? '');
        $notes = trim($_POST['payment_notes'] ?? '');
        $paidAtRaw = trim($_POST['paid_at'] ?? '');

        if ($reservationId <= 0) {
            throw new RuntimeException('Choose a valid reservation.');
        }
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

        try {
            $paidAt = $paidAtRaw !== '' ? new DateTimeImmutable($paidAtRaw) : new DateTimeImmutable();
        } catch (Throwable $e) {
            throw new RuntimeException('Enter a valid payment date and time.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
        $bookingStmt->execute([$reservationId]);
        $booking = $bookingStmt->fetch();
        if (!$booking) {
            throw new RuntimeException('Reservation not found.');
        }
        $paymentEligibility = reservation_payment_eligibility($booking, (string)($booking['status'] ?? '') === 'cancelled');
        if (!$paymentEligibility['allowed']) {
            throw new RuntimeException($paymentEligibility['message']);
        }

        $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
        $totalStmt->execute([$reservationId]);
        $currentTotal = round((float)$totalStmt->fetchColumn(), 2);
        $target = reservation_payment_target($booking);
        $remaining = max(0, round($target - $currentTotal, 2));
        $currentPaymentStatus = payment_status_for_amount($currentTotal, $target);

        if (!in_array($currentPaymentStatus, ['unpaid', 'partial'], true) || $remaining <= 0) {
            throw new RuntimeException('This reservation has no outstanding balance available for a payment update.');
        }
        if ($amount > $remaining + 0.001) {
            throw new RuntimeException('The payment cannot exceed the remaining balance of ' . money($remaining) . '.');
        }

        $stmt = $pdo->prepare('INSERT INTO payments(reservation_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([
            $reservationId,
            $amount,
            $method,
            $reference !== '' ? $reference : null,
            $notes,
            current_admin()['id'],
            $paidAt->format('Y-m-d H:i:s'),
        ]);

        $newTotal = round($currentTotal + $amount, 2);
        $paymentStatus = payment_status_for_amount($newTotal, $target);
        $stmt = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');
        $stmt->execute([$newTotal, $paymentStatus, $reservationId]);
        $pdo->commit();

        flash('success', 'Payment updated for reservation ' . $booking['reference_no'] . '.');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }

    redirect($listUrl);
}

$items = [];
$batches = [];
if ($view === 'batches') {
    $sql = "SELECT b.*,
            (SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id) AS actual_occurrences,
            (SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id AND r.archived_at IS NULL) AS active_occurrences,
            (SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id AND r.archived_at IS NOT NULL) AS archived_occurrences,
            (SELECT COALESCE(SUM(COALESCE(r.final_amount,r.estimated_amount)),0) FROM reservations r WHERE r.batch_id=b.id) AS current_total,
            (SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN reservations rp ON rp.id=p.reservation_id WHERE rp.batch_id=b.id) AS current_paid,
            (SELECT COALESCE(SUM(GREATEST(COALESCE(r.final_amount,r.estimated_amount) - COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.reservation_id=r.id),0),0)),0)
               FROM reservations r
              WHERE r.batch_id=b.id
                AND r.archived_at IS NULL
                AND r.status NOT IN ('rejected','cancelled','no_show')) AS payable_balance
            FROM reservation_batches b";
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE (b.batch_reference LIKE ? OR b.client_name LIKE ? OR b.phone LIKE ? OR b.email LIKE ? OR b.organization LIKE ? OR EXISTS (SELECT 1 FROM batch_payments bp WHERE bp.batch_id=b.id AND (bp.batch_payment_no LIKE ? OR bp.payment_reference LIKE ?)))';
        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term, $term, $term, $term];
    }
    $sql .= ' ORDER BY b.created_at DESC LIMIT 200';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();
    } catch (Throwable $e) {
        $batches = [];
    }
} else {
    // Build the filters once and reuse them for both COUNT and page data.
    // Keeping these predicates identical prevents pagination totals from
    // disagreeing with the rows that are actually displayed.
    $whereSql = $cancelledReservationsOnly
        ? " WHERE r.status='cancelled'"
        : " WHERE r.archived_at IS NULL AND r.status<>'cancelled'";
    $params = [];
    if (!$cancelledReservationsOnly && $status !== '') {
        $whereSql .= ' AND r.status=?';
        $params[] = $status;
    }
    if ($type !== '') {
        $whereSql .= ' AND r.reservation_type=?';
        $params[] = $type;
    }
    if ($date !== '') {
        try {
            $dayStart = new DateTimeImmutable($date . ' 00:00:00');
            $dayEnd = $dayStart->modify('+1 day');
            $whereSql .= ' AND r.blocked_start < ? AND r.blocked_end > ?';
            $params[] = $dayEnd->format('Y-m-d H:i:s');
            $params[] = $dayStart->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $date = '';
        }
    }
    if ($search !== '') {
        $whereSql .= ' AND (r.reference_no LIKE ? OR b.batch_reference LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR r.booking_payment_reference LIKE ? OR EXISTS (SELECT 1 FROM payments lp WHERE lp.reservation_id=r.id AND lp.payment_reference LIKE ?) OR EXISTS (SELECT 1 FROM batch_payments bp WHERE bp.id IN (SELECT lp2.batch_payment_id FROM payments lp2 WHERE lp2.reservation_id=r.id) AND bp.batch_payment_no LIKE ?))';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term, $term, $term, $term);
    }

    try {
        $pdo = db();

        // Count first so the requested page can be clamped to the real range.
        // This also gives the admin a useful "Showing X-Y of Z" summary.
        $countSql = 'SELECT COUNT(*) FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id' . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT r.*, b.batch_reference, c.original_total AS cancellation_original_total, c.refund_status AS cancellation_refund_status, c.refund_due AS cancellation_refund_due, c.refunded_amount AS cancellation_refunded_amount, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id LEFT JOIN reservation_cancellations c ON c.reservation_id=r.id' . $whereSql;
        // LIMIT/OFFSET are derived only from validated integers above, so they
        // can be safely embedded while every user-supplied filter stays bound.
        $sql .= ' ORDER BY r.event_start DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        if ($totalItems > 0) {
            $pageStart = $offset + 1;
            $pageEnd = min($offset + count($items), $totalItems);
        }
    } catch (Throwable $e) {
        $items = [];
        $totalItems = 0;
        $totalPages = 1;
        $page = 1;
    }

    // Rebuild the return URL after page clamping. Payment updates, Done/archive,
    // and other row actions therefore return the admin to the same list page.
    $listQueryValues = [];
    if (!$cancelledReservationsOnly && $status !== '') $listQueryValues['status'] = $status;
    if ($type !== '') $listQueryValues['type'] = $type;
    if ($search !== '') $listQueryValues['search'] = $search;
    if ($date !== '') $listQueryValues['date'] = $date;
    if ($page > 1) $listQueryValues['page'] = $page;
    $listQuery = http_build_query($listQueryValues);
    $listUrl = $listBasePage . ($listQuery !== '' ? '?' . $listQuery : '');
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-hub-panel">
  <div class="panel-head reservation-hub-head">
    <div>
      <h2><?= $cancelledReservationsOnly ? 'Cancelled Reservations' : ($view === 'batches' ? 'Batch Reservation Groups' : 'Reservation List') ?></h2>
      <span class="small muted"><?= $cancelledReservationsOnly ? 'All cancelled reservations and their cancellation settlement records are kept here.' : 'All active one-time and multi-date reservations are managed from this menu.' ?></span>
    </div>
    <div class="admin-actions reservation-hub-actions">
      <?php if ($cancelledReservationsOnly): ?>
        <a class="btn btn-outline btn-sm" href="reservations.php">Active Reservations</a>
      <?php else: ?>
        <a class="btn btn-primary btn-sm" href="batch-reservation-create.php">+ Batch Reservation</a>
      <?php endif; ?>
      <a class="btn btn-outline btn-sm" href="booking-calendar.php">View Monthly Calendar</a>
    </div>
  </div>

  <?php if (!$cancelledReservationsOnly): ?>
  <nav class="reservation-hub-tabs" aria-label="Reservation list views">
    <a class="<?= $view === 'all' ? 'active' : '' ?>" href="reservations.php">
      <span>All Reservations</span>
      <small>Every individual booking date</small>
    </a>
    <a class="<?= $view === 'batches' ? 'active' : '' ?>" href="reservations.php?view=batches">
      <span>Batch Groups</span>
      <small>Multi-date bookings grouped together</small>
    </a>
  </nav>
  <?php endif; ?>

  <?php if ($view === 'batches'): ?>
    <form method="get" class="toolbar reservation-batch-toolbar">
      <input type="hidden" name="view" value="batches">
      <div class="form-group"><label>Search Batch Groups</label><input name="search" value="<?= e($search) ?>" placeholder="Batch ref, client, team, phone, or email"></div>
      <button class="btn btn-dark btn-sm" type="submit">Search</button>
      <a class="btn btn-outline btn-sm" href="reservations.php?view=batches">Reset</a>
    </form>

    <div class="batch-list-guidance">
      <strong>One row represents one batch booking group.</strong>
      <span>Open a group to manage every occurrence or record a whole-batch, weekly, daily, date-range, or selected-date payment. Choose Show Dates to display its reservations in the main list.</span>
    </div>

    <div class="table-wrap batch-groups-table-wrap">
      <table class="admin-table batch-groups-table">
        <thead><tr><th>Batch</th><th>Client</th><th>Schedule</th><th>Reservations</th><th>Financial Summary</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$batches): ?>
          <tr><td colspan="6"><div class="empty-state">No batch reservation groups match your search.</div></td></tr>
        <?php else: foreach ($batches as $batch):
            $isSpecificBatch = trim((string)$batch['weekdays']) === 'specific';
            $weekdays = $isSpecificBatch ? [] : array_filter(array_map('intval', explode(',', (string)$batch['weekdays'])));
            $pattern = $isSpecificBatch
                ? 'Specific dates · Individual times and durations'
                : reservation_batch_pattern_text(
                    (string)$batch['range_start'],
                    (string)$batch['range_end'],
                    $weekdays,
                    substr((string)$batch['start_time'], 0, 5),
                    (float)$batch['duration_hours']
                );
            $batchTotal = round((float)($batch['current_total'] ?? $batch['total_amount'] ?? 0), 2);
            $batchPaid = round((float)($batch['current_paid'] ?? 0), 2);
            $batchBalance = max(0, round($batchTotal - $batchPaid, 2));
            $batchPayable = max(0, round((float)($batch['payable_balance'] ?? $batchBalance), 2));
            $batchPaymentStatus = payment_status_for_amount($batchPaid, $batchTotal);
            $showDatesUrl = 'reservations.php?' . http_build_query(['search' => (string)$batch['batch_reference']]);
        ?>
          <tr>
            <td data-label="Batch" data-priority="primary"><strong><?= e($batch['batch_reference']) ?></strong><br><span class="small muted">Created <?= e(date('M j, Y', strtotime($batch['created_at']))) ?></span></td>
            <td data-label="Client"><?= e($batch['client_name']) ?><br><span class="small muted"><?= e($batch['phone']) ?><?= !empty($batch['organization']) ? ' · ' . e($batch['organization']) : '' ?></span></td>
            <td data-label="Schedule"><strong><?= e(date('M j, Y', strtotime($batch['range_start']))) ?> – <?= e(date('M j, Y', strtotime($batch['range_end']))) ?></strong><br><span class="small muted"><?= e($pattern) ?></span></td>
            <td data-label="Reservations"><strong><?= (int)$batch['actual_occurrences'] ?> total</strong><br><span class="small muted"><?= (int)$batch['active_occurrences'] ?> active · <?= (int)$batch['archived_occurrences'] ?> archived</span></td>
            <td data-label="Financial Summary"><span class="status-pill status-<?= badge_class($batchPaymentStatus) ?>"><?= e($batchPaymentStatus) ?></span><br><strong><?= money($batchTotal) ?></strong><br><span class="small muted"><?= money($batchPaid) ?> paid · <?= money($batchBalance) ?> total balance</span><?php if (abs($batchPayable - $batchBalance) > 0.001): ?><br><span class="small muted"><?= money($batchPayable) ?> payable on eligible dates</span><?php endif; ?></td>
            <td data-label="Actions"><div class="reservation-list-actions batch-group-actions"><a class="btn btn-primary btn-sm" href="batch-reservation-view.php?id=<?= (int)$batch['id'] ?>">Open Group</a><?php if ($batchPayable > 0): ?><a class="btn btn-dark btn-sm" href="batch-reservation-view.php?id=<?= (int)$batch['id'] ?>#batch-payment">Record Payment</a><?php elseif ($batchBalance <= 0.009): ?><span class="btn btn-sm payment-complete-label">Fully Paid</span><?php elseif ($batchBalance > 0.009): ?><span class="small muted batch-payment-review-label">No eligible batch balance · Review individual balances</span><?php endif; ?><a class="btn btn-outline btn-sm" href="batch-reservation-print.php?id=<?= (int)$batch['id'] ?>" target="_blank" rel="noopener">Print Batch</a><a class="btn btn-outline btn-sm" href="<?= e($showDatesUrl) ?>">Show Dates</a><a class="btn btn-outline btn-sm" href="batch-reservation-create.php?batch_id=<?= (int)$batch['id'] ?>">Add Missing Dates</a><?php if (is_admin()): ?><form method="post" action="batch-reservation-delete.php" class="inline-action-form" onsubmit="return confirm('Permanently delete batch <?= e($batch['batch_reference']) ?> and all of its connected reservation dates? This cannot be undone. Batches with payment history will be protected and cannot be deleted.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="return_to" value="<?= e($listUrl) ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form><?php endif; ?></div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <form method="get" class="toolbar">
      <div class="form-group"><label>Search</label><input name="search" value="<?= e($search) ?>" placeholder="Booking, batch, client, or payment ref"></div>
      <?php if (!$cancelledReservationsOnly): ?><div class="form-group"><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $value))) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="form-group"><label>Type</label><select name="type"><option value="">All types</option><option value="basketball" <?= $type === 'basketball' ? 'selected' : '' ?>>Basketball</option><option value="volleyball" <?= $type === 'volleyball' ? 'selected' : '' ?>>Volleyball</option><option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Events</option></select></div>
      <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
      <button class="btn btn-dark btn-sm" type="submit">Filter</button>
      <a class="btn btn-outline btn-sm" href="<?= e($listBasePage) ?>">Reset</a>
    </form>

    <div class="table-wrap reservation-list-table-wrap">
      <table class="admin-table reservation-list-table">
        <thead><tr><th>Reference</th><th>Client</th><th>Reservation</th><th>Schedule</th><th>Amount</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item):
            // Derive payment state from the payment ledger so older or recalculated
            // reservations do not lose their quick-payment action because of a stale
            // reservations.amount_paid or reservations.payment_status value.
            $actualAmountPaid = round((float)($item['ledger_amount_paid'] ?? $item['amount_paid'] ?? 0), 2);
            $paymentView = $item;
            $paymentView['amount_paid'] = $actualAmountPaid;
            $calculatedPaymentStatus = payment_status_for_amount($actualAmountPaid, reservation_payment_target($paymentView));
            $remainingBalance = reservation_remaining_balance($paymentView);
            $excessCredit = reservation_excess_credit($paymentView);
            $canUpdatePayment = in_array($calculatedPaymentStatus, ['unpaid', 'partial'], true)
                && $remainingBalance > 0
                && reservation_can_receive_payment($paymentView, (string)$item['status'] === 'cancelled');
            $hasEnded = reservation_has_ended($item);
            $needsResolution = reservation_needs_resolution($item);
            $canReschedule = reservation_can_reschedule($item);
            $canExtend = reservation_can_extend($item);
            $canLateExtend = reservation_can_late_extend($item);
            $canCancel = reservation_can_cancel($item);
            $canEdit = reservation_can_edit($item);
            $canArchive = reservation_can_archive($paymentView);
            $isCancelled = (string)$item['status'] === 'cancelled';
            $refundStillDue = $isCancelled && (string)($item['cancellation_refund_status'] ?? '') === 'pending'
                ? max(0, round((float)($item['cancellation_refund_due'] ?? 0) - (float)($item['cancellation_refunded_amount'] ?? 0), 2))
                : 0.0;
            $cancellationOriginalTotal = $isCancelled
                ? (float)($item['cancellation_original_total'] ?? $item['estimated_amount'] ?? 0)
                : 0.0;
            $defaultMethod = in_array(($item['booking_payment_method'] ?? ''), booking_payment_methods(), true) ? $item['booking_payment_method'] : 'Cash';
        ?>
          <tr>
            <td data-label="Reference" data-priority="primary"><strong><?= e($item['reference_no']) ?></strong><?php if (str_starts_with((string)($item['admin_notes'] ?? ''), '[HISTORICAL BACKFILL]')): ?><br><span class="status-pill status-secondary" style="margin-top:5px">Historical</span><?php endif; ?><?php if (!empty($item['batch_reference'])): ?><br><a class="small batch-reference-link" href="batch-reservation-view.php?id=<?= (int)$item['batch_id'] ?>"><?= e($item['batch_reference']) ?> · #<?= (int)$item['batch_occurrence'] ?></a><?php else: ?><br><span class="small muted"><?= e(ucfirst($item['source'])) ?></span><?php endif; ?></td>
            <td data-label="Client"><?= e($item['client_name']) ?><br><span class="small muted"><?= e($item['phone']) ?></span></td>
            <td data-label="Reservation"><?= e(reservation_type_label($item['reservation_type'])) ?><br><span class="small muted"><?= e($item['purpose']) ?></span></td>
            <td data-label="Schedule"><?= date('M j, Y', strtotime($item['event_start'])) ?><br><span class="small muted"><?= date('g:i A', strtotime($item['event_start'])) ?> – <?= date('g:i A', strtotime($item['event_end'])) ?></span></td>
            <td data-label="Amount"><?php if ($isCancelled): ?><strong><?= money(reservation_payment_target($paymentView)) ?></strong><br><span class="small muted">50% cancellation charge<br>Original: <?= money($cancellationOriginalTotal) ?></span><?php else: ?><?= money($item['final_amount'] ?? $item['estimated_amount']) ?><?php endif; ?></td>
            <td data-label="Payment"><span class="status-pill status-<?= badge_class($calculatedPaymentStatus) ?>"><?= e($calculatedPaymentStatus) ?></span><br><span class="small muted"><?= money($actualAmountPaid) ?> <?= $isCancelled ? 'net retained' : 'paid' ?> · <?php if ($refundStillDue > 0): ?><?= money($refundStillDue) ?> refund pending<?php elseif ($excessCredit > 0): ?><?= money($excessCredit) ?> credit<?php else: ?><?= money($remainingBalance) ?> <?= $isCancelled ? 'cancellation balance' : 'balance' ?><?php endif; ?></span><br><span class="small muted"><?= e($item['booking_payment_method'] ? booking_payment_method_label($item['booking_payment_method']) : 'No method') ?></span></td>
            <td data-label="Status"><span class="status-pill status-<?= badge_class($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span><?php if (!empty($item['archived_at'])): ?><br><span class="status-pill status-secondary" style="margin-top:6px">Legacy Archived</span><?php elseif ($needsResolution): ?><br><span class="status-pill status-warning" style="margin-top:6px">Needs Resolution</span><?php elseif ($hasEnded): ?><br><span class="status-pill status-secondary" style="margin-top:6px"><?= $canLateExtend ? 'Ended · Late Extension Available' : 'Ended · Locked' ?></span><?php endif; ?></td>
            <td data-label="Actions">
              <?php
                // Keep the row scan-friendly: frequent/high-priority actions stay visible,
                // while secondary operations live under More. Payment state itself is already
                // shown in the Payment column, so a redundant Fully Paid action is not rendered.
              ?>
              <div class="reservation-list-actions reservation-row-actions">
                <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a>
                <?php if ($needsResolution): ?><a class="btn btn-primary btn-sm" href="needs-resolution.php?search=<?= urlencode((string)$item['reference_no']) ?>">Resolve</a><?php endif; ?>
                <?php if ($canEdit): ?><a class="btn btn-dark btn-sm" href="reservation-edit.php?id=<?= (int)$item['id'] ?>">Edit</a><?php endif; ?>
                <?php if ($canUpdatePayment): ?>
                  <button
                    class="btn btn-primary btn-sm"
                    type="button"
                    data-payment-modal-trigger
                    data-reservation-id="<?= (int)$item['id'] ?>"
                    data-reservation-reference="<?= e($item['reference_no']) ?>"
                    data-reservation-client="<?= e($item['client_name']) ?>"
                    data-reservation-balance="<?= e(number_format($remainingBalance, 2, '.', '')) ?>"
                    data-reservation-balance-label="<?= e(money($remainingBalance)) ?>"
                    data-reservation-method="<?= e($defaultMethod) ?>"
                    data-payment-title="<?= $isCancelled ? 'Pay Cancellation Fee' : 'Update Payment' ?>"
                  ><?= $isCancelled ? 'Pay Cancellation Fee' : 'Update Payment' ?></button>
                <?php endif; ?>
                <?php if ($refundStillDue > 0): ?><a class="btn btn-primary btn-sm" href="reservation-cancel.php?id=<?= (int)$item['id'] ?>">Record Refund</a><?php endif; ?>

                <details class="reservation-row-more">
                    <summary class="btn btn-outline btn-sm reservation-row-more-trigger">More <span aria-hidden="true">•••</span></summary>
                    <div class="reservation-row-more-menu">
                      <a href="reservation-print.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">Print</a>
                      <?php if ($canReschedule): ?><a href="reservation-reschedule.php?id=<?= (int)$item['id'] ?>">Reschedule</a><?php endif; ?>
                      <?php if ($canExtend): ?><a href="reservation-extend.php?id=<?= (int)$item['id'] ?>">Extend</a><?php elseif ($canLateExtend): ?><a href="reservation-extend.php?id=<?= (int)$item['id'] ?>">Late Extension</a><?php endif; ?>
                      <?php if ($canCancel): ?><a class="is-danger" href="reservation-cancel.php?id=<?= (int)$item['id'] ?>">Cancel Reservation</a><?php endif; ?>
                      <?php if ($canArchive): ?>
                        <form method="post" action="reservation-archive.php" class="inline-action-form" onsubmit="return confirm('Mark this reservation as Done and move it to Archives?');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="archive">
                          <input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>">
                          <input type="hidden" name="return_to" value="<?= e($listUrl) ?>">
                          <button type="submit">Done · Move to Archives</button>
                        </form>
                      <?php endif; ?>
                      <?php if (is_admin()): ?>
                        <form method="post" action="reservation-delete.php" class="inline-action-form" onsubmit="return confirm('Permanently delete reservation <?= e($item['reference_no']) ?>? This cannot be undone. Reservations with payment history will be protected and cannot be deleted.');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>">
                          <input type="hidden" name="return_to" value="<?= e($listUrl) ?>">
                          <button class="is-danger" type="submit">Delete Permanently</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </details>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="8" class="empty-state"><?= $cancelledReservationsOnly ? 'No cancelled reservations match the selected filters.' : 'No reservations match the selected filters.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalItems > 0):
      $paginationBase = [];
      if (!$cancelledReservationsOnly && $status !== '') $paginationBase['status'] = $status;
      if ($type !== '') $paginationBase['type'] = $type;
      if ($search !== '') $paginationBase['search'] = $search;
      if ($date !== '') $paginationBase['date'] = $date;
      $pageUrl = static function (int $targetPage) use ($paginationBase, $listBasePage): string {
          $values = $paginationBase;
          if ($targetPage > 1) {
              $values['page'] = $targetPage;
          }
          $query = http_build_query($values);
          return $listBasePage . ($query !== '' ? '?' . $query : '');
      };

      // Keep the pager compact on large datasets: first/last pages plus a
      // small window around the current page, with ellipses between gaps.
      $visiblePages = [1, $totalPages];
      for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++) {
          $visiblePages[] = $p;
      }
      $visiblePages = array_values(array_unique($visiblePages));
      sort($visiblePages);
    ?>
      <div class="reservation-pagination-bar">
        <div class="reservation-pagination-summary">
          Showing <strong><?= number_format($pageStart) ?>–<?= number_format($pageEnd) ?></strong>
          of <strong><?= number_format($totalItems) ?></strong> <?= $cancelledReservationsOnly ? 'cancelled reservations' : 'reservations' ?>
          <span>· <?= $perPage ?> per page</span>
        </div>

        <?php if ($totalPages > 1): ?>
          <nav class="reservation-pagination" aria-label="Reservation list pages">
            <?php if ($page > 1): ?>
              <a class="reservation-page-link reservation-page-step" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">Previous</a>
            <?php else: ?>
              <span class="reservation-page-link reservation-page-step is-disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <?php $lastRenderedPage = 0; foreach ($visiblePages as $visiblePage): ?>
              <?php if ($lastRenderedPage > 0 && $visiblePage > $lastRenderedPage + 1): ?>
                <span class="reservation-page-ellipsis" aria-hidden="true">…</span>
              <?php endif; ?>
              <?php if ($visiblePage === $page): ?>
                <span class="reservation-page-link is-current" aria-current="page"><?= $visiblePage ?></span>
              <?php else: ?>
                <a class="reservation-page-link" href="<?= e($pageUrl($visiblePage)) ?>"><?= $visiblePage ?></a>
              <?php endif; ?>
              <?php $lastRenderedPage = $visiblePage; ?>
            <?php endforeach; ?>

            <?php if ($page < $totalPages): ?>
              <a class="reservation-page-link reservation-page-step" href="<?= e($pageUrl($page + 1)) ?>" rel="next">Next</a>
            <?php else: ?>
              <span class="reservation-page-link reservation-page-step is-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($view === 'all'): ?>
<div class="reservation-modal" id="paymentUpdateModal" hidden>
  <div class="reservation-modal-backdrop" data-payment-modal-close></div>
  <section class="reservation-modal-dialog payment-update-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="paymentUpdateModalTitle" tabindex="-1">
    <header class="reservation-modal-header">
      <div>
        <span class="small muted">Reservation list</span>
        <h2 id="paymentUpdateModalTitle">Update Payment</h2>
        <p id="paymentUpdateModalClient" class="reservation-modal-client"></p>
      </div>
      <button type="button" class="reservation-modal-close" data-payment-modal-close aria-label="Close payment form">&times;</button>
    </header>

    <form method="post" action="<?= e($listUrl) ?>" id="paymentUpdateForm">
      <div class="reservation-modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="reservation_id" id="paymentReservationId">

        <div class="payment-update-balance">
          <span>Remaining Balance</span>
          <strong id="paymentUpdateBalance">₱0.00</strong>
        </div>

        <div class="form-grid payment-update-form-grid">
          <div class="form-group"><label for="paymentUpdateAmount">Payment Amount</label><input id="paymentUpdateAmount" type="number" min="0.01" step="0.01" name="amount" required></div>
          <div class="form-group"><label for="paymentUpdateMethod">Payment Method</label><select id="paymentUpdateMethod" name="payment_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label for="paymentUpdateReference">Reference / OR Number</label><input id="paymentUpdateReference" name="payment_reference" maxlength="120" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional: enter the OR / official receipt number for cash payments.</span></div>
          <div class="form-group"><label for="paymentUpdatePaidAt">Paid At</label><input id="paymentUpdatePaidAt" type="datetime-local" name="paid_at" required></div>
          <div class="form-group form-group-wide"><label for="paymentUpdateNotes">Notes</label><textarea id="paymentUpdateNotes" name="payment_notes" placeholder="Optional payment note"></textarea></div>
        </div>
      </div>

      <footer class="reservation-modal-footer">
        <button type="button" class="btn btn-outline" data-payment-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Payment</button>
      </footer>
    </form>
  </section>
</div>

<script>
(function () {
  const modal = document.getElementById('paymentUpdateModal');
  if (!modal) return;

  const dialog = modal.querySelector('.reservation-modal-dialog');
  const form = document.getElementById('paymentUpdateForm');
  const idInput = document.getElementById('paymentReservationId');
  const amountInput = document.getElementById('paymentUpdateAmount');
  const methodSelect = document.getElementById('paymentUpdateMethod');
  const referenceInput = document.getElementById('paymentUpdateReference');
  const paidAtInput = document.getElementById('paymentUpdatePaidAt');
  const notesInput = document.getElementById('paymentUpdateNotes');
  const title = document.getElementById('paymentUpdateModalTitle');
  const client = document.getElementById('paymentUpdateModalClient');
  const balance = document.getElementById('paymentUpdateBalance');
  const triggers = document.querySelectorAll('[data-payment-modal-trigger]');
  const closeControls = modal.querySelectorAll('[data-payment-modal-close]');
  let lastTrigger = null;

  const localDateTimeValue = () => {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const local = new Date(now.getTime() - offset * 60000);
    return local.toISOString().slice(0, 16);
  };

  const openModal = (trigger) => {
    const data = trigger.dataset;
    lastTrigger = trigger;
    form.reset();
    idInput.value = data.reservationId || '';
    title.textContent = (data.paymentTitle || 'Update Payment') + ' · ' + (data.reservationReference || 'Reservation');
    client.textContent = data.reservationClient || '';
    balance.textContent = data.reservationBalanceLabel || '₱0.00';
    amountInput.max = data.reservationBalance || '';
    amountInput.value = data.reservationBalance || '';
    methodSelect.value = data.reservationMethod || 'Cash';
    referenceInput.value = '';
    notesInput.value = '';
    paidAtInput.value = localDateTimeValue();
    methodSelect.dispatchEvent(new Event('change'));

    modal.hidden = false;
    document.body.classList.add('reservation-modal-open');
    window.requestAnimationFrame(() => modal.classList.add('is-open'));
    dialog.focus();
    window.setTimeout(() => amountInput.select(), 80);
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    document.body.classList.remove('reservation-modal-open');
    modal.hidden = true;
    if (lastTrigger) lastTrigger.focus();
  };

  triggers.forEach((trigger) => trigger.addEventListener('click', () => openModal(trigger)));
  closeControls.forEach((control) => control.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => {
    if (!modal.hidden && event.key === 'Escape') closeModal();
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
