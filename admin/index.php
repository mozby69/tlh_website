<?php
/**
 * FILE PURPOSE: Operational admin dashboard showing today, upcoming bookings, requests, payments, and cancellation/refund attention.
 * DEBUGGING: Dashboard money values should come from the payment ledger rather than stale payment_status fields.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Dashboard';

$metrics = [
    'today' => 0,
    'website_requests' => 0,
    'needs_resolution' => 0,
    'next_7_days' => 0,
    'outstanding_count' => 0,
    'outstanding_balance' => 0.0,
    'month_collected' => 0.0,
    'unread_alerts' => 0,
    'cancellation_attention' => 0,
];
$todayBookings = [];
$nextBookings = [];
$paymentAttention = [];
$cancellationAttention = [];
$websiteRequests = [];
$needsResolution = [];

try {
    $pdo = db();
    $calendarBlockCondition = reservation_calendar_block_condition('r');

    // Public requests that are still waiting for admin review. These do not hold the calendar.
    $websiteRequestStmt = $pdo->query("SELECT r.*
        FROM reservations r
        WHERE r.archived_at IS NULL
          AND r.source='website'
          AND r.status IN ('pending','for_review')
          AND r.event_end > NOW()
        ORDER BY r.created_at ASC
        LIMIT 6");
    $websiteRequests = $websiteRequestStmt->fetchAll();
    $metrics['website_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE archived_at IS NULL AND source='website' AND status IN ('pending','for_review') AND event_end > NOW()")->fetchColumn();

    // A lapsed Pending / For Review request is not Completed. It remains in an
    // explicit resolution queue until an administrator records the real outcome.
    $resolutionStmt = $pdo->query("SELECT r.*,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservations r
        WHERE r.archived_at IS NULL
          AND r.status IN ('pending','for_review')
          AND r.event_end <= NOW()
        ORDER BY r.event_end DESC
        LIMIT 6");
    $needsResolution = $resolutionStmt->fetchAll();
    $metrics['needs_resolution'] = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE archived_at IS NULL AND status IN ('pending','for_review') AND event_end <= NOW()")->fetchColumn();

    // Today's operational schedule: secured/held bookings plus already-completed/no-show records for today.
    $todayStmt = $pdo->query("SELECT r.*,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservations r
        WHERE r.archived_at IS NULL
          AND DATE(r.event_start)=CURDATE()
          AND (({$calendarBlockCondition}) OR r.status IN ('completed','no_show'))
          AND r.status NOT IN ('rejected','cancelled')
        ORDER BY r.event_start ASC");
    $todayBookings = $todayStmt->fetchAll();
    $metrics['today'] = count($todayBookings);

    // Secured / staff-held schedule for the next seven days after today.
    $nextStmt = $pdo->query("SELECT r.*
        FROM reservations r
        WHERE r.archived_at IS NULL
          AND ({$calendarBlockCondition})
          AND r.event_start >= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND r.event_start < DATE_ADD(CURDATE(), INTERVAL 8 DAY)
        ORDER BY r.event_start ASC");
    $nextBookings = $nextStmt->fetchAll();
    $metrics['next_7_days'] = count($nextBookings);

    // Outstanding payment queue. Use the ledger, not reservations.amount_paid.
    $paymentStmt = $pdo->query("SELECT r.*,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservations r
        WHERE (({$calendarBlockCondition}) OR r.status='completed')
          AND r.status NOT IN ('rejected','cancelled','no_show')
          AND NOT (r.status IN ('pending','for_review') AND r.event_end <= NOW())
        ORDER BY CASE WHEN r.event_end < NOW() THEN 0 ELSE 1 END, r.event_end ASC
        LIMIT 250");
    foreach ($paymentStmt->fetchAll() as $row) {
        $row['amount_paid'] = round((float)$row['ledger_amount_paid'], 2);
        $balance = reservation_remaining_balance($row);
        if ($balance <= 0.009) {
            continue;
        }
        $row['_remaining_balance'] = $balance;
        $paymentAttention[] = $row;
        $metrics['outstanding_count']++;
        $metrics['outstanding_balance'] += $balance;
    }
    $metrics['outstanding_balance'] = round($metrics['outstanding_balance'], 2);
    $paymentAttention = array_slice($paymentAttention, 0, 6);

    // Cancellation records requiring a refund or collection of the cancellation fee balance.
    $cancelStmt = $pdo->query("SELECT r.*, c.*,
            r.id AS reservation_id,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservation_cancellations c
        INNER JOIN reservations r ON r.id=c.reservation_id
        WHERE r.archived_at IS NULL
        ORDER BY c.cancelled_at DESC
        LIMIT 100");
    foreach ($cancelStmt->fetchAll() as $row) {
        $refundRemaining = (string)$row['refund_status'] === 'pending'
            ? max(0, round((float)$row['refund_due'] - (float)$row['refunded_amount'], 2))
            : 0.0;
        $feeBalance = max(0, round((float)$row['cancellation_fee'] - (float)$row['ledger_amount_paid'], 2));
        if ($refundRemaining <= 0.009 && $feeBalance <= 0.009) {
            continue;
        }
        $row['_refund_remaining'] = $refundRemaining;
        $row['_fee_balance'] = $feeBalance;
        $cancellationAttention[] = $row;
    }
    $metrics['cancellation_attention'] = count($cancellationAttention);
    $cancellationAttention = array_slice($cancellationAttention, 0, 5);

    $metrics['month_collected'] = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(paid_at)=YEAR(CURDATE()) AND MONTH(paid_at)=MONTH(CURDATE())")->fetchColumn();
    $metrics['unread_alerts'] = admin_notification_unread_count();

} catch (Throwable $e) {
    // Keep the dashboard usable even if the database is temporarily unavailable.
}

$nextByDay = [];
foreach ($nextBookings as $booking) {
    $dayKey = date('Y-m-d', strtotime((string)$booking['event_start']));
    $nextByDay[$dayKey][] = $booking;
}

include __DIR__ . '/_header.php';
?>

<section class="dashboard-welcome" aria-labelledby="dashboardWelcomeTitle">
  <div>
    <span class="dashboard-eyebrow">Daily Control Center</span>
    <h2 id="dashboardWelcomeTitle">Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e($adminName) ?>.</h2>
    <p><?= e(date('l, F j, Y')) ?> · Start with anything marked for attention, then review today’s venue schedule.</p>
  </div>
</section>

<section class="dashboard-section" aria-labelledby="actionCenterHeading">
  <div class="dashboard-section-head">
    <div><span class="dashboard-section-kicker">Needs Attention</span><h2 id="actionCenterHeading">Action Center</h2></div>
    <span class="small muted">Only items that may require an admin action are shown here.</span>
  </div>
  <div class="dashboard-action-grid">
    <a class="dashboard-action-card<?= $metrics['website_requests'] > 0 ? ' is-warning' : ' is-clear' ?>" href="<?= $metrics['website_requests'] > 0 ? '#dashboardWebsiteRequests' : 'notifications.php' ?>">
      <span class="dashboard-action-icon" aria-hidden="true">◎</span>
      <span class="dashboard-action-copy"><small>Website Requests</small><strong><?= $metrics['website_requests'] ?></strong><span><?= $metrics['website_requests'] > 0 ? 'Waiting for review' : 'Nothing waiting' ?></span></span>
      <span class="dashboard-action-arrow" aria-hidden="true">→</span>
    </a>
    <a class="dashboard-action-card<?= $metrics['needs_resolution'] > 0 ? ' is-danger' : ' is-clear' ?>" href="needs-resolution.php">
      <span class="dashboard-action-icon" aria-hidden="true">!</span>
      <span class="dashboard-action-copy"><small>Needs Resolution</small><strong><?= $metrics['needs_resolution'] ?></strong><span><?= $metrics['needs_resolution'] > 0 ? 'Past event still pending' : 'Nothing unresolved' ?></span></span>
      <span class="dashboard-action-arrow" aria-hidden="true">→</span>
    </a>
    <a class="dashboard-action-card<?= $metrics['outstanding_count'] > 0 ? ' is-danger' : ' is-clear' ?>" href="payments.php">
      <span class="dashboard-action-icon" aria-hidden="true">₱</span>
      <span class="dashboard-action-copy"><small>Payment Attention</small><strong><?= $metrics['outstanding_count'] ?></strong><span><?= $metrics['outstanding_count'] > 0 ? money($metrics['outstanding_balance']) . ' outstanding' : 'No balance due' ?></span></span>
      <span class="dashboard-action-arrow" aria-hidden="true">→</span>
    </a>
    <a class="dashboard-action-card<?= $metrics['cancellation_attention'] > 0 ? ' is-danger' : ' is-clear' ?>" href="#dashboardCancellationAttention">
      <span class="dashboard-action-icon" aria-hidden="true">↺</span>
      <span class="dashboard-action-copy"><small>Cancellation / Refund</small><strong><?= $metrics['cancellation_attention'] ?></strong><span><?= $metrics['cancellation_attention'] > 0 ? 'Settlement action needed' : 'Nothing pending' ?></span></span>
      <span class="dashboard-action-arrow" aria-hidden="true">→</span>
    </a>
    <a class="dashboard-action-card<?= $metrics['unread_alerts'] > 0 ? ' is-warning' : ' is-clear' ?>" href="notifications.php">
      <span class="dashboard-action-icon" aria-hidden="true">◇</span>
      <span class="dashboard-action-copy"><small>Unread Alerts</small><strong><?= $metrics['unread_alerts'] ?></strong><span><?= $metrics['unread_alerts'] > 0 ? 'Open notifications' : 'You are caught up' ?></span></span>
      <span class="dashboard-action-arrow" aria-hidden="true">→</span>
    </a>
  </div>
</section>

<div class="dashboard-primary-grid">
  <section class="panel dashboard-today-panel" aria-labelledby="todayScheduleHeading">
    <div class="panel-head dashboard-panel-head">
      <div><span class="dashboard-section-kicker">Today</span><h2 id="todayScheduleHeading">Today at The Leisure Hub</h2><p><?= $metrics['today'] ?> secured / operational reservation<?= $metrics['today'] === 1 ? '' : 's' ?> today</p></div>
      <a class="btn btn-outline btn-sm" href="booking-calendar.php?month=<?= e(date('Y-m')) ?>">Open Calendar</a>
    </div>
    <?php if ($todayBookings): ?>
      <div class="dashboard-schedule-list">
        <?php foreach ($todayBookings as $item):
            $actualPaid = round((float)($item['ledger_amount_paid'] ?? 0), 2);
            $paymentView = $item;
            $paymentView['amount_paid'] = $actualPaid;
            $balance = reservation_remaining_balance($paymentView);
            $paymentStatus = payment_status_for_amount($actualPaid, reservation_payment_target($paymentView));
        ?>
          <article class="dashboard-schedule-item">
            <div class="dashboard-schedule-time"><strong><?= date('g:i', strtotime((string)$item['event_start'])) ?></strong><span><?= date('A', strtotime((string)$item['event_start'])) ?></span><small>to <?= date('g:i A', strtotime((string)$item['event_end'])) ?></small></div>
            <div class="dashboard-schedule-main">
              <div class="dashboard-schedule-title"><strong><?= e($item['client_name']) ?></strong><span class="status-pill status-<?= badge_class((string)$item['status']) ?>"><?= e(ucwords(str_replace('_', ' ', (string)$item['status']))) ?></span></div>
              <span><?= e((string)$item['reference_no']) ?> · <?= e(reservation_type_label((string)$item['reservation_type'])) ?><?= !empty($item['purpose']) ? ' · ' . e((string)$item['purpose']) : '' ?></span>
              <small><?= e($item['organization'] ?: $item['phone']) ?></small>
            </div>
            <div class="dashboard-schedule-payment">
              <span class="status-pill status-<?= badge_class($paymentStatus) ?>"><?= e(ucfirst($paymentStatus)) ?></span>
              <small><?= $balance > 0.009 ? money($balance) . ' balance' : 'Settled' ?></small>
            </div>
            <div class="dashboard-row-actions">
              <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a>
              <?php if ($balance > 0.009): ?><a class="btn btn-dark btn-sm" href="reservations.php?search=<?= urlencode((string)$item['reference_no']) ?>">Payment</a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="dashboard-empty-state"><strong>No secured reservations today.</strong><span>The venue schedule is clear for today.</span><a href="booking-calendar.php?month=<?= e(date('Y-m')) ?>">View Booking Calendar</a></div>
    <?php endif; ?>
  </section>

  <aside class="panel dashboard-next-panel" aria-labelledby="nextSevenHeading">
    <div class="panel-head dashboard-panel-head">
      <div><span class="dashboard-section-kicker">Coming Up</span><h2 id="nextSevenHeading">Next 7 Days</h2><p><?= $metrics['next_7_days'] ?> secured booking<?= $metrics['next_7_days'] === 1 ? '' : 's' ?></p></div>
      <a class="btn btn-outline btn-sm" href="booking-calendar.php?month=<?= e(date('Y-m', strtotime('+1 day'))) ?>">Open Calendar</a>
    </div>
    <div class="dashboard-next-days">
      <?php foreach ($nextByDay as $day => $bookings):
          $previewLimit = 3;
          $previewBookings = array_slice($bookings, 0, $previewLimit);
          $hiddenBookings = max(0, count($bookings) - count($previewBookings));
      ?>
        <div class="dashboard-next-day">
          <div class="dashboard-next-date"><strong><?= date('D', strtotime($day)) ?></strong><span><?= date('M j', strtotime($day)) ?></span><small><?= count($bookings) ?> booking<?= count($bookings) === 1 ? '' : 's' ?></small></div>
          <div class="dashboard-next-bookings">
            <?php foreach ($previewBookings as $item): ?>
              <a href="reservation-view.php?id=<?= (int)$item['id'] ?>"><strong><?= date('g:i A', strtotime((string)$item['event_start'])) ?></strong><span><?= e($item['client_name']) ?></span><small><?= e(reservation_type_label((string)$item['reservation_type'])) ?> · <?= e((string)$item['reference_no']) ?></small></a>
            <?php endforeach; ?>
            <?php if ($hiddenBookings > 0): ?>
              <a class="dashboard-next-more" href="reservations.php?date=<?= e($day) ?>">+ <?= $hiddenBookings ?> more booking<?= $hiddenBookings === 1 ? '' : 's' ?> <span aria-hidden="true">→</span></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$nextByDay): ?><div class="dashboard-empty-state compact"><strong>No secured bookings in the next 7 days.</strong><span>Future approved reservations will appear here.</span></div><?php endif; ?>
    </div>
  </aside>
</div>

<section class="dashboard-section" aria-labelledby="businessSnapshotHeading">
  <div class="dashboard-section-head"><div><span class="dashboard-section-kicker">Overview</span><h2 id="businessSnapshotHeading">Business Snapshot</h2></div></div>
  <div class="dashboard-snapshot-grid">
    <div class="dashboard-snapshot-card"><span>Today’s Schedule</span><strong><?= $metrics['today'] ?></strong><small>operational reservations</small></div>
    <div class="dashboard-snapshot-card"><span>Upcoming 7 Days</span><strong><?= $metrics['next_7_days'] ?></strong><small>secured reservations</small></div>
    <div class="dashboard-snapshot-card"><span>Outstanding Balance</span><strong><?= money($metrics['outstanding_balance']) ?></strong><small>across <?= $metrics['outstanding_count'] ?> reservation<?= $metrics['outstanding_count'] === 1 ? '' : 's' ?></small></div>
    <div class="dashboard-snapshot-card"><span>Payments This Month</span><strong><?= money($metrics['month_collected']) ?></strong><small>net payment ledger total</small></div>
  </div>
</section>

<section class="panel dashboard-resolution-panel" id="dashboardResolutionAttention" aria-labelledby="resolutionAttentionHeading">
  <div class="panel-head dashboard-panel-head">
    <div><span class="dashboard-section-kicker">Event Outcome</span><h2 id="resolutionAttentionHeading">Needs Resolution</h2><p>Past event times that are still Pending / For Review stay unresolved until Admin confirms what actually happened.</p></div>
    <a class="btn btn-outline btn-sm" href="needs-resolution.php">Open Resolution Queue</a>
  </div>
  <?php if ($needsResolution): ?>
    <div class="dashboard-attention-list">
      <?php foreach ($needsResolution as $item): ?>
        <a class="dashboard-attention-row" href="reservation-view.php?id=<?= (int)$item['id'] ?>">
          <span class="dashboard-attention-date"><strong><?= date('M j', strtotime((string)$item['event_start'])) ?></strong><small><?= date('g:i A', strtotime((string)$item['event_start'])) ?></small></span>
          <span class="dashboard-attention-main"><strong><?= e($item['client_name']) ?></strong><span><?= e((string)$item['reference_no']) ?> · <?= e(reservation_type_label((string)$item['reservation_type'])) ?></span></span>
          <span class="status-pill status-warning">Needs Resolution</span>
          <span class="dashboard-attention-arrow" aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="dashboard-empty-state compact"><strong>No lapsed reservations waiting.</strong><span>Pending events will never be completed automatically.</span></div>
  <?php endif; ?>
</section>

<div class="dashboard-attention-grid">
  <section class="panel" id="dashboardWebsiteRequests" aria-labelledby="websiteRequestsHeading">
    <div class="panel-head dashboard-panel-head">
      <div><span class="dashboard-section-kicker">Review Queue</span><h2 id="websiteRequestsHeading">Website Requests</h2><p>Pending online requests do not hold the calendar until approved.</p></div>
      <a class="btn btn-outline btn-sm" href="notifications.php">Notifications</a>
    </div>
    <div class="dashboard-attention-list">
      <?php foreach ($websiteRequests as $item): ?>
        <a class="dashboard-attention-row" href="reservation-view.php?id=<?= (int)$item['id'] ?>">
          <span class="dashboard-attention-date"><strong><?= date('M j', strtotime((string)$item['event_start'])) ?></strong><small><?= date('g:i A', strtotime((string)$item['event_start'])) ?></small></span>
          <span class="dashboard-attention-main"><strong><?= e($item['client_name']) ?></strong><span><?= e((string)$item['reference_no']) ?> · <?= e(reservation_type_label((string)$item['reservation_type'])) ?></span></span>
          <span class="status-pill status-warning"><?= e(ucwords(str_replace('_', ' ', (string)$item['status']))) ?></span>
          <span class="dashboard-attention-arrow" aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
      <?php if (!$websiteRequests): ?><div class="dashboard-empty-state compact"><strong>No website requests waiting.</strong><span>New online requests will appear here for review.</span></div><?php endif; ?>
    </div>
  </section>

  <section class="panel" id="dashboardPaymentAttention" aria-labelledby="paymentAttentionHeading">
    <div class="panel-head dashboard-panel-head">
      <div><span class="dashboard-section-kicker">Collections</span><h2 id="paymentAttentionHeading">Outstanding Payments</h2><p>Past-event balances are prioritized. Payments can be recorded after the event using the actual payment date.</p></div>
      <a class="btn btn-outline btn-sm" href="payments.php">Open Collections Dashboard</a>
    </div>
    <div class="dashboard-attention-list">
      <?php foreach ($paymentAttention as $item): ?>
        <div class="dashboard-attention-row is-payment">
          <span class="dashboard-attention-date"><strong><?= date('M j', strtotime((string)$item['event_start'])) ?></strong><small><?= date('g:i A', strtotime((string)$item['event_start'])) ?></small></span>
          <span class="dashboard-attention-main"><strong><?= e($item['client_name']) ?></strong><span><?= e((string)$item['reference_no']) ?> · <?= e(reservation_type_label((string)$item['reservation_type'])) ?></span></span>
          <span class="dashboard-attention-amount"><small>Balance</small><strong><?= money((float)$item['_remaining_balance']) ?></strong></span>
          <a class="btn btn-dark btn-sm" href="payments.php?search=<?= urlencode((string)$item['reference_no']) ?>">Collect</a>
        </div>
      <?php endforeach; ?>
      <?php if (!$paymentAttention): ?><div class="dashboard-empty-state compact"><strong>No outstanding balances.</strong><span>Payment attention is clear.</span></div><?php endif; ?>
    </div>
  </section>
</div>

<section class="panel dashboard-cancellation-panel" id="dashboardCancellationAttention" aria-labelledby="cancellationAttentionHeading">
  <div class="panel-head dashboard-panel-head">
    <div><span class="dashboard-section-kicker">Settlement</span><h2 id="cancellationAttentionHeading">Cancellation & Refund Attention</h2><p>Only cancellations with a refund still due or a cancellation-fee balance are listed.</p></div>
    <a class="btn btn-outline btn-sm" href="cancelled.php">View All Cancelled</a>
  </div>
  <?php if ($cancellationAttention): ?>
    <div class="dashboard-cancellation-grid">
      <?php foreach ($cancellationAttention as $item): ?>
        <article class="dashboard-cancellation-card">
          <div><span class="small muted"><?= e((string)$item['reference_no']) ?> · <?= date('M j, Y', strtotime((string)$item['cancelled_at'])) ?></span><h3><?= e((string)$item['client_name']) ?></h3></div>
          <div class="dashboard-cancellation-values">
            <?php if ((float)$item['_refund_remaining'] > 0.009): ?><span><small>Refund Pending</small><strong><?= money((float)$item['_refund_remaining']) ?></strong></span><?php endif; ?>
            <?php if ((float)$item['_fee_balance'] > 0.009): ?><span><small>Cancellation Balance</small><strong><?= money((float)$item['_fee_balance']) ?></strong></span><?php endif; ?>
          </div>
          <a class="btn btn-outline btn-sm" href="reservation-cancel.php?id=<?= (int)$item['reservation_id'] ?>">Open Settlement</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="dashboard-empty-state compact"><strong>No cancellation settlements waiting.</strong><span>Refunds and cancellation balances are up to date.</span></div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
