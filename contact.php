<?php
/**
 * FILE PURPOSE: Public contact/inquiry page and inquiry submission handler.
 * DEBUGGING: Validate and sanitize user input before writing to the inquiries table.
 */
require_once __DIR__ . '/includes/functions.php';
$pageTitle='Contact';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');$subject=trim($_POST['subject']??'');$message=trim($_POST['message']??'');
  if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$subject===''||$message===''){flash('danger','Please complete all required fields using a valid email address.');}
  else{try{$stmt=db()->prepare('INSERT INTO inquiries(name,email,phone,subject,message) VALUES(?,?,?,?,?)');$stmt->execute([$name,$email,$phone,$subject,$message]);flash('success','Your inquiry has been sent. Our team will contact you soon.');redirect('contact.php');}catch(Throwable $e){flash('danger','The inquiry could not be submitted. Please verify that the website database is installed.');}}
}
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="eyebrow">Contact Us</span><h1>Let’s plan your visit</h1><p>Reach our team for reservations, event requirements, rates, venue policies, and general inquiries.</p></div></section>
<section class="section"><div class="container reservation-layout"><div class="form-card"><h2>Send an inquiry</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-group"><label>Full Name *</label><input name="name" required></div><div class="form-group"><label>Email Address *</label><input type="email" name="email" required></div><div class="form-group"><label>Mobile Number</label><input name="phone"></div><div class="form-group"><label>Subject *</label><input name="subject" required></div><div class="form-group full"><label>Message *</label><textarea name="message" required></textarea></div><div class="form-group full"><button class="btn btn-primary" type="submit">Send Inquiry</button></div></form></div><aside class="card booking-summary"><h3>Contact Information</h3><div class="rate-card"><span>Address</span><strong><?= e(setting('address')) ?></strong></div><div class="rate-card"><span>Phone</span><strong><?= e(setting('phone')) ?></strong></div><div class="rate-card"><span>Email</span><strong><?= e(setting('email')) ?></strong></div><div class="rate-card"><span>Hours</span><strong><?= e(setting('operating_hours')) ?></strong></div></aside></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
