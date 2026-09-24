<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();

// Administrators already have the full rental record. Keep this route dedicated
// to the Calendar Viewer so read-only access never shares action handlers with
// the editable rental page.
if (!is_calendar_viewer()) {
    $id = max(0, (int)($_GET['id'] ?? 0));
    redirect('rental-view.php?id=' . $id);
}

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
    flash('danger', 'Rental not found.');
    redirect('rental-calendar.php');
}

$stmt = db()->prepare("SELECT
        r.id, r.reference_no, r.client_name, r.contact_number, r.organization,
        r.schedule_type, r.billing_mode, r.start_date, r.end_date, r.status,
        r.original_end_date, r.early_end_reason, r.early_ended_at,
        r.notes, r.created_at, a.full_name AS created_by_name
    FROM rentals r
    LEFT JOIN admins a ON a.id = r.created_by
    WHERE r.id = ?
    LIMIT 1");
$stmt->execute([$id]);
$rental = $stmt->fetch();
if (!$rental) {
    flash('danger', 'Rental not found.');
    redirect('rental-calendar.php');
}

$itemStmt = db()->prepare("SELECT
        ri.quantity,
        x.code, x.name, x.location, x.availability_mode,
        c.name AS category_name
    FROM rental_items ri
    JOIN rentables x ON x.id = ri.rentable_id
    JOIN rental_categories c ON c.id = x.category_id
    WHERE ri.rental_id = ?
    ORDER BY ri.id");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$dateStmt = db()->prepare("SELECT rental_date, status
    FROM rental_dates
    WHERE rental_id = ?
    ORDER BY rental_date, id");
$dateStmt->execute([$id]);
$dates = $dateStmt->fetchAll();

$life = rental_lifecycle($rental);
$month = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$rentableId = max(0, (int)($_GET['rentable_id'] ?? 0));
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, ['', 'active', 'completed'], true)) {
    $status = '';
}
$returnUrl = 'rental-calendar.php?' . http_build_query([
    'month' => $month,
    'category_id' => $categoryId,
    'rentable_id' => $rentableId,
    'status' => $status,
]);

$adminPageTitle = 'Rental ' . $rental['reference_no'];
$adminCalendarOnly = true;
$hideAdminTopbar = true;
$hideAdminFlashes = true;
include __DIR__ . '/_header.php';
?>
<section class="panel rental-viewer-detail-hero">
  <div class="rental-viewer-detail-title">
    <div>
      <span class="eyebrow">Read-only Rental Details</span>
      <h2><?= e($rental['client_name']) ?></h2>
      <p class="muted"><?= e($rental['reference_no']) ?> · <?= $rental['billing_mode'] === 'monthly' ? 'Monthly Lease' : 'One-time Rental' ?></p>
    </div>
    <div class="rental-viewer-detail-badges">
      <span class="calendar-viewer-readonly-badge">Read only</span>
      <span class="status-pill status-<?= $rental['status'] === 'cancelled' ? 'danger' : ($rental['status'] === 'completed' ? 'success' : 'info') ?>"><?= e($life) ?></span>
    </div>
  </div>
  <div class="admin-actions"><a class="btn btn-outline btn-sm" href="<?= e($returnUrl) ?>">← Back to Rental Calendar</a></div>
</section>

<div class="rental-viewer-detail-grid">
  <section class="panel">
    <div class="panel-head"><div><h2>Rental Information</h2><p class="muted">Operational details only. Financial information is hidden for Calendar Viewer accounts.</p></div></div>
    <div class="rental-viewer-info-grid">
      <div><span>Client / Tenant</span><strong><?= e($rental['client_name']) ?></strong></div>
      <div><span>Contact Number</span><strong><?= e($rental['contact_number'] ?: '—') ?></strong></div>
      <div><span>Organization / Company</span><strong><?= e($rental['organization'] ?: '—') ?></strong></div>
      <div><span>Schedule Type</span><strong><?= $rental['schedule_type'] === 'flexible' ? 'Flexible Dates' : 'Continuous Dates' ?></strong></div>
      <div><span>Rental Period</span><strong><?= date('M j, Y', strtotime($rental['start_date'])) ?> – <?= date('M j, Y', strtotime($rental['end_date'])) ?></strong></div>
      <div><span>Status</span><strong><?= e($life) ?></strong></div>
      <div><span>Created By</span><strong><?= e($rental['created_by_name'] ?: 'Administrator') ?></strong></div>
      <div><span>Created</span><strong><?= date('M j, Y g:i A', strtotime($rental['created_at'])) ?></strong></div>
    </div>
    <?php if (!empty($rental['early_ended_at'])): ?>
      <div class="alert alert-warning rental-inline-alert"><strong>Ended Early</strong><br>Original end: <?= e($rental['original_end_date'] ? date('M j, Y', strtotime($rental['original_end_date'])) : '—') ?> · Actual end: <?= date('M j, Y', strtotime($rental['end_date'])) ?><?php if (!empty($rental['early_end_reason'])): ?><br><?= e($rental['early_end_reason']) ?><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><div><h2>Rentables</h2><p class="muted">Items or spaces assigned to this rental.</p></div></div>
    <div class="rental-viewer-item-list">
      <?php foreach ($items as $item): ?>
        <article>
          <div><strong><?= e($item['code']) ?> · <?= e($item['name']) ?></strong><span><?= e($item['category_name']) ?></span></div>
          <div><span>Location</span><strong><?= e($item['location'] ?: '—') ?></strong></div>
          <?php if ($item['availability_mode'] === 'quantity' || (int)$item['quantity'] > 1): ?><div><span>Quantity</span><strong><?= number_format((int)$item['quantity']) ?></strong></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$items): ?><p class="muted">No rentable items are attached to this rental.</p><?php endif; ?>
    </div>
  </section>
</div>

<?php if ($rental['schedule_type'] === 'flexible'): ?>
<section class="panel">
  <div class="panel-head"><div><h2>Flexible Rental Dates</h2><p class="muted">Only scheduled dates occupy the rentable. Released dates remain visible for reference.</p></div></div>
  <div class="rental-viewer-date-chips">
    <?php foreach ($dates as $date): ?><span class="rental-viewer-date-chip<?= $date['status'] !== 'scheduled' ? ' is-released' : '' ?>"><?= date('M j, Y', strtotime($date['rental_date'])) ?><?= $date['status'] !== 'scheduled' ? ' · Released' : '' ?></span><?php endforeach; ?>
    <?php if (!$dates): ?><span class="muted">No flexible dates recorded.</span><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (trim((string)$rental['notes']) !== ''): ?>
<section class="panel">
  <div class="panel-head"><div><h2>Notes</h2></div></div>
  <div class="rental-viewer-notes"><?= nl2br(e($rental['notes'])) ?></div>
</section>
<?php endif; ?>

<div class="calendar-viewer-readonly-note"><strong>Read-only rental access</strong><span>Financial information and all rental actions are hidden for this account.</span></div>
<?php include __DIR__ . '/_footer.php'; ?>
