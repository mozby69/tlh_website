<?php
/**
 * FILE PURPOSE: Admin-only live conflict checker for rescheduling and extending an existing reservation.
 * DEBUGGING: This endpoint excludes the current reservation from overlap checks. Final POST handlers still acquire the shared venue lock and repeat the authoritative conflict check.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function admin_conflict_response(bool $ok, string $status, string $message, array $extra = []): void
{
    http_response_code($ok ? 200 : 422);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$bookingId = (int)($_GET['booking_id'] ?? 0);
$mode = trim((string)($_GET['mode'] ?? ''));
if ($bookingId < 1 || !in_array($mode, ['reschedule', 'extend'], true)) {
    admin_conflict_response(false, 'invalid', 'Choose a valid reservation and action.');
}

try {
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id=? LIMIT 1');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Live conflict checking is temporarily unavailable. The schedule will still be checked again when you submit.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$booking) {
    admin_conflict_response(false, 'invalid', 'Reservation not found.');
}
if (reservation_is_archived($booking)) {
    admin_conflict_response(false, 'invalid', 'Archived reservations cannot be changed.');
}

try {
    if ($mode === 'reschedule') {
        if (reservation_has_ended($booking)) {
            admin_conflict_response(false, 'invalid', 'This reservation has already ended and can no longer be rescheduled.');
        }
        if (!in_array($booking['status'], reschedulable_reservation_statuses(), true)) {
            admin_conflict_response(false, 'invalid', 'This reservation can no longer be rescheduled.');
        }

        $date = trim((string)($_GET['date'] ?? ''));
        $startTime = trim((string)($_GET['start_time'] ?? ''));
        $endTime = trim((string)($_GET['end_time'] ?? ''));
        $startSlots = reservation_start_time_slots();
        $endSlots = reservation_end_time_slots();
        // Older reservations may contain a legacy whole-minute time that is not
        // part of today's standard dropdown. The Reschedule form preserves that
        // stored option, so the live checker mirrors the same allowance.
        $storedStartTime = (new DateTimeImmutable((string)$booking['event_start']))->format('H:i');
        $storedEndTime = (new DateTimeImmutable((string)$booking['event_end']))->format('H:i');
        if (!array_key_exists($storedStartTime, $startSlots)) {
            $startSlots[$storedStartTime] = $storedStartTime;
        }
        if (!array_key_exists($storedEndTime, $endSlots)) {
            $endSlots[$storedEndTime] = $storedEndTime;
        }

        if (!reservation_date_is_valid($date)) {
            admin_conflict_response(false, 'invalid', 'Choose a valid reservation date.');
        }
        if (!array_key_exists($startTime, $startSlots)) {
            admin_conflict_response(false, 'invalid', 'Choose a valid starting time.');
        }
        if (!array_key_exists($endTime, $endSlots)) {
            admin_conflict_response(false, 'invalid', 'Choose a valid ending time.');
        }

        $newStart = new DateTimeImmutable($date . ' ' . $startTime . ':00');
        $newEnd = new DateTimeImmutable($date . ' ' . $endTime . ':00');
        if ($newStart >= $newEnd) {
            admin_conflict_response(false, 'invalid', 'End time must be later than start time.');
        }
        if ($newStart <= new DateTimeImmutable()) {
            admin_conflict_response(false, 'invalid', 'Choose a future reservation date and time.');
        }
        if ($newEnd->format('Y-m-d') !== $date || $newEnd->format('H:i') > '22:00') {
            admin_conflict_response(false, 'invalid', 'This schedule extends beyond the 10:00 PM closing time.');
        }

        $currentStart = new DateTimeImmutable((string)$booking['event_start']);
        $currentEnd = new DateTimeImmutable((string)$booking['event_end']);
        if ($newStart->format('Y-m-d H:i:s') === $currentStart->format('Y-m-d H:i:s')
            && $newEnd->format('Y-m-d H:i:s') === $currentEnd->format('Y-m-d H:i:s')) {
            admin_conflict_response(false, 'invalid', 'Choose a different date or time from the current schedule.');
        }

        $blockedStart = $newStart->modify('-' . max(0, (int)$booking['setup_minutes']) . ' minutes');
        $blockedEnd = $newEnd->modify('+' . max(0, (int)$booking['cleanup_minutes']) . ' minutes');
        $conflict = reservation_conflict(
            $blockedStart->format('Y-m-d H:i:s'),
            $blockedEnd->format('Y-m-d H:i:s'),
            $bookingId
        );

        if ($conflict) {
            admin_conflict_response(true, 'unavailable', 'This schedule overlaps another active reservation, including setup or cleanup time.', [
                'available' => false,
            ]);
        }

        admin_conflict_response(true, 'available', 'No conflict found for the proposed reschedule. The schedule will be checked again when you confirm.', [
            'available' => true,
        ]);
    }

    $now = new DateTimeImmutable();
    $isLateExtension = reservation_has_ended($booking, $now);
    if ($isLateExtension) {
        if (!reservation_can_late_extend($booking)) {
            $message = in_array((string)($booking['status'] ?? ''), ['pending', 'for_review'], true)
                ? 'Resolve this lapsed reservation first before recording a late extension.'
                : 'This reservation is not eligible for a late extension.';
            admin_conflict_response(false, 'invalid', $message);
        }
    } elseif (!reservation_can_extend($booking)) {
        admin_conflict_response(false, 'invalid', 'This reservation can no longer be extended.');
    }

    $hoursRaw = trim((string)($_GET['extension_hours'] ?? ''));
    $hours = reservation_duration_hours_from_input($hoursRaw);
    $maxHours = reservation_max_extension_hours($booking);
    if ($hours === null || $hours > $maxHours) {
        admin_conflict_response(false, 'invalid', 'Choose a valid 30-minute extension.');
    }

    $currentEnd = new DateTimeImmutable((string)$booking['event_end']);
    $newEnd = reservation_add_duration($currentEnd, $hours);
    $blockedEnd = $newEnd->modify('+' . max(0, (int)$booking['cleanup_minutes']) . ' minutes');
    $analysis = reservation_extension_conflict_analysis($booking, $blockedEnd, $now, $isLateExtension);
    $futureConflicts = $analysis['future'] ?? [];
    $historicalConflicts = $analysis['historical'] ?? [];

    if ($futureConflicts) {
        $summary = reservation_extension_conflict_summary($futureConflicts);
        admin_conflict_response(true, 'unavailable', 'This extension overlaps another reservation in time that has not fully passed' . ($summary !== '' ? ': ' . $summary : '') . '.', [
            'available' => false,
            'late_extension' => $isLateExtension,
            'conflict_summary' => $summary,
        ]);
    }

    if ($historicalConflicts) {
        $summary = reservation_extension_conflict_summary($historicalConflicts);
        if (!is_admin()) {
            admin_conflict_response(true, 'unavailable', 'Historical overlap detected' . ($summary !== '' ? ': ' . $summary : '') . '. Only an Administrator can acknowledge and record this late extension.', [
                'available' => false,
                'late_extension' => true,
                'historical_conflict' => true,
                'conflict_summary' => $summary,
            ]);
        }

        admin_conflict_response(true, 'historical_conflict', 'Historical overlap detected' . ($summary !== '' ? ': ' . $summary : '') . '. If this reflects what actually happened, confirm the Historical Overlap acknowledgement before saving.', [
            'available' => true,
            'requires_ack' => true,
            'late_extension' => true,
            'historical_conflict' => true,
            'conflict_summary' => $summary,
            'new_end' => $newEnd->format(DateTimeInterface::ATOM),
            'blocked_end' => $blockedEnd->format(DateTimeInterface::ATOM),
        ]);
    }

    admin_conflict_response(true, 'available', $isLateExtension
        ? 'No conflicting reservation was found for the added late-extension time. The schedule will be checked again when you confirm.'
        : 'No conflict found for this extension. The schedule will be checked again when you confirm.', [
        'available' => true,
        'late_extension' => $isLateExtension,
        'requires_ack' => false,
        'new_end' => $newEnd->format(DateTimeInterface::ATOM),
        'blocked_end' => $blockedEnd->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Live conflict checking is temporarily unavailable. The schedule will still be checked again when you submit.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
