<?php
/**
 * FILE PURPOSE: Archives or restores eligible reservations while preserving history and payments.
 * DEBUGGING: Archive eligibility is intentionally strict for unsettled cancellations/refunds.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reservations.php');
}

verify_csrf();
$action = $_POST['action'] ?? '';
$reservationId = (int)($_POST['reservation_id'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'reservations.php'));

// Only permit local admin-page redirects.
if ($returnTo === '' || preg_match('~[\r\n]~', $returnTo) || parse_url($returnTo, PHP_URL_SCHEME) !== null || parse_url($returnTo, PHP_URL_HOST) !== null || str_starts_with($returnTo, '/') || str_starts_with($returnTo, '../')) {
    $returnTo = $action === 'restore' ? 'archives.php' : 'reservations.php';
}

try {
    if ($reservationId < 1 || !in_array($action, ['archive', 'restore'], true)) {
        throw new RuntimeException('Invalid archive request.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
    $stmt->execute([$reservationId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Reservation not found.');
    }

    if ($action === 'archive') {
        if (reservation_is_archived($booking)) {
            throw new RuntimeException('This reservation is already in Archives.');
        }
        if (!reservation_can_archive($booking)) {
            if ((string)($booking['status'] ?? '') === 'cancelled') {
                throw new RuntimeException('Cancelled reservations stay in the Cancelled section and are not moved to Archives.');
            }
            if (reservation_needs_resolution($booking)) {
                throw new RuntimeException('This reservation is still Pending / For Review after its event time. Resolve the event outcome first; it will not be completed automatically.');
            }
            throw new RuntimeException('Only completed/closed reservations, or an Approved reservation whose event has ended, can be marked Done.');
        }

        $previousStatus = (string)$booking['status'];
        $newStatus = reservation_archive_status($booking);
        $stmt = $pdo->prepare('UPDATE reservations SET status=?,archived_at=NOW(),archived_by=? WHERE id=?');
        $stmt->execute([$newStatus, current_admin()['id'], $reservationId]);
        $stmt = $pdo->prepare('INSERT INTO reservation_archives(reservation_id,archive_action,previous_status,new_status,notes,changed_by) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$reservationId, 'archived', $previousStatus, $newStatus, 'Marked Done and moved to Archives.', current_admin()['id']]);
        $message = 'Reservation ' . $booking['reference_no'] . ' was marked Done and moved to Archives.';
    } else {
        if (!reservation_is_archived($booking)) {
            throw new RuntimeException('This reservation is not archived.');
        }

        $historyStmt = $pdo->prepare("SELECT previous_status FROM reservation_archives WHERE reservation_id=? AND archive_action='archived' ORDER BY id DESC LIMIT 1");
        $historyStmt->execute([$reservationId]);
        $restoredStatus = (string)($historyStmt->fetchColumn() ?: $booking['status']);
        $allowedStatuses = ['pending', 'for_review', 'approved', 'rejected', 'cancelled', 'completed', 'no_show'];
        if (!in_array($restoredStatus, $allowedStatuses, true)) {
            $restoredStatus = 'completed';
        }

        $previousStatus = (string)$booking['status'];
        $stmt = $pdo->prepare('UPDATE reservations SET status=?,archived_at=NULL,archived_by=NULL WHERE id=?');
        $stmt->execute([$restoredStatus, $reservationId]);
        $stmt = $pdo->prepare('INSERT INTO reservation_archives(reservation_id,archive_action,previous_status,new_status,notes,changed_by) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$reservationId, 'restored', $previousStatus, $restoredStatus, 'Restored from Archives.', current_admin()['id']]);
        $message = 'Reservation ' . $booking['reference_no'] . ' was restored to the active reservation list.';
    }

    $pdo->commit();
    flash('success', $message);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The archive update could not be saved.');
}

redirect($returnTo);
