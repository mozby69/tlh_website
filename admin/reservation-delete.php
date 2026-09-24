<?php
/**
 * FILE PURPOSE: Password-confirmed permanent deletion for an erroneously encoded reservation.
 * SECURITY: Administrator-only, CSRF-protected, and requires the current administrator password.
 * FINANCE: Deletes the reservation's payment ledger rows so the erroneous collection no longer appears in Sales/Collections.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if (!is_admin()) {
    flash('danger', 'Administrator access is required to permanently delete reservations.');
    redirect('reservations.php');
}

$reservationId = (int)($_GET['id'] ?? $_POST['reservation_id'] ?? 0);
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'reservations.php'));

// Only allow a local admin-page redirect after the action.
if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = 'reservations.php';
}

if ($reservationId < 1) {
    flash('danger', 'Invalid reservation delete request.');
    redirect($returnTo);
}

$loadBooking = static function (int $id): array|false {
    $stmt = db()->prepare("SELECT r.*, b.batch_reference,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid,
        (SELECT COUNT(*) FROM payments p2 WHERE p2.reservation_id=r.id) AS payment_count
      FROM reservations r
      LEFT JOIN reservation_batches b ON b.id=r.batch_id
      WHERE r.id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
};

try {
    $booking = $loadBooking($reservationId);
} catch (Throwable $e) {
    $booking = false;
}

if (!$booking) {
    flash('danger', 'Reservation not found.');
    redirect($returnTo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string)($_POST['admin_password'] ?? '');
    $pdo = null;

    try {
        if ($password === '') {
            throw new RuntimeException('Enter your administrator password to confirm permanent deletion.');
        }

        $admin = current_admin();
        if (!$admin || (int)($admin['id'] ?? 0) < 1) {
            throw new RuntimeException('Your administrator session could not be verified.');
        }

        // Re-authenticate the administrator specifically for this destructive action.
        $accountStmt = db()->prepare('SELECT id, role, is_active, password_hash FROM admins WHERE id=? LIMIT 1');
        $accountStmt->execute([(int)$admin['id']]);
        $account = $accountStmt->fetch();
        if (!$account || empty($account['is_active']) || (string)$account['role'] !== 'admin' || !password_verify($password, (string)$account['password_hash'])) {
            throw new RuntimeException('Incorrect administrator password. The reservation was not deleted.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
        $bookingStmt->execute([$reservationId]);
        $lockedBooking = $bookingStmt->fetch();
        if (!$lockedBooking) {
            throw new RuntimeException('Reservation not found.');
        }

        $reference = (string)$lockedBooking['reference_no'];
        $batchId = (int)($lockedBooking['batch_id'] ?? 0);

        // Capture affected batch-payment masters before removing this reservation's ledger rows.
        // This lets us keep shared batch payments accurate for the remaining reservation dates.
        $batchPaymentIdsStmt = $pdo->prepare('SELECT DISTINCT batch_payment_id FROM payments WHERE reservation_id=? AND batch_payment_id IS NOT NULL FOR UPDATE');
        $batchPaymentIdsStmt->execute([$reservationId]);
        $affectedBatchPaymentIds = array_values(array_filter(array_map('intval', $batchPaymentIdsStmt->fetchAll(PDO::FETCH_COLUMN))));

        $paymentSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount),0) AS ledger_total FROM payments WHERE reservation_id=?');
        $paymentSummaryStmt->execute([$reservationId]);
        $paymentSummary = $paymentSummaryStmt->fetch() ?: ['payment_count' => 0, 'ledger_total' => 0];
        $removedPaymentCount = (int)$paymentSummary['payment_count'];
        $removedLedgerTotal = round((float)$paymentSummary['ledger_total'], 2);

        // Explicitly delete financial children first. The schema also uses cascades,
        // but doing this directly makes the intent clear and works safely on upgraded databases.
        $scopeDelete = $pdo->prepare('DELETE FROM batch_payment_scopes WHERE reservation_id=?');
        $scopeDelete->execute([$reservationId]);

        $paymentDelete = $pdo->prepare('DELETE FROM payments WHERE reservation_id=?');
        $paymentDelete->execute([$reservationId]);

        // Operational history (archives, cancellations, reschedules, extensions,
        // notifications, etc.) is removed by its ON DELETE CASCADE relationship.
        $deleteStmt = $pdo->prepare('DELETE FROM reservations WHERE id=?');
        $deleteStmt->execute([$reservationId]);
        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException('The reservation could not be deleted.');
        }

        // Reconcile any shared batch-payment master records. The individual payment
        // ledger is the financial source of truth, so the master amount must match the
        // allocations that still exist after this erroneous reservation is removed.
        foreach ($affectedBatchPaymentIds as $batchPaymentId) {
            $remainingStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS amount, COUNT(DISTINCT reservation_id) AS allocation_count FROM payments WHERE batch_payment_id=?');
            $remainingStmt->execute([$batchPaymentId]);
            $remaining = $remainingStmt->fetch() ?: ['amount' => 0, 'allocation_count' => 0];
            $remainingAmount = round((float)$remaining['amount'], 2);
            $remainingAllocationCount = (int)$remaining['allocation_count'];

            if (abs($remainingAmount) <= 0.005 || $remainingAllocationCount < 1) {
                $deleteMaster = $pdo->prepare('DELETE FROM batch_payments WHERE id=?');
                $deleteMaster->execute([$batchPaymentId]);
                continue;
            }

            $scopeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM batch_payment_scopes WHERE batch_payment_id=?');
            $scopeCountStmt->execute([$batchPaymentId]);
            $remainingScopeCount = (int)$scopeCountStmt->fetchColumn();

            $updateMaster = $pdo->prepare('UPDATE batch_payments SET amount=?, allocation_count=?, coverage_count=?, coverage_label=NULL WHERE id=?');
            $updateMaster->execute([$remainingAmount, $remainingAllocationCount, $remainingScopeCount, $batchPaymentId]);
        }

        if ($batchId > 0) {
            $batchCountStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE batch_id=?');
            $batchCountStmt->execute([$batchId]);
            $remainingBatchReservations = (int)$batchCountStmt->fetchColumn();

            if ($remainingBatchReservations < 1) {
                // If the deleted reservation was the final occurrence, remove the now-empty
                // batch header as well. Any remaining batch masters cascade from this delete.
                $deleteBatch = $pdo->prepare('DELETE FROM reservation_batches WHERE id=?');
                $deleteBatch->execute([$batchId]);
            } else {
                reservation_sync_batch_summary($pdo, $batchId);
            }
        }

        $pdo->commit();

        $message = 'Reservation ' . $reference . ' was permanently deleted.';
        if ($removedPaymentCount > 0) {
            $message .= ' ' . $removedPaymentCount . ' payment transaction' . ($removedPaymentCount === 1 ? '' : 's') . ' totaling ' . money(abs($removedLedgerTotal)) . ' net were also removed from the ledger and will no longer appear in Sales/Collections.';
        }
        flash('success', $message);
        redirect($returnTo);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The reservation could not be deleted.');
        redirect('reservation-delete.php?id=' . $reservationId . '&return_to=' . rawurlencode($returnTo));
    }
}

$ledgerTotal = round((float)($booking['ledger_amount_paid'] ?? 0), 2);
$paymentCount = (int)($booking['payment_count'] ?? 0);
$adminPageTitle = 'Delete Reservation';
include __DIR__ . '/_header.php';
?>
<div class="admin-grid reservation-delete-grid">
  <section class="panel reservation-delete-panel">
    <div class="panel-head">
      <div>
        <span class="small muted">Reservation <?= e($booking['reference_no']) ?></span>
        <h2>Permanent Delete</h2>
      </div>
      <a class="btn btn-outline btn-sm" href="reservation-view.php?id=<?= $reservationId ?>">Back to Reservation</a>
    </div>

    <div class="alert alert-danger reservation-delete-warning">
      <strong>This action cannot be undone.</strong>
      The reservation and all of its payment history will be permanently removed. Any payment amount attached to this reservation will also be removed from Sales and Collections reports.
    </div>

    <div class="reservation-delete-summary">
      <div><span>Client</span><strong><?= e($booking['client_name']) ?></strong></div>
      <div><span>Event</span><strong><?= e(date('M j, Y g:i A', strtotime((string)$booking['event_start']))) ?></strong></div>
      <div><span>Status</span><strong><?= e(ucwords(str_replace('_', ' ', (string)$booking['status']))) ?></strong></div>
      <div><span>Reservation Total</span><strong><?= money(reservation_payment_target($booking)) ?></strong></div>
      <div><span>Payment Transactions</span><strong><?= $paymentCount ?></strong></div>
      <div class="reservation-delete-sales-impact"><span>Net Amount Removed from Sales</span><strong><?= money($ledgerTotal) ?></strong></div>
      <?php if (!empty($booking['batch_id'])): ?>
        <div><span>Batch</span><strong><?= e($booking['batch_reference'] ?: ('Batch #' . (int)$booking['batch_id'])) ?></strong></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($booking['batch_id'])): ?>
      <div class="alert alert-info">If this reservation is part of a batch, only this reservation's payment allocation will be removed. Shared batch payments for the other reservation dates will be automatically recalculated.</div>
    <?php endif; ?>

    <form method="post" class="reservation-delete-form" data-permanent-delete-form>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="reservation_id" value="<?= $reservationId ?>">
      <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

      <div class="form-group">
        <label for="deleteAdminPassword">Administrator Password</label>
        <input id="deleteAdminPassword" type="password" name="admin_password" autocomplete="current-password" required autofocus>
        <span class="field-help">Enter the password of the administrator account currently signed in.</span>
      </div>

      <div class="reservation-delete-actions">
        <a class="btn btn-outline" href="reservation-view.php?id=<?= $reservationId ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">Permanently Delete Reservation &amp; Payment History</button>
      </div>
    </form>
  </section>
</div>

<script>
(function () {
  const form = document.querySelector('[data-permanent-delete-form]');
  if (!form) return;
  form.addEventListener('submit', function (event) {
    const message = 'Permanently delete reservation <?= e($booking['reference_no']) ?> and all of its payment history? This will remove the related amount from Sales/Collections and cannot be undone.';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });
}());
</script>
<?php include __DIR__ . '/_footer.php'; ?>
