<?php
/**
 * FILE PURPOSE: Public leasing information/inquiry page.
 * DEBUGGING: Presentation page; shared contact/inquiry logic should remain consistent with the rest of the public site.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Commercial Leasing';
$currentPage = 'leasing.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $businessName = trim($_POST['business_name'] ?? '');
    $businessType = trim($_POST['business_type'] ?? '');
    $spaceNeed = trim($_POST['space_need'] ?? '');
    $message = trim($_POST['message'] ?? '');

    try {
        if ($name === '' || $email === '' || $businessName === '' || $businessType === '') {
            throw new RuntimeException('Please complete all required leasing inquiry fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $subject = 'Leasing Inquiry - ' . $businessName;
        $details = "Business / Brand: {$businessName}\nBusiness Type: {$businessType}\nPreferred Space / Requirement: {$spaceNeed}\n\nMessage:\n{$message}";
        $stmt = db()->prepare('INSERT INTO inquiries (name,email,phone,subject,message,status) VALUES (?,?,?,?,?,\'new\')');
        $stmt->execute([$name, $email, $phone, $subject, $details]);
        flash('success', 'Thank you. Your leasing inquiry has been submitted to The Leisure Hub team.');
        redirect('leasing.php#leasing-form');
    } catch (Throwable $e) {
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to submit the leasing inquiry. Please try again.');
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero page-hero-leasing">
  <div class="container leasing-hero-layout">
    <div>
      <span class="eyebrow">Commercial Spaces for Lease</span>
      <h1><?= e(setting('leasing_title', 'Grow your business at The Leisure Hub')) ?></h1>
      <p><?= e(setting('leasing_text', 'Position your brand inside a destination built for sports, events, dining, services, and community experiences.')) ?></p>
      <div class="hero-actions"><a class="btn btn-primary" href="#leasing-form">Inquire About a Space</a><a class="btn btn-outline" href="stores.php">View Current Stores</a></div>
    </div>
    <div class="leasing-hero-card">
      <span>Ideal for</span>
      <div class="leasing-tags"><b>Food & Coffee</b><b>Fitness</b><b>Retail</b><b>Clinics</b><b>Offices</b><b>Services</b></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container center leasing-heading">
    <span class="eyebrow">Why Locate Here</span>
    <h2>Build around an active community destination</h2>
    <p class="muted">The Leisure Hub combines recurring sports traffic, private events, community gatherings, and complementary commercial concepts in one visible location.</p>
  </div>
  <div class="container grid-4 leasing-benefits">
    <article class="card"><div class="icon-box">◎</div><h3>Mixed Audience</h3><p class="muted">Reach athletes, families, event guests, professionals, and nearby communities.</p></article>
    <article class="card"><div class="icon-box">↗</div><h3>Brand Visibility</h3><p class="muted">Receive a dedicated store profile on the official The Leisure Hub website.</p></article>
    <article class="card"><div class="icon-box">◫</div><h3>Flexible Concepts</h3><p class="muted">Suitable for food, fitness, retail, clinics, offices, and service-oriented businesses.</p></article>
    <article class="card"><div class="icon-box">✦</div><h3>Destination Value</h3><p class="muted">Benefit from the combined appeal of venue bookings, events, and other tenant brands.</p></article>
  </div>
</section>

<section class="section surface" id="leasing-form">
  <div class="container leasing-form-layout">
    <div>
      <span class="eyebrow">Leasing Inquiry</span>
      <h2>Tell us about your business concept</h2>
      <p class="muted">Submit your brand, preferred space, and contact information. The leasing team can review your concept and contact you regarding availability and commercial terms.</p>
      <div class="leasing-contact-card">
        <small>Leasing Contact</small>
        <strong><?= e(setting('phone')) ?></strong>
        <a href="mailto:<?= e(setting('leasing_email', setting('email'))) ?>"><?= e(setting('leasing_email', setting('email'))) ?></a>
        <span><?= e(setting('address')) ?></span>
      </div>
    </div>

    <form class="form-card" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="form-grid">
        <div class="form-group"><label>Your Name *</label><input name="name" value="<?= e($_POST['name'] ?? '') ?>" required></div>
        <div class="form-group"><label>Email Address *</label><input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></div>
        <div class="form-group"><label>Mobile Number</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>"></div>
        <div class="form-group"><label>Business / Brand Name *</label><input name="business_name" value="<?= e($_POST['business_name'] ?? '') ?>" required></div>
        <div class="form-group"><label>Business Type *</label><input name="business_type" placeholder="Example: Coffee shop, gym, clinic" value="<?= e($_POST['business_type'] ?? '') ?>" required></div>
        <div class="form-group"><label>Preferred Space / Requirement</label><input name="space_need" placeholder="Example: Ground floor, 50 sqm" value="<?= e($_POST['space_need'] ?? '') ?>"></div>
        <div class="form-group full"><label>Business Concept or Message</label><textarea name="message" placeholder="Share your concept, target opening date, and other requirements."><?= e($_POST['message'] ?? '') ?></textarea></div>
        <div class="form-group full"><button class="btn btn-primary" type="submit">Submit Leasing Inquiry</button></div>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
