<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$calendarReadOnly = is_calendar_viewer();
if (!$calendarReadOnly && !is_admin()) {
    flash('danger', 'Administrator access is required.');
    redirect('index.php');
}

$adminPageTitle = 'Rental Calendar';
$month = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthStart = $month . '-01';
$startObj = new DateTimeImmutable($monthStart);
$monthEnd = $startObj->modify('last day of this month')->format('Y-m-d');
$prev = $startObj->modify('-1 month')->format('Y-m');
$next = $startObj->modify('+1 month')->format('Y-m');

$rentableId = max(0, (int)($_GET['rentable_id'] ?? 0));
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$status = trim((string)($_GET['status'] ?? ''));

$where = [
    "r.status<>'cancelled'",
    "((r.schedule_type<>'flexible' AND r.start_date<=? AND r.end_date>=?) OR (r.schedule_type='flexible' AND EXISTS(SELECT 1 FROM rental_dates rd WHERE rd.rental_id=r.id AND rd.status='scheduled' AND rd.rental_date BETWEEN ? AND ?)))",
];
$params = [$monthEnd, $monthStart, $monthStart, $monthEnd];

if (in_array($status, ['active', 'completed'], true)) {
    $where[] = 'r.status=?';
    $params[] = $status;
}
if ($rentableId > 0) {
    $where[] = 'EXISTS(SELECT 1 FROM rental_items ri WHERE ri.rental_id=r.id AND ri.rentable_id=?)';
    $params[] = $rentableId;
}
if ($categoryId > 0) {
    $where[] = 'EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=r.id AND x.category_id=?)';
    $params[] = $categoryId;
}

$stmt = db()->prepare("SELECT r.* FROM rentals r WHERE " . implode(' AND ', $where) . " ORDER BY r.start_date,r.id");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$events = [];
foreach ($rows as $r) {
    $dates = $r['schedule_type'] === 'flexible'
        ? array_map(static fn($d) => (string)$d['rental_date'], rental_dates_for((int)$r['id'], false))
        : rental_schedule_dates('continuous', max($r['start_date'], $monthStart), min($r['end_date'], $monthEnd), []);

    foreach ($dates as $d) {
        if ($d < $monthStart || $d > $monthEnd) {
            continue;
        }
        $events[$d][] = [
            'id' => (int)$r['id'],
            'ref' => $r['reference_no'],
            'client' => $r['client_name'],
            'items' => rental_item_summary((int)$r['id'], 2),
            'billing' => $r['billing_mode'],
            'status' => $r['status'],
        ];
    }
}
ksort($events);

$rentables = db()->query('SELECT id,code,name FROM rentables ORDER BY code')->fetchAll();
$categories = db()->query('SELECT id,name FROM rental_categories ORDER BY sort_order,name')->fetchAll();

$gridStart = $startObj->modify('monday this week');
if ((int)$startObj->format('N') === 1) {
    $gridStart = $startObj;
}
$gridEnd = (new DateTimeImmutable($monthEnd))->modify('sunday this week');

function rental_calendar_query(string $monthValue, int $categoryId, int $rentableId, string $status): string
{
    return http_build_query([
        'month' => $monthValue,
        'category_id' => $categoryId,
        'rentable_id' => $rentableId,
        'status' => $status,
    ]);
}

$viewerDetailQuery = http_build_query([
    'month' => $month,
    'category_id' => $categoryId,
    'rentable_id' => $rentableId,
    'status' => $status,
]);

$activeFilterCount = ($categoryId > 0 ? 1 : 0) + ($rentableId > 0 ? 1 : 0) + ($status !== '' ? 1 : 0);

include __DIR__ . '/_header.php';
?>
<section class="panel rental-calendar-panel">
  <div class="panel-head rental-calendar-panel-head">
    <div>
      <h2>Rental Calendar</h2>
      <p class="muted">Shows occupied dates for all configured rentables. Flexible rentals appear only on selected dates.</p>
      <?php if ($calendarReadOnly): ?><span class="calendar-viewer-readonly-badge">Read only</span><?php endif; ?>
    </div>
    <?php if ($calendarReadOnly): ?>
      <div class="admin-actions"><a class="btn btn-outline btn-sm" href="booking-calendar.php?month=<?= e($month) ?>">Booking Calendar</a></div>
    <?php else: ?>
      <div class="admin-actions"><a class="btn btn-outline btn-sm" href="rentals.php">Rental Records</a><a class="btn btn-primary btn-sm" href="rental-create.php">+ New Rental</a></div>
    <?php endif; ?>
  </div>

  <div class="rental-calendar-mobile-nav" aria-label="Rental calendar month navigation">
    <a class="calendar-nav-btn" href="?<?= e(rental_calendar_query($prev, $categoryId, $rentableId, $status)) ?>" aria-label="Previous month">‹</a>
    <div class="calendar-only-title">
      <h2><?= e($startObj->format('F Y')) ?></h2>
      <a href="?<?= e(rental_calendar_query(date('Y-m'), $categoryId, $rentableId, $status)) ?>">Today</a>
      <?php if ($calendarReadOnly): ?><a href="booking-calendar.php?month=<?= e($month) ?>">Booking Calendar</a><?php endif; ?>
    </div>
    <a class="calendar-nav-btn" href="?<?= e(rental_calendar_query($next, $categoryId, $rentableId, $status)) ?>" aria-label="Next month">›</a>
  </div>

  <form method="get" class="rental-filter-bar rental-calendar-desktop-filters">
    <input type="month" name="month" value="<?= e($month) ?>">
    <select name="category_id"><option value="0">All categories</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $categoryId === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="rentable_id"><option value="0">All rentables</option><?php foreach ($rentables as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $rentableId === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['code'] . ' · ' . $x['name']) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">All active/completed</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Completed</option></select>
    <button class="btn btn-dark btn-sm">Apply</button>
    <a class="btn btn-outline btn-sm" href="rental-calendar.php?month=<?= e(date('Y-m')) ?>">Today</a>
  </form>

  <details class="rental-calendar-mobile-filters"<?= $activeFilterCount > 0 ? ' open' : '' ?>>
    <summary>Filters<?= $activeFilterCount > 0 ? ' · ' . $activeFilterCount . ' active' : '' ?></summary>
    <form method="get" class="rental-mobile-filter-form">
      <input type="hidden" name="month" value="<?= e($month) ?>">
      <label><span>Category</span><select name="category_id"><option value="0">All categories</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $categoryId === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label><span>Rentable</span><select name="rentable_id"><option value="0">All rentables</option><?php foreach ($rentables as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $rentableId === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['code'] . ' · ' . $x['name']) ?></option><?php endforeach; ?></select></label>
      <label><span>Status</span><select name="status"><option value="">All active/completed</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Completed</option></select></label>
      <div class="rental-mobile-filter-actions"><button class="btn btn-dark btn-sm">Apply Filters</button><a class="btn btn-outline btn-sm" href="rental-calendar.php?month=<?= e($month) ?>">Reset</a></div>
    </form>
  </details>

  <div class="rental-calendar-nav rental-calendar-desktop-nav">
    <a class="btn btn-outline btn-sm" href="?<?= e(rental_calendar_query($prev, $categoryId, $rentableId, $status)) ?>">← Previous</a>
    <h3><?= e($startObj->format('F Y')) ?></h3>
    <a class="btn btn-outline btn-sm" href="?<?= e(rental_calendar_query($next, $categoryId, $rentableId, $status)) ?>">Next →</a>
  </div>

  <div class="rental-calendar-desktop-grid">
    <div class="rental-calendar-grid rental-calendar-weekdays"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><div><?= e($day) ?></div><?php endforeach; ?></div>
    <div class="rental-calendar-grid">
      <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
          $key = $d->format('Y-m-d');
          $outside = $d->format('Y-m') !== $month;
          $today = $key === date('Y-m-d');
      ?>
        <div class="rental-calendar-day<?= $outside ? ' is-outside' : '' ?><?= $today ? ' is-today' : '' ?>">
          <div class="rental-calendar-date"><?= (int)$d->format('j') ?></div>
          <div class="rental-calendar-events">
            <?php foreach ($events[$key] ?? [] as $ev): ?>
              <?php if ($calendarReadOnly): ?>
                <a class="rental-calendar-event is-readonly" href="rental-view-readonly.php?id=<?= $ev['id'] ?>&<?= e($viewerDetailQuery) ?>" title="View read-only rental details for <?= e($ev['client']) ?>"><strong><?= e($ev['items']) ?></strong><span><?= e($ev['client']) ?></span><small><?= $ev['billing'] === 'monthly' ? 'Monthly' : 'One-time' ?> · <?= e(ucfirst($ev['status'])) ?></small></a>
              <?php else: ?>
                <a class="rental-calendar-event" href="rental-view.php?id=<?= $ev['id'] ?>"><strong><?= e($ev['items']) ?></strong><span><?= e($ev['client']) ?></span><small><?= $ev['billing'] === 'monthly' ? 'Monthly' : 'One-time' ?> · <?= e(ucfirst($ev['status'])) ?></small></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <div class="calendar-mobile-list rental-calendar-mobile-list">
    <?php if (!$events): ?>
      <div class="empty-state">No rentals found for this month.</div>
    <?php else: ?>
      <?php foreach ($events as $dateKey => $items): ?>
        <div class="calendar-mobile-day<?= $dateKey === date('Y-m-d') ? ' is-today' : '' ?>">
          <div class="calendar-mobile-date"><strong><?= e(date('D', strtotime($dateKey))) ?></strong><span><?= e(date('M j', strtotime($dateKey))) ?></span></div>
          <div class="calendar-mobile-bookings">
            <?php foreach ($items as $ev): ?>
              <?php $detailUrl = $calendarReadOnly ? 'rental-view-readonly.php?id=' . (int)$ev['id'] . '&' . $viewerDetailQuery : 'rental-view.php?id=' . (int)$ev['id']; ?>
              <a class="calendar-booking rental-calendar-booking<?= $ev['status'] === 'completed' ? ' is-completed' : '' ?>" href="<?= e($detailUrl) ?>">
                <span class="rental-calendar-stall"><?= e($ev['items']) ?></span>
                <strong><?= e($ev['client']) ?></strong>
                <span class="calendar-booking-time"><?= $ev['billing'] === 'monthly' ? 'Monthly rental' : 'One-time rental' ?> · <?= e(ucfirst($ev['status'])) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($calendarReadOnly): ?><div class="calendar-viewer-readonly-note"><strong>Read-only rental calendar access</strong><span>You can review rental occupancy and open read-only rental details. Editing, payments, and other rental actions remain disabled.</span></div><?php endif; ?>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
