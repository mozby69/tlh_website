<?php
/**
 * FILE PURPOSE: Permanently deletes an erroneous batch and all of its connected reservation dates.
 * SECURITY: Administrator-only, POST-only, CSRF-protected, and blocked when financial history exists.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if (!is_admin()) {
    flash('danger', 'Administrator access is required to permanently delete batch reservations.');
    redirect('reservations.php?view=batches');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reservations.php?view=batches');
}

verify_csrf();
$batchId = (int)($_POST['batch_id'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'reservations.php?view=batches'));

if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = 'reservations.php?view=batches';
}

try {
    if ($batchId < 1) {
        throw new RuntimeException('Invalid batch delete request.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $batchStmt = $pdo->prepare('SELECT * FROM reservation_batches WHERE id=? FOR UPDATE');
    $batchStmt->execute([$batchId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        throw new RuntimeException('Batch reservation not found.');
    }

    $childStmt = $pdo->prepare('SELECT id, reference_no, amount_paid FROM reservations WHERE batch_id=? ORDER BY id FOR UPDATE');
    $childStmt->execute([$batchId]);
    $children = $childStmt->fetchAll();
    $childIds = array_map(static fn(array $row): int => (int)$row['id'], $children);

    $batchPaymentStmt = $pdo->prepare('SELECT COUNT(*) FROM batch_payments WHERE batch_id=?');
    $batchPaymentStmt->execute([$batchId]);
    if ((int)$batchPaymentStmt->fetchColumn() > 0) {
        throw new RuntimeException('This batch has recorded batch payments and cannot be permanently deleted. Preserve it for financial audit history.');
    }

    if ($childIds) {
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));
        $paymentStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reservation_id IN ($placeholders)");
        $paymentStmt->execute($childIds);
        if ((int)$paymentStmt->fetchColumn() > 0) {
            throw new RuntimeException('One or more reservations in this batch have recorded payments. The batch cannot be permanently deleted.');
        }

        $scopeStmt = $pdo->prepare("SELECT COUNT(*) FROM batch_payment_scopes WHERE reservation_id IN ($placeholders)");
        $scopeStmt->execute($childIds);
        if ((int)$scopeStmt->fetchColumn() > 0) {
            throw new RuntimeException('This batch contains reservation dates referenced by batch payment history and cannot be permanently deleted.');
        }

        foreach ($children as $child) {
            if (abs((float)($child['amount_paid'] ?? 0)) > 0.005) {
                throw new RuntimeException('One or more reservations in this batch show a paid amount. The batch cannot be permanently deleted.');
            }
        }
    }

    // Reservation history tables use ON DELETE CASCADE, so deleting the children
    // first removes their non-financial operational history without leaving orphans.
    $deleteReservations = $pdo->prepare('DELETE FROM reservations WHERE batch_id=?');
    $deleteReservations->execute([$batchId]);

    $deleteBatch = $pdo->prepare('DELETE FROM reservation_batches WHERE id=?');
    $deleteBatch->execute([$batchId]);

    $pdo->commit();
    flash('success', 'Batch ' . (string)$batch['batch_reference'] . ' and ' . count($children) . ' connected reservation date' . (count($children) === 1 ? '' : 's') . ' were permanently deleted.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The batch reservation could not be deleted.');
}

redirect($returnTo);
