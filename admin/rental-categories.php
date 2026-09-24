<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) { flash('danger','Administrator access is required.'); redirect('index.php'); }
$adminPageTitle = 'Rental Categories';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'save');
        $id=(int)($_POST['id']??0);
        if ($action==='delete') {
            $count=db()->prepare('SELECT COUNT(*) FROM rentables WHERE category_id=?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn()>0) throw new RuntimeException('This category already has rentables. Set it inactive instead of deleting it.');
            db()->prepare('DELETE FROM rental_categories WHERE id=?')->execute([$id]);
            flash('success','Rental category deleted.');
        } else {
            $name=trim((string)($_POST['name']??''));
            $type=in_array($_POST['category_type']??'', ['space','equipment','other'], true)?(string)$_POST['category_type']:'other';
            $availability=in_array($_POST['default_availability_mode']??'', ['exclusive','quantity'], true)?(string)$_POST['default_availability_mode']:'exclusive';
            $billing=in_array($_POST['default_billing_mode']??'', ['one_time','monthly'], true)?(string)$_POST['default_billing_mode']:'one_time';
            $allowFlexible=isset($_POST['allow_flexible_dates'])?1:0;
            $allowUtilities=isset($_POST['allow_utilities'])?1:0;
            $active=isset($_POST['is_active'])?1:0;
            if ($name==='') throw new RuntimeException('Enter a category name.');
            if (strlen($name)>120) throw new RuntimeException('Category name is too long.');
            $slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'));
            if ($slug==='') $slug='category-'.bin2hex(random_bytes(2));
            $dup=db()->prepare('SELECT COUNT(*) FROM rental_categories WHERE (name=? OR slug=?) AND id<>?');
            $dup->execute([$name,$slug,$id]);
            if ((int)$dup->fetchColumn()>0) throw new RuntimeException('That category already exists.');
            if ($id>0) {
                $stmt=db()->prepare('UPDATE rental_categories SET name=?,slug=?,category_type=?,default_availability_mode=?,default_billing_mode=?,allow_flexible_dates=?,allow_utilities=?,is_active=? WHERE id=?');
                $stmt->execute([$name,$slug,$type,$availability,$billing,$allowFlexible,$allowUtilities,$active,$id]);
                flash('success','Rental category updated.');
            } else {
                $stmt=db()->prepare('INSERT INTO rental_categories(name,slug,category_type,default_availability_mode,default_billing_mode,allow_flexible_dates,allow_utilities,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$name,$slug,$type,$availability,$billing,$allowFlexible,$allowUtilities,$active,current_admin()['id']]);
                flash('success','Rental category added.');
            }
        }
    } catch(Throwable $e) { flash('danger',$e instanceof RuntimeException?$e->getMessage():'Unable to update rental category.'); }
    redirect('rental-categories.php');
}

$edit=null;
if (isset($_GET['edit'])) { $stmt=db()->prepare('SELECT * FROM rental_categories WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit=$stmt->fetch()?:null; }
$rows=db()->query("SELECT c.*,(SELECT COUNT(*) FROM rentables x WHERE x.category_id=c.id) AS rentable_count FROM rental_categories c ORDER BY c.is_active DESC,c.sort_order,c.name")->fetchAll();
include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
<section class="panel">
  <div class="panel-head"><div><h2>Rental Categories</h2><p class="muted">Categories control the default behavior of new rentables. You can still override the settings on each rentable.</p></div><a class="btn btn-outline btn-sm" href="rentables.php">Manage Rentables</a></div>
  <div class="table-wrap"><table class="admin-table mobile-card-table"><thead><tr><th>Category</th><th>Type</th><th>Availability</th><th>Billing</th><th>Options</th><th>Rentables</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($rows as $row): ?><tr>
    <td data-label="Category" data-priority="primary"><strong><?= e($row['name']) ?></strong></td>
    <td data-label="Type"><?= e(ucfirst($row['category_type'])) ?></td>
    <td data-label="Availability"><?= $row['default_availability_mode']==='quantity'?'Quantity based':'Exclusive' ?></td>
    <td data-label="Billing"><?= $row['default_billing_mode']==='monthly'?'Monthly recurring':'One-time / agreed' ?></td>
    <td data-label="Options"><span class="small"><?= $row['allow_flexible_dates']?'Flexible dates · ':'' ?><?= $row['allow_utilities']?'Utilities':'' ?><?= !$row['allow_flexible_dates']&&!$row['allow_utilities']?'—':'' ?></span></td>
    <td data-label="Rentables"><?= number_format((int)$row['rentable_count']) ?></td>
    <td data-label="Actions"><div class="admin-actions"><a class="btn btn-outline btn-sm" href="?edit=<?= (int)$row['id'] ?>">Edit</a><?php if((int)$row['rentable_count']===0): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this rental category?">Delete</button></form><?php endif; ?></div></td>
  </tr><?php endforeach; ?></tbody></table></div>
</section>
<aside class="panel">
  <h2><?= $edit?'Edit Category':'Add Category' ?></h2>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
    <div class="form-group admin-form-gap"><label>Category Name *</label><input name="name" maxlength="120" value="<?= e($edit['name']??'') ?>" placeholder="Restaurant / Cafe Space" required></div>
    <div class="form-group admin-form-gap"><label>Category Type *</label><select name="category_type"><option value="space"<?= ($edit['category_type']??'space')==='space'?' selected':'' ?>>Space</option><option value="equipment"<?= ($edit['category_type']??'')==='equipment'?' selected':'' ?>>Equipment / Item</option><option value="other"<?= ($edit['category_type']??'')==='other'?' selected':'' ?>>Other</option></select></div>
    <div class="form-group admin-form-gap"><label>Default Availability *</label><select name="default_availability_mode"><option value="exclusive"<?= ($edit['default_availability_mode']??'exclusive')==='exclusive'?' selected':'' ?>>Exclusive — one client at a time</option><option value="quantity"<?= ($edit['default_availability_mode']??'')==='quantity'?' selected':'' ?>>Quantity based</option></select></div>
    <div class="form-group admin-form-gap"><label>Default Billing *</label><select name="default_billing_mode"><option value="one_time"<?= ($edit['default_billing_mode']??'one_time')==='one_time'?' selected':'' ?>>One-time / agreed amount</option><option value="monthly"<?= ($edit['default_billing_mode']??'')==='monthly'?' selected':'' ?>>Monthly recurring</option></select></div>
    <label class="admin-check"><input type="checkbox" name="allow_flexible_dates" value="1"<?= !isset($edit['allow_flexible_dates'])||$edit['allow_flexible_dates']?' checked':'' ?>> Allow flexible / non-consecutive dates</label>
    <label class="admin-check"><input type="checkbox" name="allow_utilities" value="1"<?= !empty($edit['allow_utilities'])?' checked':'' ?>> Allow Electricity / Water billing</label>
    <label class="admin-check"><input type="checkbox" name="is_active" value="1"<?= !isset($edit['is_active'])||$edit['is_active']?' checked':'' ?>> Active</label>
    <button class="btn btn-primary btn-block" type="submit"><?= $edit?'Save Category':'Add Category' ?></button>
    <?php if($edit): ?><a class="btn btn-outline btn-block" style="margin-top:8px" href="rental-categories.php">Cancel Edit</a><?php endif; ?>
  </form>
</aside>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
