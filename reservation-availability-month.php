<?php
/**
 * FILE PURPOSE: Privacy-safe monthly availability summary for the booking calendar.
 * A date is selectable only when at least one valid start time remains for the
 * requested duration/setup/cleanup. No reservation identity is returned.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function month_availability_response(bool $ok, string $status, string $message, array $extra = []): void
{
    http_response_code($ok ? 200 : 422);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$monthRaw = trim($_GET['month'] ?? '');
$durationRaw = trim($_GET['duration_hours'] ?? '');
$type = trim($_GET['reservation_type'] ?? 'basketball');
$setupRaw = trim($_GET['setup_minutes'] ?? '0');
$cleanupRaw = trim($_GET['cleanup_minutes'] ?? '0');
$allowPastForAdmin = (($_GET['admin_allow_past'] ?? '') === '1') && current_admin() !== null;

if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) {
    month_availability_response(false, 'invalid', 'Choose a valid calendar month.');
}

try {
    $monthStart = new DateTimeImmutable($monthRaw . '-01 00:00:00');
} catch (Throwable $e) {
    month_availability_response(false, 'invalid', 'Choose a valid calendar month.');
}
if ($monthStart->format('Y-m') !== $monthRaw) {
    month_availability_response(false, 'invalid', 'Choose a valid calendar month.');
}
$monthEnd = $monthStart->modify('first day of next month');

$durationHours = reservation_duration_hours_from_input($durationRaw);
$setupMinutes = ctype_digit($setupRaw) ? (int)$setupRaw : 0;
$cleanupMinutes = ctype_digit($cleanupRaw) ? (int)$cleanupRaw : 0;

if ($durationHours === null) {
    month_availability_response(false, 'invalid', 'Choose a valid reservation duration.');
}
if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
    month_availability_response(false, 'invalid', 'Choose a valid reservation type.');
}
if ($type !== 'event') {
    $setupMinutes = 0;
    $cleanupMinutes = 0;
} else {
    if (!in_array($setupMinutes, [0, 30, 60, 120, 180], true)) {
        month_availability_response(false, 'invalid', 'Choose a valid setup time.');
    }
    if (!in_array($cleanupMinutes, [0, 30, 60, 120], true)) {
        month_availability_response(false, 'invalid', 'Choose a valid cleanup time.');
    }
}

$calendarBlockCondition = reservation_calendar_block_condition();
$conflictCondition = $allowPastForAdmin
    ? "({$calendarBlockCondition} OR status = 'completed')"
    : $calendarBlockCondition;

// One query for the whole month. The extra setup/cleanup margin ensures an
// adjacent reservation outside the month can still block a boundary slot.
$queryStart = $monthStart->modify('-' . $setupMinutes . ' minutes');
$queryEnd = $monthEnd->modify('+' . $cleanupMinutes . ' minutes');

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
        'message' => 'Calendar availability could not be loaded right now. Please try again shortly.',
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
        // Ignore malformed legacy rows instead of exposing an endpoint failure.
    }
}

$now = new DateTimeImmutable();
$startSlots = reservation_start_time_slots();
$dates = [];

for ($day = $monthStart; $day < $monthEnd; $day = $day->modify('+1 day')) {
    $dateKey = $day->format('Y-m-d');
    $availableCount = 0;

    foreach ($startSlots as $startValue => $startLabel) {
        try {
            $start = new DateTimeImmutable($dateKey . ' ' . $startValue . ':00');
            $end = reservation_add_duration($start, $durationHours);
        } catch (Throwable $e) {
            continue;
        }

        if ($end->format('Y-m-d') !== $dateKey || $end->format('H:i') > '22:00') {
            continue;
        }
        if (!$allowPastForAdmin && $start < $now->modify('-5 minutes')) {
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

        if (!$conflict) {
            $availableCount++;
        }
    }

    $dates[$dateKey] = [
        'available' => $availableCount > 0,
        'available_count' => $availableCount,
    ];
}

month_availability_response(true, 'ok', 'Calendar availability loaded.', [
    'month' => $monthRaw,
    'duration_hours' => $durationHours,
    'dates' => $dates,
]);
