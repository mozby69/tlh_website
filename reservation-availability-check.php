<?php
/**
 * FILE PURPOSE: Privacy-safe live single-reservation availability endpoint used by public and admin forms.
 * DEBUGGING: Pending public website requests do not block. Final reservation submission still performs the authoritative conflict check.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function availability_response(bool $ok, string $status, string $message, array $extra = []): void
{
    http_response_code($ok ? 200 : 422);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

// Read-only availability checks intentionally return no client identity or
// reservation reference data. This endpoint is safe for anonymous public use.
$date = trim($_GET['date'] ?? '');
$startTime = trim($_GET['start_time'] ?? '');
$durationRaw = trim($_GET['duration_hours'] ?? '');
$type = trim($_GET['reservation_type'] ?? 'basketball');
$setupRaw = trim($_GET['setup_minutes'] ?? '0');
$cleanupRaw = trim($_GET['cleanup_minutes'] ?? '0');
$allowPastForAdmin = (($_GET['admin_allow_past'] ?? '') === '1') && current_admin() !== null;

$startSlots = reservation_start_time_slots();
$durationHours = reservation_duration_hours_from_input($durationRaw);
$setupMinutes = ctype_digit($setupRaw) ? (int)$setupRaw : 0;
$cleanupMinutes = ctype_digit($cleanupRaw) ? (int)$cleanupRaw : 0;

if (!reservation_date_is_valid($date)) {
    availability_response(false, 'invalid', 'Choose a valid reservation date.');
}
if (!array_key_exists($startTime, $startSlots)) {
    availability_response(false, 'invalid', 'Choose a valid 30-minute start time.');
}
if ($durationHours === null) {
    availability_response(false, 'invalid', 'Choose a valid reservation duration.');
}
if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
    availability_response(false, 'invalid', 'Choose a valid reservation type.');
}

if ($type !== 'event') {
    $setupMinutes = 0;
    $cleanupMinutes = 0;
} else {
    if (!in_array($setupMinutes, [0, 30, 60, 120, 180], true)) {
        availability_response(false, 'invalid', 'Choose a valid setup time.');
    }
    if (!in_array($cleanupMinutes, [0, 30, 60, 120], true)) {
        availability_response(false, 'invalid', 'Choose a valid cleanup time.');
    }
}

try {
    $start = new DateTimeImmutable($date . ' ' . $startTime . ':00');
    $end = reservation_add_duration($start, $durationHours);
} catch (Throwable $e) {
    availability_response(false, 'invalid', 'Choose a valid reservation schedule.');
}

if ($end->format('Y-m-d') !== $date || $end->format('H:i') > '22:00') {
    availability_response(false, 'invalid', 'This schedule extends beyond the 10:00 PM closing time.');
}
if (!$allowPastForAdmin && $start < new DateTimeImmutable('-5 minutes')) {
    availability_response(false, 'invalid', 'Please choose a reservation time in the future.');
}

$blockedStart = $start->modify('-' . $setupMinutes . ' minutes');
$blockedEnd = $end->modify('+' . $cleanupMinutes . ' minutes');

try {
    $conflict = reservation_conflict(
        $blockedStart->format('Y-m-d H:i:s'),
        $blockedEnd->format('Y-m-d H:i:s'),
        null,
        $allowPastForAdmin && $end < new DateTimeImmutable()
    );
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Live availability could not be checked right now. The schedule will still be checked again when you submit.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($conflict) {
    availability_response(true, 'unavailable', 'This date and time is already reserved. Please choose another available schedule.', [
        'available' => false,
    ]);
}

availability_response(true, 'available', $start < new DateTimeImmutable() ? 'No conflicting reservation is recorded for this historical schedule.' : 'This schedule is currently available to request.', [
    'available' => true,
    'historical' => $start < new DateTimeImmutable(),
]);
