<?php
/**
 * FILE PURPOSE: Explicitly resolves a lapsed Pending / For Review reservation.
 * DEBUGGING: A past pending request must never become Completed from time alone;
 * this endpoint is the intentional administrator decision point.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('needs-resolution.php');
}

verify_csrf();
$id = (int)($_POST['reservation_id'] ?? 0);
$outcome = trim((string)($_POST['outcome'] ?? ''));
$returnTo = trim((string)($_POST['return_to'] ?? 'needs-resolution.php'));

if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = 'needs-resolution.php';
}

$outcomes = [
    'occurred' => ['status' => 'completed', 'label' => 'Event Occurred'],
    'no_show' => ['status' => 'no_show', 'label' => 'No Show'],
    'did_not_proceed' => ['status' => 'rejected', 'label' => 'Did Not Proceed'],
];

try {
    if ($id < 1 || !isset($outcomes[$outcome])) {
        throw new RuntimeException('Choose a valid resolution action.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Reservation not found.');
    }
    if (!reservation_needs_resolution($booking)) {
        throw new RuntimeException('This reservation no longer needs resolution. Refresh the queue and review its current status.');
    }

    $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
    $totalStmt->execute([$id]);
    $totalPaid = round((float)$totalStmt->fetchColumn(), 2);
    $target = reservation_payment_target($booking);
    $paymentStatus = payment_status_for_amount($totalPaid, $target);

    $admin = current_admin();
    $adminName = trim((string)($admin['name'] ?? 'Administrator')) ?: 'Administrator';
    $stamp = (new DateTimeImmutable())->format('M j, Y g:i A');
    $resolutionNote = '[' . $stamp . '] Lapsed reservation resolved by ' . $adminName . ': ' . $outcomes[$outcome]['label'] . '.';
    $existingNotes = trim((string)($booking['admin_notes'] ?? ''));
    $notes = $existingNotes !== '' ? $existingNotes . "\n\n" . $resolutionNote : $resolutionNote;

    $update = $pdo->prepare('UPDATE reservations SET status=?,admin_notes=?,amount_paid=?,payment_status=? WHERE id=?');
    $update->execute([$outcomes[$outcome]['status'], $notes, $totalPaid, $paymentStatus, $id]);
    $pdo->commit();

    $message = match ($outcome) {
        'occurred' => 'Event occurrence confirmed. The reservation is now Completed; any remaining balance can still be recorded later.',
        'no_show' => 'Reservation marked No Show.',
        default => 'Reservation marked Did Not Proceed (Rejected).',
    };
    flash('success', $message);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The reservation outcome could not be saved.');
}

redirect($returnTo);
