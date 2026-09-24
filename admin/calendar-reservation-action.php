<?php
/**
 * FILE PURPOSE: Authenticated JSON endpoint for quick status and payment actions from the admin calendar.
 * DEBUGGING: Approval performs a fresh conflict check under the shared venue lock. Payment writes must use the ledger and protect against overpayment.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

header('Content-Type: application/json; charset=utf-8');

function calendar_action_response(bool $ok, string $message, array $extra = [], int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_calendar_viewer()) {
    calendar_action_response(false, 'This account has read-only Calendar access.', [], 403);
}

function calendar_action_booking_payload(array $booking): array
{
    $target = reservation_payment_target($booking);
    $paid = round((float)($booking['amount_paid'] ?? 0), 2);
    $remaining = reservation_remaining_balance($booking);
    $excess = reservation_excess_credit($booking);
    $status = (string)($booking['status'] ?? 'pending');
    $paymentStatus = (string)($booking['payment_status'] ?? payment_status_for_amount($paid, $target));
    $hasEnded = reservation_has_ended($booking);
    $canLateExtend = reservation_can_late_extend($booking);
    $needsResolution = reservation_needs_resolution($booking);
    $standardPaymentAllowed = reservation_can_receive_payment($booking);
    $lockMessage = $needsResolution
        ? 'The event time passed while this reservation was still Pending / For Review. It is not Completed. Open the reservation and explicitly resolve the event outcome.'
        : ($status === 'cancelled'
            ? 'Cancelled reservations are locked. Use Reservation Details for any cancellation settlement, or rebook to create a new active reservation.'
            : ($hasEnded
                ? ($canLateExtend
                    ? 'The booked end time has passed. Editing/rescheduling are locked, but a Late Extension can be recorded if the client actually used additional time.'
                    : ($standardPaymentAllowed
                        ? 'The booked end time has passed. Operational changes are locked; payment recording remains available for any legitimate balance due.'
                        : 'The booked end time has passed. Operational changes are locked and this status is not eligible for standard payment collection.'))
                : ''));

    return [
        'reservation_id' => (int)$booking['id'],
        'status_value' => $status,
        'status_label' => ucwords(str_replace('_', ' ', $status)),
        'status_class' => badge_class($status),
        'holds_calendar' => reservation_holds_calendar($booking),
        'has_ended' => $hasEnded,
        'calendar_hold' => $needsResolution ? 'Needs resolution · Event time passed while Pending' : ($hasEnded ? ($canLateExtend ? 'Event time passed · Late Extension available' : 'Event time passed · Operational changes locked') : (reservation_holds_calendar($booking) ? 'Schedule secured' : 'Pending / inactive · Not holding slot')),
        'payment_status' => ucfirst($paymentStatus),
        'payment_status_class' => badge_class($paymentStatus),
        'total' => money($target),
        'paid' => money($paid),
        'balance' => money($excess > 0 ? $excess : $remaining),
        'balance_label' => $status === 'cancelled'
            ? ($excess > 0 ? 'Refund Pending' : 'Cancellation Balance')
            : ($excess > 0 ? 'Excess Payment Credit' : 'Balance'),
        'remaining_balance' => round($remaining, 2),
        'can_add_payment' => $remaining > 0.001 && reservation_can_receive_payment($booking),
        'can_update_status' => reservation_can_update_status($booking),
        'lock_message' => $lockMessage,
        'edit_url' => reservation_can_edit($booking) ? 'reservation-edit.php?id=' . (int)$booking['id'] : '',
        'reschedule_url' => reservation_can_reschedule($booking) ? 'reservation-reschedule.php?id=' . (int)$booking['id'] : '',
        'extend_url' => reservation_can_record_extension($booking) ? 'reservation-extend.php?id=' . (int)$booking['id'] : '',
        'extend_label' => $canLateExtend ? 'Late Extension' : 'Extend',
        'cancel_url' => reservation_can_cancel($booking) ? 'reservation-cancel.php?id=' . (int)$booking['id'] : '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    calendar_action_response(false, 'This action requires a POST request.', [], 405);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    calendar_action_response(false, 'Your session token has expired. Refresh the calendar and try again.', [], 419);
}

$id = (int)($_POST['reservation_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($id < 1) {
    calendar_action_response(false, 'Reservation not found.', [], 404);
}

$pdo = null;
$approvalLockAcquired = false;

// Quick calendar actions still enforce the same server-side business rules as
// the full Reservation Details page; the modal is not a privileged shortcut.
try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.id=?');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new RuntimeException('Reservation not found.');
    }
    if (reservation_is_archived($booking) && $action !== 'add_payment') {
        throw new RuntimeException('Restore this reservation from Archives before changing its operational status. Financial payments may still be recorded while archived.');
    }

    if ($action === 'update_status') {
        if (reservation_has_ended($booking)) {
            throw new RuntimeException('This reservation has already ended. Operational details and status are locked; payments may still be recorded.');
        }
        $status = (string)($_POST['status'] ?? '');
        $allowed = ['pending', 'for_review', 'approved', 'completed', 'rejected', 'no_show'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Choose a valid reservation status.');
        }
        if ((string)$booking['status'] === 'cancelled') {
            throw new RuntimeException('A cancelled reservation cannot be reactivated here. Use the rebook action instead.');
        }

        if ($status === 'approved' && (string)$booking['status'] !== 'approved') {
            $approvalLockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
            if (!$approvalLockAcquired) {
                throw new RuntimeException('The booking calendar is busy. Please try approving the reservation again.');
            }
            if (reservation_conflict((string)$booking['blocked_start'], (string)$booking['blocked_end'], $id)) {
                $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
                $approvalLockAcquired = false;
                throw new RuntimeException('This request cannot be approved because another approved or staff-held reservation overlaps the selected schedule. Reschedule or reject this request first.');
            }
        }

        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
        $lockStmt->execute([$id]);
        $current = $lockStmt->fetch();
        if (!$current || reservation_is_archived($current)) {
            throw new RuntimeException('This reservation is no longer available for updating.');
        }
        if (reservation_has_ended($current)) {
            throw new RuntimeException('This reservation ended while the update was being processed. Operational changes are now locked.');
        }
        if ((string)$current['status'] === 'cancelled') {
            throw new RuntimeException('A cancelled reservation cannot be reactivated here.');
        }

        $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
        $totalStmt->execute([$id]);
        $totalPaid = round((float)$totalStmt->fetchColumn(), 2);
        $target = reservation_payment_target($current);
        $paymentStatus = payment_status_for_amount($totalPaid, $target);

        $update = $pdo->prepare('UPDATE reservations SET status=?, amount_paid=?, payment_status=? WHERE id=?');
        $update->execute([$status, $totalPaid, $paymentStatus, $id]);
        $pdo->commit();

        if ($approvalLockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
            $approvalLockAcquired = false;
        }

        $message = $status === 'approved'
            ? 'Reservation approved. The schedule is now secured on the calendar.'
            : 'Reservation status updated to ' . ucwords(str_replace('_', ' ', $status)) . '.';
        $title = $status === 'approved' ? 'Reservation Approved' : 'Status Updated';
    } elseif ($action === 'add_payment') {
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $method = trim((string)($_POST['payment_method'] ?? ''));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $notes = trim((string)($_POST['payment_notes'] ?? ''));
        $paidAtRaw = trim((string)($_POST['paid_at'] ?? ''));

        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            throw new RuntimeException('Enter a valid payment amount.');
        }
        $amount = round((float)$amountRaw, 2);
        if (!in_array($method, booking_payment_methods(), true)) {
            throw new RuntimeException('Choose a valid payment method.');
        }
        if ($method !== 'Cash' && $reference === '') {
            throw new RuntimeException('Enter a transaction reference or OR number for non-cash payments.');
        }
        if (strlen($reference) > 120) {
            throw new RuntimeException('The payment reference may not exceed 120 characters.');
        }
        try {
            $paidAt = $paidAtRaw !== '' ? new DateTimeImmutable($paidAtRaw) : new DateTimeImmutable('now');
        } catch (Throwable $e) {
            throw new RuntimeException('Enter a valid payment date and time.');
        }

        $pdo->beginTransaction();
        $bookingStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
        $bookingStmt->execute([$id]);
        $current = $bookingStmt->fetch();
        if (!$current) {
            throw new RuntimeException('Reservation not found.');
        }
        $paymentEligibility = reservation_payment_eligibility($current);
        if (!$paymentEligibility['allowed']) {
            throw new RuntimeException($paymentEligibility['message']);
        }
        $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id=?');
        $totalStmt->execute([$id]);
        $currentTotal = round((float)$totalStmt->fetchColumn(), 2);
        $target = reservation_payment_target($current);
        $remaining = max(0, round($target - $currentTotal, 2));
        if ($remaining <= 0.001) {
            throw new RuntimeException('This reservation has no remaining balance to collect.');
        }
        if ($amount > $remaining + 0.001) {
            throw new RuntimeException('The payment cannot exceed the remaining balance of ' . money($remaining) . '.');
        }

        $insert = $pdo->prepare('INSERT INTO payments(reservation_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?)');
        $insert->execute([$id, $amount, $method, $reference !== '' ? $reference : null, $notes, (int)current_admin()['id'], $paidAt->format('Y-m-d H:i:s')]);

        $newTotal = round($currentTotal + $amount, 2);
        $paymentStatus = payment_status_for_amount($newTotal, $target);
        $update = $pdo->prepare('UPDATE reservations SET amount_paid=?, payment_status=? WHERE id=?');
        $update->execute([$newTotal, $paymentStatus, $id]);
        $pdo->commit();

        $message = 'Payment of ' . money($amount) . ' recorded successfully.';
        $title = 'Payment Recorded';
    } else {
        throw new RuntimeException('Unsupported calendar action.');
    }

    $stmt = $pdo->prepare('SELECT r.*, b.batch_reference FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE r.id=?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    if (!$updated) {
        throw new RuntimeException('Reservation could not be refreshed after the update.');
    }

    calendar_action_response(true, $message, [
        'title' => $title,
        'reservation' => calendar_action_booking_payload($updated),
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($approvalLockAcquired && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')");
        } catch (Throwable $ignore) {
        }
    }
    calendar_action_response(false, $e instanceof RuntimeException ? $e->getMessage() : 'The calendar update could not be saved.', [], 422);
}
