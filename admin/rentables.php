<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) { flash('danger','Administrator access is required.'); redirect('index.php'); }
$adminPageTitle='Manage Rentables';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'save');
        $id=(int)($_POST['id']??0);
        if ($action==='delete') {
            $count=db()->prepare('SELECT COUNT(*) FROM rental_items WHERE rentable_id=?'); $count->execute([$id]);
            if ((int)$count->fetchColumn()>0) throw new RuntimeException('This rentable already has rental history. Set it inactive instead of deleting it.');
            db()->prepare('DELETE FROM rentables WHERE id=?')->execute([$id]);
            flash('success','Rentable deleted.');
        } else {
            $categoryId=(int)($_POST['category_id']??0);
            $code=strtoupper(trim((string)($_POST['code']??'')));
            $name=trim((string)($_POST['name']??''));
            $location=trim((string)($_POST['location']??''));
            $availability=in_array($_POST['availability_mode']??'', ['exclusive','quantity'],true)?(string)$_POST['availability_mode']:'exclusive';
            $quantity=max(1,(int)($_POST['total_quantity']??1));
            $billing=in_array($_POST['billing_mode']??'', ['one_time','monthly'],true)?(string)$_POST['billing_mode']:'one_time';
            $allowFlexible=isset($_POST['allow_flexible_dates'])?1:0;
            $allowUtilities=isset($_POST['allow_utilities'])?1:0;
            $notes=trim((string)($_POST['notes']??''));
            $active=isset($_POST['is_active'])?1:0;
            if ($categoryId<=0) throw new RuntimeException('Choose a rental category.');
            if ($code==='') throw new RuntimeException('Enter a rentable code.');
            if ($name==='') throw new RuntimeException('Enter a rentable name.');
            if (strlen($code)>60||strlen($name)>160||strlen($location)>160) throw new RuntimeException('One of the rentable details is too long.');
            if ($availability==='exclusive') $quantity=1;
            if ($billing==='monthly') $allowFlexible=0;
            $dup=db()->prepare('SELECT COUNT(*) FROM rentables WHERE code=? AND id<>?'); $dup->execute([$code,$id]);
            if ((int)$dup->fetchColumn()>0) throw new RuntimeException('That rentable code already exists.');
            if ($id>0) {
                $currentStmt=db()->prepare('SELECT * FROM rentables WHERE id=?'); $currentStmt->execute([$id]); $current=$currentStmt->fetch();
                if(!$current) throw new RuntimeException('Rentable not found.');
                $historyStmt=db()->prepare('SELECT COUNT(*) FROM rental_items WHERE rentable_id=?'); $historyStmt->execute([$id]); $hasHistory=(int)$historyStmt->fetchColumn()>0;
                if($hasHistory){
                    if($availability!==(string)$current['availability_mode']) throw new RuntimeException('Availability mode cannot be changed after this rentable has rental history. Create a new rentable instead.');
                    if($billing!==(string)$current['billing_mode']) throw new RuntimeException('Billing mode cannot be changed after this rentable has rental history. Create a new rentable instead.');
                    if($quantity<(int)$current['total_quantity']) throw new RuntimeException('Total quantity cannot be reduced after this rentable has rental history. You may increase it or set the rentable inactive.');
                    if($allowUtilities!==(int)$current['allow_utilities']) throw new RuntimeException('Utility billing cannot be changed after this rentable has rental history. Create a new rentable instead.');
                }
                $stmt=db()->prepare('UPDATE rentables SET category_id=?,code=?,name=?,location=?,availability_mode=?,total_quantity=?,billing_mode=?,allow_flexible_dates=?,allow_utilities=?,notes=?,is_active=? WHERE id=?');
                $stmt->execute([$categoryId,$code,$name,$location?:null,$availability,$quantity,$billing,$allowFlexible,$allowUtilities,$notes?:null,$active,$id]);
                flash('success','Rentable updated.');
            } else {
                $stmt=db()->prepare('INSERT INTO rentables(category_id,code,name,location,availability_mode,total_quantity,billing_mode,allow_flexible_dates,allow_utilities,notes,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$categoryId,$code,$name,$location?:null,$availability,$quantity,$billing,$allowFlexible,$allowUtilities,$notes?:null,$active,current_admin()['id']]);
                flash('success','Rentable added.');
            }
        }
    } catch(Throwable $e) { flash('danger',$e instanceof RuntimeException?$e->getMessage():'Unable to update rentable.'); }
    redirect('rentables.php');
}

$edit=null;
if(isset($_GET['edit'])) { $stmt=db()->prepare('SELECT * FROM rentables WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit=$stmt->fetch()?:null; }
$categories=db()->query('SELECT * FROM rental_categories WHERE is_active=1 OR id='.(int)($edit['category_id']??0).' ORDER BY sort_order,name')->fetchAll();
$rows=db()->query("SELECT x.*,c.name AS category_name,(SELECT COUNT(*) FROM rental_items ri WHERE ri.rentable_id=x.id) AS rental_count FROM rentables x JOIN rental_categories c ON c.id=x.category_id ORDER BY x.is_active DESC,c.sort_order,c.name,x.code")->fetchAll();
include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
<section class="panel">
  <div class="panel-head"><div><h2>Rentables</h2><p class="muted">Add spaces or items here. Prices are never stored in the catalog; Admin enters the agreed amount on each rental.</p></div><div class="admin-actions"><a class="btn btn-outline btn-sm" href="rental-categories.php">Categories</a><a class="btn btn-outline btn-sm" href="rental-charge-types.php">Charge Types</a><a class="btn btn-primary btn-sm" href="rentals.php">Rental Records</a></div></div>
  <div class="table-wrap"><table class="admin-table mobile-card-table"><thead><tr><th>Rentable</th><th>Category</th><th>Availability</th><th>Billing</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($rows as $row): ?><tr>
    <td data-label="Rentable" data-priority="primary"><strong><?= e($row['code']) ?></strong><br><span class="small muted"><?= e($row['name']) ?></span></td>
    <td data-label="Category"><?= e($row['category_name']) ?></td>
    <td data-label="Availability"><?= $row['availability_mode']==='quantity' ? number_format((int)$row['total_quantity']).' units' : 'Exclusive' ?></td>
    <td data-label="Billing"><?= $row['billing_mode']==='monthly'?'Monthly':'One-time' ?></td>
    <td data-label="Location"><?= e($row['location']?:'—') ?></td>
    <td data-label="Status"><span class="status-pill status-<?= $row['is_active']?'success':'secondary' ?>"><?= $row['is_active']?'Active':'Inactive' ?></span></td>
    <td data-label="Actions"><div class="admin-actions"><a class="btn btn-outline btn-sm" href="?edit=<?= (int)$row['id'] ?>">Edit</a><?php if((int)$row['rental_count']===0): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this rentable?">Delete</button></form><?php endif; ?></div></td>
  </tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7" class="empty-state">No rentables yet.</td></tr><?php endif; ?></tbody></table></div>
</section>
<aside class="panel">
  <h2><?= $edit?'Edit Rentable':'Add Rentable' ?></h2>
  <form method="post" data-rentable-form><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
    <div class="form-group admin-form-gap"><label>Category *</label><select name="category_id" required data-rentable-category><option value="">Select category</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>" data-availability="<?= e($c['default_availability_mode']) ?>" data-billing="<?= e($c['default_billing_mode']) ?>" data-flexible="<?= (int)$c['allow_flexible_dates'] ?>" data-utilities="<?= (int)$c['allow_utilities'] ?>"<?= (int)($edit['category_id']??0)===(int)$c['id']?' selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-grid"><div class="form-group"><label>Code *</label><input name="code" maxlength="60" placeholder="FS-01 / REST-01 / CHAIR" value="<?= e($edit['code']??'') ?>" required></div><div class="form-group"><label>Name *</label><input name="name" maxlength="160" placeholder="Restaurant Space 01" value="<?= e($edit['name']??'') ?>" required></div></div>
    <div class="form-group admin-form-gap"><label>Location</label><input name="location" maxlength="160" placeholder="Ground Floor / Parking Area" value="<?= e($edit['location']??'') ?>"></div>
    <div class="form-group admin-form-gap"><label>Availability *</label><select name="availability_mode" data-rentable-availability><option value="exclusive"<?= ($edit['availability_mode']??'exclusive')==='exclusive'?' selected':'' ?>>Exclusive — one client at a time</option><option value="quantity"<?= ($edit['availability_mode']??'')==='quantity'?' selected':'' ?>>Quantity based</option></select></div>
    <div class="form-group admin-form-gap" data-rentable-quantity><label>Total Quantity *</label><input type="number" name="total_quantity" min="1" max="100000" value="<?= (int)($edit['total_quantity']??1) ?>"></div>
    <div class="form-group admin-form-gap"><label>Billing *</label><select name="billing_mode" data-rentable-billing><option value="one_time"<?= ($edit['billing_mode']??'one_time')==='one_time'?' selected':'' ?>>One-time / agreed amount</option><option value="monthly"<?= ($edit['billing_mode']??'')==='monthly'?' selected':'' ?>>Monthly recurring lease</option></select></div>
    <label class="admin-check"><input type="checkbox" name="allow_flexible_dates" value="1" data-rentable-flexible<?= !isset($edit['allow_flexible_dates'])||$edit['allow_flexible_dates']?' checked':'' ?>> Allow flexible dates</label>
    <label class="admin-check"><input type="checkbox" name="allow_utilities" value="1" data-rentable-utilities<?= !empty($edit['allow_utilities'])?' checked':'' ?>> Electricity / Water may be billed</label>
    <div class="form-group admin-form-gap"><label>Notes</label><textarea name="notes" placeholder="Optional notes about this rentable"><?= e($edit['notes']??'') ?></textarea></div>
    <label class="admin-check"><input type="checkbox" name="is_active" value="1"<?= !isset($edit['is_active'])||$edit['is_active']?' checked':'' ?>> Available for new rentals</label>
    <button class="btn btn-primary btn-block" type="submit"><?= $edit?'Save Changes':'Add Rentable' ?></button><?php if($edit): ?><a class="btn btn-outline btn-block" style="margin-top:8px" href="rentables.php">Cancel Edit</a><?php endif; ?>
  </form>
</aside>
</div>
<script>
(function(){
  var form=document.querySelector('[data-rentable-form]'); if(!form) return;
  var category=form.querySelector('[data-rentable-category]'), availability=form.querySelector('[data-rentable-availability]'), billing=form.querySelector('[data-rentable-billing]'), quantityWrap=form.querySelector('[data-rentable-quantity]'), flexible=form.querySelector('[data-rentable-flexible]'), utilities=form.querySelector('[data-rentable-utilities]');
  function syncQuantity(){ quantityWrap.hidden=availability.value!=='quantity'; }
  function syncBilling(){ if(billing.value==='monthly'){ flexible.checked=false; flexible.disabled=true; } else flexible.disabled=false; }
  if(category && !<?= $edit?'true':'false' ?>){ category.addEventListener('change',function(){ var o=category.options[category.selectedIndex]; if(!o||!o.value)return; availability.value=o.dataset.availability||'exclusive'; billing.value=o.dataset.billing||'one_time'; flexible.checked=o.dataset.flexible==='1'; utilities.checked=o.dataset.utilities==='1'; syncQuantity(); syncBilling(); }); }
  availability.addEventListener('change',syncQuantity); billing.addEventListener('change',syncBilling); syncQuantity(); syncBilling();
}());
</script>
<?php include __DIR__ . '/_footer.php'; ?>
