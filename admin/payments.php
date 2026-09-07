<?php
/**
 * FILE PURPOSE: Payment & Collection Dashboard for outstanding reservation balances and recent receipts.
 * DEBUGGING: Financial totals must come from the payments ledger; client payment intent never counts as paid.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Payment & Collections';

$scope = trim((string)($_GET['scope'] ?? 'all'));
$paymentState = trim((string)($_GET['payment'] ?? 'all'));
$type = trim((string)($_GET['type'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

if (!in_array($scope, ['all', 'past', 'upcoming'], true)) {
    $scope = 'all';
}
if (!in_array($paymentState, ['all', 'unpaid', 'partial'], true)) {
    $paymentState = 'all';
}
if (!in_array($type, ['', 'basketball', 'volleyball', 'event'], true)) {
    $type = '';
}

$filterValues = [];
if ($scope !== 'all') $filterValues['scope'] = $scope;
if ($paymentState !== 'all') $filterValues['payment'] = $paymentState;
if ($type !== '') $filterValues['type'] = $type;
if ($search !== '') $filterValues['search'] = $search;
if ($page > 1) $filterValues['page'] = $page;
$filterQuery = http_build_query($filterValues);
$filterUrl = 'payments.php' . ($filterQuery !== '' ? '?' . $filterQuery : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action !== 'add_payment') {
            throw new RuntimeException('Invalid payment action.');
        }

        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $method = trim((string)($_POST['payment_method'] ?? ''));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $notes = trim((string)($_POST['payment_notes'] ?? ''));
        $paidAtRaw = trim((string)($_POST['paid_at'] ?? ''));
        $returnQuery = trim((string)($_POST['return_query'] ?? ''));

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
        $paymentEligibility = reservation_payment_eligibility($booking);
        if (!$paymentEligibility['allowed']) {
            throw new RuntimeException($paymentEligibility['message']);
        }

        $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
        $totalStmt->execute([$reservationId]);
        $currentTotal = round((float)$totalStmt->fetchColumn(), 2);
        $target = reservation_payment_target($booking);
        $remaining = max(0, round($target - $currentTotal, 2));
        if ($remaining <= 0.009) {
            throw new RuntimeException('This reservation has no outstanding balance.');
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
        $newPaymentStatus = payment_status_for_amount($newTotal, $target);
        $stmt = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');
        $stmt->execute([$newTotal, $newPaymentStatus, $reservationId]);
        $pdo->commit();

        flash('success', 'Payment recorded for reservation ' . $booking['reference_no'] . '.');
        $safeReturn = 'payments.php';
        if ($returnQuery !== '') {
            parse_str($returnQuery, $parsedReturn);
            if (is_array($parsedReturn)) {
                $allowedReturn = [];
                foreach (['scope', 'payment', 'type', 'search', 'page'] as $key) {
                    if (isset($parsedReturn[$key]) && is_scalar($parsedReturn[$key])) {
                        $allowedReturn[$key] = (string)$parsedReturn[$key];
                    }
                }
                $encodedReturn = http_build_query($allowedReturn);
                if ($encodedReturn !== '') {
                    $safeReturn .= '?' . $encodedReturn;
                }
            }
        }
        redirect($safeReturn);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The payment could not be recorded.');
        redirect($filterUrl);
    }
}

$metrics = [
    'outstanding_count' => 0,
    'outstanding_balance' => 0.0,
    'past_count' => 0,
    'past_balance' => 0.0,
    'upcoming_count' => 0,
    'upcoming_balance' => 0.0,
    'unpaid_count' => 0,
    'partial_count' => 0,
    'month_collected' => 0.0,
    'today_collected' => 0.0,
];
$items = [];
$recentPayments = [];
$totalItems = 0;
$totalPages = 1;
$pageStart = 0;
$pageEnd = 0;
$needsResolutionCount = 0;

try {
    $pdo = db();
    $calendarBlockCondition = reservation_calendar_block_condition('r');

    $candidateSql = "SELECT r.*, b.batch_reference,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid,
            (SELECT MAX(p2.paid_at) FROM payments p2 WHERE p2.reservation_id=r.id AND p2.amount>0) AS last_payment_at,
            COALESCE(r.final_amount,r.estimated_amount,0) AS payment_target
        FROM reservations r
        LEFT JOIN reservation_batches b ON b.id=r.batch_id
        WHERE (({$calendarBlockCondition}) OR r.status='completed')
          AND r.status NOT IN ('rejected','cancelled','no_show')
          AND NOT (r.status IN ('pending','for_review') AND r.event_end <= NOW())";

    $metricsSql = "SELECT
            COUNT(*) AS outstanding_count,
            COALESCE(SUM(balance),0) AS outstanding_balance,
            SUM(CASE WHEN event_end < NOW() THEN 1 ELSE 0 END) AS past_count,
            COALESCE(SUM(CASE WHEN event_end < NOW() THEN balance ELSE 0 END),0) AS past_balance,
            SUM(CASE WHEN event_end >= NOW() THEN 1 ELSE 0 END) AS upcoming_count,
            COALESCE(SUM(CASE WHEN event_end >= NOW() THEN balance ELSE 0 END),0) AS upcoming_balance,
            SUM(CASE WHEN ledger_amount_paid <= 0.009 THEN 1 ELSE 0 END) AS unpaid_count,
            SUM(CASE WHEN ledger_amount_paid > 0.009 THEN 1 ELSE 0 END) AS partial_count
        FROM (
            SELECT q.*, GREATEST(q.payment_target-q.ledger_amount_paid,0) AS balance
            FROM ({$candidateSql}) q
        ) balances
        WHERE balance > 0.009";
    $metricRow = $pdo->query($metricsSql)->fetch() ?: [];
    foreach (['outstanding_count', 'past_count', 'upcoming_count', 'unpaid_count', 'partial_count'] as $key) {
        $metrics[$key] = (int)($metricRow[$key] ?? 0);
    }
    foreach (['outstanding_balance', 'past_balance', 'upcoming_balance'] as $key) {
        $metrics[$key] = round((float)($metricRow[$key] ?? 0), 2);
    }
    $metrics['month_collected'] = round((float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) FROM payments WHERE YEAR(paid_at)=YEAR(CURDATE()) AND MONTH(paid_at)=MONTH(CURDATE())")->fetchColumn(), 2);
    $metrics['today_collected'] = round((float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) FROM payments WHERE DATE(paid_at)=CURDATE()")->fetchColumn(), 2);
    $needsResolutionCount = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE archived_at IS NULL AND status IN ('pending','for_review') AND event_end <= NOW()")->fetchColumn();

    $innerSql = $candidateSql;
    $params = [];
    if ($type !== '') {
        $innerSql .= ' AND r.reservation_type=?';
        $params[] = $type;
    }
    if ($search !== '') {
        $innerSql .= ' AND (r.reference_no LIKE ? OR b.batch_reference LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR EXISTS (SELECT 1 FROM payments ps WHERE ps.reservation_id=r.id AND ps.payment_reference LIKE ?))';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term, $term);
    }

    $outerWhere = ['balance > 0.009'];
    if ($scope === 'past') {
        $outerWhere[] = 'event_end < NOW()';
    } elseif ($scope === 'upcoming') {
        $outerWhere[] = 'event_end >= NOW()';
    }
    if ($paymentState === 'unpaid') {
        $outerWhere[] = 'ledger_amount_paid <= 0.009';
    } elseif ($paymentState === 'partial') {
        $outerWhere[] = 'ledger_amount_paid > 0.009';
    }
    $outerWhereSql = implode(' AND ', $outerWhere);
    $derivedSql = "SELECT q.*, GREATEST(q.payment_target-q.ledger_amount_paid,0) AS balance FROM ({$innerSql}) q";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$derivedSql}) collection_rows WHERE {$outerWhereSql}");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listSql = "SELECT * FROM ({$derivedSql}) collection_rows
        WHERE {$outerWhereSql}
        ORDER BY CASE WHEN event_end < NOW() THEN 0 ELSE 1 END,
                 CASE WHEN event_end < NOW() THEN event_end END ASC,
                 CASE WHEN event_end >= NOW() THEN event_start END ASC,
                 balance DESC
        LIMIT {$perPage} OFFSET {$offset}";
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $items = $listStmt->fetchAll();
    if ($totalItems > 0) {
        $pageStart = $offset + 1;
        $pageEnd = min($offset + count($items), $totalItems);
    }

    $recentPayments = $pdo->query("SELECT p.*, r.reference_no, r.client_name, r.event_start, a.full_name AS recorded_by_name
        FROM payments p
        INNER JOIN reservations r ON r.id=p.reservation_id
        LEFT JOIN admins a ON a.id=p.recorded_by
        WHERE p.amount > 0
        ORDER BY p.paid_at DESC, p.id DESC
        LIMIT 8")->fetchAll();
} catch (Throwable $e) {
    $items = [];
    $recentPayments = [];
}

$pageUrl = static function (int $targetPage) use ($scope, $paymentState, $type, $search): string {
    $values = [];
    if ($scope !== 'all') $values['scope'] = $scope;
    if ($paymentState !== 'all') $values['payment'] = $paymentState;
    if ($type !== '') $values['type'] = $type;
    if ($search !== '') $values['search'] = $search;
    if ($targetPage > 1) $values['page'] = $targetPage;
    $query = http_build_query($values);
    return 'payments.php' . ($query !== '' ? '?' . $query : '');
};

$daysFromEvent = static function (string $eventEnd): array {
    try {
        $end = new DateTimeImmutable($eventEnd);
        $now = new DateTimeImmutable();
    } catch (Throwable $e) {
        return ['past' => false, 'label' => 'Schedule unavailable'];
    }
    if ($end >= $now) {
        $days = (int)floor(($end->getTimestamp() - $now->getTimestamp()) / 86400);
        return ['past' => false, 'label' => $days <= 0 ? 'Event today' : ($days === 1 ? 'Event in 1 day' : 'Event in ' . $days . ' days')];
    }
    $days = (int)floor(($now->getTimestamp() - $end->getTimestamp()) / 86400);
    return ['past' => true, 'label' => $days <= 0 ? 'Event ended today' : ($days === 1 ? '1 day after event' : $days . ' days after event')];
};

include __DIR__ . '/_header.php';
?>

<section class="collections-hero" aria-labelledby="collectionsTitle">
  <div class="collections-hero-copy">
    <span class="dashboard-eyebrow">Finance Control Center</span>
    <h2 id="collectionsTitle">Payment &amp; Collections</h2>
    <p>Track every verified balance from the payment ledger, prioritize past-event collections, and record late payments using the actual date received.</p>
  </div>
  <div class="collections-hero-actions">
    <a class="btn btn-outline" href="reports.php">Reports</a>
    <a class="btn btn-outline" href="reservations.php">Reservations</a>
    <?php if ($needsResolutionCount > 0): ?><a class="btn btn-dark" href="needs-resolution.php">Resolve <?= $needsResolutionCount ?> Lapsed</a><?php endif; ?>
  </div>
</section>

<section class="collections-metrics" aria-label="Collection summary">
  <article class="collection-metric-card is-primary">
    <span>Outstanding Balance</span>
    <strong><?= money($metrics['outstanding_balance']) ?></strong>
    <small><?= $metrics['outstanding_count'] ?> reservation<?= $metrics['outstanding_count'] === 1 ? '' : 's' ?> to collect</small>
  </article>
  <article class="collection-metric-card is-past">
    <span>Past-Event Balance</span>
    <strong><?= money($metrics['past_balance']) ?></strong>
    <small><?= $metrics['past_count'] ?> past-event reservation<?= $metrics['past_count'] === 1 ? '' : 's' ?></small>
  </article>
  <article class="collection-metric-card">
    <span>Upcoming Balance</span>
    <strong><?= money($metrics['upcoming_balance']) ?></strong>
    <small><?= $metrics['upcoming_count'] ?> upcoming reservation<?= $metrics['upcoming_count'] === 1 ? '' : 's' ?></small>
  </article>
  <article class="collection-metric-card is-collected">
    <span>Collected This Month</span>
    <strong><?= money($metrics['month_collected']) ?></strong>
    <small><?= money($metrics['today_collected']) ?> received today</small>
  </article>
</section>

<?php if ($needsResolutionCount > 0): ?>
<section class="collections-resolution-note" aria-label="Needs resolution notice">
  <div><strong><?= $needsResolutionCount ?> lapsed reservation<?= $needsResolutionCount === 1 ? '' : 's' ?> need an event outcome first.</strong><span>Pending / For Review bookings are intentionally excluded from collections after their event time until Admin resolves what happened.</span></div>
  <a class="btn btn-outline btn-sm" href="needs-resolution.php">Open Needs Resolution</a>
</section>
<?php endif; ?>

<section class="panel collections-panel" aria-labelledby="collectionQueueTitle">
  <div class="panel-head collections-panel-head">
    <div>
      <span class="dashboard-section-kicker">Collection Queue</span>
      <h2 id="collectionQueueTitle">Balances to Collect</h2>
      <p>Past-event balances appear first. Client payment intent is shown only as context; only Admin-recorded ledger transactions count as paid.</p>
    </div>
    <div class="collections-state-chips" aria-label="Payment state summary">
      <span><strong><?= $metrics['unpaid_count'] ?></strong> Unpaid</span>
      <span><strong><?= $metrics['partial_count'] ?></strong> Partial</span>
    </div>
  </div>

  <form method="get" class="collections-filters" aria-label="Filter collections">
    <div class="collections-search-field"><label for="collectionsSearch">Search</label><input id="collectionsSearch" name="search" value="<?= e($search) ?>" placeholder="Reference, client, phone, email, payment ref / OR no."></div>
    <div><label for="collectionsScope">Timing</label><select id="collectionsScope" name="scope"><option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All balances</option><option value="past" <?= $scope === 'past' ? 'selected' : '' ?>>Past event</option><option value="upcoming" <?= $scope === 'upcoming' ? 'selected' : '' ?>>Upcoming</option></select></div>
    <div><label for="collectionsPayment">Payment State</label><select id="collectionsPayment" name="payment"><option value="all" <?= $paymentState === 'all' ? 'selected' : '' ?>>Unpaid + Partial</option><option value="unpaid" <?= $paymentState === 'unpaid' ? 'selected' : '' ?>>Unpaid only</option><option value="partial" <?= $paymentState === 'partial' ? 'selected' : '' ?>>Partial only</option></select></div>
    <div><label for="collectionsType">Reservation Type</label><select id="collectionsType" name="type"><option value="" <?= $type === '' ? 'selected' : '' ?>>All types</option><option value="basketball" <?= $type === 'basketball' ? 'selected' : '' ?>>Basketball Court</option><option value="volleyball" <?= $type === 'volleyball' ? 'selected' : '' ?>>Volleyball Court</option><option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Events Reservation</option></select></div>
    <div class="collections-filter-actions"><button class="btn btn-dark" type="submit">Apply</button><a class="btn btn-outline" href="payments.php">Clear</a></div>
  </form>

  <?php if ($items): ?>
    <div class="collections-list" role="list">
      <?php foreach ($items as $item):
        $paid = round((float)$item['ledger_amount_paid'], 2);
        $target = round((float)$item['payment_target'], 2);
        $balance = round((float)$item['balance'], 2);
        $state = $paid <= 0.009 ? 'unpaid' : 'partial';
        $timing = $daysFromEvent((string)$item['event_end']);
        $defaultMethod = in_array((string)($item['booking_payment_method'] ?? ''), booking_payment_methods(), true) ? (string)$item['booking_payment_method'] : 'Cash';
        $intentChoice = (string)($item['booking_payment_choice'] ?? 'none');
        $intentAmount = max(0, round((float)($item['booking_payment_intent_amount'] ?? 0), 2));
        $intentLabel = match ($intentChoice) { 'full' => 'Client intent: Full', 'partial' => 'Client intent: Partial', default => 'No client payment selected' };
      ?>
      <article class="collection-row<?= $timing['past'] ? ' is-past-event' : '' ?>" role="listitem">
        <div class="collection-row-status">
          <span class="badge <?= e(badge_class($state)) ?>"><?= e(ucfirst($state)) ?></span>
          <span class="collection-aging<?= $timing['past'] ? ' is-past' : '' ?>"><?= e($timing['label']) ?></span>
        </div>
        <div class="collection-row-main">
          <div class="collection-row-title">
            <strong><?= e((string)$item['client_name']) ?></strong>
            <a href="reservation-view.php?id=<?= (int)$item['id'] ?>"><?= e((string)$item['reference_no']) ?></a>
          </div>
          <span><?= e(reservation_type_label((string)$item['reservation_type'])) ?> · <?= date('M j, Y · g:i A', strtotime((string)$item['event_start'])) ?></span>
          <?php if (!empty($item['batch_reference'])): ?><span class="collection-batch-line">Batch <?= e((string)$item['batch_reference']) ?></span><?php endif; ?>
          <small><?= e($intentLabel) ?><?= $intentChoice !== 'none' && $intentAmount > 0 ? ' · ' . e(money($intentAmount)) : '' ?><?= $intentChoice !== 'none' && !empty($item['booking_payment_method']) ? ' · ' . e(booking_payment_method_label((string)$item['booking_payment_method'])) : '' ?></small>
        </div>
        <div class="collection-row-finance">
          <span><small>Total</small><strong><?= money($target) ?></strong></span>
          <span><small>Paid</small><strong><?= money($paid) ?></strong></span>
          <span class="is-balance"><small>Balance</small><strong><?= money($balance) ?></strong></span>
        </div>
        <div class="collection-row-last-payment">
          <small>Last Payment</small>
          <strong><?= !empty($item['last_payment_at']) ? date('M j, Y', strtotime((string)$item['last_payment_at'])) : 'None yet' ?></strong>
        </div>
        <div class="collection-row-actions">
          <button
            class="btn btn-primary btn-sm"
            type="button"
            data-collection-payment-trigger
            data-reservation-id="<?= (int)$item['id'] ?>"
            data-reservation-reference="<?= e((string)$item['reference_no']) ?>"
            data-reservation-client="<?= e((string)$item['client_name']) ?>"
            data-reservation-balance="<?= e(number_format($balance, 2, '.', '')) ?>"
            data-reservation-balance-label="<?= e(money($balance)) ?>"
            data-reservation-method="<?= e($defaultMethod) ?>"
            data-reservation-intent-amount="<?= e(number_format(min($balance, $intentAmount), 2, '.', '')) ?>"
          >Record Payment</button>
          <?php if (!empty($item['batch_id'])): ?><a class="btn btn-outline btn-sm" href="batch-reservation-view.php?id=<?= (int)$item['batch_id'] ?>">Open Group</a><?php else: ?><a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="collections-pagination-wrap">
      <span>Showing <?= $pageStart ?>–<?= $pageEnd ?> of <?= $totalItems ?></span>
      <?php if ($totalPages > 1): ?>
      <nav class="reservation-pagination" aria-label="Collections pages">
        <?php if ($page > 1): ?><a class="reservation-page-link reservation-page-step" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php else: ?><span class="reservation-page-link reservation-page-step is-disabled" aria-disabled="true">Previous</span><?php endif; ?>
        <span class="collections-page-count">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="reservation-page-link reservation-page-step" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php else: ?><span class="reservation-page-link reservation-page-step is-disabled" aria-disabled="true">Next</span><?php endif; ?>
      </nav>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="dashboard-empty-state collections-empty-state"><strong>No balances match these filters.</strong><span>Clear the filters or check back when a secured reservation has an unpaid balance.</span></div>
  <?php endif; ?>
</section>

<section class="panel collections-recent-panel" aria-labelledby="recentCollectionsTitle">
  <div class="panel-head collections-panel-head">
    <div><span class="dashboard-section-kicker">Ledger Activity</span><h2 id="recentCollectionsTitle">Recent Collections</h2><p>The latest verified positive payment transactions recorded by Admin.</p></div>
  </div>
  <?php if ($recentPayments): ?>
  <div class="collections-recent-list">
    <?php foreach ($recentPayments as $payment): ?>
      <a class="collection-recent-row" href="reservation-view.php?id=<?= (int)$payment['reservation_id'] ?>">
        <span><strong><?= e((string)$payment['client_name']) ?></strong><small><?= e((string)$payment['reference_no']) ?></small></span>
        <span><strong><?= e(booking_payment_method_label((string)$payment['payment_method'])) ?></strong><small><?= !empty($payment['payment_reference']) ? e((string)$payment['payment_reference']) : 'No reference' ?></small></span>
        <span><strong><?= date('M j, Y', strtotime((string)$payment['paid_at'])) ?></strong><small><?= date('g:i A', strtotime((string)$payment['paid_at'])) ?><?= !empty($payment['recorded_by_name']) ? ' · ' . e((string)$payment['recorded_by_name']) : '' ?></small></span>
        <span class="collection-recent-amount"><?= money((float)$payment['amount']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div class="dashboard-empty-state compact"><strong>No payment transactions yet.</strong><span>Recorded payments will appear here.</span></div><?php endif; ?>
</section>

<div class="reservation-modal" id="collectionPaymentModal" hidden>
  <div class="reservation-modal-backdrop" data-collection-payment-close></div>
  <section class="reservation-modal-dialog payment-update-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="collectionPaymentModalTitle" tabindex="-1">
    <header class="reservation-modal-header">
      <div><span class="small muted">Payment &amp; Collections</span><h2 id="collectionPaymentModalTitle">Record Payment</h2><p id="collectionPaymentModalClient" class="reservation-modal-client"></p></div>
      <button type="button" class="reservation-modal-close" data-collection-payment-close aria-label="Close payment form">&times;</button>
    </header>
    <form method="post" id="collectionPaymentForm">
      <div class="reservation-modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="reservation_id" id="collectionPaymentReservationId">
        <input type="hidden" name="return_query" value="<?= e($filterQuery) ?>">
        <div class="payment-update-balance"><span>Remaining Balance</span><strong id="collectionPaymentBalance">₱0.00</strong></div>
        <div class="form-grid payment-update-form-grid">
          <div class="form-group"><label for="collectionPaymentAmount">Payment Amount</label><input id="collectionPaymentAmount" type="number" min="0.01" step="0.01" name="amount" required></div>
          <div class="form-group"><label for="collectionPaymentMethod">Payment Method</label><select id="collectionPaymentMethod" name="payment_method" data-booking-payment-method required><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>"><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label for="collectionPaymentReference">Reference / OR Number</label><input id="collectionPaymentReference" name="payment_reference" maxlength="120" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional: enter the OR / official receipt number for cash payments.</span></div>
          <div class="form-group"><label for="collectionPaymentPaidAt">Paid At</label><input id="collectionPaymentPaidAt" type="datetime-local" name="paid_at" required><span class="field-help">Use the actual date and time the payment was received, even when it is after the event.</span></div>
          <div class="form-group form-group-wide"><label for="collectionPaymentNotes">Notes</label><textarea id="collectionPaymentNotes" name="payment_notes" placeholder="Optional collection note"></textarea></div>
        </div>
      </div>
      <footer class="reservation-modal-footer"><button type="button" class="btn btn-outline" data-collection-payment-close>Cancel</button><button type="submit" class="btn btn-primary">Record Payment</button></footer>
    </form>
  </section>
</div>

<script>
(function () {
  const modal = document.getElementById('collectionPaymentModal');
  if (!modal) return;
  const dialog = modal.querySelector('.reservation-modal-dialog');
  const form = document.getElementById('collectionPaymentForm');
  const idInput = document.getElementById('collectionPaymentReservationId');
  const amountInput = document.getElementById('collectionPaymentAmount');
  const methodSelect = document.getElementById('collectionPaymentMethod');
  const referenceInput = document.getElementById('collectionPaymentReference');
  const paidAtInput = document.getElementById('collectionPaymentPaidAt');
  const notesInput = document.getElementById('collectionPaymentNotes');
  const title = document.getElementById('collectionPaymentModalTitle');
  const client = document.getElementById('collectionPaymentModalClient');
  const balance = document.getElementById('collectionPaymentBalance');
  const triggers = document.querySelectorAll('[data-collection-payment-trigger]');
  const closers = modal.querySelectorAll('[data-collection-payment-close]');
  let lastTrigger = null;

  const localDateTimeValue = () => {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
  };

  const openModal = (trigger) => {
    const data = trigger.dataset;
    lastTrigger = trigger;
    form.reset();
    idInput.value = data.reservationId || '';
    title.textContent = 'Record Payment · ' + (data.reservationReference || 'Reservation');
    client.textContent = data.reservationClient || '';
    balance.textContent = data.reservationBalanceLabel || '₱0.00';
    amountInput.max = data.reservationBalance || '';
    const intentAmount = Number.parseFloat(data.reservationIntentAmount || '0');
    amountInput.value = intentAmount > 0 ? intentAmount.toFixed(2) : (data.reservationBalance || '');
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
  closers.forEach((control) => control.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => {
    if (!modal.hidden && event.key === 'Escape') closeModal();
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
