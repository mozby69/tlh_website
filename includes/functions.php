<?php
/**
 * FILE PURPOSE: Central application bootstrap and shared business rules for security, pricing, conflicts, payments, archiving, uploads, schema upgrades, and notifications.
 * DEBUGGING: When debugging behavior used by multiple pages, start here. Route files should call these helpers instead of duplicating business rules.
 */
require_once __DIR__ . '/../config/database.php';

// ============================================================================
// SECURITY BOOTSTRAP & HTTP BASICS
// Session cookie hardening and common response security headers are initialized
// here because almost every public/admin route loads this file.
// ============================================================================
function app_request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = app_request_is_https();
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if ($secureCookie) {
        @ini_set('session.cookie_secure', '1');
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ============================================================================
// OUTPUT, SETTINGS & USER FEEDBACK
// Escaping, site settings, flash messages, redirects, and success dialogs.
// ============================================================================
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function site_tagline(): string
{
    return 'Designed For Premium Experience';
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value !== false ? (string)$value : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function success_modal_title(string $message): string
{
    $message = strtolower($message);
    return match (true) {
        str_contains($message, 'signed in') || str_contains($message, 'login successful') => 'Login Successful',
        str_contains($message, 'signed out') => 'Signed Out',
        str_contains($message, 'reschedul') => 'Reservation Rescheduled',
        (str_contains($message, 'extend') || str_contains($message, 'extension')) => 'Reservation Extended',
        str_contains($message, 'cancel') => 'Reservation Cancelled',
        str_contains($message, 'approved') => 'Reservation Approved',
        str_contains($message, 'reject') => 'Reservation Updated',
        str_contains($message, 'restore') => 'Reservation Restored',
        str_contains($message, 'archive') => 'Reservation Archived',
        str_contains($message, 'payment') => 'Payment Saved',
        str_contains($message, 'batch') && str_contains($message, 'created') => 'Batch Reservation Created',
        str_contains($message, 'reservation') && str_contains($message, 'submitted') => 'Reservation Submitted',
        str_contains($message, 'reservation') && str_contains($message, 'created') => 'Reservation Created',
        str_contains($message, 'reservation') && (str_contains($message, 'updated') || str_contains($message, 'saved')) => 'Reservation Updated',
        str_contains($message, 'settings') || str_contains($message, 'rates') => 'Settings Saved',
        str_contains($message, 'backup') => 'Backup Completed',
        str_contains($message, 'uploaded') => 'Upload Completed',
        str_contains($message, 'deleted') => 'Deleted Successfully',
        str_contains($message, 'saved') || str_contains($message, 'updated') => 'Changes Saved',
        default => 'Action Completed',
    };
}

function render_flashes(bool $showInline = true): void
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    $successMessages = [];
    foreach ($items as $item) {
        $type = (string)($item['type'] ?? 'info');
        $message = (string)($item['message'] ?? '');
        if ($type === 'success') {
            $successMessages[] = $message;
            continue;
        }
        if ($showInline) {
            $safeType = $type === 'error' ? 'danger' : $type;
            echo '<div class="alert alert-' . e($safeType) . '">' . e($message) . '</div>';
        } else {
            $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        }
    }

    if ($successMessages === []) {
        return;
    }

    $title = success_modal_title($successMessages[0]);
    echo '<div class="action-success-modal" data-action-success-modal role="dialog" aria-modal="true" aria-labelledby="action-success-title">';
    echo '<button class="action-success-backdrop" type="button" data-action-success-close aria-label="Close confirmation"></button>';
    echo '<div class="action-success-dialog" tabindex="-1">';
    echo '<div class="action-success-icon" aria-hidden="true">&#10003;</div>';
    echo '<div class="action-success-copy"><span class="action-success-kicker">Success</span><h2 id="action-success-title">' . e($title) . '</h2>';
    foreach ($successMessages as $message) {
        echo '<p>' . e($message) . '</p>';
    }
    echo '</div><button class="btn btn-primary action-success-ok" type="button" data-action-success-close>OK</button>';
    echo '</div></div>';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ============================================================================
// CSRF PROTECTION
// Every state-changing form/POST endpoint should emit csrf_token() and call
// verify_csrf() before writing to the database.
// ============================================================================
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid or expired request token. Please refresh the page and try again.');
    }
}

// ============================================================================
// RESERVATION / BATCH REFERENCE NUMBERS
// Reference generation is collision-safe and keeps existing legacy references.
// ============================================================================
function generate_reference(): string
{
    $pdo = db();

    // Reserve the next short reference number atomically. The sequence table
    // prevents duplicate TLH numbers when two reservations are created at the
    // same time. Existing legacy references remain valid.
    try {
        do {
            $pdo->exec("INSERT INTO reference_sequences(sequence_name,current_value)
                VALUES ('reservation', LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)");
            $number = (int)$pdo->lastInsertId();
            if ($number < 1) {
                $number = (int)$pdo->query("SELECT current_value FROM reference_sequences WHERE sequence_name='reservation'")->fetchColumn();
            }
            $reference = 'TLH' . $number;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE reference_no = ?');
            $stmt->execute([$reference]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $reference;
    } catch (Throwable $e) {
        // Compatibility fallback if the automatic sequence-table upgrade has
        // not yet been permitted by the database host.
        do {
            $reference = 'TLH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE reference_no = ?');
            $stmt->execute([$reference]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $reference;
    }
}


function generate_batch_reference(): string
{
    do {
        $reference = 'BATCH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = db()->prepare('SELECT COUNT(*) FROM reservation_batches WHERE batch_reference = ?');
        $stmt->execute([$reference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $reference;
}


function generate_batch_payment_no(): string
{
    do {
        $reference = 'BPMT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = db()->prepare('SELECT COUNT(*) FROM batch_payments WHERE batch_payment_no = ?');
        $stmt->execute([$reference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $reference;
}


// ============================================================================
// BATCH PAYMENT COVERAGE HELPERS
// These helpers describe which occurrence dates a batch payment applies to.
// Individual reservation payment ledgers remain the source of financial truth.
// ============================================================================
function batch_payment_coverage_types(): array
{
    return [
        'entire_batch' => 'Entire batch',
        'specific_week' => 'Specific week',
        'date_range' => 'Date range',
        'selected_dates' => 'Selected dates',
        'single_date' => 'Single reservation date',
    ];
}

function batch_payment_coverage_type_label(?string $type): string
{
    $types = batch_payment_coverage_types();
    return $types[(string)$type] ?? 'Entire batch';
}

function batch_payment_date_range_label(?string $startDate, ?string $endDate = null): string
{
    if (!$startDate) {
        return '';
    }

    try {
        $start = new DateTimeImmutable($startDate . ' 00:00:00');
        $end = $endDate ? new DateTimeImmutable($endDate . ' 00:00:00') : $start;
    } catch (Throwable $e) {
        return trim((string)$startDate . ($endDate && $endDate !== $startDate ? ' - ' . $endDate : ''));
    }

    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('M j, Y');
    }
    if ($start->format('Y') === $end->format('Y') && $start->format('m') === $end->format('m')) {
        return $start->format('M j') . '-' . $end->format('j, Y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('M j') . ' - ' . $end->format('M j, Y');
    }
    return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
}

function batch_payment_coverage_description(array $payment): string
{
    $stored = trim((string)($payment['coverage_label'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    $type = (string)($payment['coverage_type'] ?? 'entire_batch');
    $range = batch_payment_date_range_label(
        !empty($payment['coverage_start']) ? (string)$payment['coverage_start'] : null,
        !empty($payment['coverage_end']) ? (string)$payment['coverage_end'] : null
    );
    $count = (int)($payment['coverage_count'] ?? $payment['allocation_count'] ?? 0);

    return match ($type) {
        'specific_week' => $range !== '' ? 'Week of ' . $range : 'Specific week',
        'date_range' => $range !== '' ? 'Date range: ' . $range : 'Date range',
        'selected_dates' => ($count > 0 ? $count . ' selected date' . ($count === 1 ? '' : 's') : 'Selected dates') . ($range !== '' ? ' · ' . $range : ''),
        'single_date' => $range !== '' ? 'Single date: ' . $range : 'Single reservation date',
        default => 'Entire payable batch',
    };
}

function batch_payment_scope_date_summary(array $scopes, int $limit = 4): string
{
    if (!$scopes) {
        return '';
    }

    usort($scopes, static function (array $a, array $b): int {
        return strcmp((string)($a['event_start'] ?? ''), (string)($b['event_start'] ?? ''));
    });

    $labels = [];
    foreach ($scopes as $scope) {
        $value = (string)($scope['event_start'] ?? '');
        if ($value === '') {
            continue;
        }
        $timestamp = strtotime($value);
        $labels[] = $timestamp ? date('M j, Y', $timestamp) : substr($value, 0, 10);
    }
    $labels = array_values(array_unique($labels));
    if (!$labels) {
        return '';
    }

    $limit = max(1, $limit);
    if (count($labels) <= $limit) {
        return implode(', ', $labels);
    }

    $visible = array_slice($labels, 0, max(1, $limit - 1));
    return implode(', ', $visible) . ' and ' . (count($labels) - count($visible)) . ' more';
}

// ============================================================================
// BATCH SCHEDULE GENERATION
// Converts recurrence selections into concrete dates and human-readable patterns.
// ============================================================================
function reservation_batch_weekday_labels(): array
{
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function reservation_batch_dates(string $startDate, string $endDate, array $weekdays): array
{
    try {
        $start = new DateTimeImmutable($startDate . ' 00:00:00');
        $end = new DateTimeImmutable($endDate . ' 00:00:00');
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Choose a valid batch date range.');
    }

    if ($end < $start) {
        throw new InvalidArgumentException('The batch end date must be on or after the start date.');
    }
    if ($end->getTimestamp() - $start->getTimestamp() > 366 * 86400) {
        throw new InvalidArgumentException('A batch reservation may cover at most one year.');
    }

    $allowed = array_keys(reservation_batch_weekday_labels());
    $selected = array_values(array_unique(array_map('intval', $weekdays)));
    $selected = array_values(array_intersect($allowed, $selected));
    sort($selected);
    if (!$selected) {
        throw new InvalidArgumentException('Select at least one weekday for the batch reservation.');
    }

    $dates = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        if (in_array((int)$cursor->format('N'), $selected, true)) {
            $dates[] = $cursor->format('Y-m-d');
            if (count($dates) > 200) {
                throw new InvalidArgumentException('A single batch reservation is limited to 200 occurrences.');
            }
        }
    }
    if (!$dates) {
        throw new InvalidArgumentException('No dates in the selected range match the chosen weekdays.');
    }
    return $dates;
}

function reservation_batch_pattern_text(string $startDate, string $endDate, array $weekdays, string $startTime, float $durationHours): string
{
    $labels = reservation_batch_weekday_labels();
    $names = [];
    foreach ($weekdays as $weekday) {
        $weekday = (int)$weekday;
        if (isset($labels[$weekday])) {
            $names[] = $labels[$weekday];
        }
    }
    $names = array_values(array_unique($names));
    $dayText = count($names) === 7 ? 'Every day' : implode(', ', $names);
    try {
        $time = (new DateTimeImmutable('2000-01-01 ' . $startTime . ':00'))->format('g:i A');
    } catch (Throwable $e) {
        $time = $startTime;
    }
    return $dayText . ' · ' . $time . ' · ' . reservation_duration_label($durationHours);
}

/**
 * Rebuild the summary fields for an existing batch from its connected
 * reservation rows. This keeps the batch header, totals, and chronological
 * occurrence numbers correct after a child reservation is edited, extended,
 * cancelled, or rescheduled outside the original batch range.
 */
function reservation_sync_batch_summary(PDO $pdo, int $batchId): void
{
    if ($batchId < 1) {
        return;
    }

    $orderStmt = $pdo->prepare('SELECT id FROM reservations WHERE batch_id=? ORDER BY event_start ASC, id ASC');
    $orderStmt->execute([$batchId]);
    $reservationIds = $orderStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$reservationIds) {
        $emptyStmt = $pdo->prepare('UPDATE reservation_batches SET occurrence_count=0,total_amount=0 WHERE id=?');
        $emptyStmt->execute([$batchId]);
        return;
    }

    $renumberStmt = $pdo->prepare('UPDATE reservations SET batch_occurrence=? WHERE id=?');
    foreach ($reservationIds as $position => $reservationId) {
        $renumberStmt->execute([$position + 1, (int)$reservationId]);
    }

    $summaryStmt = $pdo->prepare("UPDATE reservation_batches b SET
        occurrence_count=(SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id),
        total_amount=(SELECT COALESCE(SUM(COALESCE(r.final_amount,r.estimated_amount)),0) FROM reservations r WHERE r.batch_id=b.id),
        range_start=(SELECT MIN(DATE(r.event_start)) FROM reservations r WHERE r.batch_id=b.id),
        range_end=(SELECT MAX(DATE(r.event_start)) FROM reservations r WHERE r.batch_id=b.id)
        WHERE b.id=?");
    $summaryStmt->execute([$batchId]);
}

// ============================================================================
// CALENDAR OCCUPANCY & CONFLICT RULES
// IMPORTANT: pending public website requests do NOT hold the venue. Approved
// reservations and staff-held schedules do. A Completed reservation also keeps
// blocking the venue until its cleanup period ends. Use these helpers everywhere
// rather than inventing page-specific conflict rules.
// ============================================================================
function reservation_calendar_block_condition(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "(
        {$prefix}status = 'approved'
        OR ({$prefix}status = 'completed' AND {$prefix}blocked_end > NOW())
        OR (
            {$prefix}status IN ('pending','for_review')
            AND ({$prefix}created_by IS NOT NULL OR {$prefix}source IN ('walk_in','internal'))
        )
    )";
}

function reservation_holds_calendar(array $booking): bool
{
    if (!empty($booking['archived_at'])) {
        return false;
    }
    $status = (string)($booking['status'] ?? '');
    if ($status === 'approved') {
        return true;
    }
    if ($status === 'completed') {
        try {
            $blockedEnd = new DateTimeImmutable((string)($booking['blocked_end'] ?? ''));
            return $blockedEnd > new DateTimeImmutable();
        } catch (Throwable $e) {
            return false;
        }
    }
    if (!in_array($status, ['pending', 'for_review'], true)) {
        return false;
    }
    return !empty($booking['created_by']) || in_array((string)($booking['source'] ?? ''), ['walk_in', 'internal'], true);
}

function reservation_conflict(string $blockedStart, string $blockedEnd, ?int $excludeId = null, bool $includeCompleted = false): bool
{
    $calendarBlockCondition = reservation_calendar_block_condition();
    $conflictCondition = $includeCompleted ? "({$calendarBlockCondition} OR status = 'completed')" : $calendarBlockCondition;
    $sql = "SELECT COUNT(*) FROM reservations
            WHERE archived_at IS NULL
              AND {$conflictCondition}
              AND blocked_start < :blocked_end
              AND blocked_end > :blocked_start";
    $params = [':blocked_start' => $blockedStart, ':blocked_end' => $blockedEnd];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Return reservations overlapping a blocking window. This is primarily used
 * by Late Extension so the app can distinguish conflicts that are already
 * historical from conflicts that still extend into the future.
 *
 * @return array<int,array<string,mixed>>
 */
function reservation_conflicts_in_window(string $blockedStart, string $blockedEnd, ?int $excludeId = null, bool $includeCompleted = false, bool $includeArchived = false): array
{
    $calendarBlockCondition = reservation_calendar_block_condition();
    $conflictCondition = $includeCompleted ? "({$calendarBlockCondition} OR status = 'completed')" : $calendarBlockCondition;
    $sql = "SELECT id,reference_no,event_start,event_end,blocked_start,blocked_end,status,archived_at
            FROM reservations
            WHERE {$conflictCondition}
              AND blocked_start < :blocked_end
              AND blocked_end > :blocked_start";
    if (!$includeArchived) {
        $sql .= ' AND archived_at IS NULL';
    }
    $params = [':blocked_start' => $blockedStart, ':blocked_end' => $blockedEnd];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $sql .= ' ORDER BY blocked_start ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Analyze only the NEW blocking time created by an extension. The reservation
 * already owned the venue through its existing blocked_end, so an extension
 * should not re-flag an overlap that existed entirely inside the old block.
 *
 * Historical overlaps may be acknowledged by an Administrator when recording
 * a late extension. Any overlap that reaches beyond "now" remains a hard
 * scheduling conflict because it can still affect another booking.
 *
 * @return array{historical:array<int,array<string,mixed>>,future:array<int,array<string,mixed>>,window_start:string,window_end:string}
 */
function reservation_extension_conflict_analysis(array $booking, DateTimeImmutable $newBlockedEnd, ?DateTimeImmutable $now = null, bool $includeCompleted = false): array
{
    $now ??= new DateTimeImmutable();
    try {
        $rawBlockedEnd = trim((string)($booking['blocked_end'] ?? ''));
        if ($rawBlockedEnd === '') {
            throw new RuntimeException('Missing blocked end.');
        }
        $existingBlockedEnd = new DateTimeImmutable($rawBlockedEnd);
    } catch (Throwable $e) {
        $eventEnd = new DateTimeImmutable((string)($booking['event_end'] ?? ''));
        $existingBlockedEnd = $eventEnd->modify('+' . max(0, (int)($booking['cleanup_minutes'] ?? 0)) . ' minutes');
    }

    if ($newBlockedEnd <= $existingBlockedEnd) {
        return [
            'historical' => [],
            'future' => [],
            'window_start' => $existingBlockedEnd->format('Y-m-d H:i:s'),
            'window_end' => $newBlockedEnd->format('Y-m-d H:i:s'),
        ];
    }

    $conflicts = reservation_conflicts_in_window(
        $existingBlockedEnd->format('Y-m-d H:i:s'),
        $newBlockedEnd->format('Y-m-d H:i:s'),
        isset($booking['id']) ? (int)$booking['id'] : null,
        $includeCompleted,
        $includeCompleted
    );

    $historical = [];
    $future = [];
    foreach ($conflicts as $conflict) {
        try {
            $conflictStart = new DateTimeImmutable((string)$conflict['blocked_start']);
            $conflictEnd = new DateTimeImmutable((string)$conflict['blocked_end']);
        } catch (Throwable $e) {
            $future[] = $conflict;
            continue;
        }

        $overlapStart = $conflictStart > $existingBlockedEnd ? $conflictStart : $existingBlockedEnd;
        $overlapEnd = $conflictEnd < $newBlockedEnd ? $conflictEnd : $newBlockedEnd;
        if ($overlapEnd <= $overlapStart) {
            continue;
        }

        if ($overlapEnd > $now) {
            $future[] = $conflict;
        } else {
            $historical[] = $conflict;
        }
    }

    return [
        'historical' => $historical,
        'future' => $future,
        'window_start' => $existingBlockedEnd->format('Y-m-d H:i:s'),
        'window_end' => $newBlockedEnd->format('Y-m-d H:i:s'),
    ];
}

function reservation_extension_conflict_summary(array $conflicts, int $limit = 4): string
{
    if (!$conflicts) {
        return '';
    }

    $parts = [];
    foreach (array_slice($conflicts, 0, max(1, $limit)) as $conflict) {
        $reference = trim((string)($conflict['reference_no'] ?? '')) ?: ('Reservation #' . (int)($conflict['id'] ?? 0));
        try {
            $start = new DateTimeImmutable((string)($conflict['event_start'] ?? ''));
            $end = new DateTimeImmutable((string)($conflict['event_end'] ?? ''));
            $parts[] = $reference . ' (' . $start->format('M j, g:i A') . '-' . $end->format('g:i A') . ')';
        } catch (Throwable $e) {
            $parts[] = $reference;
        }
    }
    if (count($conflicts) > $limit) {
        $parts[] = '+' . (count($conflicts) - $limit) . ' more';
    }
    return implode(', ', $parts);
}

// ============================================================================
// PRICING ENGINE & PACKAGE INCLUSIONS
// calculate_reservation_pricing() is the authoritative calculator for NEW prices.
// Existing bookings retain their saved pricing snapshot when rates later change.
// Setup/cleanup blocks the venue but is intentionally non-billable.
// ============================================================================
function reservation_pricing_defaults(): array
{
    return [
        'intro_start' => '2026-08-01',
        'intro_end' => '2026-10-31',
        'intro_fan_rate' => 1500.00,
        'intro_aircon_rate' => 4500.00,
        'regular_fan_rate' => 2000.00,
        'regular_aircon_rate' => 5000.00,
        'tournament_fan_rate' => 2500.00,
        'tournament_aircon_rate' => 5500.00,
        'big_event_fan_rate' => 6000.00,
        'big_event_aircon_rate' => 8000.00,
        'shower_room_fee' => 500.00,
        'equipment_bundle_fee' => 100.00,
    ];
}

function reservation_pricing_config(): array
{
    $defaults = reservation_pricing_defaults();
    $config = [
        'intro_start' => setting('pricing_intro_start', $defaults['intro_start']),
        'intro_end' => setting('pricing_intro_end', $defaults['intro_end']),
    ];
    foreach (array_diff(array_keys($defaults), ['intro_start', 'intro_end']) as $key) {
        $config[$key] = max(0, round((float)setting('pricing_' . $key, (string)$defaults[$key]), 2));
    }
    return $config;
}

function reservation_cooling_options(): array
{
    return ['fan' => 'Fan with Lights', 'aircon' => 'Aircon with Lights'];
}

function reservation_cooling_label(?string $cooling): string
{
    return reservation_cooling_options()[$cooling ?? ''] ?? 'Not recorded';
}

function reservation_package_for_guests(int $guestCount): ?string
{
    if ($guestCount >= 1 && $guestCount <= 29) {
        return 'regular';
    }
    if ($guestCount >= 30 && $guestCount <= 200) {
        return 'tournament';
    }
    if ($guestCount >= 201 && $guestCount <= 300) {
        return 'big_event';
    }
    return null;
}

function reservation_package_label(?string $package): string
{
    return match ($package) {
        'regular' => 'Regular Booking',
        'tournament' => 'Tournament (30–200 guests)',
        'big_event' => 'Big Event (201–300 guests)',
        default => 'Not recorded',
    };
}

function reservation_rate_period_label(?string $period): string
{
    return match ($period) {
        'introductory' => 'Introductory Rate',
        'original' => 'Regular Rate',
        'tournament' => 'Tournament Rate',
        'big_event' => 'Big Event Rate',
        'regular' => 'Regular Rate',
        default => 'Saved Rate',
    };
}

function reservation_package_inclusions(?string $package): array
{
    $shared = [
        'Shot clocks, scoreboard, controller, and complete sound system',
        'Venue and social-media advertisement',
        'Allocated parking space',
    ];
    if ($package === 'tournament') {
        return $shared;
    }
    if ($package === 'big_event') {
        return [...$shared, 'Carpet installation'];
    }
    return [];
}

function reservation_inclusions_for_booking(array $booking): array
{
    $package = (string)($booking['pricing_package'] ?? '');
    $cooling = (string)($booking['cooling_option'] ?? '');
    $inclusions = ['Exclusive venue use during the reserved event schedule'];

    if (array_key_exists($cooling, reservation_cooling_options())) {
        $inclusions[] = reservation_cooling_label($cooling);
    }

    foreach (reservation_package_inclusions($package) as $inclusion) {
        $inclusions[] = $inclusion;
    }

    if ($package === 'regular' && !empty($booking['equipment_bundle_addon'])) {
        $inclusions[] = 'Shot clocks, scoreboard, controller, and complete sound system';
    }
    if (!empty($booking['shower_room_addon'])) {
        $inclusions[] = 'Shower room access';
    }

    return array_values(array_unique($inclusions));
}

function reservation_inclusions_text(array $booking, string $separator = ', '): string
{
    return implode($separator, reservation_inclusions_for_booking($booking));
}

function calculate_reservation_pricing(
    string $start,
    string $end,
    int $guestCount,
    string $coolingOption,
    bool $showerRoom = false,
    bool $equipmentBundle = false,
    bool $allowLegacyTimeOffset = false,
    bool $complimentaryShower = false,
    bool $complimentaryEquipment = false
): array {
    $startTime = new DateTimeImmutable($start);
    $endTime = new DateTimeImmutable($end);
    $seconds = $endTime->getTimestamp() - $startTime->getTimestamp();
    $validMinutes = ['00', '30'];
    if (!$allowLegacyTimeOffset && (!in_array($startTime->format('i'), $validMinutes, true) || !in_array($endTime->format('i'), $validMinutes, true))) {
        throw new InvalidArgumentException('Reservation start and end times must use 30-minute increments.');
    }
    if ($seconds <= 0 || $seconds % 1800 !== 0) {
        throw new InvalidArgumentException('Reservations must use 30-minute duration increments.');
    }

    $hours = $seconds / 3600;
    $package = reservation_package_for_guests($guestCount);
    if ($package === null) {
        throw new InvalidArgumentException('Guest count must be between 1 and 300 guests.');
    }
    if (!array_key_exists($coolingOption, reservation_cooling_options())) {
        throw new InvalidArgumentException('Choose either Fan with Lights or Aircon with Lights.');
    }

    $config = reservation_pricing_config();
    $eventDate = $startTime->format('Y-m-d');
    $ratePeriod = $package;
    if ($package === 'regular') {
        $ratePeriod = ($eventDate >= $config['intro_start'] && $eventDate <= $config['intro_end'])
            ? 'introductory'
            : 'original';
        $rateKey = ($ratePeriod === 'introductory' ? 'intro_' : 'regular_') . $coolingOption . '_rate';
    } elseif ($package === 'tournament') {
        $rateKey = 'tournament_' . $coolingOption . '_rate';
    } else {
        $rateKey = 'big_event_' . $coolingOption . '_rate';
    }

    $hourlyRate = round((float)$config[$rateKey], 2);
    $equipmentIncluded = $package !== 'regular';
    $complimentaryShower = $showerRoom && $complimentaryShower;
    $complimentaryEquipment = $equipmentBundle && !$equipmentIncluded && $complimentaryEquipment;
    $showerFee = ($showerRoom && !$complimentaryShower) ? round((float)$config['shower_room_fee'], 2) : 0.0;
    $equipmentHourlyRate = round((float)$config['equipment_bundle_fee'], 2);
    $equipmentFee = ($equipmentBundle && !$equipmentIncluded && !$complimentaryEquipment)
        ? round($equipmentHourlyRate * $hours, 2)
        : 0.0;
    $baseAmount = round($hourlyRate * $hours, 2);
    $total = round($baseAmount + $showerFee + $equipmentFee, 2);

    return [
        'package' => $package,
        'package_label' => reservation_package_label($package),
        'rate_period' => $ratePeriod,
        'cooling_option' => $coolingOption,
        'cooling_label' => reservation_cooling_label($coolingOption),
        'billable_hours' => $hours,
        'hourly_rate' => $hourlyRate,
        'base_amount' => $baseAmount,
        'shower_room_addon' => $showerRoom,
        'shower_room_complimentary' => $complimentaryShower,
        'shower_room_fee' => $showerFee,
        'equipment_bundle_addon' => $equipmentBundle || $equipmentIncluded,
        'equipment_bundle_included' => $equipmentIncluded,
        'equipment_bundle_complimentary' => $complimentaryEquipment,
        'equipment_bundle_hourly_rate' => $equipmentHourlyRate,
        'equipment_bundle_rate_unit' => 'hour',
        'equipment_bundle_fee' => $equipmentFee,
        'package_inclusions' => reservation_package_inclusions($package),
        'total' => $total,
        'intro_start' => $config['intro_start'],
        'intro_end' => $config['intro_end'],
    ];
}

function reservation_pricing_snapshot(array $pricing): string
{
    return json_encode($pricing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Reprice an existing reservation for a new schedule while preserving the
 * commercial terms already attached to the booking. The venue rate can either
 * stay locked to the saved/original booking rate or switch to the configured
 * rate for the new date. Existing add-on rates and complimentary flags remain
 * attached to the booking in both cases.
 */
function reservation_reprice_for_reschedule(array $booking, string $start, string $end, string $venueRateMode = 'original_rate'): array
{
    if (!in_array($venueRateMode, ['original_rate', 'new_date_rate'], true)) {
        throw new InvalidArgumentException('Choose a valid reschedule pricing treatment.');
    }

    $guestCount = (int)($booking['guest_count'] ?? 0);
    $coolingOption = (string)($booking['cooling_option'] ?? '');
    if (reservation_package_for_guests($guestCount) === null
        || !array_key_exists($coolingOption, reservation_cooling_options())) {
        throw new InvalidArgumentException('This reservation needs a valid guest count and cooling option before it can be repriced.');
    }

    $showerSelected = !empty($booking['shower_room_addon']);
    $equipmentSelected = !empty($booking['equipment_bundle_addon']);
    $showerComplimentary = reservation_shower_is_complimentary($booking);
    $equipmentComplimentary = reservation_equipment_is_complimentary($booking);

    // Calculate once using the new schedule so duration validation, package
    // selection, and the active venue rate for the new date stay centralized.
    $currentDatePricing = calculate_reservation_pricing(
        $start,
        $end,
        $guestCount,
        $coolingOption,
        $showerSelected,
        $equipmentSelected,
        false,
        $showerComplimentary,
        $equipmentComplimentary
    );

    $snapshot = [];
    if (!empty($booking['pricing_snapshot'])) {
        $decoded = json_decode((string)$booking['pricing_snapshot'], true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    $package = (string)($booking['pricing_package'] ?? ($snapshot['package'] ?? $currentDatePricing['package']));
    if ($package === '') {
        $package = (string)$currentDatePricing['package'];
    }

    $savedHourlyRate = null;
    if (array_key_exists('hourly_rate', $booking) && $booking['hourly_rate'] !== null && $booking['hourly_rate'] !== '') {
        $savedHourlyRate = round((float)$booking['hourly_rate'], 2);
    } elseif (isset($snapshot['hourly_rate']) && is_numeric($snapshot['hourly_rate'])) {
        $savedHourlyRate = round((float)$snapshot['hourly_rate'], 2);
    }
    if ($venueRateMode === 'original_rate' && $savedHourlyRate === null) {
        throw new InvalidArgumentException('This reservation has no saved hourly rate. Use the current payable total or apply the rate for the new date.');
    }

    $hourlyRate = $venueRateMode === 'original_rate'
        ? max(0, (float)$savedHourlyRate)
        : max(0, round((float)$currentDatePricing['hourly_rate'], 2));
    $ratePeriod = $venueRateMode === 'original_rate'
        ? (string)($booking['rate_period'] ?? ($snapshot['rate_period'] ?? $currentDatePricing['rate_period']))
        : (string)$currentDatePricing['rate_period'];
    $hours = (float)$currentDatePricing['billable_hours'];
    $baseAmount = round($hourlyRate * $hours, 2);

    // Shower Room is a one-time add-on. If it was already part of the booking,
    // preserve its saved fee rather than silently changing the agreement when
    // only the reservation date/time is being moved.
    $showerFee = 0.0;
    if ($showerSelected && !$showerComplimentary) {
        if (array_key_exists('shower_room_fee', $booking) && $booking['shower_room_fee'] !== null && $booking['shower_room_fee'] !== '') {
            $showerFee = max(0, round((float)$booking['shower_room_fee'], 2));
        } elseif (isset($snapshot['shower_room_fee']) && is_numeric($snapshot['shower_room_fee'])) {
            $showerFee = max(0, round((float)$snapshot['shower_room_fee'], 2));
        } else {
            $showerFee = max(0, round((float)$currentDatePricing['shower_room_fee'], 2));
        }
    }

    $equipmentIncluded = $package !== 'regular' || !empty($snapshot['equipment_bundle_included']);
    $equipmentRateUnit = (string)($snapshot['equipment_bundle_rate_unit'] ?? '');
    $equipmentHourlyRate = 0.0;
    $equipmentFee = 0.0;
    if ($equipmentSelected && !$equipmentIncluded && !$equipmentComplimentary) {
        if ($equipmentRateUnit === 'hour') {
            if (isset($snapshot['equipment_bundle_hourly_rate']) && is_numeric($snapshot['equipment_bundle_hourly_rate'])) {
                $equipmentHourlyRate = max(0, round((float)$snapshot['equipment_bundle_hourly_rate'], 2));
            } else {
                $oldHours = (float)($booking['billable_hours'] ?? ($snapshot['billable_hours'] ?? 0));
                $oldFee = (float)($booking['equipment_bundle_fee'] ?? ($snapshot['equipment_bundle_fee'] ?? 0));
                if ($oldHours > 0 && $oldFee > 0) {
                    $equipmentHourlyRate = max(0, round($oldFee / $oldHours, 2));
                } else {
                    $equipmentHourlyRate = max(0, round((float)$currentDatePricing['equipment_bundle_hourly_rate'], 2));
                }
            }
            $equipmentFee = round($equipmentHourlyRate * $hours, 2);
        } else {
            // Older records may contain the pre-v1.2.58 one-time Equipment
            // Bundle charge. Preserve that historical commercial term.
            if (array_key_exists('equipment_bundle_fee', $booking) && $booking['equipment_bundle_fee'] !== null && $booking['equipment_bundle_fee'] !== '') {
                $equipmentFee = max(0, round((float)$booking['equipment_bundle_fee'], 2));
            } elseif (isset($snapshot['equipment_bundle_fee']) && is_numeric($snapshot['equipment_bundle_fee'])) {
                $equipmentFee = max(0, round((float)$snapshot['equipment_bundle_fee'], 2));
            }
        }
    }

    $total = round($baseAmount + $showerFee + $equipmentFee, 2);
    $config = reservation_pricing_config();

    return [
        'package' => $package,
        'package_label' => reservation_package_label($package),
        'rate_period' => $ratePeriod,
        'cooling_option' => $coolingOption,
        'cooling_label' => reservation_cooling_label($coolingOption),
        'billable_hours' => $hours,
        'hourly_rate' => $hourlyRate,
        'base_amount' => $baseAmount,
        'shower_room_addon' => $showerSelected,
        'shower_room_complimentary' => $showerComplimentary,
        'shower_room_fee' => $showerFee,
        'equipment_bundle_addon' => $equipmentSelected || $equipmentIncluded,
        'equipment_bundle_included' => $equipmentIncluded,
        'equipment_bundle_complimentary' => $equipmentComplimentary,
        'equipment_bundle_hourly_rate' => $equipmentHourlyRate,
        'equipment_bundle_rate_unit' => $equipmentRateUnit === 'hour' ? 'hour' : ($equipmentSelected && !$equipmentIncluded ? 'reservation' : 'hour'),
        'equipment_bundle_fee' => $equipmentFee,
        'package_inclusions' => reservation_package_inclusions($package),
        'total' => $total,
        'intro_start' => $venueRateMode === 'original_rate'
            ? (string)($snapshot['intro_start'] ?? $config['intro_start'])
            : (string)$config['intro_start'],
        'intro_end' => $venueRateMode === 'original_rate'
            ? (string)($snapshot['intro_end'] ?? $config['intro_end'])
            : (string)$config['intro_end'],
        'venue_rate_source' => $venueRateMode === 'original_rate' ? 'original_booking' : 'new_date',
    ];
}


/**
 * Complimentary shower access is intentionally stored without another schema
 * column: shower_room_addon remains true, shower_room_fee is zero, and new
 * pricing snapshots carry an explicit marker. The zero-fee fallback also makes
 * older manually-comped records display correctly.
 */
function reservation_shower_is_complimentary(array $booking): bool
{
    if (empty($booking['shower_room_addon'])) {
        return false;
    }

    $snapshot = [];
    if (!empty($booking['pricing_snapshot'])) {
        $decoded = json_decode((string)$booking['pricing_snapshot'], true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    if (!empty($snapshot['shower_room_complimentary'])) {
        return true;
    }

    return array_key_exists('shower_room_fee', $booking)
        && round((float)$booking['shower_room_fee'], 2) <= 0.0;
}

/**
 * Complimentary equipment is stored using the existing equipment fields plus
 * the pricing snapshot. Tournament/Big Event equipment remains package-included
 * and is not treated as an admin waiver.
 */
function reservation_equipment_is_complimentary(array $booking): bool
{
    if (empty($booking['equipment_bundle_addon'])) {
        return false;
    }

    $snapshot = [];
    if (!empty($booking['pricing_snapshot'])) {
        $decoded = json_decode((string)$booking['pricing_snapshot'], true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    $package = (string)($booking['pricing_package'] ?? ($snapshot['package'] ?? ''));
    if ($package !== 'regular') {
        return false;
    }

    if (!empty($snapshot['equipment_bundle_complimentary'])) {
        return true;
    }

    return array_key_exists('equipment_bundle_fee', $booking)
        && round((float)$booking['equipment_bundle_fee'], 2) <= 0.0;
}

/**
 * Return the SAVED hourly Equipment Bundle rate that must continue during an
 * extension. Legacy per-reservation equipment charges are intentionally not
 * repeated. Tournament/Big Event and complimentary equipment also return zero.
 */
function reservation_equipment_extension_hourly_rate(array $booking): float
{
    if (empty($booking['equipment_bundle_addon']) || reservation_equipment_is_complimentary($booking)) {
        return 0.0;
    }

    $snapshot = [];
    if (!empty($booking['pricing_snapshot'])) {
        $decoded = json_decode((string)$booking['pricing_snapshot'], true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    $package = (string)($booking['pricing_package'] ?? ($snapshot['package'] ?? ''));
    if ($package !== 'regular' || (string)($snapshot['equipment_bundle_rate_unit'] ?? '') !== 'hour') {
        return 0.0;
    }

    if (isset($snapshot['equipment_bundle_hourly_rate']) && is_numeric($snapshot['equipment_bundle_hourly_rate'])) {
        return max(0, round((float)$snapshot['equipment_bundle_hourly_rate'], 2));
    }

    $hours = (float)($booking['billable_hours'] ?? ($snapshot['billable_hours'] ?? 0));
    $savedFee = (float)($booking['equipment_bundle_fee'] ?? ($snapshot['equipment_bundle_fee'] ?? 0));
    if ($hours > 0 && $savedFee > 0) {
        return max(0, round($savedFee / $hours, 2));
    }

    return 0.0;
}

/**
 * Legacy fallback retained for older records and extension code.
 */
function calculate_estimate(string $type, string $start, string $end): float
{
    $startTime = new DateTimeImmutable($start);
    $endTime = new DateTimeImmutable($end);
    $hours = max(1, ($endTime->getTimestamp() - $startTime->getTimestamp()) / 3600);
    $rate = match ($type) {
        'basketball' => (float)setting('basketball_rate', '800'),
        'volleyball' => (float)setting('volleyball_rate', '800'),
        default => (float)setting('event_rate', '1500'),
    };
    return round($hours * $rate, 2);
}

function money(float|string|null $amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Returns true once the booked service end time has passed.
 *
 * This is intentionally based on event_end (not cleanup time): after the
 * client's reserved service period is over, operational reservation details
 * are treated as historical and should no longer be changed.
 */
// ============================================================================
// SERVED/ENDED RESERVATION LOCKS
// After the booked end time passes, operational edits are blocked. Viewing,
// printing, archiving, and legitimate outstanding-payment settlement can remain.
// ============================================================================
function reservation_has_ended(array $booking, ?DateTimeImmutable $now = null): bool
{
    $rawEnd = trim((string)($booking['event_end'] ?? ''));
    if ($rawEnd === '') {
        return false;
    }

    try {
        $eventEnd = new DateTimeImmutable($rawEnd);
    } catch (Throwable $e) {
        return false;
    }

    return $eventEnd <= ($now ?? new DateTimeImmutable());
}

/**
 * A past reservation that is still Pending / For Review needs an explicit
 * administrator outcome. Time passing by itself must never mark it Completed.
 */
function reservation_needs_resolution(array $booking, ?DateTimeImmutable $now = null): bool
{
    if (reservation_is_archived($booking)) {
        return false;
    }

    if (!in_array((string)($booking['status'] ?? ''), ['pending', 'for_review'], true)) {
        return false;
    }

    return reservation_has_ended($booking, $now);
}

/**
 * Central business rule for standard reservation collections. Cancellation
 * settlement can be enabled explicitly on screens that provide cancellation
 * context; quick/standard collection surfaces should leave it disabled.
 *
 * @return array{allowed:bool,message:string}
 */
function reservation_payment_eligibility(array $booking, bool $allowCancellationSettlement = false): array
{
    $status = (string)($booking['status'] ?? '');

    if ($status === 'cancelled') {
        if ($allowCancellationSettlement) {
            return ['allowed' => true, 'message' => ''];
        }
        return ['allowed' => false, 'message' => 'Cancelled reservations must be settled from Reservation Details using the cancellation payment workflow.'];
    }

    if (in_array($status, ['rejected', 'no_show'], true)) {
        return ['allowed' => false, 'message' => 'This reservation is not eligible for the standard collection workflow.'];
    }

    if (reservation_needs_resolution($booking)) {
        return ['allowed' => false, 'message' => 'Resolve the lapsed reservation outcome before recording a collection.'];
    }

    if (!reservation_holds_calendar($booking) && $status !== 'completed') {
        return ['allowed' => false, 'message' => 'Only secured or completed reservations can receive a collection payment.'];
    }

    return ['allowed' => true, 'message' => ''];
}

function reservation_can_receive_payment(array $booking, bool $allowCancellationSettlement = false): bool
{
    return reservation_payment_eligibility($booking, $allowCancellationSettlement)['allowed'];
}

/** Operational details are editable only before the booked service ends. */
function reservation_can_edit(array $booking): bool
{
    return empty($booking['archived_at'])
        && (string)($booking['status'] ?? '') !== 'cancelled'
        && !reservation_has_ended($booking);
}

function reservation_can_reschedule(array $booking): bool
{
    return empty($booking['archived_at'])
        && in_array((string)($booking['status'] ?? ''), reschedulable_reservation_statuses(), true)
        && !reservation_has_ended($booking);
}

function reservation_can_update_status(array $booking): bool
{
    return empty($booking['archived_at'])
        && (string)($booking['status'] ?? '') !== 'cancelled'
        && !reservation_has_ended($booking);
}

function active_reservation_statuses(): array
{
    return ['pending', 'for_review', 'approved'];
}

function reservation_type_label(string $type): string
{
    return match ($type) {
        'basketball' => 'Basketball Court',
        'volleyball' => 'Volleyball Court',
        default => 'Events Reservation',
    };
}

function booking_payment_methods(): array
{
    return ['Cash', 'GCash', 'Bank Transfer', 'Card', 'Other'];
}

function booking_payment_method_label(string $method): string
{
    return $method === 'Cash' ? 'Cash / Pay at Venue' : $method;
}


/**
 * The client-cancellation policy retains half of the reservation price.
 * The balance of a fully paid reservation is refundable. For partial
 * payments, only the amount paid above the 50% cancellation charge is due
 * back to the client; any shortfall remains payable.
 */
// ============================================================================
// CANCELLATION & PAYMENT LEDGER RULES
// Cancellation finance and payment totals are ledger-driven. Refund entries are
// negative ledger transactions so revenue is not counted twice.
// ============================================================================
function reservation_cancellation_rate(): float
{
    return 50.0;
}

function reservation_can_cancel(array $booking): bool
{
    if (reservation_is_archived($booking) || reservation_has_ended($booking)) {
        return false;
    }

    return in_array((string)($booking['status'] ?? ''), active_reservation_statuses(), true);
}

function reservation_cancellation_calculation(array $booking, float $amountPaid): array
{
    $originalTotal = reservation_payment_target($booking);
    $rate = reservation_cancellation_rate();
    $cancellationFee = round($originalTotal * ($rate / 100), 2);
    $policyRefundablePortion = round($originalTotal - $cancellationFee, 2);
    $amountPaid = max(0, round($amountPaid, 2));
    $refundDue = max(0, round($amountPaid - $cancellationFee, 2));
    $balanceDue = max(0, round($cancellationFee - $amountPaid, 2));

    return [
        'rate' => $rate,
        'original_total' => $originalTotal,
        'cancellation_fee' => $cancellationFee,
        'policy_refundable_portion' => $policyRefundablePortion,
        'amount_paid' => $amountPaid,
        'refund_due' => $refundDue,
        'balance_due' => $balanceDue,
    ];
}

function reservation_payment_ledger_totals(int $reservationId): array
{
    $stmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) AS gross_paid,
        COALESCE(ABS(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END)),0) AS refunded,
        COALESCE(SUM(amount),0) AS net_paid
      FROM payments
      WHERE reservation_id=?");
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch() ?: [];

    return [
        'gross_paid' => round((float)($row['gross_paid'] ?? 0), 2),
        'refunded' => round((float)($row['refunded'] ?? 0), 2),
        'net_paid' => round((float)($row['net_paid'] ?? 0), 2),
    ];
}

function reservation_cancellation_record(int $reservationId): ?array
{
    try {
        $stmt = db()->prepare('SELECT c.*, ca.full_name AS cancelled_by_name, ra.full_name AS refunded_by_name FROM reservation_cancellations c LEFT JOIN admins ca ON ca.id=c.cancelled_by LEFT JOIN admins ra ON ra.id=c.refunded_by WHERE c.reservation_id=? ORDER BY c.id DESC LIMIT 1');
        $stmt->execute([$reservationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function payment_transaction_type(array $payment): string
{
    $stored = strtolower(trim((string)($payment['transaction_type'] ?? '')));
    if ($stored === 'refund' || (float)($payment['amount'] ?? 0) < 0) {
        return 'refund';
    }
    return 'payment';
}

function payment_transaction_label(array $payment): string
{
    return payment_transaction_type($payment) === 'refund' ? 'Refund' : 'Payment';
}

/**
 * Paid booking choices use 30-minute increments from 6:00 AM to 10:00 PM.
 * Setup and cleanup also use 30-minute blocks, so occupancy, pricing, and
 * customer-facing schedule choices now share the same time grid.
 */
// ============================================================================
// SCHEDULE INPUT HELPERS
// 30-minute UI slots and duration helpers are shared across public, admin,
// reschedule, extend, and batch workflows.
// ============================================================================
function reservation_time_slots(): array
{
    static $slots = null;
    if ($slots !== null) {
        return $slots;
    }

    $slots = [];
    $time = new DateTimeImmutable('2000-01-01 06:00:00');
    $last = new DateTimeImmutable('2000-01-01 22:00:00');
    while ($time <= $last) {
        $slots[$time->format('H:i')] = $time->format('g:i A');
        $time = $time->modify('+30 minutes');
    }

    return $slots;
}

function reservation_start_time_slots(): array
{
    $slots = reservation_time_slots();
    array_pop($slots); // 10:00 PM is available only as an ending time.
    return $slots;
}

function reservation_end_time_slots(): array
{
    $slots = reservation_time_slots();
    array_shift($slots); // 6:00 AM is available only as a starting time.
    return $slots;
}

function reservation_duration_hours_from_input(mixed $value): ?float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || $value === null || !is_numeric($value)) {
        return null;
    }
    $hours = round((float)$value, 2);
    if ($hours < 0.5 || $hours > 16 || abs(($hours * 2) - round($hours * 2)) > 0.0001) {
        return null;
    }
    return $hours;
}

function reservation_duration_label(float $hours): string
{
    $hours = round($hours, 2);
    if (abs($hours - 0.5) < 0.001) {
        return '30 minutes';
    }
    $text = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    return $text . ' hour' . (abs($hours - 1.0) < 0.001 ? '' : 's');
}

function reservation_duration_options(float $maxHours = 16.0): array
{
    $maxHours = min(16.0, max(0.5, floor($maxHours * 2) / 2));
    $options = [];
    for ($halfHours = 1; $halfHours <= (int)round($maxHours * 2); $halfHours++) {
        $hours = $halfHours / 2;
        $value = rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');
        $options[$value] = reservation_duration_label($hours);
    }
    return $options;
}

function reservation_add_duration(DateTimeImmutable $start, float $hours): DateTimeImmutable
{
    $minutes = (int)round($hours * 60);
    return $start->modify('+' . $minutes . ' minutes');
}

function reservation_date_is_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function reservation_payment_target(array $booking): float
{
    $target = $booking['final_amount'] ?? null;
    if ($target === null || $target === '') {
        $target = $booking['estimated_amount'] ?? 0;
    }
    return max(0, round((float)$target, 2));
}

/**
 * Derive the effective flexible discount represented by a gross calculated
 * total and an approved payable total. If the payable is above the gross, the
 * difference is an upward final-amount adjustment rather than a discount.
 */
function reservation_discount_for_payable(float $grossTotal, float $payableTotal): float
{
    $grossTotal = max(0, round($grossTotal, 2));
    $payableTotal = max(0, round($payableTotal, 2));
    return max(0, round($grossTotal - $payableTotal, 2));
}

/**
 * Preserve a saved flexible discount amount when a reservation is repriced.
 * A discount may never consume the entire newly calculated total because the
 * normal reservation creation flow also requires a positive client payable.
 */
function reservation_reprice_with_discount(float $grossTotal, float $storedDiscount): array
{
    $grossTotal = max(0, round($grossTotal, 2));
    $storedDiscount = max(0, round($storedDiscount, 2));

    if ($storedDiscount <= 0.001) {
        return [
            'discount_amount' => 0.0,
            'payable_total' => $grossTotal,
        ];
    }

    $maximumDiscount = max(0, round($grossTotal - 0.01, 2));
    $effectiveDiscount = min($storedDiscount, $maximumDiscount);

    return [
        'discount_amount' => $effectiveDiscount,
        'payable_total' => round($grossTotal - $effectiveDiscount, 2),
    ];
}

/**
 * Build a client-facing itemization from the reservation's saved pricing fields.
 *
 * The saved hourly rate, duration, and add-on fees are used so later changes in
 * Rates Settings do not alter an existing reservation's printed charges. When
 * an administrator has approved a different final amount, a reconciliation
 * adjustment is included so the itemized rows still equal the payable total.
 */
// ============================================================================
// SAVED CHARGE / BALANCE ITEMIZATION
// These helpers describe financial state for existing bookings. They must prefer
// stored booking values and ledger totals rather than current rate settings.
// ============================================================================
function reservation_charge_itemization(array $booking): array
{
    $snapshot = json_decode((string)($booking['pricing_snapshot'] ?? ''), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }

    $target = reservation_payment_target($booking);
    $package = (string)($booking['pricing_package'] ?? ($snapshot['package'] ?? ''));
    $cooling = (string)($booking['cooling_option'] ?? ($snapshot['cooling_option'] ?? ''));
    $ratePeriod = (string)($booking['rate_period'] ?? ($snapshot['rate_period'] ?? ''));
    $hourlyRate = round((float)($booking['hourly_rate'] ?? ($snapshot['hourly_rate'] ?? 0)), 2);

    $hours = (float)($booking['billable_hours'] ?? ($snapshot['billable_hours'] ?? 0));
    if ($hours <= 0 && !empty($booking['event_start']) && !empty($booking['event_end'])) {
        try {
            $start = new DateTimeImmutable((string)$booking['event_start']);
            $end = new DateTimeImmutable((string)$booking['event_end']);
            $hours = max(0, round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2));
        } catch (Throwable $e) {
            $hours = 0;
        }
    }

    $showerSelected = !empty($booking['shower_room_addon']) || !empty($snapshot['shower_room_addon']);
    $showerComplimentary = $showerSelected && (
        !empty($snapshot['shower_room_complimentary'])
        || (array_key_exists('shower_room_fee', $booking) && round((float)$booking['shower_room_fee'], 2) <= 0.0)
    );
    $equipmentSelected = !empty($booking['equipment_bundle_addon']) || !empty($snapshot['equipment_bundle_addon']);
    $equipmentIncluded = $package !== 'regular' && $equipmentSelected;
    $equipmentComplimentary = !$equipmentIncluded && $equipmentSelected && (
        !empty($snapshot['equipment_bundle_complimentary'])
        || (array_key_exists('equipment_bundle_fee', $booking) && round((float)$booking['equipment_bundle_fee'], 2) <= 0.0)
    );
    $equipmentRateUnit = (string)($snapshot['equipment_bundle_rate_unit'] ?? 'reservation');
    $equipmentHourlyRate = isset($snapshot['equipment_bundle_hourly_rate']) && is_numeric($snapshot['equipment_bundle_hourly_rate'])
        ? max(0, round((float)$snapshot['equipment_bundle_hourly_rate'], 2))
        : (($equipmentRateUnit === 'hour' && $hours > 0)
            ? max(0, round((float)($booking['equipment_bundle_fee'] ?? 0) / $hours, 2))
            : 0.0);
    $showerFee = $showerSelected
        ? max(0, round((float)($booking['shower_room_fee'] ?? ($snapshot['shower_room_fee'] ?? 0)), 2))
        : 0.0;
    $equipmentFee = $equipmentSelected
        ? max(0, round((float)($booking['equipment_bundle_fee'] ?? ($snapshot['equipment_bundle_fee'] ?? 0)), 2))
        : 0.0;

    $snapshotBase = isset($snapshot['base_amount']) && is_numeric($snapshot['base_amount'])
        ? round((float)$snapshot['base_amount'], 2)
        : null;
    if ($hourlyRate > 0 && $hours > 0) {
        $baseAmount = round($hourlyRate * $hours, 2);
    } elseif ($snapshotBase !== null && $snapshotBase >= 0) {
        $baseAmount = $snapshotBase;
    } else {
        // Legacy reservations may not have saved automatic-pricing fields.
        $baseAmount = max(0, round($target - $showerFee - $equipmentFee, 2));
    }

    $subtotal = round($baseAmount + $showerFee + $equipmentFee, 2);
    $adjustment = round($target - $subtotal, 2);
    if (abs($adjustment) < 0.005) {
        $adjustment = 0.0;
    }

    $packageLabel = reservation_package_label($package);
    $coolingLabel = reservation_cooling_label($cooling);
    $baseLabelParts = [];
    if ($packageLabel !== 'Not recorded') {
        $baseLabelParts[] = $packageLabel;
    }
    if ($coolingLabel !== 'Not recorded') {
        $baseLabelParts[] = $coolingLabel;
    }
    $baseLabel = $baseLabelParts ? implode(' — ', $baseLabelParts) : 'Venue Rental';

    $hoursText = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    if ($hoursText === '') {
        $hoursText = '0';
    }
    $ratePrefix = '';
    if ($package === 'regular' && in_array($ratePeriod, ['introductory', 'original'], true)) {
        $ratePrefix = ucfirst($ratePeriod) . ' rate · ';
    }
    if ($hourlyRate > 0 && $hours > 0) {
        $baseDetail = $ratePrefix . $hoursText . ' hour' . (abs($hours - 1) < 0.001 ? '' : 's') . ' × ' . money($hourlyRate) . ' per hour';
    } else {
        $baseDetail = 'Saved venue-rental charge';
    }

    $rows = [[
        'key' => 'venue',
        'label' => $baseLabel,
        'detail' => $baseDetail,
        'amount' => $baseAmount,
        'signed' => false,
    ]];
    if ($showerSelected) {
        $rows[] = [
            'key' => $showerComplimentary ? 'shower_complimentary' : 'shower',
            'label' => 'Shower Room',
            'detail' => $showerComplimentary ? 'Complimentary — fee waived by admin' : 'One-time fee per reservation',
            'amount' => $showerFee,
            'signed' => false,
        ];
    }
    if (!$equipmentIncluded && $equipmentSelected) {
        $equipmentDetail = 'One-time fee per reservation';
        if ($equipmentComplimentary) {
            $equipmentDetail = 'Complimentary — fee waived by admin';
        } elseif ($equipmentRateUnit === 'hour' && $equipmentHourlyRate > 0 && $hours > 0) {
            $equipmentDetail = $hoursText . ' hour' . (abs($hours - 1) < 0.001 ? '' : 's') . ' × ' . money($equipmentHourlyRate) . ' per hour';
        }
        $rows[] = [
            'key' => $equipmentComplimentary ? 'equipment_complimentary' : 'equipment',
            'label' => 'Equipment Bundle',
            'detail' => $equipmentDetail,
            'amount' => $equipmentFee,
            'signed' => false,
        ];
    }
    if ($adjustment !== 0.0) {
        $isCancellationAdjustment = (string)($booking['status'] ?? '') === 'cancelled'
            && $adjustment < 0
            && (!empty($booking['_cancellation_policy_applied']) || !empty($booking['cancellation_record_id']));
        $storedDiscount = max(0, round((float)($booking['discount_amount'] ?? 0), 2));
        $isFlexibleDiscount = !$isCancellationAdjustment && $adjustment < 0 && $storedDiscount > 0.001;
        $rows[] = [
            'key' => $isCancellationAdjustment ? 'cancellation_adjustment' : ($isFlexibleDiscount ? 'discount' : 'adjustment'),
            'label' => $isCancellationAdjustment
                ? rtrim(rtrim(number_format(reservation_cancellation_rate(), 2, '.', ''), '0'), '.') . '% Cancellation Policy Adjustment'
                : ($isFlexibleDiscount ? 'Flexible Discount' : ($adjustment < 0 ? 'Final Amount Discount / Adjustment' : 'Final Amount Adjustment')),
            'detail' => $isCancellationAdjustment
                ? 'Reduces the original reservation price to the required cancellation charge'
                : ($isFlexibleDiscount
                    ? ((string)($booking['discount_reason'] ?? '') !== '' ? (string)$booking['discount_reason'] : 'Admin-applied discount')
                    : 'Reconciles the itemized charges with the approved payable total'),
            'amount' => $adjustment,
            'signed' => true,
        ];
    }

    return [
        'rows' => $rows,
        'base_label' => $baseLabel,
        'base_detail' => $baseDetail,
        'base_amount' => $baseAmount,
        'hours' => $hours,
        'hourly_rate' => $hourlyRate,
        'shower_fee' => $showerFee,
        'shower_complimentary' => $showerComplimentary,
        'equipment_fee' => $equipmentFee,
        'equipment_included' => $equipmentIncluded,
        'equipment_complimentary' => $equipmentComplimentary,
        'equipment_rate_unit' => $equipmentRateUnit,
        'equipment_hourly_rate' => $equipmentHourlyRate,
        'subtotal' => $subtotal,
        'adjustment' => $adjustment,
        'total' => $target,
    ];
}

function reservation_remaining_balance(array $booking): float
{
    return max(0, round(reservation_payment_target($booking) - (float)($booking['amount_paid'] ?? 0), 2));
}

function reservation_excess_credit(array $booking): float
{
    return max(0, round((float)($booking['amount_paid'] ?? 0) - reservation_payment_target($booking), 2));
}

/**
 * Consolidate saved charge itemizations from all occurrences in a batch.
 */
function reservation_batch_charge_breakdown(array $bookings): array
{
    $groups = [];
    $total = 0.0;

    $addGroup = static function (array &$groups, string $key, array $row): void {
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'type' => (string)$row['type'],
                'label' => (string)$row['label'],
                'detail' => (string)$row['detail'],
                'amount' => 0.0,
                'count' => 0,
                'hours' => $row['hours'] ?? null,
                'unit_amount' => $row['unit_amount'] ?? null,
                'rate_period' => (string)($row['rate_period'] ?? ''),
            ];
        }
        $groups[$key]['count']++;
        $groups[$key]['amount'] = round((float)$groups[$key]['amount'] + (float)$row['amount'], 2);
    };

    foreach ($bookings as $booking) {
        $itemization = reservation_charge_itemization($booking);
        $total = round($total + (float)$itemization['total'], 2);

        $hours = round((float)($itemization['hours'] ?? 0), 2);
        $hourlyRate = round((float)($itemization['hourly_rate'] ?? 0), 2);
        $ratePeriod = (string)($booking['rate_period'] ?? '');
        $venueKey = implode('|', [
            'venue',
            (string)$itemization['base_label'],
            $ratePeriod,
            number_format($hours, 2, '.', ''),
            number_format($hourlyRate, 2, '.', ''),
        ]);
        $addGroup($groups, $venueKey, [
            'type' => 'venue',
            'label' => (string)$itemization['base_label'],
            'detail' => (string)$itemization['base_detail'],
            'amount' => (float)$itemization['base_amount'],
            'hours' => $hours,
            'unit_amount' => $hourlyRate,
            'rate_period' => $ratePeriod,
        ]);

        $showerFee = round((float)($itemization['shower_fee'] ?? 0), 2);
        if (!empty($itemization['shower_complimentary'])) {
            $addGroup($groups, 'shower_complimentary', [
                'type' => 'shower_complimentary',
                'label' => 'Shower Room',
                'detail' => 'Complimentary — fee waived by admin',
                'amount' => 0.0,
                'unit_amount' => null,
            ]);
        } elseif ($showerFee > 0.004) {
            $addGroup($groups, 'shower|' . number_format($showerFee, 2, '.', ''), [
                'type' => 'shower',
                'label' => 'Shower Room',
                'detail' => 'One-time fee per reservation date',
                'amount' => $showerFee,
                'unit_amount' => $showerFee,
            ]);
        }

        $equipmentFee = round((float)($itemization['equipment_fee'] ?? 0), 2);
        if (!empty($itemization['equipment_complimentary'])) {
            $addGroup($groups, 'equipment_complimentary', [
                'type' => 'equipment_complimentary',
                'label' => 'Equipment Bundle',
                'detail' => 'Complimentary — fee waived by admin',
                'amount' => 0.0,
                'unit_amount' => null,
            ]);
        } elseif ($equipmentFee > 0.004) {
            $equipmentRateUnit = (string)($itemization['equipment_rate_unit'] ?? 'reservation');
            $equipmentHourlyRate = round((float)($itemization['equipment_hourly_rate'] ?? 0), 2);
            if ($equipmentRateUnit === 'hour' && $equipmentHourlyRate > 0 && $hours > 0) {
                $equipmentKey = implode('|', [
                    'equipment_hourly',
                    number_format($hours, 2, '.', ''),
                    number_format($equipmentHourlyRate, 2, '.', ''),
                ]);
                $addGroup($groups, $equipmentKey, [
                    'type' => 'equipment_hourly',
                    'label' => 'Equipment Bundle',
                    'detail' => 'Hourly equipment fee',
                    'amount' => $equipmentFee,
                    'hours' => $hours,
                    'unit_amount' => $equipmentHourlyRate,
                ]);
            } else {
                $addGroup($groups, 'equipment|' . number_format($equipmentFee, 2, '.', ''), [
                    'type' => 'equipment',
                    'label' => 'Equipment Bundle',
                    'detail' => 'One-time fee per reservation date',
                    'amount' => $equipmentFee,
                    'unit_amount' => $equipmentFee,
                ]);
            }
        }

        $adjustment = round((float)($itemization['adjustment'] ?? 0), 2);
        if (abs($adjustment) > 0.004) {
            $isCancellationAdjustment = (string)($booking['status'] ?? '') === 'cancelled'
            && $adjustment < 0
            && (!empty($booking['_cancellation_policy_applied']) || !empty($booking['cancellation_record_id']));
            $isFlexibleDiscount = !$isCancellationAdjustment && $adjustment < 0 && (float)($booking['discount_amount'] ?? 0) > 0.001;
            $adjustmentType = $isCancellationAdjustment ? 'cancellation_adjustment' : ($isFlexibleDiscount ? 'discount' : 'adjustment');
            $addGroup($groups, $adjustmentType . '|' . ($adjustment < 0 ? 'negative' : 'positive'), [
                'type' => $adjustmentType,
                'label' => $isCancellationAdjustment
                    ? rtrim(rtrim(number_format(reservation_cancellation_rate(), 2, '.', ''), '0'), '.') . '% Cancellation Policy Adjustment'
                    : ($isFlexibleDiscount ? 'Flexible Discount' : ($adjustment < 0 ? 'Final Amount Discount / Adjustment' : 'Final Amount Adjustment')),
                'detail' => $isCancellationAdjustment
                    ? 'Reduces original reservation prices to their required cancellation charges'
                    : ($isFlexibleDiscount ? 'Admin-applied discount across the selected batch dates' : 'Reconciles calculated charges with approved payable totals'),
                'amount' => $adjustment,
            ]);
        }
    }

    $order = ['venue' => 10, 'shower' => 20, 'shower_complimentary' => 21, 'equipment' => 30, 'equipment_hourly' => 30, 'equipment_complimentary' => 31, 'cancellation_adjustment' => 40, 'adjustment' => 50];
    $groups = array_values($groups);
    usort($groups, static function (array $left, array $right) use ($order): int {
        $comparison = ($order[$left['type']] ?? 99) <=> ($order[$right['type']] ?? 99);
        return $comparison !== 0 ? $comparison : strcmp((string)$left['label'], (string)$right['label']);
    });

    $rows = [];
    foreach ($groups as $group) {
        $count = max(1, (int)$group['count']);
        $dateText = $count . ' reservation date' . ($count === 1 ? '' : 's');
        $detail = (string)$group['detail'];
        $unitAmount = $group['unit_amount'] !== null ? round((float)$group['unit_amount'], 2) : null;
        $hours = $group['hours'] !== null ? round((float)$group['hours'], 2) : null;

        if ($group['type'] === 'venue' && $hours !== null && $hours > 0 && $unitAmount !== null && $unitAmount > 0) {
            $hoursText = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
            $periodText = in_array($group['rate_period'], ['introductory', 'original'], true)
                ? ucfirst((string)$group['rate_period']) . ' rate · '
                : '';
            $detail = $periodText . $dateText . ' × ' . $hoursText . ' hour' . (abs($hours - 1) < 0.001 ? '' : 's') . ' × ' . money($unitAmount) . ' per hour';
        } elseif ($group['type'] === 'equipment_hourly' && $hours !== null && $hours > 0 && $unitAmount !== null && $unitAmount > 0) {
            $hoursText = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
            $detail = $dateText . ' × ' . $hoursText . ' hour' . (abs($hours - 1) < 0.001 ? '' : 's') . ' × ' . money($unitAmount) . ' per hour';
        } elseif (in_array($group['type'], ['shower', 'equipment'], true) && $unitAmount !== null) {
            $detail = $dateText . ' × ' . money($unitAmount) . ' one-time fee';
        } elseif ($group['type'] === 'cancellation_adjustment') {
            $detail = '50% cancellation policy applied across ' . $dateText;
        } elseif ($group['type'] === 'adjustment') {
            $detail = ((float)$group['amount'] < 0 ? 'Approved deductions' : 'Approved additional charges') . ' across ' . $dateText;
        } elseif ($count > 1) {
            $detail = $dateText . ' · ' . $detail;
        }

        $rows[] = [
            'type' => (string)$group['type'],
            'label' => (string)$group['label'],
            'detail' => $detail,
            'amount' => round((float)$group['amount'], 2),
            'count' => $count,
        ];
    }

    return ['rows' => $rows, 'total' => $total];
}

function reservation_charge_money(float|string|int|null $amount): string
{
    $value = round((float)$amount, 2);
    return $value < -0.004 ? '−' . money(abs($value)) : money($value);
}

function reschedulable_reservation_statuses(): array
{
    return ['pending', 'for_review', 'approved'];
}

function extendable_reservation_statuses(): array
{
    return ['pending', 'for_review', 'approved'];
}

function late_extendable_reservation_statuses(): array
{
    return ['approved', 'completed'];
}

function reservation_max_extension_hours(array $booking): float
{
    try {
        $eventEnd = new DateTimeImmutable((string)($booking['event_end'] ?? ''));
    } catch (Throwable $e) {
        return 0.0;
    }

    $closingTime = $eventEnd->setTime(22, 0, 0);
    if ($eventEnd >= $closingTime) {
        return 0.0;
    }

    $secondsAvailable = $closingTime->getTimestamp() - $eventEnd->getTimestamp();
    return max(0.0, floor($secondsAvailable / 1800) / 2);
}

function reservation_can_extend(array $booking): bool
{
    if (!in_array((string)($booking['status'] ?? ''), extendable_reservation_statuses(), true)) {
        return false;
    }

    try {
        $eventEnd = new DateTimeImmutable((string)($booking['event_end'] ?? ''));
    } catch (Throwable $e) {
        return false;
    }

    return $eventEnd > new DateTimeImmutable()
        && reservation_max_extension_hours($booking) > 0
        && round((float)($booking['hourly_rate'] ?? 0), 2) > 0;
}


/**
 * Late Extension is intentionally narrower than a normal extension: the
 * original event must already have ended and the reservation must represent an
 * actual secured/completed event. Pending/For Review records must be resolved
 * first, while rejected/cancelled/no-show records are never extendable.
 */
function reservation_can_late_extend(array $booking): bool
{
    return empty($booking['archived_at'])
        && in_array((string)($booking['status'] ?? ''), late_extendable_reservation_statuses(), true)
        && reservation_has_ended($booking)
        && reservation_max_extension_hours($booking) >= 0.5
        && round((float)($booking['hourly_rate'] ?? 0), 2) > 0;
}

function reservation_can_record_extension(array $booking): bool
{
    return reservation_can_extend($booking) || reservation_can_late_extend($booking);
}

function payment_status_for_amount(float $amountPaid, float $target): string
{
    if ($amountPaid <= 0) {
        return 'unpaid';
    }
    if ($target <= 0 || $amountPaid + 0.001 >= $target) {
        return 'paid';
    }
    return 'partial';
}

function badge_class(string $status): string
{
    return match ($status) {
        'approved', 'paid', 'completed' => 'success',
        'pending', 'for_review', 'partial' => 'warning',
        'rejected', 'cancelled', 'no_show' => 'danger',
        default => 'secondary',
    };
}

// ============================================================================
// ADMIN AUTHENTICATION & SESSION REVALIDATION
// Every protected admin route calls admin_required(). It also rechecks whether
// the account is still active so disabled users do not keep stale sessions.
// ============================================================================
function admin_required(): void
{
    static $validated = false;

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    if (empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
    if ($validated) {
        return;
    }

    try {
        $stmt = db()->prepare('SELECT id, full_name, role, is_active FROM admins WHERE id=? LIMIT 1');
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $account = $stmt->fetch();
        if (!$account || empty($account['is_active'])) {
            throw new RuntimeException('inactive');
        }

        $_SESSION['admin_id'] = (int)$account['id'];
        $_SESSION['admin_name'] = (string)$account['full_name'];
        $_SESSION['admin_role'] = (string)$account['role'];
        $validated = true;
    } catch (Throwable $e) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        flash('danger', 'Your admin session is no longer active or could not be verified. Please sign in again.');
        redirect('login.php');
    }
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['admin_id'],
        'name' => (string)($_SESSION['admin_name'] ?? 'Administrator'),
        'role' => (string)($_SESSION['admin_role'] ?? 'staff'),
    ];
}

function is_admin(): bool
{
    return ($_SESSION['admin_role'] ?? '') === 'admin';
}

/**
 * Content expansion introduced in v1.0.8.
 * This lightweight migration keeps existing XAMPP installations working
 * without requiring a separate schema installer to be run again.
 */
// ============================================================================
// AUTOMATIC SCHEMA COMPATIBILITY UPGRADES
// These ensure_* helpers make older installations compatible without requiring
// the operator to run a separate installer. Existing databases are upgraded in place.
// Failures are intentionally non-fatal and are retried on later requests.
// ============================================================================
function ensure_v108_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS hero_slides (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          eyebrow VARCHAR(120) NOT NULL DEFAULT 'The Leisure Hub',
          title VARCHAR(180) NOT NULL,
          body TEXT NULL,
          primary_label VARCHAR(80) NULL,
          primary_url VARCHAR(255) NULL,
          secondary_label VARCHAR(80) NULL,
          secondary_url VARCHAR(255) NULL,
          image_path VARCHAR(255) NOT NULL,
          image_position VARCHAR(40) NOT NULL DEFAULT 'center 50%',
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          store_name VARCHAR(160) NOT NULL,
          slug VARCHAR(180) NOT NULL UNIQUE,
          category VARCHAR(100) NULL,
          short_description TEXT NULL,
          unit_location VARCHAR(120) NULL,
          contact_phone VARCHAR(50) NULL,
          website_url VARCHAR(255) NULL,
          facebook_url VARCHAR(255) NULL,
          image_path VARCHAR(255) NULL,
          is_featured TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Database-backed image storage is a permanent fallback for Windows/XAMPP
        // installations where Apache cannot write inside the website folder.
        $pdo->exec("CREATE TABLE IF NOT EXISTS media_assets (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          original_name VARCHAR(255) NULL,
          mime_type VARCHAR(80) NOT NULL,
          size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS media_asset_chunks (
          asset_id BIGINT UNSIGNED NOT NULL,
          chunk_no INT UNSIGNED NOT NULL,
          chunk_data MEDIUMBLOB NOT NULL,
          PRIMARY KEY (asset_id, chunk_no)
        ) ENGINE=InnoDB");

        $settings = [
            'leasing_title' => 'Grow your business at The Leisure Hub',
            'leasing_text' => 'Position your brand inside a destination built for sports, events, dining, services, and community experiences.',
            'leasing_email' => setting('email', 'info@theleisurehub.local'),
        ];
        $settingStmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
        foreach ($settings as $key => $value) {
            $settingStmt->execute([$key, $value]);
        }

        $slideCount = (int)$pdo->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
        if ($slideCount === 0) {
            $slideStmt = $pdo->prepare('INSERT INTO hero_slides
                (eyebrow,title,body,primary_label,primary_url,secondary_label,secondary_url,image_path,image_position,sort_order,is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,1)');
            $slideStmt->execute([
                'SPORTS, LIFESTYLE & EVENTS VENUE',
                setting('hero_title', 'Play. Celebrate. Connect.'),
                setting('hero_text', 'A flexible multipurpose venue for sports, celebrations, corporate functions, and community events.'),
                'Reserve the Venue', 'reserve.php', 'Check Availability', 'availability.php',
                'assets/img/leisure-hub-hero.jpg', 'center 49%', 10,
            ]);
            $slideStmt->execute([
                'Commercial Spaces for Lease',
                'A place for brands to grow.',
                'Bring your concept closer to athletes, families, professionals, and event guests in one active community destination.',
                'Explore Leasing', 'leasing.php', 'View Our Stores', 'stores.php',
                'assets/img/leisure-hub-hero.jpg', 'center 56%', 20,
            ]);
            $slideStmt->execute([
                'Shops, Services & Experiences',
                'More than a venue.',
                'Discover the businesses, food concepts, fitness services, and everyday experiences that make The Leisure Hub a complete destination.',
                'Explore Stores', 'stores.php', 'Visit The Hub', 'venue.php',
                'assets/img/leisure-hub-hero.jpg', 'center 43%', 30,
            ]);
        }
    } catch (Throwable $e) {
        // The database may not be available yet. Public pages already handle
        // unavailable tables gracefully, so no fatal error is raised here.
    }
}

// ============================================================================
// GENERAL TEXT / URL / UPLOAD UTILITIES
// Shared low-level helpers used by multiple content-management screens.
// ============================================================================
function text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function text_initial(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '?';
    }
    return function_exists('mb_substr') ? mb_substr($value, 0, 1) : substr($value, 0, 1);
}

function text_excerpt(string $value, int $width = 90, string $suffix = '…'): string
{
    $value = trim($value);
    $width = max(1, $width);
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, $suffix);
    }

    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    $suffixCharacters = preg_split('//u', $suffix, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($characters) && is_array($suffixCharacters)) {
        if (count($characters) <= $width) {
            return $value;
        }
        $visible = max(1, $width - count($suffixCharacters));
        return rtrim(implode('', array_slice($characters, 0, $visible))) . $suffix;
    }

    if (strlen($value) <= $width) {
        return $value;
    }
    return rtrim(substr($value, 0, max(1, $width - strlen($suffix)))) . $suffix;
}

function safe_href(?string $url, string $fallback = '#'): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return $fallback;
    }
    if (preg_match('~^(?:https?://|mailto:|tel:|[a-z0-9_./?=&%#-]+$)~i', $url)) {
        return $url;
    }
    return $fallback;
}

function slugify(string $value): string
{
    $value = trim(text_lower($value));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'store-' . bin2hex(random_bytes(3));
}

function ensure_writable_upload_folder(string $relativeFolder): array
{
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $relativeFolder = trim(str_replace('\\', '/', $relativeFolder), '/') . '/';
    $leaf = trim(basename(rtrim($relativeFolder, '/')), '/');
    $candidates = [
        $relativeFolder,
        'uploads/' . $leaf . '_files/',
        'assets/img/' . $leaf . '_uploads/',
    ];

    foreach ($candidates as $relative) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($relative, '/'));
        if (!is_dir($absolute) && !@mkdir($absolute, 0755, true) && !is_dir($absolute)) {
            continue;
        }
        @chmod($absolute, 0755);
        clearstatcache(true, $absolute);
        $probe = $absolute . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok', LOCK_EX) !== false) {
            @unlink($probe);
            return ['absolute' => $absolute, 'relative' => rtrim($relative, '/') . '/'];
        }
    }

    throw new RuntimeException('No writable image folder was found.');
}

/**
 * Store an uploaded image in MySQL in small chunks. This avoids Windows ACL
 * problems and keeps each database packet comfortably below common XAMPP limits.
 */
function store_image_in_database(string $temporaryFile, string $mime, string $originalName): string
{
    $pdo = db();
    $handle = @fopen($temporaryFile, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded image could not be opened for storage.');
    }

    $assetId = 0;
    try {
        $pdo->beginTransaction();
        $meta = $pdo->prepare('INSERT INTO media_assets (original_name, mime_type, size_bytes) VALUES (?, ?, ?)');
        $meta->execute([
            substr($originalName, 0, 255),
            $mime,
            (int)(filesize($temporaryFile) ?: 0),
        ]);
        $assetId = (int)$pdo->lastInsertId();

        $chunkInsert = $pdo->prepare('INSERT INTO media_asset_chunks (asset_id, chunk_no, chunk_data) VALUES (?, ?, ?)');
        $chunkNumber = 0;
        $chunkSize = 512 * 1024;
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                throw new RuntimeException('The uploaded image could not be read completely.');
            }
            if ($chunk === '') {
                continue;
            }
            $chunkInsert->bindValue(1, $assetId, PDO::PARAM_INT);
            $chunkInsert->bindValue(2, $chunkNumber++, PDO::PARAM_INT);
            $chunkInsert->bindValue(3, $chunk, PDO::PARAM_LOB);
            $chunkInsert->execute();
        }

        if ($chunkNumber === 0) {
            throw new RuntimeException('The selected image is empty.');
        }

        $pdo->commit();
        fclose($handle);
        return 'media.php?id=' . $assetId;
    } catch (Throwable $e) {
        fclose($handle);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($assetId > 0) {
            try {
                $pdo->prepare('DELETE FROM media_assets WHERE id=?')->execute([$assetId]);
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
}

function save_uploaded_image(array $file, string $folder, string $prefix = 'image'): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The image exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The image is larger than the allowed form limit.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select an image.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the temporary upload. Check the XAMPP temp folder and disk space.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        ];
        throw new RuntimeException($messages[$error] ?? 'The image could not be uploaded.');
    }

    $maxSize = 15 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) < 1 || (int)$file['size'] > $maxSize) {
        throw new RuntimeException('Images must be between 1 byte and 15 MB.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('The selected file was not received as a valid upload.');
    }

    $info = @getimagesize((string)$file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $mime = strtolower((string)($info['mime'] ?? ''));
    if ($info === false || !isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are supported. HEIC/HEIF images must be converted to JPG first.');
    }

    $filename = preg_replace('/[^a-z0-9-]+/i', '-', $prefix) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $filesystemFailure = '';

    try {
        $storage = ensure_writable_upload_folder($folder);
        $target = $storage['absolute'] . DIRECTORY_SEPARATOR . $filename;
        if (@move_uploaded_file((string)$file['tmp_name'], $target) || @copy((string)$file['tmp_name'], $target)) {
            @chmod($target, 0644);
            return $storage['relative'] . $filename;
        }
        @unlink($target);
        $filesystemFailure = 'The writable folder was found, but PHP could not save the file there.';
    } catch (Throwable $e) {
        $filesystemFailure = $e->getMessage();
    }

    // Permanent no-permission fallback: save the image inside MySQL and serve
    // it through media.php. Existing installations are upgraded automatically.
    try {
        return store_image_in_database(
            (string)$file['tmp_name'],
            $mime,
            (string)($file['name'] ?? $filename)
        );
    } catch (Throwable $databaseError) {
        throw new RuntimeException(
            'The image could not be saved to the website folder or the database. ' .
            'Folder result: ' . $filesystemFailure . ' Database result: ' . $databaseError->getMessage()
        );
    }
}

function delete_managed_image(?string $path): void
{
    if (!$path) {
        return;
    }

    if (preg_match('~(?:^|/)media\.php\?id=(\d+)$~', $path, $match)) {
        try {
            $assetId = (int)$match[1];
            db()->prepare('DELETE FROM media_asset_chunks WHERE asset_id=?')->execute([$assetId]);
            db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$assetId]);
        } catch (Throwable $ignored) {
        }
        return;
    }

    $allowedPrefixes = [
        'uploads/hero/', 'uploads/hero_files/',
        'uploads/tenants/', 'uploads/tenants_files/',
        'uploads/gallery/', 'uploads/gallery_files/',
        'assets/img/hero_uploads/', 'assets/img/tenants_uploads/', 'assets/img/gallery_uploads/',
    ];
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            return;
        }
    }
}


/**
 * Reservation payment-selection fields introduced in v1.0.13.
 * Existing installations receive the columns automatically on first load.
 */
// ---------------------------------------------------------------------------
// HISTORICAL SCHEMA MIGRATIONS
// Keep these versioned functions even when a fresh database no longer needs them;
// deployed sites may upgrade from much older versions.
// ---------------------------------------------------------------------------
function ensure_v1013_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$columnExists('booking_payment_method')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN booking_payment_method VARCHAR(80) NULL AFTER phone");
        }
        if (!$columnExists('booking_payment_reference')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN booking_payment_reference VARCHAR(120) NULL AFTER booking_payment_method");
        }
    } catch (Throwable $e) {
        // The database may be temporarily unavailable. Existing deployments retry
        // this compatibility upgrade on a later request.
    }
}

/**
 * Reservation rescheduling history introduced in v1.0.23.
 * The table is created automatically so existing installations keep their data.
 */
function ensure_v1023_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS reservation_reschedules (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          reservation_id BIGINT UNSIGNED NOT NULL,
          previous_event_start DATETIME NOT NULL,
          previous_event_end DATETIME NOT NULL,
          new_event_start DATETIME NOT NULL,
          new_event_end DATETIME NOT NULL,
          previous_blocked_start DATETIME NOT NULL,
          previous_blocked_end DATETIME NOT NULL,
          new_blocked_start DATETIME NOT NULL,
          new_blocked_end DATETIME NOT NULL,
          previous_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          new_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          previous_payment_status VARCHAR(30) NOT NULL,
          new_payment_status VARCHAR(30) NOT NULL,
          reason TEXT NOT NULL,
          changed_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_reschedule_reservation (reservation_id, created_at),
          CONSTRAINT fk_reschedule_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
          CONSTRAINT fk_reschedule_admin FOREIGN KEY (changed_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        // Newer databases already contain this table. Older sites retry this
        // compatibility upgrade after the main database is available.
    }
}

/**
 * Automatic proposal pricing introduced in v1.0.24.
 * Existing reservations keep their totals; new pricing fields are nullable so
 * historical bookings remain valid.
 */

function ensure_v1024_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };
        $columns = [
            'pricing_package' => "VARCHAR(30) NULL AFTER guest_count",
            'cooling_option' => "VARCHAR(20) NULL AFTER pricing_package",
            'rate_period' => "VARCHAR(30) NULL AFTER cooling_option",
            'hourly_rate' => "DECIMAL(12,2) NULL AFTER rate_period",
            'billable_hours' => "DECIMAL(6,2) UNSIGNED NULL AFTER hourly_rate",
            'shower_room_addon' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER billable_hours",
            'shower_room_fee' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shower_room_addon",
            'equipment_bundle_addon' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER shower_room_fee",
            'equipment_bundle_fee' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER equipment_bundle_addon",
            'pricing_snapshot' => "TEXT NULL AFTER equipment_bundle_fee",
        ];
        foreach ($columns as $name => $definition) {
            if (!$columnExists($name)) {
                $pdo->exec("ALTER TABLE reservations ADD COLUMN $name $definition");
            }
        }

        $defaults = reservation_pricing_defaults();
        $settings = [
            'pricing_intro_start' => $defaults['intro_start'],
            'pricing_intro_end' => $defaults['intro_end'],
        ];
        foreach (array_diff(array_keys($defaults), ['intro_start', 'intro_end']) as $key) {
            $settings['pricing_' . $key] = (string)$defaults[$key];
        }
        $stmt = $pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade.
    }
}


/**
 * Returns true when a reservation has already been moved to Archives.
 */
// ============================================================================
// ARCHIVE RULES
// Archiving removes completed work from active operational views without deleting
// reservation, payment, cancellation, or audit history.
// ============================================================================
function reservation_is_archived(array $booking): bool
{
    return !empty($booking['archived_at']);
}

/**
 * A reservation can be archived once it already has a closed status. An
 * Approved reservation may also be marked Done after its event ends. Pending
 * / For Review reservations must be resolved explicitly first and are never
 * converted to Completed merely because their scheduled time passed.
 */
function reservation_can_archive(array $booking): bool
{
    if (reservation_is_archived($booking)) {
        return false;
    }

    $status = (string)($booking['status'] ?? '');
    if ($status === 'cancelled') {
        // Cancelled reservations have their own permanent Admin section.
        // They should never be moved into Archives, even after settlement.
        return false;
    }
    if (in_array($status, ['completed', 'rejected', 'no_show'], true)) {
        return true;
    }
    if (in_array($status, ['pending', 'for_review'], true)) {
        return false;
    }

    return $status === 'approved' && reservation_has_ended($booking);
}

function reservation_archive_status(array $booking): string
{
    $status = (string)($booking['status'] ?? '');
    if (in_array($status, ['completed', 'rejected', 'cancelled', 'no_show'], true)) {
        return $status;
    }
    if ($status === 'approved' && reservation_has_ended($booking)) {
        return 'completed';
    }

    // Defensive fallback: never silently turn a Pending / For Review request
    // into a completed event if this helper is called outside the normal gate.
    return $status !== '' ? $status : 'pending';
}

/**
 * Reservation extension history introduced in v1.0.34.
 * Existing installations receive the table automatically on first load.
 */
function ensure_v1034_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS reservation_extensions (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          reservation_id BIGINT UNSIGNED NOT NULL,
          previous_event_end DATETIME NOT NULL,
          new_event_end DATETIME NOT NULL,
          previous_blocked_end DATETIME NOT NULL,
          new_blocked_end DATETIME NOT NULL,
          added_hours DECIMAL(5,2) UNSIGNED NOT NULL,
          hourly_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
          additional_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
          previous_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          new_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          previous_payment_status VARCHAR(30) NOT NULL,
          new_payment_status VARCHAR(30) NOT NULL,
          reason TEXT NOT NULL,
          changed_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_extension_reservation (reservation_id, created_at),
          CONSTRAINT fk_extension_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
          CONSTRAINT fk_extension_admin FOREIGN KEY (changed_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        // Newer databases already contain this table. Older sites retry this
        // compatibility upgrade after the main database is available.
    }
}


/**
 * Reservation Archives introduced in v1.0.35.
 * Existing installations receive archive columns and audit history
 * automatically on first load.
 */
function ensure_v1035_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$columnExists('archived_at')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN archived_at DATETIME NULL AFTER admin_notes");
        }
        if (!$columnExists('archived_by')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at");
        }

        $indexStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND INDEX_NAME='idx_reservation_archived'");
        $indexStmt->execute();
        if ((int)$indexStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE reservations ADD INDEX idx_reservation_archived (archived_at)");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_archives (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          reservation_id BIGINT UNSIGNED NOT NULL,
          archive_action VARCHAR(20) NOT NULL,
          previous_status VARCHAR(30) NOT NULL,
          new_status VARCHAR(30) NOT NULL,
          notes TEXT NULL,
          changed_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_archive_reservation (reservation_id, created_at),
          CONSTRAINT fk_archive_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
          CONSTRAINT fk_archive_admin FOREIGN KEY (changed_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade. Existing
        // sites retry automatically after the main database is available.
    }
}

/**
 * Batch / recurring reservations introduced in v1.0.37.
 * Each generated occurrence remains a normal reservation while sharing one
 * batch reference for grouping and management.
 */
function ensure_v1037_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_batches (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          batch_reference VARCHAR(40) NOT NULL UNIQUE,
          client_name VARCHAR(150) NOT NULL,
          organization VARCHAR(150) NULL,
          phone VARCHAR(40) NOT NULL,
          email VARCHAR(150) NOT NULL,
          reservation_type VARCHAR(30) NOT NULL,
          purpose VARCHAR(150) NULL,
          range_start DATE NOT NULL,
          range_end DATE NOT NULL,
          weekdays VARCHAR(40) NOT NULL,
          start_time TIME NOT NULL,
          duration_hours DECIMAL(5,2) UNSIGNED NOT NULL,
          occurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
          total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_batch_dates (range_start, range_end),
          INDEX idx_batch_client (client_name),
          CONSTRAINT fk_batch_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$columnExists('batch_id')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER archived_by");
        }
        if (!$columnExists('batch_occurrence')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN batch_occurrence INT UNSIGNED NULL AFTER batch_id");
        }
        $indexStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND INDEX_NAME='idx_reservation_batch'");
        $indexStmt->execute();
        if ((int)$indexStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE reservations ADD INDEX idx_reservation_batch (batch_id, batch_occurrence)");
        }
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade.
    }
}

/**
 * Batch-level payments introduced in v1.0.41.
 * One incoming transaction is stored once in batch_payments and then allocated
 * to the individual reservation payment ledgers for accurate balances, print
 * copies, receipts, and reporting.
 */
function ensure_v1041_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS batch_payments (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          batch_id BIGINT UNSIGNED NOT NULL,
          batch_payment_no VARCHAR(40) NOT NULL UNIQUE,
          amount DECIMAL(14,2) NOT NULL,
          payment_method VARCHAR(80) NOT NULL,
          payment_reference VARCHAR(120) NULL,
          notes TEXT NULL,
          allocation_count INT UNSIGNED NOT NULL DEFAULT 0,
          recorded_by INT UNSIGNED NULL,
          paid_at DATETIME NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_batch_payment_batch (batch_id, paid_at),
          CONSTRAINT fk_batch_payment_batch FOREIGN KEY (batch_id) REFERENCES reservation_batches(id) ON DELETE CASCADE,
          CONSTRAINT fk_batch_payment_admin FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='batch_payment_id'");
        $columnStmt->execute();
        if ((int)$columnStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN batch_payment_id BIGINT UNSIGNED NULL AFTER reservation_id");
        }

        $indexStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_payment_batch_payment'");
        $indexStmt->execute();
        if ((int)$indexStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE payments ADD INDEX idx_payment_batch_payment (batch_payment_id)");
        }
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade. Existing
        // sites retry automatically after the main database is available.
    }
}

/**
 * Flexible batch-payment coverage introduced in v1.0.43.
 * The master transaction stores the selected coverage, while the scope table
 * preserves every chosen reservation date even when a partial payment reaches
 * only the earliest dates in that selection.
 */
function ensure_v1043_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='batch_payments' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $columns = [
            'coverage_type' => "VARCHAR(30) NOT NULL DEFAULT 'entire_batch' AFTER notes",
            'coverage_start' => 'DATE NULL AFTER coverage_type',
            'coverage_end' => 'DATE NULL AFTER coverage_start',
            'coverage_label' => 'VARCHAR(255) NULL AFTER coverage_end',
            'coverage_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER coverage_label',
        ];
        foreach ($columns as $name => $definition) {
            if (!$columnExists($name)) {
                $pdo->exec("ALTER TABLE batch_payments ADD COLUMN $name $definition");
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS batch_payment_scopes (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          batch_payment_id BIGINT UNSIGNED NOT NULL,
          reservation_id BIGINT UNSIGNED NOT NULL,
          reservation_reference VARCHAR(40) NULL,
          event_start_snapshot DATETIME NULL,
          event_end_snapshot DATETIME NULL,
          balance_before DECIMAL(14,2) NOT NULL DEFAULT 0,
          allocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_batch_payment_scope (batch_payment_id, reservation_id),
          INDEX idx_batch_payment_scope_reservation (reservation_id),
          CONSTRAINT fk_batch_payment_scope_payment FOREIGN KEY (batch_payment_id) REFERENCES batch_payments(id) ON DELETE CASCADE,
          CONSTRAINT fk_batch_payment_scope_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $scopeColumnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='batch_payment_scopes' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };
        $scopeColumns = [
            'reservation_reference' => 'VARCHAR(40) NULL AFTER reservation_id',
            'event_start_snapshot' => 'DATETIME NULL AFTER reservation_reference',
            'event_end_snapshot' => 'DATETIME NULL AFTER event_start_snapshot',
        ];
        foreach ($scopeColumns as $name => $definition) {
            if (!$scopeColumnExists($name)) {
                $pdo->exec("ALTER TABLE batch_payment_scopes ADD COLUMN $name $definition");
            }
        }

        // Preserve the individual allocations from v1.0.41-v1.0.42 as legacy
        // scope records so their dates remain visible in history and printouts.
        $legacyScopeStmt = $pdo->query("SELECT 1
          FROM payments p
          WHERE p.batch_payment_id IS NOT NULL
            AND NOT EXISTS (
              SELECT 1 FROM batch_payment_scopes bps
              WHERE bps.batch_payment_id=p.batch_payment_id AND bps.reservation_id=p.reservation_id
            )
          LIMIT 1");
        if ($legacyScopeStmt && $legacyScopeStmt->fetchColumn()) {
            $pdo->exec("INSERT IGNORE INTO batch_payment_scopes(batch_payment_id,reservation_id,reservation_reference,event_start_snapshot,event_end_snapshot,balance_before,allocated_amount)
              SELECT p.batch_payment_id,p.reservation_id,r.reference_no,r.event_start,r.event_end,SUM(p.amount),SUM(p.amount)
              FROM payments p
              INNER JOIN reservations r ON r.id=p.reservation_id
              WHERE p.batch_payment_id IS NOT NULL
              GROUP BY p.batch_payment_id,p.reservation_id,r.reference_no,r.event_start,r.event_end");
        }

        $pdo->exec("UPDATE batch_payment_scopes bps
          INNER JOIN reservations r ON r.id=bps.reservation_id
          SET bps.reservation_reference=COALESCE(bps.reservation_reference,r.reference_no),
              bps.event_start_snapshot=COALESCE(bps.event_start_snapshot,r.event_start),
              bps.event_end_snapshot=COALESCE(bps.event_end_snapshot,r.event_end)
          WHERE bps.reservation_reference IS NULL
             OR bps.event_start_snapshot IS NULL
             OR bps.event_end_snapshot IS NULL");

        $pdo->exec("UPDATE batch_payments bp
          SET bp.coverage_label=COALESCE(NULLIF(bp.coverage_label,''),'Entire payable batch'),
              bp.coverage_count=CASE
                WHEN bp.coverage_count > 0 THEN bp.coverage_count
                ELSE COALESCE(NULLIF((SELECT COUNT(*) FROM batch_payment_scopes bps WHERE bps.batch_payment_id=bp.id),0),bp.allocation_count)
              END
          WHERE bp.coverage_label IS NULL OR bp.coverage_label='' OR bp.coverage_count=0");
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade. Existing
        // sites retry the automatic upgrade on the next request.
    }
}



/**
 * Client cancellation settlement and refund audit trail introduced in v1.0.48.
 * Refunds are stored as explicit negative ledger transactions so every existing
 * balance, revenue, batch, archive, and printing calculation remains accurate.
 */
function ensure_v1048_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='transaction_type'");
        $columnStmt->execute();
        if ((int)$columnStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN transaction_type VARCHAR(20) NOT NULL DEFAULT 'payment' AFTER batch_payment_id");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_cancellations (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          reservation_id BIGINT UNSIGNED NOT NULL,
          previous_status VARCHAR(30) NOT NULL,
          original_total DECIMAL(12,2) NOT NULL DEFAULT 0,
          cancellation_rate DECIMAL(5,2) NOT NULL DEFAULT 50.00,
          cancellation_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
          paid_before_cancellation DECIMAL(12,2) NOT NULL DEFAULT 0,
          refund_due DECIMAL(12,2) NOT NULL DEFAULT 0,
          refund_status VARCHAR(20) NOT NULL DEFAULT 'not_applicable',
          refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
          refund_method VARCHAR(80) NULL,
          refund_reference VARCHAR(120) NULL,
          refund_notes TEXT NULL,
          refund_payment_id BIGINT UNSIGNED NULL,
          cancellation_reason TEXT NOT NULL,
          cancelled_by INT UNSIGNED NULL,
          cancelled_at DATETIME NOT NULL,
          refunded_by INT UNSIGNED NULL,
          refunded_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_reservation_cancellation (reservation_id),
          INDEX idx_cancellation_refund_status (refund_status, cancelled_at),
          CONSTRAINT fk_cancellation_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
          CONSTRAINT fk_cancellation_admin FOREIGN KEY (cancelled_by) REFERENCES admins(id) ON DELETE SET NULL,
          CONSTRAINT fk_cancellation_refund_admin FOREIGN KEY (refunded_by) REFERENCES admins(id) ON DELETE SET NULL,
          CONSTRAINT fk_cancellation_refund_payment FOREIGN KEY (refund_payment_id) REFERENCES payments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {
        // Newer databases already contain these fields; older sites retry this compatibility upgrade. Existing
        // sites retry the automatic upgrade on the next request.
    }
}


/**
 * Short sequential reservation references introduced in v1.0.57.
 * New reservations use TLH1, TLH2, TLH3... while existing references are
 * preserved exactly as they were issued.
 */
function ensure_v1057_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS reference_sequences (
          sequence_name VARCHAR(40) PRIMARY KEY,
          current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // If a site already contains short references (for example from a test
        // deployment), continue after the highest existing TLH number. Legacy
        // TLH-YYMMDD-XXXXXX references are intentionally ignored.
        $maxShort = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(reference_no,4) AS UNSIGNED)),0)
            FROM reservations
            WHERE reference_no REGEXP '^TLH[0-9]+$'")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO reference_sequences(sequence_name,current_value) VALUES('reservation',?)
            ON DUPLICATE KEY UPDATE current_value=GREATEST(current_value,VALUES(current_value))");
        $stmt->execute([$maxShort]);
    } catch (Throwable $e) {
        // Newer databases already contain this table. Older sites retry automatically
        // on the next request.
    }
}


/**
 * Admin notifications for public website reservation requests introduced in v1.0.59.
 */
function ensure_v1059_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          notification_type VARCHAR(40) NOT NULL DEFAULT 'website_reservation',
          reservation_id BIGINT UNSIGNED NULL,
          title VARCHAR(180) NOT NULL,
          message VARCHAR(500) NULL,
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          read_by INT UNSIGNED NULL,
          read_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_admin_notifications_unread (is_read, created_at),
          INDEX idx_admin_notifications_reservation (reservation_id),
          UNIQUE KEY uq_admin_notification_type_reservation (notification_type, reservation_id),
          CONSTRAINT fk_admin_notification_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
          CONSTRAINT fk_admin_notification_reader FOREIGN KEY (read_by) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // Surface any website requests that were already waiting for review
        // when this feature was installed. The unique key prevents duplicates.
        db()->exec("INSERT IGNORE INTO admin_notifications(notification_type,reservation_id,title,message)
          SELECT 'website_reservation', r.id,
                 CONCAT('Website reservation request - ', r.reference_no),
                 CONCAT(r.client_name, ' | ', DATE_FORMAT(r.event_start,'%b %e, %Y %l:%i %p'), ' - ', DATE_FORMAT(r.event_end,'%l:%i %p'), ' | ', UPPER(REPLACE(r.status,'_',' ')), ' - Not holding slot')
            FROM reservations r
           WHERE r.source='website'
             AND r.archived_at IS NULL
             AND r.status IN ('pending','for_review')");
    } catch (Throwable $e) {
        // Newer databases already contain this table. Older sites retry the
        // automatic upgrade on the next request.
    }
}

/**
 * 30-minute reservation support introduced in v1.2.16.
 * Existing whole-hour values remain valid; decimal columns allow 0.50-hour
 * pricing snapshots, batch durations, and extension history.
 */
function ensure_v1216_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnType = static function (string $table, string $column) use ($pdo): string {
            $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
            $stmt->execute([$table, $column]);
            return strtolower((string)($stmt->fetchColumn() ?: ''));
        };
        if (!str_starts_with($columnType('reservations', 'billable_hours'), 'decimal(')) {
            $pdo->exec("ALTER TABLE reservations MODIFY billable_hours DECIMAL(6,2) UNSIGNED NULL");
        }
        if (!str_starts_with($columnType('reservation_batches', 'duration_hours'), 'decimal(')) {
            $pdo->exec("ALTER TABLE reservation_batches MODIFY duration_hours DECIMAL(5,2) UNSIGNED NOT NULL");
        }
        if (!str_starts_with($columnType('reservation_extensions', 'added_hours'), 'decimal(')) {
            $pdo->exec("ALTER TABLE reservation_extensions MODIFY added_hours DECIMAL(5,2) UNSIGNED NOT NULL");
        }
    } catch (Throwable $e) {
        // Fresh installations already use decimal duration columns. Existing
        // sites retry this lightweight migration on the next request if needed.
    }
}


/**
 * v1.2.44 separates client payment intent from verified/admin-recorded payments.
 * Website clients choose the intended payment type/method, but no amount is
 * credited until an administrator records the actual transaction reference.
 */
function ensure_v1244_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$columnExists('booking_payment_choice')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN booking_payment_choice ENUM('none','partial','full') NULL AFTER phone");
        }
        if (!$columnExists('booking_payment_intent_amount')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN booking_payment_intent_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER booking_payment_method");
        }
        $pdo->exec("UPDATE reservations SET booking_payment_choice = CASE WHEN booking_payment_choice IS NOT NULL THEN booking_payment_choice WHEN booking_payment_method IS NULL OR booking_payment_method='' THEN 'none' WHEN amount_paid >= COALESCE(final_amount,estimated_amount) AND amount_paid > 0 THEN 'full' WHEN amount_paid > 0 THEN 'partial' ELSE 'none' END WHERE booking_payment_choice IS NULL");
        $pdo->exec("UPDATE reservations SET booking_payment_intent_amount = amount_paid WHERE booking_payment_intent_amount=0 AND booking_payment_choice IN ('partial','full') AND amount_paid>0");
    } catch (Throwable $e) {
        // Fresh installations already include these columns. Existing sites
        // retry this lightweight migration on the next request if needed.
    }
}

// ============================================================================
// ADMIN NOTIFICATIONS
// Website-origin reservation requests generate admin alerts. Notification failure
// must never roll back or prevent the reservation request itself.
// ============================================================================
function create_website_reservation_notification(int $reservationId, ?PDO $pdo = null): void
{
    if ($reservationId <= 0) {
        return;
    }
    try {
        $pdo = $pdo ?: db();
        $stmt = $pdo->prepare("SELECT reference_no, client_name, event_start, event_end, status, source FROM reservations WHERE id=? LIMIT 1");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();
        if (!$reservation || (string)$reservation['source'] !== 'website') {
            return;
        }
        $schedule = date('M j, Y g:i A', strtotime((string)$reservation['event_start'])) . ' - ' . date('g:i A', strtotime((string)$reservation['event_end']));
        $title = 'New website reservation request - ' . (string)$reservation['reference_no'];
        $message = (string)$reservation['client_name'] . ' | ' . $schedule . ' | Pending - Not holding slot';
        $insert = $pdo->prepare("INSERT IGNORE INTO admin_notifications(notification_type,reservation_id,title,message) VALUES('website_reservation',?,?,?)");
        $insert->execute([$reservationId, $title, $message]);
    } catch (Throwable $e) {
        // Notification failure should never prevent the reservation itself.
    }
}

function admin_notification_unread_count(): int
{
    try {
        return (int)db()->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_recent_notifications(int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    try {
        return db()->query("SELECT n.*, r.reference_no, r.client_name, r.event_start, r.event_end, r.status, r.source
          FROM admin_notifications n
          LEFT JOIN reservations r ON r.id=n.reservation_id
          ORDER BY n.created_at DESC, n.id DESC
          LIMIT " . $limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function mark_admin_notification_read(int $notificationId, ?int $adminId = null): ?int
{
    if ($notificationId <= 0) {
        return null;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT reservation_id FROM admin_notifications WHERE id=? LIMIT 1');
        $stmt->execute([$notificationId]);
        $reservationId = $stmt->fetchColumn();
        if ($reservationId === false) {
            return null;
        }
        $update = $pdo->prepare('UPDATE admin_notifications SET is_read=1, read_by=?, read_at=COALESCE(read_at,NOW()) WHERE id=?');
        $update->execute([$adminId ?: null, $notificationId]);
        return $reservationId !== null ? (int)$reservationId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mark_admin_notifications_for_reservation_read(int $reservationId, ?int $adminId = null): void
{
    if ($reservationId <= 0) {
        return;
    }
    try {
        $stmt = db()->prepare('UPDATE admin_notifications SET is_read=1, read_by=COALESCE(read_by,?), read_at=COALESCE(read_at,NOW()) WHERE reservation_id=? AND is_read=0');
        $stmt->execute([$adminId ?: null, $reservationId]);
    } catch (Throwable $e) {
    }
}

function mark_all_admin_notifications_read(?int $adminId = null): void
{
    try {
        $stmt = db()->prepare('UPDATE admin_notifications SET is_read=1, read_by=COALESCE(read_by,?), read_at=COALESCE(read_at,NOW()) WHERE is_read=0');
        $stmt->execute([$adminId ?: null]);
    } catch (Throwable $e) {
    }
}


/**
 * Flexible administrator discounts introduced in v1.2.59.
 * final_amount remains the authoritative net payable; these fields preserve
 * the explicit discount for audit-friendly details and printouts.
 */
function ensure_v1259_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo = db();
        $columnExists = static function (string $column) use ($pdo): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME=?");
            $stmt->execute([$column]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$columnExists('discount_amount')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER final_amount");
        }
        if (!$columnExists('discount_reason')) {
            $pdo->exec("ALTER TABLE reservations ADD COLUMN discount_reason VARCHAR(255) NULL AFTER discount_amount");
        }
    } catch (Throwable $e) {
        // Newer databases already contain these columns; older sites retry this compatibility upgrade.
    }
}

ensure_v108_schema();
ensure_v1013_schema();
ensure_v1023_schema();
ensure_v1024_schema();
ensure_v1034_schema();
ensure_v1035_schema();
ensure_v1037_schema();
ensure_v1041_schema();
ensure_v1043_schema();
ensure_v1048_schema();
ensure_v1057_schema();
ensure_v1059_schema();
ensure_v1216_schema();
ensure_v1244_schema();
ensure_v1259_schema();
