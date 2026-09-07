<?php
/**
 * FILE PURPOSE: Public tenant/store directory.
 * DEBUGGING: Tenant records are managed from admin/tenants.php.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Stores & Tenants';
$currentPage = 'stores.php';

try {
    $tenants = db()->query("SELECT * FROM tenants WHERE is_active=1 ORDER BY sort_order, store_name")->fetchAll();
} catch (Throwable $e) {
    $tenants = [];
}

$categories = [];
foreach ($tenants as $tenant) {
    $category = trim((string)($tenant['category'] ?? ''));
    if ($category !== '') {
        $categories[$category] = true;
    }
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero page-hero-stores">
  <div class="container">
    <span class="eyebrow">Stores at The Leisure Hub</span>
    <h1>Discover the brands inside the hub</h1>
    <p>Explore dining, coffee, fitness, retail, professional services, and lifestyle experiences available in one destination.</p>
  </div>
</section>

<section class="section">
  <div class="container section-heading-row stores-heading">
    <div>
      <span class="eyebrow">Tenant Directory</span>
      <h2>Shops, services, and experiences</h2>
      <p class="muted">Tenant profiles are maintained through The Leisure Hub administration portal.</p>
    </div>
    <a class="btn btn-primary" href="leasing.php">Lease a Space</a>
  </div>

  <?php if ($categories): ?>
    <div class="container store-filters" aria-label="Filter stores by category">
      <button class="store-filter is-active" type="button" data-store-filter="all">All Stores</button>
      <?php foreach ($categories as $category): ?>
        <button class="store-filter" type="button" data-store-filter="<?= e(text_lower($category)) ?>"><?= e($category) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="container tenant-directory-grid">
    <?php foreach ($tenants as $tenant): ?>
      <article class="tenant-directory-card" data-store-card data-store-category="<?= e(text_lower((string)$tenant['category'])) ?>">
        <div class="tenant-directory-image">
          <?php if (!empty($tenant['image_path'])): ?>
            <img src="<?= e($tenant['image_path']) ?>" loading="lazy" decoding="async" alt="<?= e($tenant['store_name']) ?>">
          <?php else: ?>
            <div class="tenant-image-placeholder"><span><?= e(text_initial($tenant['store_name'])) ?></span></div>
          <?php endif; ?>
          <?php if (!empty($tenant['category'])): ?><span class="tenant-category"><?= e($tenant['category']) ?></span><?php endif; ?>
        </div>
        <div class="tenant-directory-body">
          <h2><?= e($tenant['store_name']) ?></h2>
          <?php if (!empty($tenant['unit_location'])): ?>
            <p class="tenant-location"><span>Location</span><?= e($tenant['unit_location']) ?></p>
          <?php endif; ?>
          <?php if (!empty($tenant['short_description'])): ?><p class="muted"><?= nl2br(e($tenant['short_description'])) ?></p><?php endif; ?>
          <div class="tenant-links">
            <?php if (!empty($tenant['contact_phone'])): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $tenant['contact_phone'])) ?>">Call Store</a><?php endif; ?>
            <?php if (!empty($tenant['website_url'])): ?><a href="<?= e(safe_href($tenant['website_url'])) ?>" target="_blank" rel="noopener">Website</a><?php endif; ?>
            <?php if (!empty($tenant['facebook_url'])): ?><a href="<?= e(safe_href($tenant['facebook_url'])) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$tenants): ?>
      <div class="leasing-empty-state tenant-directory-empty">
        <div>
          <span class="eyebrow">Tenant Directory Ready</span>
          <h3>No stores have been published yet.</h3>
          <p class="muted">As businesses open inside The Leisure Hub, their photos, descriptions, locations, and contact links will appear on this page.</p>
        </div>
        <a class="btn btn-primary" href="leasing.php">Become a Tenant</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm surface">
  <div class="container cta-band leasing-cta">
    <div><span class="cta-kicker">Commercial Leasing</span><h2>Bring your brand to The Leisure Hub</h2><p>Join a destination designed around sports, events, dining, services, and community experiences.</p></div>
    <a class="btn btn-primary" href="leasing.php">Send a Leasing Inquiry</a>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
