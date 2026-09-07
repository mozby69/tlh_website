<?php
/**
 * FILE PURPOSE: Admin editor for general website settings that are not reservation rates.
 * DEBUGGING: Pricing settings belong in rates-settings.php; avoid duplicating rate configuration here.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) {
    flash('danger', 'Administrator access is required.');
    redirect('index.php');
}
$adminPageTitle = 'Website Settings';
$fields = [
    'site_name','tagline','hero_title','hero_text','about_text','address','phone','email','operating_hours',
    'facebook_url','map_embed','leasing_title','leasing_text','leasing_email'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach ($fields as $field) {
            $stmt->execute([$field, trim($_POST[$field] ?? '')]);
        }
        $pdo->commit();
        flash('success', 'Website settings updated.');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', 'Unable to update website settings.');
    }
    redirect('settings.php');
}

include __DIR__ . '/_header.php';
?>
<section class="panel">
  <div class="panel-head"><div><h2>Public Website Content</h2><p class="muted">Changes appear on the customer-facing website. Reservation prices are managed separately under Rates Settings.</p></div></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="form-group"><label>Website Name</label><input name="site_name" value="<?= e(setting('site_name')) ?>"></div>
    <div class="form-group"><label>Tagline</label><input name="tagline" value="<?= e(setting('tagline')) ?>"></div>
    <div class="form-group full"><label>Default Hero Title</label><input name="hero_title" value="<?= e(setting('hero_title')) ?>"><span class="field-help">Used as the fallback headline and when the database is first upgraded.</span></div>
    <div class="form-group full"><label>Website Description</label><textarea name="hero_text"><?= e(setting('hero_text')) ?></textarea></div>
    <div class="form-group full"><label>About Text</label><textarea name="about_text"><?= e(setting('about_text')) ?></textarea></div>
    <div class="form-group"><label>Address</label><input name="address" value="<?= e(setting('address')) ?>"></div>
    <div class="form-group"><label>Phone</label><input name="phone" value="<?= e(setting('phone')) ?>"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= e(setting('email')) ?>"></div>
    <div class="form-group"><label>Operating Hours</label><input name="operating_hours" value="<?= e(setting('operating_hours')) ?>"></div>

    <div class="form-group full admin-section-label"><h3>Commercial Leasing</h3></div>
    <div class="form-group full"><label>Leasing Page Headline</label><input name="leasing_title" value="<?= e(setting('leasing_title')) ?>"></div>
    <div class="form-group full"><label>Leasing Page Description</label><textarea name="leasing_text"><?= e(setting('leasing_text')) ?></textarea></div>
    <div class="form-group"><label>Leasing Email</label><input type="email" name="leasing_email" value="<?= e(setting('leasing_email', setting('email'))) ?>"></div>
    <div class="form-group"><label>Facebook URL</label><input name="facebook_url" value="<?= e(setting('facebook_url')) ?>"></div>
    <div class="form-group full"><label>Map Embed Code / URL</label><textarea name="map_embed"><?= e(setting('map_embed')) ?></textarea></div>
    <div class="form-group full"><button class="btn btn-primary" type="submit">Save Website Settings</button></div>
  </form>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
