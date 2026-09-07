<?php
/**
 * FILE PURPOSE: Admin CRUD screen for homepage hero carousel slides.
 * DEBUGGING: Image storage uses the shared managed-image helpers; deleting a slide also removes its managed image when appropriate.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Hero Carousel';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT image_path FROM hero_slides WHERE id=?');
            $stmt->execute([$id]);
            $image = $stmt->fetchColumn();
            db()->prepare('DELETE FROM hero_slides WHERE id=?')->execute([$id]);
            delete_managed_image($image ? (string)$image : null);
            flash('success', 'Hero slide deleted.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $eyebrow = trim($_POST['eyebrow'] ?? 'The Leisure Hub');
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $primaryLabel = trim($_POST['primary_label'] ?? '');
            $primaryUrl = trim($_POST['primary_url'] ?? '');
            $secondaryLabel = trim($_POST['secondary_label'] ?? '');
            $secondaryUrl = trim($_POST['secondary_url'] ?? '');
            $imagePosition = trim($_POST['image_position'] ?? 'center 50%');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($title === '') {
                throw new RuntimeException('Please enter a slide title.');
            }

            $existingImage = '';
            if ($id > 0) {
                $stmt = db()->prepare('SELECT image_path FROM hero_slides WHERE id=?');
                $stmt->execute([$id]);
                $existingImage = (string)($stmt->fetchColumn() ?: '');
            }

            $imagePath = $existingImage ?: 'assets/img/leisure-hub-hero.jpg';
            if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imagePath = save_uploaded_image($_FILES['image'], 'uploads/hero', 'hero-slide');
            }

            if ($id > 0) {
                $stmt = db()->prepare('UPDATE hero_slides SET eyebrow=?,title=?,body=?,primary_label=?,primary_url=?,secondary_label=?,secondary_url=?,image_path=?,image_position=?,sort_order=?,is_active=? WHERE id=?');
                $stmt->execute([$eyebrow, $title, $body, $primaryLabel, $primaryUrl, $secondaryLabel, $secondaryUrl, $imagePath, $imagePosition, $sortOrder, $isActive, $id]);
                if ($existingImage !== '' && $existingImage !== $imagePath) {
                    delete_managed_image($existingImage);
                }
                flash('success', 'Hero slide updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO hero_slides (eyebrow,title,body,primary_label,primary_url,secondary_label,secondary_url,image_path,image_position,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$eyebrow, $title, $body, $primaryLabel, $primaryUrl, $secondaryLabel, $secondaryUrl, $imagePath, $imagePosition, $sortOrder, $isActive]);
                flash('success', 'Hero slide added.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update the hero carousel.');
    }
    redirect('hero-slides.php');
}

$editSlide = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM hero_slides WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editSlide = $stmt->fetch() ?: null;
}

try {
    $slides = db()->query('SELECT * FROM hero_slides ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $slides = [];
}

include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
  <section class="panel">
    <div class="panel-head"><div><h2>Homepage Slides</h2><p class="muted">Slides appear in this order on the public homepage.</p></div><a class="btn btn-outline btn-sm" href="hero-slides.php">New Slide</a></div>
    <div class="admin-card-list">
      <?php foreach ($slides as $slide): ?>
        <article class="admin-media-card">
          <img src="../<?= e($slide['image_path']) ?>" loading="lazy" decoding="async" alt="<?= e($slide['title']) ?>">
          <div class="admin-media-card-body">
            <div class="admin-card-meta"><span><?= e($slide['eyebrow']) ?></span><span>Order <?= (int)$slide['sort_order'] ?></span></div>
            <h3><?= e($slide['title']) ?></h3>
            <p class="muted small"><?= e($slide['body']) ?></p>
            <div class="admin-actions">
              <a class="btn btn-outline btn-sm" href="hero-slides.php?edit=<?= (int)$slide['id'] ?>">Edit</a>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$slide['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Delete this hero slide?">Delete</button>
              </form>
              <span class="status-pill status-<?= $slide['is_active'] ? 'success' : 'secondary' ?>"><?= $slide['is_active'] ? 'Active' : 'Hidden' ?></span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$slides): ?><div class="empty-state">No hero slides have been created.</div><?php endif; ?>
    </div>
  </section>

  <aside class="panel">
    <h2><?= $editSlide ? 'Edit Slide' : 'Add Hero Slide' ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editSlide['id'] ?? 0) ?>">
      <div class="form-group admin-form-gap"><label>Eyebrow / Category</label><input name="eyebrow" value="<?= e($editSlide['eyebrow'] ?? 'The Leisure Hub') ?>"></div>
      <div class="form-group admin-form-gap"><label>Headline *</label><input name="title" value="<?= e($editSlide['title'] ?? '') ?>" required></div>
      <div class="form-group admin-form-gap"><label>Description</label><textarea name="body"><?= e($editSlide['body'] ?? '') ?></textarea></div>
      <div class="form-grid compact-form-grid">
        <div class="form-group"><label>Primary Button</label><input name="primary_label" value="<?= e($editSlide['primary_label'] ?? '') ?>"></div>
        <div class="form-group"><label>Primary URL</label><input name="primary_url" value="<?= e($editSlide['primary_url'] ?? '') ?>" placeholder="reserve.php"></div>
        <div class="form-group"><label>Secondary Button</label><input name="secondary_label" value="<?= e($editSlide['secondary_label'] ?? '') ?>"></div>
        <div class="form-group"><label>Secondary URL</label><input name="secondary_url" value="<?= e($editSlide['secondary_url'] ?? '') ?>" placeholder="leasing.php"></div>
      </div>
      <div class="form-group admin-form-gap"><label>Background Image</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"><span class="field-help">Leave empty while editing to keep the current image. If Windows blocks the upload folders, the website stores the image in MySQL automatically.</span></div>
      <div class="form-grid compact-form-grid">
        <div class="form-group"><label>Image Position</label><select name="image_position"><?php foreach (['center 35%'=>'Higher','center 45%'=>'Slightly High','center 50%'=>'Center','center 58%'=>'Slightly Low','center 68%'=>'Lower'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($editSlide['image_position'] ?? 'center 50%') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" value="<?= e((string)($editSlide['sort_order'] ?? 0)) ?>"></div>
      </div>
      <label class="admin-check"><input type="checkbox" name="is_active" value="1" <?= !isset($editSlide['is_active']) || $editSlide['is_active'] ? 'checked' : '' ?>> Show this slide on the homepage</label>
      <button class="btn btn-primary btn-block" type="submit"><?= $editSlide ? 'Save Slide Changes' : 'Add Hero Slide' ?></button>
    </form>
  </aside>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
