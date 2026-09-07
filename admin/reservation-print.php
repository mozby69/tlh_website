<?php
/**
 * FILE PURPOSE: Admin entry point for printing one reservation.
 * DEBUGGING: Printable markup is centralized in includes/reservation-print-document.php.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    flash('danger', 'Reservation not found.');
    redirect('reservations.php');
}

try {
    $stmt = db()->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.id=? LIMIT 1');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Reservation not found.');
    }

    $stmt = db()->prepare('SELECT p.*, bp.batch_payment_no FROM payments p LEFT JOIN batch_payments bp ON bp.id=p.batch_payment_id WHERE p.reservation_id=? ORDER BY p.paid_at ASC, p.id ASC');
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();
    $cancellation = reservation_cancellation_record($id);
} catch (Throwable $e) {
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The printable reservation could not be loaded.');
    redirect('reservations.php');
}

$admin = current_admin();
$printAssetPrefix = '../';
$printBackUrl = 'reservation-view.php?id=' . $id;
$printPreparedBy = $admin['name'] ?? 'The Leisure Hub Administration';
$printContext = 'admin';
require __DIR__ . '/../includes/reservation-print-document.php';
