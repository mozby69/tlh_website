<?php
/**
 * FILE PURPOSE: Permanently deletes an erroneous reservation when no financial ledger history exists.
 * SECURITY: Administrator-only, POST-only, CSRF-protected, and payment-aware.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if (!is_admin()) {
    flash('danger', 'Administrator access is required to permanently delete reservations.');
    redirect('reservations.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reservations.php');
}

verify_csrf();
$reservationId = (int)($_POST['reservation_id'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'reservations.php'));

// Only allow a local admin-page redirect after the action.
if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = 'reservations.php';
}

try {
    if ($reservationId < 1) {
        throw new RuntimeException('Invalid reservation delete request.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
    $stmt->execute([$reservationId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Reservation not found.');
    }

    // Hard delete is intended for mistaken/data-entry records only. Once money
    // has been recorded, preserve the financial audit trail and use cancellation
    // or archive instead.
    $paymentStmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE reservation_id=?');
    $paymentStmt->execute([$reservationId]);
    $paymentCount = (int)$paymentStmt->fetchColumn();

    $scopeStmt = $pdo->prepare('SELECT COUNT(*) FROM batch_payment_scopes WHERE reservation_id=?');
    $scopeStmt->execute([$reservationId]);
    $scopeCount = (int)$scopeStmt->fetchColumn();

    if ($paymentCount > 0 || $scopeCount > 0 || abs((float)($booking['amount_paid'] ?? 0)) > 0.005) {
        throw new RuntimeException('This reservation has recorded payment history and cannot be permanently deleted. Cancel or archive it instead so the financial audit trail is preserved.');
    }

    $batchId = (int)($booking['batch_id'] ?? 0);
    if ($batchId > 0) {
        $batchPaymentStmt = $pdo->prepare('SELECT COUNT(*) FROM batch_payments WHERE batch_id=?');
        $batchPaymentStmt->execute([$batchId]);
        if ((int)$batchPaymentStmt->fetchColumn() > 0) {
            throw new RuntimeException('This reservation belongs to a batch with payment history. It cannot be permanently deleted without altering that batch payment audit trail.');
        }

        $batchCountStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE batch_id=?');
        $batchCountStmt->execute([$batchId]);
        if ((int)$batchCountStmt->fetchColumn() <= 1) {
            throw new RuntimeException('This is the last reservation in its batch. Delete the Batch Reservation instead.');
        }
    }

    $reference = (string)$booking['reference_no'];
    $deleteStmt = $pdo->prepare('DELETE FROM reservations WHERE id=?');
    $deleteStmt->execute([$reservationId]);

    if ($batchId > 0) {
        reservation_sync_batch_summary($pdo, $batchId);
    }

    $pdo->commit();
    flash('success', 'Reservation ' . $reference . ' was permanently deleted.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be deleted.');
}

redirect($returnTo);
