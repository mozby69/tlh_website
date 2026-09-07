<?php
/**
 * FILE PURPOSE: Admin gallery image upload, listing, and deletion screen.
 * DEBUGGING: Image validation/storage is centralized in save_uploaded_image() and delete_managed_image().
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'Gallery';

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $last = strtolower($value[strlen($value) - 1]);
    $number = (float)$value;
    return match ($last) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When post_max_size is exceeded, PHP may provide an empty POST and FILES array.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = ini_bytes((string)ini_get('post_max_size'));
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        flash('danger', 'The selected image is too large for the server. Current POST limit: ' . ini_get('post_max_size') . '. Please use a smaller image or restart Apache after applying the included upload settings.');
        redirect('gallery.php');
    }

    verify_csrf();
    $action = $_POST['action'] ?? 'upload';

    try {
        if ($action === 'delete') {
            $stmt = db()->prepare('SELECT image_path FROM gallery_items WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            $path = $stmt->fetchColumn();

            $stmt = db()->prepare('DELETE FROM gallery_items WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            delete_managed_image($path ? (string)$path : null);
            flash('success', 'Gallery item deleted.');
        } else {
            $title = trim($_POST['title'] ?? '');
            $category = $_POST['category'] ?? 'venue';
            $sort = (int)($_POST['sort_order'] ?? 0);

            if ($title === '') {
                throw new RuntimeException('Please enter an image title.');
            }
            if (!isset($_FILES['image'])) {
                throw new RuntimeException('Please choose an image to upload.');
            }

            if (!in_array($category, ['venue', 'sports', 'events'], true)) {
                $category = 'venue';
            }

            $path = save_uploaded_image($_FILES['image'], 'uploads/gallery', 'gallery');
            try {
                $stmt = db()->prepare('INSERT INTO gallery_items (title, image_path, category, sort_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$title, $path, $category, $sort]);
            } catch (Throwable $databaseError) {
                delete_managed_image($path);
                throw $databaseError;
            }

            flash('success', 'Gallery image uploaded successfully.');
        }
    } catch (Throwable $e) {
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update the gallery. Please verify that the database is installed.';
        flash('danger', $message);
    }

    redirect('gallery.php');
}

try {
    $items = db()->query('SELECT * FROM gallery_items ORDER BY sort_order, id DESC')->fetchAll();
} catch (Throwable $e) {
    $items = [];
}

$serverUploadLimit = ini_get('upload_max_filesize') ?: 'Unknown';
$postLimit = ini_get('post_max_size') ?: 'Unknown';
include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
    <section class="panel">
        <div class="panel-head"><h2>Gallery Images</h2></div>
        <div class="grid-3">
            <?php foreach ($items as $item): ?>
                <article class="card" style="padding:12px">
                    <img src="../<?= e($item['image_path']) ?>" loading="lazy" decoding="async" alt="<?= e($item['title']) ?>" style="height:170px;width:100%;object-fit:cover;border-radius:12px">
                    <div style="padding:12px 4px 4px">
                        <strong><?= e($item['title']) ?></strong>
                        <p class="small muted"><?= e(ucfirst($item['category'])) ?></p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button class="btn btn-danger btn-sm" data-confirm="Delete this gallery image?">Delete</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <div class="empty-state">No gallery images uploaded yet.</div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="panel">
        <h2>Upload Image</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="15728640">

            <div class="form-group" style="margin-bottom:14px">
                <label>Image Title</label>
                <input name="title" required>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label>Category</label>
                <select name="category">
                    <option value="venue">Venue</option>
                    <option value="sports">Sports</option>
                    <option value="events">Events</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label>Sort Order</label>
                <input type="number" name="sort_order" value="0">
            </div>
            <div class="form-group" style="margin-bottom:18px">
                <label>Image File</label>
                <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" required>
                <span class="field-help">JPG, PNG, WEBP, or GIF, up to 15 MB. HEIC files must first be converted to JPG.</span>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Upload Image</button>
        </form>
        <p class="small muted" style="margin-top:16px">Server limits: upload <?= e((string)$serverUploadLimit) ?> · form POST <?= e((string)$postLimit) ?></p>
        <p class="small muted">The uploader first tries the website folders. When Windows blocks them, the image is stored safely in MySQL automatically—no folder permission change is required.</p>
    </aside>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
