<?php
/**
 * FILE PURPOSE: Queue of reservations whose event time has passed while the
 * status is still Pending / For Review. Admin must explicitly resolve outcome.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Needs Resolution';

$search = trim((string)($_GET['search'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$allowedTypes = ['basketball', 'volleyball', 'event'];
if (!in_array($type, $allowedTypes, true)) {
    $type = '';
}

$sql = "SELECT r.*, b.batch_reference,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
    FROM reservations r
    LEFT JOIN reservation_batches b ON b.id=r.batch_id
    WHERE r.archived_at IS NULL
      AND r.status IN ('pending','for_review')
      AND r.event_end <= NOW()";
$params = [];
if ($type !== '') {
    $sql .= ' AND r.reservation_type=?';
    $params[] = $type;
}
if ($search !== '') {
    $sql .= ' AND (r.reference_no LIKE ? OR b.batch_reference LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
$sql .= ' ORDER BY r.event_end DESC, r.id DESC LIMIT 300';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    $items = [];
}

include __DIR__ . '/_header.php';
?>
<section class="panel reservation-hub-panel">
  <div class="panel-head reservation-hub-head">
    <div>
      <span class="dashboard-section-kicker">Needs Attention</span>
      <h2>Needs Resolution</h2>
      <span class="small muted">These event times have already passed, but the reservation is still Pending / For Review. Nothing here is completed automatically.</span>
    </div>
    <a class="btn btn-outline btn-sm" href="reservations.php">All Reservations</a>
  </div>

  <div class="alert alert-warning reservation-resolution-guidance">
    <strong>Choose the real event outcome.</strong> Confirm <em>Event Occurred</em> only when the booking actually took place. Use <em>No Show</em> when the client did not attend, or <em>Did Not Proceed</em> when the pending request was never accepted/fulfilled. Payment remains a separate financial record.
  </div>

  <form method="get" class="toolbar">
    <div class="form-group"><label>Search</label><input name="search" value="<?= e($search) ?>" placeholder="Booking, batch, client, phone, or email"></div>
    <div class="form-group"><label>Type</label><select name="type"><option value="">All types</option><option value="basketball" <?= $type === 'basketball' ? 'selected' : '' ?>>Basketball</option><option value="volleyball" <?= $type === 'volleyball' ? 'selected' : '' ?>>Volleyball</option><option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Events</option></select></div>
    <button class="btn btn-dark btn-sm" type="submit">Filter</button>
    <a class="btn btn-outline btn-sm" href="needs-resolution.php">Reset</a>
  </form>

  <div class="table-wrap reservation-list-table-wrap">
    <table class="admin-table reservation-list-table">
      <thead><tr><th>Reference</th><th>Client</th><th>Reservation</th><th>Event Schedule</th><th>Payment</th><th>Current Status</th><th>Resolve</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item):
          $paid = round((float)($item['ledger_amount_paid'] ?? 0), 2);
          $paymentView = $item;
          $paymentView['amount_paid'] = $paid;
          $target = reservation_payment_target($paymentView);
          $balance = reservation_remaining_balance($paymentView);
          $paymentStatus = payment_status_for_amount($paid, $target);
      ?>
        <tr>
          <td data-label="Reference" data-priority="primary"><strong><?= e($item['reference_no']) ?></strong><?php if (!empty($item['batch_reference'])): ?><br><span class="small muted"><?= e($item['batch_reference']) ?> · #<?= (int)$item['batch_occurrence'] ?></span><?php endif; ?></td>
          <td data-label="Client"><?= e($item['client_name']) ?><br><span class="small muted"><?= e($item['phone']) ?></span></td>
          <td data-label="Reservation"><?= e(reservation_type_label((string)$item['reservation_type'])) ?><br><span class="small muted"><?= e((string)$item['purpose']) ?></span></td>
          <td data-label="Event Schedule"><strong><?= date('M j, Y', strtotime((string)$item['event_start'])) ?></strong><br><span class="small muted"><?= date('g:i A', strtotime((string)$item['event_start'])) ?> – <?= date('g:i A', strtotime((string)$item['event_end'])) ?></span></td>
          <td data-label="Payment"><span class="status-pill status-<?= badge_class($paymentStatus) ?>"><?= e($paymentStatus) ?></span><br><span class="small muted"><?= money($paid) ?> paid · <?= money($balance) ?> balance</span></td>
          <td data-label="Current Status"><span class="status-pill status-warning">Needs Resolution</span><br><span class="small muted"><?= e(ucwords(str_replace('_', ' ', (string)$item['status']))) ?> · event time lapsed</span></td>
          <td data-label="Resolve">
            <div class="resolution-row-actions">
              <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a>
              <form method="post" action="reservation-resolve.php" class="inline-action-form" onsubmit="return confirm('Confirm that this event actually occurred? The reservation will become Completed.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>">
                <input type="hidden" name="outcome" value="occurred">
                <input type="hidden" name="return_to" value="<?= e('needs-resolution.php' . ($search !== '' || $type !== '' ? '?' . http_build_query(array_filter(['search' => $search, 'type' => $type], fn($v) => $v !== '')) : '')) ?>">
                <button class="btn btn-primary btn-sm" type="submit">Event Occurred</button>
              </form>
              <details class="reservation-row-more">
                <summary class="btn btn-outline btn-sm reservation-row-more-trigger">Other Outcome <span aria-hidden="true">•••</span></summary>
                <div class="reservation-row-more-menu">
                  <form method="post" action="reservation-resolve.php" class="inline-action-form" onsubmit="return confirm('Mark this reservation as No Show?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="outcome" value="no_show"><input type="hidden" name="return_to" value="needs-resolution.php"><button type="submit">Mark No Show</button>
                  </form>
                  <form method="post" action="reservation-resolve.php" class="inline-action-form" onsubmit="return confirm('Mark this pending request as Did Not Proceed?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="outcome" value="did_not_proceed"><input type="hidden" name="return_to" value="needs-resolution.php"><button type="submit">Did Not Proceed</button>
                  </form>
                </div>
              </details>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="7" class="empty-state">No lapsed Pending / For Review reservations need resolution.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
