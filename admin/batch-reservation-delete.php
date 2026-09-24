<?php
/**
 * FILE PURPOSE: Password-confirmed permanent deletion for an erroneously encoded batch reservation.
 * SECURITY: Administrator-only, CSRF-protected, and requires the current administrator password.
 * FINANCE: Deletes every connected reservation and payment ledger row so erroneous amounts no longer appear in Sales/Collections.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if (!is_admin()) {
    flash('danger', 'Administrator access is required to permanently delete batch reservations.');
    redirect('reservations.php?view=batches');
}

$batchId = (int)($_GET['id'] ?? $_POST['batch_id'] ?? 0);
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'reservations.php?view=batches'));

if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = 'reservations.php?view=batches';
}

if ($batchId < 1) {
    flash('danger', 'Invalid batch delete request.');
    redirect($returnTo);
}

$loadBatch = static function (int $id): array|false {
    $stmt = db()->prepare("SELECT b.*,
        (SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id) AS reservation_count,
        COALESCE((SELECT SUM(p.amount) FROM payments p INNER JOIN reservations r2 ON r2.id=p.reservation_id WHERE r2.batch_id=b.id),0) AS ledger_total,
        (SELECT COUNT(*) FROM payments p2 INNER JOIN reservations r3 ON r3.id=p2.reservation_id WHERE r3.batch_id=b.id) AS payment_count,
        (SELECT COUNT(*) FROM batch_payments bp WHERE bp.batch_id=b.id) AS batch_payment_count
      FROM reservation_batches b
      WHERE b.id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
};

try {
    $batch = $loadBatch($batchId);
} catch (Throwable $e) {
    $batch = false;
}

if (!$batch) {
    flash('danger', 'Batch reservation not found.');
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

        $accountStmt = db()->prepare('SELECT id, role, is_active, password_hash FROM admins WHERE id=? LIMIT 1');
        $accountStmt->execute([(int)$admin['id']]);
        $account = $accountStmt->fetch();
        if (!$account || empty($account['is_active']) || (string)$account['role'] !== 'admin' || !password_verify($password, (string)$account['password_hash'])) {
            throw new RuntimeException('Incorrect administrator password. The batch reservation was not deleted.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        $batchStmt = $pdo->prepare('SELECT * FROM reservation_batches WHERE id=? FOR UPDATE');
        $batchStmt->execute([$batchId]);
        $lockedBatch = $batchStmt->fetch();
        if (!$lockedBatch) {
            throw new RuntimeException('Batch reservation not found.');
        }

        $childStmt = $pdo->prepare('SELECT id, reference_no FROM reservations WHERE batch_id=? ORDER BY id FOR UPDATE');
        $childStmt->execute([$batchId]);
        $children = $childStmt->fetchAll();
        $childIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $children));

        $removedPaymentCount = 0;
        $removedLedgerTotal = 0.0;

        if ($childIds) {
            $placeholders = implode(',', array_fill(0, count($childIds), '?'));

            $paymentSummaryStmt = $pdo->prepare("SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount),0) AS ledger_total FROM payments WHERE reservation_id IN ($placeholders)");
            $paymentSummaryStmt->execute($childIds);
            $paymentSummary = $paymentSummaryStmt->fetch() ?: ['payment_count' => 0, 'ledger_total' => 0];
            $removedPaymentCount = (int)$paymentSummary['payment_count'];
            $removedLedgerTotal = round((float)$paymentSummary['ledger_total'], 2);

            // Remove batch scope rows and ledger rows explicitly before deleting the reservations.
            // This makes the financial effect immediate and clear even on upgraded databases.
            $scopeDelete = $pdo->prepare("DELETE FROM batch_payment_scopes WHERE reservation_id IN ($placeholders)");
            $scopeDelete->execute($childIds);

            $paymentDelete = $pdo->prepare("DELETE FROM payments WHERE reservation_id IN ($placeholders)");
            $paymentDelete->execute($childIds);

            $deleteReservations = $pdo->prepare('DELETE FROM reservations WHERE batch_id=?');
            $deleteReservations->execute([$batchId]);
        }

        // Remove batch-level payment masters after their child allocations are gone.
        // Any remaining scope rows are also removed by ON DELETE CASCADE.
        $deleteBatchPayments = $pdo->prepare('DELETE FROM batch_payments WHERE batch_id=?');
        $deleteBatchPayments->execute([$batchId]);

        $deleteBatch = $pdo->prepare('DELETE FROM reservation_batches WHERE id=?');
        $deleteBatch->execute([$batchId]);
        if ($deleteBatch->rowCount() !== 1) {
            throw new RuntimeException('The batch reservation could not be deleted.');
        }

        $pdo->commit();

        $message = 'Batch ' . (string)$lockedBatch['batch_reference'] . ' and ' . count($children) . ' connected reservation date' . (count($children) === 1 ? '' : 's') . ' were permanently deleted.';
        if ($removedPaymentCount > 0) {
            $message .= ' ' . $removedPaymentCount . ' payment transaction' . ($removedPaymentCount === 1 ? '' : 's') . ' totaling ' . money(abs($removedLedgerTotal)) . ' net were also removed from the ledger and will no longer appear in Sales/Collections.';
        }
        flash('success', $message);
        redirect($returnTo);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The batch reservation could not be deleted.');
        redirect('batch-reservation-delete.php?id=' . $batchId . '&return_to=' . rawurlencode($returnTo));
    }
}

$ledgerTotal = round((float)($batch['ledger_total'] ?? 0), 2);
$paymentCount = (int)($batch['payment_count'] ?? 0);
$reservationCount = (int)($batch['reservation_count'] ?? 0);
$batchPaymentCount = (int)($batch['batch_payment_count'] ?? 0);
$adminPageTitle = 'Delete Batch Reservation';
include __DIR__ . '/_header.php';
?>
<div class="admin-grid reservation-delete-grid">
  <section class="panel reservation-delete-panel">
    <div class="panel-head">
      <div>
        <span class="small muted">Batch <?= e($batch['batch_reference']) ?></span>
        <h2>Permanent Delete</h2>
      </div>
      <a class="btn btn-outline btn-sm" href="batch-reservation-view.php?id=<?= $batchId ?>">Back to Batch</a>
    </div>

    <div class="alert alert-danger reservation-delete-warning">
      <strong>This action cannot be undone.</strong>
      The entire batch, all connected reservation dates, and all of their payment history will be permanently removed. The related payment amount will also be removed from Sales and Collections reports.
    </div>

    <div class="reservation-delete-summary">
      <div><span>Client</span><strong><?= e($batch['client_name']) ?></strong></div>
      <div><span>Batch</span><strong><?= e($batch['batch_reference']) ?></strong></div>
      <div><span>Date Range</span><strong><?= e(date('M j, Y', strtotime((string)$batch['range_start']))) ?> – <?= e(date('M j, Y', strtotime((string)$batch['range_end']))) ?></strong></div>
      <div><span>Reservation Dates</span><strong><?= $reservationCount ?></strong></div>
      <div><span>Batch Payment Records</span><strong><?= $batchPaymentCount ?></strong></div>
      <div><span>Payment Transactions</span><strong><?= $paymentCount ?></strong></div>
      <div class="reservation-delete-sales-impact"><span>Net Amount Removed from Sales/Collections</span><strong><?= money($ledgerTotal) ?></strong></div>
    </div>

    <form method="post" class="reservation-delete-form" data-permanent-delete-form>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

      <div class="form-group">
        <label for="deleteBatchAdminPassword">Administrator Password</label>
        <input id="deleteBatchAdminPassword" type="password" name="admin_password" autocomplete="current-password" required autofocus>
        <span class="field-help">Enter the password of the administrator account currently signed in.</span>
      </div>

      <div class="reservation-delete-actions">
        <a class="btn btn-outline" href="batch-reservation-view.php?id=<?= $batchId ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">Permanently Delete Batch &amp; Payment History</button>
      </div>
    </form>
  </section>
</div>

<script>
(function () {
  const form = document.querySelector('[data-permanent-delete-form]');
  if (!form) return;
  form.addEventListener('submit', function (event) {
    const message = 'Permanently delete batch <?= e($batch['batch_reference']) ?>, all <?= $reservationCount ?> connected reservation date<?= $reservationCount === 1 ? '' : 's' ?>, and all payment history? This will remove the related amount from Sales/Collections and cannot be undone.';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });
}());
</script>
<?php include __DIR__ . '/_footer.php'; ?>
