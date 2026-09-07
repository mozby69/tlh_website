<?php
/**
 * FILE PURPOSE: Public/client-safe print entry point for one reservation reference.
 * DEBUGGING: Keep personal/financial visibility limited to what is appropriate for the client-facing document.
 */
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('track.php');
}
verify_csrf();

$reference = trim($_POST['reference'] ?? '');
$contact = trim($_POST['contact'] ?? ($_POST['email'] ?? ($_POST['phone'] ?? '')));
if ($reference === '' || $contact === '') {
    flash('danger', 'Enter the reservation reference and email address or mobile number before printing.');
    redirect('track.php');
}

try {
    $stmt = db()->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.reference_no=? LIMIT 1');
    $stmt->execute([$reference]);
    $booking = $stmt->fetch();
    $storedEmail = is_array($booking) ? trim((string)($booking['email'] ?? '')) : '';
    $storedPhone = is_array($booking) ? preg_replace('/\D+/', '', (string)($booking['phone'] ?? '')) : '';
    $contactPhone = preg_replace('/\D+/', '', $contact);
    $emailMatches = $storedEmail !== '' && strcasecmp($storedEmail, $contact) === 0;
    $phoneMatches = $storedPhone !== '' && $contactPhone !== '' && hash_equals($storedPhone, $contactPhone);
    if (!$booking || (!$emailMatches && !$phoneMatches)) {
        throw new RuntimeException('The reservation could not be verified.');
    }

    $stmt = db()->prepare('SELECT p.*, bp.batch_payment_no FROM payments p LEFT JOIN batch_payments bp ON bp.id=p.batch_payment_id WHERE p.reservation_id=? ORDER BY p.paid_at ASC, p.id ASC');
    $stmt->execute([(int)$booking['id']]);
    $payments = $stmt->fetchAll();
    $cancellation = reservation_cancellation_record((int)$booking['id']);
} catch (Throwable $e) {
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The printable reservation is currently unavailable.');
    redirect('track.php');
}

$printAssetPrefix = '';
$printBackUrl = 'track.php?reference=' . urlencode($reference);
$printPreparedBy = 'The Leisure Hub Administration';
$printContext = 'client';
require __DIR__ . '/includes/reservation-print-document.php';
