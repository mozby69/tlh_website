<?php
/**
 * FILE PURPOSE: JSON endpoint for live batch schedule conflict checking and live batch price calculation.
 * DEBUGGING: This endpoint is informational; final creation rechecks conflicts while holding the shared booking lock. Pricing comes from calculate_reservation_pricing().
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function batch_availability_response(bool $ok, string $status, string $message, array $extra = []): void
{
    http_response_code($ok ? 200 : 422);
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$scheduleMode = in_array((string)($input['schedule_mode'] ?? 'recurring'), ['recurring', 'specific'], true)
    ? (string)$input['schedule_mode']
    : 'recurring';
$type = trim((string)($input['reservation_type'] ?? 'basketball'));
$setupRaw = trim((string)($input['setup_minutes'] ?? '0'));
$cleanupRaw = trim((string)($input['cleanup_minutes'] ?? '0'));
$guestRaw = trim((string)($input['guest_count'] ?? ''));
$cooling = trim((string)($input['cooling_option'] ?? 'fan'));
$shower = ($input['shower_room_addon'] ?? '') === '1';
$showerComplimentary = $shower && ($input['shower_room_complimentary'] ?? '') === '1';
$equipment = ($input['equipment_bundle_addon'] ?? '') === '1';
$equipmentComplimentary = $equipment && ($input['equipment_bundle_complimentary'] ?? '') === '1';
$allowPastDates = ($input['allow_past_dates'] ?? '') === '1';

$startSlots = reservation_start_time_slots();
$setupMinutes = ctype_digit($setupRaw) ? (int)$setupRaw : 0;
$cleanupMinutes = ctype_digit($cleanupRaw) ? (int)$cleanupRaw : 0;
$guestCount = ctype_digit($guestRaw) ? (int)$guestRaw : 0;

if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) {
    batch_availability_response(false, 'invalid', 'Choose a valid reservation type.');
}
if ($type !== 'event') {
    $setupMinutes = 0;
    $cleanupMinutes = 0;
} else {
    if (!in_array($setupMinutes, [0, 30, 60, 120, 180], true)) {
        batch_availability_response(false, 'invalid', 'Choose a valid setup time.');
    }
    if (!in_array($cleanupMinutes, [0, 30, 60, 120], true)) {
        batch_availability_response(false, 'invalid', 'Choose a valid cleanup time.');
    }
}

$occurrences = [];
if ($scheduleMode === 'specific') {
    $specificDates = isset($input['specific_date']) && is_array($input['specific_date']) ? array_values($input['specific_date']) : [];
    $specificStarts = isset($input['specific_start_time']) && is_array($input['specific_start_time']) ? array_values($input['specific_start_time']) : [];
    $specificDurations = isset($input['specific_duration_hours']) && is_array($input['specific_duration_hours']) ? array_values($input['specific_duration_hours']) : [];
    if (!$specificDates) {
        batch_availability_response(false, 'invalid', 'Add at least one specific reservation date.');
    }
    if (count($specificDates) > 200) {
        batch_availability_response(false, 'invalid', 'A single batch reservation is limited to 200 occurrences.');
    }
    foreach ($specificDates as $index => $rawDate) {
        $date = trim((string)$rawDate);
        $startTime = trim((string)($specificStarts[$index] ?? ''));
        $durationRaw = trim((string)($specificDurations[$index] ?? ''));
        $durationHours = reservation_duration_hours_from_input($durationRaw);
        if (!reservation_date_is_valid($date)) {
            batch_availability_response(false, 'invalid', 'Choose a valid date for every specific reservation.');
        }
        if (!array_key_exists($startTime, $startSlots)) {
            batch_availability_response(false, 'invalid', 'Choose a valid 30-minute start time for every specific reservation.');
        }
        if ($durationHours === null) {
            batch_availability_response(false, 'invalid', 'Choose a valid duration for every specific reservation.');
        }
        $start = new DateTimeImmutable($date . ' ' . $startTime . ':00');
        $end = reservation_add_duration($start, $durationHours);
        if (!$allowPastDates && $start < new DateTimeImmutable()) {
            batch_availability_response(false, 'invalid', 'Enable Past Date Selection to include a date or time that has already passed.');
        }
        if ($end->format('Y-m-d') !== $date || $end->format('H:i') > '22:00') {
            batch_availability_response(false, 'invalid', 'One of the selected reservations extends beyond the 10:00 PM closing time.');
        }
        $occurrences[] = [
            'date' => $date,
            'start_time' => $startTime,
            'duration' => $durationHours,
            'source_index' => $index,
        ];
    }
} else {
    $rangeStart = trim((string)($input['range_start'] ?? ''));
    $rangeEnd = trim((string)($input['range_end'] ?? ''));
    $startTime = trim((string)($input['start_time'] ?? ''));
    $durationRaw = trim((string)($input['duration_hours'] ?? ''));
    $durationHours = reservation_duration_hours_from_input($durationRaw);
    $weekdaysRaw = $input['weekdays'] ?? [];
    $weekdays = is_array($weekdaysRaw) ? array_values(array_unique(array_map('intval', $weekdaysRaw))) : [];

    if (!reservation_date_is_valid($rangeStart) || !reservation_date_is_valid($rangeEnd)) {
        batch_availability_response(false, 'invalid', 'Choose a valid batch date range.');
    }
    if (!$allowPastDates && $rangeStart < date('Y-m-d')) {
        batch_availability_response(false, 'invalid', 'Enable Past Date Selection to use a batch range that starts before today.');
    }
    if (!array_key_exists($startTime, $startSlots)) {
        batch_availability_response(false, 'invalid', 'Choose a valid 30-minute start time.');
    }
    if ($durationHours === null) {
        batch_availability_response(false, 'invalid', 'Choose a valid reservation duration.');
    }
    try {
        $dates = reservation_batch_dates($rangeStart, $rangeEnd, $weekdays);
    } catch (InvalidArgumentException $e) {
        batch_availability_response(false, 'invalid', $e->getMessage());
    }
    foreach ($dates as $index => $date) {
        $start = new DateTimeImmutable($date . ' ' . $startTime . ':00');
        $end = reservation_add_duration($start, $durationHours);
        if (!$allowPastDates && $start < new DateTimeImmutable()) {
            batch_availability_response(false, 'invalid', 'Enable Past Date Selection to include a date or time that has already passed.');
        }
        if ($end->format('Y-m-d') !== $date || $end->format('H:i') > '22:00') {
            batch_availability_response(false, 'invalid', 'The selected duration extends beyond the 10:00 PM closing time.');
        }
        $occurrences[] = [
            'date' => $date,
            'start_time' => $startTime,
            'duration' => $durationHours,
            'source_index' => $index,
        ];
    }
}

if (!$occurrences) {
    batch_availability_response(false, 'invalid', 'Add at least one reservation occurrence to the batch.');
}

$pricingKnown = $guestCount >= 1
    && reservation_package_for_guests($guestCount) !== null
    && array_key_exists($cooling, reservation_cooling_options());
$rows = [];
$conflicts = 0;
$availableTotal = 0.0;
$selectedTotal = 0.0;
$availableBaseSubtotal = 0.0;
$availableShowerSubtotal = 0.0;
$availableEquipmentSubtotal = 0.0;
$availableBillableHours = 0.0;
$rateGroups = [];
$pricingMeta = null;

try {
    $blockedRanges = [];
    foreach ($occurrences as $occurrence) {
        $start = new DateTimeImmutable($occurrence['date'] . ' ' . $occurrence['start_time'] . ':00');
        $end = reservation_add_duration($start, (float)$occurrence['duration']);
        $blockedRanges[] = [
            'start' => $start,
            'end' => $end,
            'blocked_start' => $start->modify('-' . $setupMinutes . ' minutes'),
            'blocked_end' => $end->modify('+' . $cleanupMinutes . ' minutes'),
            'source_index' => (int)$occurrence['source_index'],
        ];
    }

    usort($blockedRanges, static function (array $a, array $b): int {
        $cmp = $a['start'] <=> $b['start'];
        return $cmp !== 0 ? $cmp : ($a['source_index'] <=> $b['source_index']);
    });

    $queryStart = $blockedRanges[0]['blocked_start'];
    $queryEnd = $blockedRanges[0]['blocked_end'];
    foreach ($blockedRanges as $range) {
        if ($range['blocked_start'] < $queryStart) $queryStart = $range['blocked_start'];
        if ($range['blocked_end'] > $queryEnd) $queryEnd = $range['blocked_end'];
    }

    $calendarBlockCondition = reservation_calendar_block_condition();
    $entryConflictCondition = "({$calendarBlockCondition} OR status = 'completed')";
    $stmt = db()->prepare("SELECT blocked_start, blocked_end FROM reservations
        WHERE archived_at IS NULL
          AND {$entryConflictCondition}
          AND blocked_start < :range_end
          AND blocked_end > :range_start");
    $stmt->execute([
        ':range_start' => $queryStart->format('Y-m-d H:i:s'),
        ':range_end' => $queryEnd->format('Y-m-d H:i:s'),
    ]);
    $existingBlocks = array_map(static function (array $row): array {
        return [
            'start' => strtotime((string)$row['blocked_start']),
            'end' => strtotime((string)$row['blocked_end']),
        ];
    }, $stmt->fetchAll());

    $acceptedSelectedBlocks = [];
    foreach ($blockedRanges as $range) {
        $blockedStartTs = $range['blocked_start']->getTimestamp();
        $blockedEndTs = $range['blocked_end']->getTimestamp();
        $conflict = false;
        $conflictSource = null;
        foreach ($existingBlocks as $existingBlock) {
            if ($existingBlock['start'] < $blockedEndTs && $existingBlock['end'] > $blockedStartTs) {
                $conflict = true;
                $conflictSource = 'existing';
                break;
            }
        }
        if (!$conflict) {
            foreach ($acceptedSelectedBlocks as $selectedBlock) {
                if ($selectedBlock['start'] < $blockedEndTs && $selectedBlock['end'] > $blockedStartTs) {
                    $conflict = true;
                    $conflictSource = 'selected';
                    break;
                }
            }
        }
        if (!$conflict) {
            $acceptedSelectedBlocks[] = ['start' => $blockedStartTs, 'end' => $blockedEndTs];
        }

        $amount = null;
        if ($pricingKnown) {
            $pricing = calculate_reservation_pricing(
                $range['start']->format('Y-m-d H:i:s'),
                $range['end']->format('Y-m-d H:i:s'),
                $guestCount,
                $cooling,
                $shower,
                $equipment,
                false,
                $showerComplimentary,
                $equipmentComplimentary
            );
            $amount = (float)$pricing['total'];
            $selectedTotal += $amount;
            if ($pricingMeta === null) {
                $pricingMeta = [
                    'package' => (string)$pricing['package'],
                    'package_label' => (string)$pricing['package_label'],
                    'cooling_label' => (string)$pricing['cooling_label'],
                    'equipment_bundle_included' => !empty($pricing['equipment_bundle_included']),
                ];
            }
            if (!$conflict) {
                $availableTotal += $amount;
                $availableBaseSubtotal += (float)$pricing['base_amount'];
                $availableShowerSubtotal += (float)$pricing['shower_room_fee'];
                $availableEquipmentSubtotal += (float)$pricing['equipment_bundle_fee'];
                $availableBillableHours += (float)$pricing['billable_hours'];

                $groupKey = implode('|', [
                    (string)$pricing['package'],
                    (string)$pricing['rate_period'],
                    (string)$pricing['cooling_option'],
                    number_format((float)$pricing['hourly_rate'], 2, '.', ''),
                ]);
                if (!isset($rateGroups[$groupKey])) {
                    $rateGroups[$groupKey] = [
                        'package' => (string)$pricing['package'],
                        'package_label' => (string)$pricing['package_label'],
                        'rate_period' => (string)$pricing['rate_period'],
                        'cooling_label' => (string)$pricing['cooling_label'],
                        'hourly_rate' => (float)$pricing['hourly_rate'],
                        'billable_hours' => 0.0,
                        'occurrence_count' => 0,
                        'subtotal' => 0.0,
                    ];
                }
                $rateGroups[$groupKey]['billable_hours'] += (float)$pricing['billable_hours'];
                $rateGroups[$groupKey]['occurrence_count']++;
                $rateGroups[$groupKey]['subtotal'] += (float)$pricing['base_amount'];
            }
        }
        if ($conflict) $conflicts++;
        $rows[] = [
            'source_index' => $range['source_index'],
            'date' => $range['start']->format('Y-m-d'),
            'date_label' => $range['start']->format('D, M j, Y'),
            'start_label' => $range['start']->format('g:i A'),
            'end_label' => $range['end']->format('g:i A'),
            'available' => !$conflict,
            'conflict_source' => $conflictSource,
            'historical' => $range['end'] < new DateTimeImmutable(),
            'amount' => $amount,
        ];
    }
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Live conflict checking is temporarily unavailable. The batch will still be checked again before creation.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$total = count($rows);
$available = $total - $conflicts;
$status = $conflicts === 0 ? 'available' : ($available === 0 ? 'unavailable' : 'partial');
if ($conflicts === 0) {
    $message = 'All ' . $total . ' selected occurrence' . ($total === 1 ? ' is' : 's are') . ' currently available.';
} elseif ($available === 0) {
    $message = 'All selected occurrences conflict with existing reservations or with another selected occurrence. Change the date or time.';
} else {
    $message = $conflicts . ' of ' . $total . ' selected occurrences conflict with the calendar or another selected occurrence. Adjust those rows or skip them when creating the batch.';
}

batch_availability_response(true, $status, $message, [
    'schedule_mode' => $scheduleMode,
    'occurrence_count' => $total,
    'available_count' => $available,
    'conflict_count' => $conflicts,
    'pricing_known' => $pricingKnown,
    'selected_total' => $pricingKnown ? round($selectedTotal, 2) : null,
    'available_total' => $pricingKnown ? round($availableTotal, 2) : null,
    'pricing_summary' => $pricingKnown ? [
        'package' => (string)($pricingMeta['package'] ?? ''),
        'package_label' => (string)($pricingMeta['package_label'] ?? ''),
        'cooling_label' => (string)($pricingMeta['cooling_label'] ?? ''),
        'selected_occurrences' => $total,
        'available_occurrences' => $available,
        'available_billable_hours' => $availableBillableHours,
        'base_subtotal' => round($availableBaseSubtotal, 2),
        'shower_subtotal' => round($availableShowerSubtotal, 2),
        'equipment_subtotal' => round($availableEquipmentSubtotal, 2),
        'shower_room_selected' => $shower,
        'shower_room_complimentary' => $showerComplimentary,
        'equipment_bundle_selected' => $equipment,
        'equipment_bundle_complimentary' => $equipmentComplimentary && empty($pricingMeta['equipment_bundle_included']),
        'equipment_bundle_included' => !empty($pricingMeta['equipment_bundle_included']),
        'selected_total' => round($selectedTotal, 2),
        'available_total' => round($availableTotal, 2),
        'rate_groups' => array_values(array_map(static function (array $group): array {
            $group['subtotal'] = round((float)$group['subtotal'], 2);
            return $group;
        }, $rateGroups)),
    ] : null,
    'rows' => $rows,
]);
