<?php
/**
 * FILE PURPOSE: Privacy-safe availability for every possible single-reservation
 * start time on a selected date. One database read loads the day's secured
 * windows, then each 30-minute start option is evaluated in PHP.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function grid_response(bool $ok, string $status, string $message, array $extra = []): void
{
    http_response_code($ok ? 200 : 422);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$date = trim($_GET['date'] ?? '');
$durationRaw = trim($_GET['duration_hours'] ?? '');
$type = trim($_GET['reservation_type'] ?? 'basketball');
$setupRaw = trim($_GET['setup_minutes'] ?? '0');
$cleanupRaw = trim($_GET['cleanup_minutes'] ?? '0');
$allowPastForAdmin = (($_GET['admin_allow_past'] ?? '') === '1') && current_admin() !== null;

$durationHours = reservation_duration_hours_from_input($durationRaw);
$setupMinutes = ctype_digit($setupRaw) ? (int)$setupRaw : 0;
$cleanupMinutes = ctype_digit($cleanupRaw) ? (int)$cleanupRaw : 0;
$startSlots = reservation_start_time_slots();

if (!reservation_date_is_valid($date)) {
    grid_response(false, 'invalid', 'Choose a valid reservation date.');
}
if ($durationHours === null) {
    grid_response(false, 'invalid', 'Choose a valid reservation duration.');
}
if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
    grid_response(false, 'invalid', 'Choose a valid reservation type.');
}
if ($type !== 'event') {
    $setupMinutes = 0;
    $cleanupMinutes = 0;
} else {
    if (!in_array($setupMinutes, [0, 30, 60, 120, 180], true)) {
        grid_response(false, 'invalid', 'Choose a valid setup time.');
    }
    if (!in_array($cleanupMinutes, [0, 30, 60, 120], true)) {
        grid_response(false, 'invalid', 'Choose a valid cleanup time.');
    }
}

try {
    $dayStart = new DateTimeImmutable($date . ' 00:00:00');
    $dayEnd = $dayStart->modify('+1 day');
} catch (Throwable $e) {
    grid_response(false, 'invalid', 'Choose a valid reservation date.');
}

$calendarBlockCondition = reservation_calendar_block_condition();
$conflictCondition = $allowPastForAdmin
    ? "({$calendarBlockCondition} OR status = 'completed')"
    : $calendarBlockCondition;
$queryStart = $dayStart->modify('-' . $setupMinutes . ' minutes');
$queryEnd = $dayEnd->modify('+' . $cleanupMinutes . ' minutes');

try {
    $stmt = db()->prepare("SELECT blocked_start, blocked_end
        FROM reservations
        WHERE archived_at IS NULL
          AND {$conflictCondition}
          AND blocked_start < :range_end
          AND blocked_end > :range_start
        ORDER BY blocked_start ASC");
    $stmt->execute([
        ':range_start' => $queryStart->format('Y-m-d H:i:s'),
        ':range_end' => $queryEnd->format('Y-m-d H:i:s'),
    ]);
    $holds = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Live availability could not be checked right now. Please try again shortly.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$holdWindows = [];
foreach ($holds as $hold) {
    try {
        $holdWindows[] = [
            new DateTimeImmutable((string)$hold['blocked_start']),
            new DateTimeImmutable((string)$hold['blocked_end']),
        ];
    } catch (Throwable $e) {
        // Skip malformed legacy rows without exposing reservation data publicly.
    }
}

$slots = [];
$now = new DateTimeImmutable();
foreach ($startSlots as $startValue => $startLabel) {
    try {
        $start = new DateTimeImmutable($date . ' ' . $startValue . ':00');
        $end = reservation_add_duration($start, $durationHours);
    } catch (Throwable $e) {
        continue;
    }

    $slot = [
        'value' => $startValue,
        'label' => $startLabel,
        'available' => false,
        'status' => 'invalid',
        'message' => 'This schedule is not available.',
        'end_time' => $end->format('H:i'),
    ];

    if ($end->format('Y-m-d') !== $date || $end->format('H:i') > '22:00') {
        $slot['status'] = 'closed';
        $slot['message'] = 'Extends beyond the 10:00 PM closing time.';
        $slots[] = $slot;
        continue;
    }
    if (!$allowPastForAdmin && $start < $now->modify('-5 minutes')) {
        $slot['status'] = 'past';
        $slot['message'] = 'This time has already passed.';
        $slots[] = $slot;
        continue;
    }

    $blockedStart = $start->modify('-' . $setupMinutes . ' minutes');
    $blockedEnd = $end->modify('+' . $cleanupMinutes . ' minutes');
    $conflict = false;
    foreach ($holdWindows as [$holdStart, $holdEnd]) {
        if ($holdStart < $blockedEnd && $holdEnd > $blockedStart) {
            $conflict = true;
            break;
        }
    }

    if ($conflict) {
        $slot['status'] = 'unavailable';
        $slot['message'] = 'Already reserved.';
        $slots[] = $slot;
        continue;
    }

    $slot['available'] = true;
    $slot['status'] = $start < $now ? 'historical' : 'available';
    $slot['message'] = $start < $now
        ? 'Historical slot with no conflicting reservation.'
        : 'Available';
    $slots[] = $slot;
}

grid_response(true, 'ok', 'Slot availability loaded.', [
    'date' => $date,
    'duration_hours' => $durationHours,
    'slots' => $slots,
]);
