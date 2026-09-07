<?php
/**
 * FILE PURPOSE: Public gallery page.
 * DEBUGGING: Managed image URLs may be filesystem paths or database-backed media.php URLs; use shared helpers rather than assuming disk storage.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle='Gallery';
try {$items=db()->query("SELECT * FROM gallery_items WHERE is_active=1 ORDER BY sort_order,id DESC")->fetchAll();} catch(Throwable $e){$items=[];}
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="eyebrow">Gallery</span><h1>Experience the venue</h1><p>Explore the multipurpose court, sports activities, and event transformations at The Leisure Hub.</p></div></section>
<section class="section"><div class="container"><div class="filter-row"><button class="btn btn-outline active" data-gallery-filter="all">All</button><button class="btn btn-outline" data-gallery-filter="venue">Venue</button><button class="btn btn-outline" data-gallery-filter="sports">Sports</button><button class="btn btn-outline" data-gallery-filter="events">Events</button></div><div class="gallery-grid">
<?php if (!$items): ?>
  <?php foreach ([['venue','The Multipurpose Venue'],['sports','Basketball Court'],['sports','Volleyball Setup'],['events','Private Events'],['events','Corporate Functions'],['venue','The Leisure Hub']] as $placeholder): ?><article class="gallery-card" data-gallery-category="<?= e($placeholder[0]) ?>"><div class="gallery-placeholder"><?= e($placeholder[1]) ?></div><div class="caption"><strong><?= e($placeholder[1]) ?></strong></div></article><?php endforeach; ?>
<?php else: foreach($items as $item): ?><article class="gallery-card" data-gallery-category="<?= e($item['category']) ?>"><img src="<?= e($item['image_path']) ?>" loading="lazy" decoding="async" alt="<?= e($item['title']) ?>"><div class="caption"><strong><?= e($item['title']) ?></strong></div></article><?php endforeach; endif; ?>
</div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
