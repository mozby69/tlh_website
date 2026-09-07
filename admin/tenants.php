<?php
/**
 * FILE PURPOSE: Admin CRUD screen for tenant/store directory entries.
 * DEBUGGING: Tenant image storage uses the shared managed-image helpers.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Stores & Tenants';

function unique_tenant_slug(string $name, int $excludeId = 0): string
{
    $base = slugify($name);
    $slug = $base;
    $number = 2;
    while (true) {
        $sql = 'SELECT COUNT(*) FROM tenants WHERE slug=?';
        $params = [$slug];
        if ($excludeId > 0) {
            $sql .= ' AND id<>?';
            $params[] = $excludeId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $number++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT image_path FROM tenants WHERE id=?');
            $stmt->execute([$id]);
            $image = $stmt->fetchColumn();
            db()->prepare('DELETE FROM tenants WHERE id=?')->execute([$id]);
            delete_managed_image($image ? (string)$image : null);
            flash('success', 'Store profile deleted.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $storeName = trim($_POST['store_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['short_description'] ?? '');
            $unitLocation = trim($_POST['unit_location'] ?? '');
            $contactPhone = trim($_POST['contact_phone'] ?? '');
            $websiteUrl = trim($_POST['website_url'] ?? '');
            $facebookUrl = trim($_POST['facebook_url'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($storeName === '') {
                throw new RuntimeException('Please enter the store or tenant name.');
            }

            $existingImage = '';
            if ($id > 0) {
                $stmt = db()->prepare('SELECT image_path FROM tenants WHERE id=?');
                $stmt->execute([$id]);
                $existingImage = (string)($stmt->fetchColumn() ?: '');
            }
            $imagePath = $existingImage;
            if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imagePath = save_uploaded_image($_FILES['image'], 'uploads/tenants', 'tenant');
            }

            $slug = unique_tenant_slug($storeName, $id);
            if ($id > 0) {
                $stmt = db()->prepare('UPDATE tenants SET store_name=?,slug=?,category=?,short_description=?,unit_location=?,contact_phone=?,website_url=?,facebook_url=?,image_path=?,is_featured=?,is_active=?,sort_order=? WHERE id=?');
                $stmt->execute([$storeName, $slug, $category, $description, $unitLocation, $contactPhone, $websiteUrl, $facebookUrl, $imagePath ?: null, $isFeatured, $isActive, $sortOrder, $id]);
                if ($existingImage !== '' && $existingImage !== $imagePath) {
                    delete_managed_image($existingImage);
                }
                flash('success', 'Store profile updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO tenants (store_name,slug,category,short_description,unit_location,contact_phone,website_url,facebook_url,image_path,is_featured,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$storeName, $slug, $category, $description, $unitLocation, $contactPhone, $websiteUrl, $facebookUrl, $imagePath ?: null, $isFeatured, $isActive, $sortOrder]);
                flash('success', 'Store profile added.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update the store profile.');
    }
    redirect('tenants.php');
}

$editTenant = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editTenant = $stmt->fetch() ?: null;
}

try {
    $tenants = db()->query('SELECT * FROM tenants ORDER BY sort_order, store_name')->fetchAll();
} catch (Throwable $e) {
    $tenants = [];
}

include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
  <section class="panel">
    <div class="panel-head"><div><h2>Published Stores & Tenants</h2><p class="muted">Active profiles appear on the public Stores page.</p></div><a class="btn btn-outline btn-sm" href="tenants.php">New Store</a></div>
    <div class="admin-card-list">
      <?php foreach ($tenants as $tenant): ?>
        <article class="admin-media-card tenant-admin-card">
          <?php if (!empty($tenant['image_path'])): ?><img src="../<?= e($tenant['image_path']) ?>" loading="lazy" decoding="async" alt="<?= e($tenant['store_name']) ?>"><?php else: ?><div class="admin-image-placeholder"><?= e(text_initial($tenant['store_name'])) ?></div><?php endif; ?>
          <div class="admin-media-card-body">
            <div class="admin-card-meta"><span><?= e($tenant['category'] ?: 'Uncategorized') ?></span><span><?= e($tenant['unit_location'] ?: 'Location not set') ?></span></div>
            <h3><?= e($tenant['store_name']) ?></h3>
            <p class="muted small"><?= e($tenant['short_description']) ?></p>
            <div class="admin-actions">
              <a class="btn btn-outline btn-sm" href="tenants.php?edit=<?= (int)$tenant['id'] ?>">Edit</a>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$tenant['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Delete this store profile?">Delete</button>
              </form>
              <?php if ($tenant['is_featured']): ?><span class="status-pill status-warning">Featured</span><?php endif; ?>
              <span class="status-pill status-<?= $tenant['is_active'] ? 'success' : 'secondary' ?>"><?= $tenant['is_active'] ? 'Active' : 'Hidden' ?></span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$tenants): ?><div class="empty-state">No store profiles have been added yet.</div><?php endif; ?>
    </div>
  </section>

  <aside class="panel">
    <h2><?= $editTenant ? 'Edit Store Profile' : 'Add Store / Tenant' ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editTenant['id'] ?? 0) ?>">
      <div class="form-group admin-form-gap"><label>Store / Tenant Name *</label><input name="store_name" value="<?= e($editTenant['store_name'] ?? '') ?>" required></div>
      <div class="form-grid compact-form-grid">
        <div class="form-group"><label>Category</label><input name="category" placeholder="Coffee, Fitness, Retail" value="<?= e($editTenant['category'] ?? '') ?>"></div>
        <div class="form-group"><label>Unit / Location</label><input name="unit_location" placeholder="Ground Floor, Unit 03" value="<?= e($editTenant['unit_location'] ?? '') ?>"></div>
      </div>
      <div class="form-group admin-form-gap"><label>Short Description</label><textarea name="short_description"><?= e($editTenant['short_description'] ?? '') ?></textarea></div>
      <div class="form-group admin-form-gap"><label>Store Photo or Logo</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"><span class="field-help">A wide storefront or brand image works best. If Windows blocks the upload folders, the website stores the image in MySQL automatically.</span></div>
      <div class="form-group admin-form-gap"><label>Contact Number</label><input name="contact_phone" value="<?= e($editTenant['contact_phone'] ?? '') ?>"></div>
      <div class="form-group admin-form-gap"><label>Website URL</label><input type="url" name="website_url" placeholder="https://" value="<?= e($editTenant['website_url'] ?? '') ?>"></div>
      <div class="form-group admin-form-gap"><label>Facebook URL</label><input type="url" name="facebook_url" placeholder="https://facebook.com/" value="<?= e($editTenant['facebook_url'] ?? '') ?>"></div>
      <div class="form-group admin-form-gap"><label>Sort Order</label><input type="number" name="sort_order" value="<?= e((string)($editTenant['sort_order'] ?? 0)) ?>"></div>
      <label class="admin-check"><input type="checkbox" name="is_featured" value="1" <?= !empty($editTenant['is_featured']) ? 'checked' : '' ?>> Feature this store on the homepage</label>
      <label class="admin-check"><input type="checkbox" name="is_active" value="1" <?= !isset($editTenant['is_active']) || $editTenant['is_active'] ? 'checked' : '' ?>> Publish this store on the website</label>
      <button class="btn btn-primary btn-block" type="submit"><?= $editTenant ? 'Save Store Changes' : 'Add Store Profile' ?></button>
    </form>
  </aside>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
