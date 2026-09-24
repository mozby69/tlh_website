<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) { flash('danger','Administrator access is required.'); redirect('index.php'); }
$adminPageTitle='Rentals';
$q=trim((string)($_GET['q']??''));
$status=trim((string)($_GET['status']??''));
$categoryId=max(0,(int)($_GET['category_id']??0));
$billing=trim((string)($_GET['billing_mode']??''));
$where=['1=1']; $params=[];
if($q!==''){ $where[]="(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.organization LIKE ? OR EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=r.id AND (x.code LIKE ? OR x.name LIKE ?)))"; $like='%'.$q.'%'; array_push($params,$like,$like,$like,$like,$like); }
if(in_array($status,['active','completed','cancelled'],true)){ $where[]='r.status=?'; $params[]=$status; }
if(in_array($billing,['one_time','monthly'],true)){ $where[]='r.billing_mode=?'; $params[]=$billing; }
if($categoryId>0){ $where[]='EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=r.id AND x.category_id=?)'; $params[]=$categoryId; }
$sql="SELECT r.*,
      COALESCE((SELECT SUM(p.amount) FROM rental_payments p WHERE p.rental_id=r.id),0) AS ledger_paid,
      COALESCE((SELECT SUM(c.amount) FROM rental_charges c WHERE c.rental_id=r.id),0) AS charge_total,
      (SELECT rc.original_total FROM rental_cancellations rc WHERE rc.rental_id=r.id LIMIT 1) AS cancellation_original_total,
      (SELECT rc.refund_amount FROM rental_cancellations rc WHERE rc.rental_id=r.id LIMIT 1) AS cancellation_refund_amount,
      (SELECT rc.retained_amount FROM rental_cancellations rc WHERE rc.rental_id=r.id LIMIT 1) AS cancellation_retained_amount,
      (SELECT GROUP_CONCAT(CONCAT(x.code,CASE WHEN ri.quantity>1 THEN CONCAT(' x',ri.quantity) ELSE '' END) ORDER BY ri.id SEPARATOR ', ') FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=r.id) AS item_summary
      FROM rentals r WHERE ".implode(' AND ',$where)." ORDER BY CASE WHEN r.status='active' AND r.end_date>=CURDATE() THEN 0 WHEN r.status='active' THEN 1 WHEN r.status='completed' THEN 2 ELSE 3 END,r.start_date ASC,r.id DESC";
$stmt=db()->prepare($sql); $stmt->execute($params); $rentals=$stmt->fetchAll();
$categories=db()->query('SELECT id,name FROM rental_categories ORDER BY sort_order,name')->fetchAll();
$totals=db()->query("SELECT COUNT(*) AS rental_count,
  COALESCE(SUM(CASE WHEN status<>'cancelled' THEN base_amount ELSE 0 END),0) AS base_value,
  COALESCE(SUM(CASE WHEN status<>'cancelled' THEN COALESCE((SELECT SUM(c.amount) FROM rental_charges c WHERE c.rental_id=rentals.id),0) ELSE 0 END),0) AS charge_value,
  COALESCE(SUM(CASE WHEN status<>'cancelled' THEN GREATEST(base_amount+COALESCE((SELECT SUM(c2.amount) FROM rental_charges c2 WHERE c2.rental_id=rentals.id),0)-COALESCE((SELECT SUM(p.amount) FROM rental_payments p WHERE p.rental_id=rentals.id),0),0) ELSE 0 END),0) AS outstanding
  FROM rentals")->fetch()?:[];
$collected=(float)db()->query('SELECT COALESCE(SUM(amount),0) FROM rental_payments')->fetchColumn();
include __DIR__ . '/_header.php';
?>
<section class="panel rental-list-panel">
  <div class="panel-head rental-page-head"><div><h2>Rentals</h2><p class="muted">One rental engine for Food Stalls, commercial spaces, equipment, and future rentable categories.</p></div><div class="admin-actions"><a class="btn btn-outline btn-sm" href="rental-calendar.php">Rental Calendar</a><a class="btn btn-outline btn-sm" href="rentables.php">Manage Rentables</a><a class="btn btn-primary btn-sm" href="rental-create.php">+ New Rental</a></div></div>
  <div class="metric-grid rental-metric-grid">
    <div class="metric-card"><span>Rental Records</span><strong><?= number_format((int)($totals['rental_count']??0)) ?></strong></div>
    <div class="metric-card"><span>Base Rental Value</span><strong><?= money((float)($totals['base_value']??0)) ?></strong></div>
    <div class="metric-card"><span>Additional Charges</span><strong><?= money((float)($totals['charge_value']??0)) ?></strong></div>
    <div class="metric-card"><span>Collected</span><strong><?= money($collected) ?></strong></div>
    <div class="metric-card"><span>Outstanding</span><strong><?= money((float)($totals['outstanding']??0)) ?></strong></div>
  </div>
</section>
<section class="panel rental-records-panel">
  <div class="panel-head"><div><h2>Rental Records</h2><p class="muted"><?= count($rentals) ?> record<?= count($rentals)===1?'':'s' ?> shown</p></div></div>
  <form method="get" class="rental-filter-bar">
    <input name="q" value="<?= e($q) ?>" placeholder="Search client, reference, rentable..." aria-label="Search rentals">
    <select name="status"><option value="">All statuses</option><?php foreach(['active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled'] as $v=>$label): ?><option value="<?= $v ?>"<?= $status===$v?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
    <select name="category_id"><option value="0">All categories</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $categoryId===(int)$c['id']?' selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="billing_mode"><option value="">All billing</option><option value="one_time"<?= $billing==='one_time'?' selected':'' ?>>One-time</option><option value="monthly"<?= $billing==='monthly'?' selected':'' ?>>Monthly</option></select>
    <button class="btn btn-dark btn-sm">Apply</button><a class="btn btn-outline btn-sm" href="rentals.php">Reset</a>
  </form>
  <div class="table-wrap"><table class="admin-table mobile-card-table"><thead><tr><th>Rental</th><th>Client</th><th>Rentables</th><th>Schedule</th><th>Billing</th><th>Status</th><th>Financial</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($rentals as $r): $isCancelled=(string)$r['status']==='cancelled'; $total=$isCancelled&&$r['cancellation_original_total']!==null?round((float)$r['cancellation_original_total'],2):round((float)$r['base_amount']+(float)$r['charge_total'],2); $paid=$isCancelled&&$r['cancellation_retained_amount']!==null?round((float)$r['cancellation_retained_amount'],2):round((float)$r['ledger_paid'],2); $balance=$isCancelled?0:max(0,$total-$paid); $life=rental_lifecycle($r); ?>
    <tr>
      <td data-label="Rental" data-priority="primary"><strong><?= e($r['reference_no']) ?></strong><br><span class="small muted"><?= date('M j, Y',strtotime($r['created_at'])) ?></span></td>
      <td data-label="Client"><strong><?= e($r['client_name']) ?></strong><?php if(!empty($r['organization'])): ?><br><span class="small muted"><?= e($r['organization']) ?></span><?php endif; ?></td>
      <td data-label="Rentables"><?= e($r['item_summary']?:'—') ?></td>
      <td data-label="Schedule"><?php if($r['schedule_type']==='flexible'): ?><span class="status-pill status-info">Flexible</span><br><?php endif; ?><span class="small"><?= date('M j, Y',strtotime($r['start_date'])) ?> – <?= date('M j, Y',strtotime($r['end_date'])) ?></span></td>
      <td data-label="Billing"><span class="status-pill status-<?= $r['billing_mode']==='monthly'?'warning':'secondary' ?>"><?= $r['billing_mode']==='monthly'?'Monthly':'One-time' ?></span><?php if(!empty($r['is_complimentary'])): ?><br><span class="status-pill status-success">Complimentary</span><?php endif; ?></td>
      <td data-label="Status"><span class="status-pill status-<?= $r['status']==='cancelled'?'danger':($r['status']==='completed'?'success':'info') ?>"><?= e($life) ?></span></td>
      <td data-label="Financial"><?php if($isCancelled&&$r['cancellation_retained_amount']!==null): ?><strong>Retained <?= money((float)$r['cancellation_retained_amount']) ?></strong><br><span class="small muted">Refunded <?= money((float)$r['cancellation_refund_amount']) ?> · Original <?= money($total) ?></span><?php else: ?><strong><?= money($total) ?></strong><br><span class="small muted">Paid <?= money($paid) ?> · Bal <?= money($balance) ?></span><?php endif; ?></td>
      <td data-label="Actions"><div class="admin-actions"><a class="btn btn-outline btn-sm" href="rental-view.php?id=<?= (int)$r['id'] ?>">Open</a><?php if($r['status']!=='cancelled'&&$balance>0.009): ?><a class="btn btn-primary btn-sm" href="rental-view.php?id=<?= (int)$r['id'] ?>#rental-payment"><?= $paid>0.009?'Update Payment':'Record Payment' ?></a><?php endif; ?><?php if($r['status']==='active'&&$r['start_date']>=date('Y-m-d')): ?><a class="btn btn-outline btn-sm" href="rental-cancel.php?id=<?= (int)$r['id'] ?>">Cancel</a><?php endif; ?><a class="btn btn-outline btn-sm" href="rental-print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a></div></td>
    </tr>
  <?php endforeach; ?><?php if(!$rentals): ?><tr><td colspan="8" class="empty-state">No rental records match the current filters.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
