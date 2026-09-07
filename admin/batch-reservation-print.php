<?php
/**
 * FILE PURPOSE: Loads one batch and prepares the printable batch reservation document.
 * DEBUGGING: The print layout itself is in includes/batch-reservation-print-document.php.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$batchId = (int)($_GET['id'] ?? 0);
if ($batchId < 1) {
    flash('danger', 'Batch reservation not found.');
    redirect('reservations.php?view=batches');
}

try {
    $stmt = db()->prepare('SELECT b.*, a.full_name AS created_by_name FROM reservation_batches b LEFT JOIN admins a ON a.id=b.created_by WHERE b.id=? LIMIT 1');
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch();
    if (!$batch) {
        throw new RuntimeException('Batch reservation not found.');
    }

    $stmt = db()->prepare("SELECT r.*, c.id AS cancellation_record_id, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
        FROM reservations r
        LEFT JOIN reservation_cancellations c ON c.reservation_id=r.id
        WHERE r.batch_id=?
        ORDER BY r.event_start ASC, r.id ASC");
    $stmt->execute([$batchId]);
    $items = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT bp.*, a.full_name AS recorded_by_name FROM batch_payments bp LEFT JOIN admins a ON a.id=bp.recorded_by WHERE bp.batch_id=? ORDER BY bp.paid_at ASC, bp.id ASC');
    $stmt->execute([$batchId]);
    $batchPayments = $stmt->fetchAll();

    $scopeMap = [];
    if ($batchPayments) {
        try {
            $batchPaymentIds = array_map(static fn(array $row): int => (int)$row['id'], $batchPayments);
            $scopePlaceholders = implode(',', array_fill(0, count($batchPaymentIds), '?'));
            $scopeStmt = db()->prepare("SELECT bps.*, COALESCE(bps.reservation_reference,r.reference_no) AS reference_no, COALESCE(bps.event_start_snapshot,r.event_start) AS event_start, COALESCE(bps.event_end_snapshot,r.event_end) AS event_end, r.batch_occurrence
                FROM batch_payment_scopes bps
                INNER JOIN reservations r ON r.id=bps.reservation_id
                WHERE bps.batch_payment_id IN ($scopePlaceholders)
                ORDER BY COALESCE(bps.event_start_snapshot,r.event_start) ASC, r.id ASC");
            $scopeStmt->execute($batchPaymentIds);
            foreach ($scopeStmt->fetchAll() as $scopeRow) {
                $scopeMap[(int)$scopeRow['batch_payment_id']][] = $scopeRow;
            }
        } catch (Throwable $e) {
            $scopeMap = [];
        }
    }
    foreach ($batchPayments as &$batchPayment) {
        $batchPayment['_scopes'] = $scopeMap[(int)$batchPayment['id']] ?? [];
    }
    unset($batchPayment);

    $stmt = db()->prepare("SELECT p.*, r.reference_no, r.batch_occurrence
        FROM payments p
        INNER JOIN reservations r ON r.id=p.reservation_id
        WHERE r.batch_id=? AND p.batch_payment_id IS NULL
        ORDER BY p.paid_at ASC, p.id ASC");
    $stmt->execute([$batchId]);
    $individualPayments = $stmt->fetchAll();
} catch (Throwable $e) {
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The printable batch reservation could not be loaded.');
    redirect('reservations.php?view=batches');
}

$admin = current_admin();
$printAssetPrefix = '../';
$printBackUrl = 'batch-reservation-view.php?id=' . $batchId;
$printPreparedBy = $admin['name'] ?? $batch['created_by_name'] ?? 'The Leisure Hub Administration';
require __DIR__ . '/../includes/batch-reservation-print-document.php';
