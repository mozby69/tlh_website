<?php
/**
 * FILE PURPOSE: Lists archived reservations with search/filter tools and links to archived records.
 * DEBUGGING: Archive eligibility is controlled by reservation_can_archive() and reservation archive helpers in includes/functions.php.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Reservation Archives';

$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');
$date = $_GET['date'] ?? '';
$allowedStatuses = ['pending', 'for_review', 'approved', 'rejected', 'completed', 'no_show'];
$allowedTypes = ['basketball', 'volleyball', 'event'];
if (!in_array($status, $allowedStatuses, true)) $status = '';
if (!in_array($type, $allowedTypes, true)) $type = '';
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

$queryValues = [];
if ($status !== '') $queryValues['status'] = $status;
if ($type !== '') $queryValues['type'] = $type;
if ($search !== '') $queryValues['search'] = $search;
if ($date !== '') $queryValues['date'] = $date;
$queryString = http_build_query($queryValues);
$pageUrl = 'archives.php' . ($queryString !== '' ? '?' . $queryString : '');

$sql = "SELECT r.*, a.full_name archived_by_name,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservations r
        LEFT JOIN admins a ON a.id=r.archived_by
        WHERE r.archived_at IS NOT NULL
          AND r.status<>'cancelled'";
$params = [];
if ($status !== '') { $sql .= ' AND r.status=?'; $params[] = $status; }
if ($type !== '') { $sql .= ' AND r.reservation_type=?'; $params[] = $type; }
if ($date !== '') { $sql .= ' AND DATE(r.event_start)=?'; $params[] = $date; }
if ($search !== '') {
    $sql .= ' AND (r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR r.booking_payment_reference LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
$sql .= ' ORDER BY r.archived_at DESC LIMIT 300';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    $items = [];
}

include __DIR__ . '/_header.php';
?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Reservation Archives</h2>
      <span class="small muted">Completed and closed reservations removed from the active working list. Archived records can still accept legitimate late payments when a balance remains.</span>
    </div>
    <a class="btn btn-outline btn-sm" href="reservations.php">Active Reservations</a>
  </div>

  <form method="get" class="toolbar">
    <div class="form-group"><label>Search</label><input name="search" value="<?= e($search) ?>" placeholder="Booking, client, or payment ref"></div>
    <div class="form-group"><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $value))) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Type</label><select name="type"><option value="">All types</option><option value="basketball" <?= $type === 'basketball' ? 'selected' : '' ?>>Basketball</option><option value="volleyball" <?= $type === 'volleyball' ? 'selected' : '' ?>>Volleyball</option><option value="event" <?= $type === 'event' ? 'selected' : '' ?>>Events</option></select></div>
    <div class="form-group"><label>Event Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
    <button class="btn btn-dark btn-sm" type="submit">Filter</button>
    <a class="btn btn-outline btn-sm" href="archives.php">Reset</a>
  </form>

  <div class="table-wrap">
    <table class="admin-table mobile-card-table archive-mobile-table">
      <thead><tr><th>Reference</th><th>Client</th><th>Reservation</th><th>Schedule</th><th>Payment</th><th>Status</th><th>Archived</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item):
          $actualPaid = round((float)($item['ledger_amount_paid'] ?? $item['amount_paid'] ?? 0), 2);
          $paymentView = $item;
          $paymentView['amount_paid'] = $actualPaid;
          $paymentStatus = payment_status_for_amount($actualPaid, reservation_payment_target($paymentView));
          $balance = reservation_remaining_balance($paymentView);
          $credit = reservation_excess_credit($paymentView);
      ?>
        <tr>
          <td data-label="Reference" data-priority="primary"><strong><?= e($item['reference_no']) ?></strong><br><span class="small muted"><?= e(ucfirst($item['source'])) ?></span></td>
          <td data-label="Client"><?= e($item['client_name']) ?><br><span class="small muted"><?= e($item['phone']) ?></span></td>
          <td data-label="Reservation"><?= e(reservation_type_label($item['reservation_type'])) ?><br><span class="small muted"><?= e($item['purpose']) ?></span></td>
          <td data-label="Schedule"><?= date('M j, Y', strtotime($item['event_start'])) ?><br><span class="small muted"><?= date('g:i A', strtotime($item['event_start'])) ?> – <?= date('g:i A', strtotime($item['event_end'])) ?></span></td>
          <td data-label="Payment"><span class="status-pill status-<?= badge_class($paymentStatus) ?>"><?= e($paymentStatus) ?></span><br><span class="small muted"><?= money($actualPaid) ?> paid · <?= money($credit > 0 ? $credit : $balance) ?> <?= $credit > 0 ? 'credit' : 'balance' ?></span></td>
          <td data-label="Status"><span class="status-pill status-<?= badge_class($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span></td>
          <td data-label="Archived"><?= date('M j, Y g:i A', strtotime($item['archived_at'])) ?><br><span class="small muted">by <?= e($item['archived_by_name'] ?: 'Unknown administrator') ?></span></td>
          <td data-label="Actions"><div class="reservation-list-actions archive-list-actions">
            <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Open</a>
            <?php if ($balance > 0.009): ?><a class="btn btn-primary btn-sm" href="reservation-view.php?id=<?= (int)$item['id'] ?>">Record Payment</a><?php endif; ?>
            <a class="btn btn-outline btn-sm" href="reservation-print.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">Print</a>
            <form method="post" action="reservation-archive.php" class="inline-action-form" onsubmit="return confirm('Restore this reservation to the active reservation list?');">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="reservation_id" value="<?= (int)$item['id'] ?>">
              <input type="hidden" name="return_to" value="<?= e($pageUrl) ?>">
              <button class="btn btn-primary btn-sm" type="submit">Restore</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="8" class="empty-state">No archived reservations match the selected filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
