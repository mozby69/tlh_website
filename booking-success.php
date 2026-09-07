<?php
/**
 * FILE PURPOSE: Native-style public post-submission confirmation page.
 * SECURITY: Booking details are sourced from the current session; private data is
 * never placed in query strings. Tracking still requires reference + contact.
 */
require_once __DIR__ . '/includes/functions.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$reference = (string)($_SESSION['last_booking_reference'] ?? '');
$email = (string)($_SESSION['last_booking_email'] ?? '');
$phone = (string)($_SESSION['last_booking_phone'] ?? '');
$contact = $email !== '' ? $email : $phone;
$paymentChoice = (string)($_SESSION['last_booking_payment_choice'] ?? 'none');
$paymentMethod = (string)($_SESSION['last_booking_payment_method'] ?? '');
$paymentIntentAmount = (float)($_SESSION['last_booking_payment_intent_amount'] ?? 0);
$reservationTotal = (float)($_SESSION['last_booking_total'] ?? 0);
$reservationType = (string)($_SESSION['last_booking_reservation_type'] ?? 'basketball');
$eventStart = (string)($_SESSION['last_booking_event_start'] ?? '');
$eventEnd = (string)($_SESSION['last_booking_event_end'] ?? '');
$guestCount = (int)($_SESSION['last_booking_guest_count'] ?? 0);

if ($reference === '') {
    redirect('reserve.php');
}

$typeLabel = reservation_type_label($reservationType);
$scheduleLabel = 'Schedule submitted';
if ($eventStart !== '' && $eventEnd !== '') {
    try {
        $start = new DateTimeImmutable($eventStart);
        $end = new DateTimeImmutable($eventEnd);
        $scheduleLabel = $start->format('M j, Y · g:i A') . ' – ' . $end->format('g:i A');
    } catch (Throwable $e) {
        $scheduleLabel = 'Schedule submitted';
    }
}

$pageTitle = 'Reservation Request Sent';
$bodyClass = 'native-success-body';
include __DIR__ . '/includes/header.php';
?>
<section class="native-success-page">
  <div class="container native-success-shell">
    <div class="native-success-check" aria-hidden="true">✓</div>
    <span class="native-success-kicker">Request received</span>
    <h1>Reservation Request Sent</h1>
    <p class="native-success-lead">We received your request. Your schedule becomes secured only after The Leisure Hub reviews and approves it.</p>

    <div class="native-success-reference-card">
      <span>Reservation reference</span>
      <strong data-copy-reference-value><?= e($reference) ?></strong>
      <button type="button" data-copy-reference="<?= e($reference) ?>"><span aria-hidden="true">⧉</span> Copy reference</button>
    </div>

    <div class="native-success-status-row">
      <span class="native-success-status-dot" aria-hidden="true"></span>
      <div><strong>Pending Review</strong><small>Your request is not holding the schedule yet.</small></div>
    </div>

    <div class="native-success-summary">
      <div><span>Reservation</span><strong><?= e($typeLabel) ?></strong></div>
      <div><span>Schedule</span><strong><?= e($scheduleLabel) ?></strong></div>
      <?php if ($guestCount > 0): ?><div><span>Guests</span><strong><?= e((string)$guestCount) ?></strong></div><?php endif; ?>
      <div><span>Total</span><strong><?= money($reservationTotal) ?></strong></div>
      <div><span>Payment</span><strong><?= e(match ($paymentChoice) { 'full' => 'Full payment selected', 'partial' => 'Partial payment selected', default => 'No payment yet' }) ?></strong></div>
      <?php if ($paymentChoice !== 'none'): ?>
        <div><span>Planned method</span><strong><?= e($paymentMethod !== '' ? booking_payment_method_label($paymentMethod) : 'Not specified') ?></strong></div>
        <div><span>Intended amount</span><strong><?= money($paymentIntentAmount) ?></strong></div>
      <?php endif; ?>
    </div>

    <?php if ($paymentChoice !== 'none'): ?>
      <div class="native-success-note"><strong>Payment is not yet counted as paid.</strong><span>The administrator will record and verify the official transaction details.</span></div>
    <?php endif; ?>

    <div class="native-success-actions">
      <form method="post" action="track.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="reference" value="<?= e($reference) ?>">
        <input type="hidden" name="contact" value="<?= e($contact) ?>">
        <button class="btn btn-primary" type="submit">Track Reservation</button>
      </form>
      <a class="btn btn-outline" href="index.php">Back Home</a>
      <form method="post" action="reservation-print.php" target="_blank" class="native-success-print">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="reference" value="<?= e($reference) ?>">
        <input type="hidden" name="contact" value="<?= e($contact) ?>">
        <button type="submit">Print reservation</button>
      </form>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
