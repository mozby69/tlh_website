<?php
/**
 * FILE PURPOSE: Three-step admin workflow for recurring and specific-date batch reservations.
 * DEBUGGING: Trace schedule generation first, then live availability, then the final POST transaction. Final creation must recheck every occurrence under the shared venue lock.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$adminPageTitle = 'New Batch Reservation';
$errors = [];
$previewRows = [];
$previewTotal = 0.0;
$previewConflicts = 0;
$startTimeSlots = reservation_start_time_slots();
$pricingConfig = reservation_pricing_config();
$weekdayLabels = reservation_batch_weekday_labels();
$selectedPaymentChoice = trim((string)($_POST['payment_choice'] ?? ''));
$allowPastDates = isset($_POST['allow_past_dates']);
$todayDate = date('Y-m-d');
$selectedBatchPaidAt = trim((string)($_POST['booking_paid_at'] ?? date('Y-m-d\TH:i')));
$appendBatchId = max(0, (int)($_POST['existing_batch_id'] ?? $_GET['batch_id'] ?? 0));
$appendBatch = null;
$appendTemplate = null;
if ($appendBatchId > 0) {
    try {
        $appendStmt = db()->prepare('SELECT * FROM reservation_batches WHERE id=? LIMIT 1');
        $appendStmt->execute([$appendBatchId]);
        $appendBatch = $appendStmt->fetch() ?: null;
        if ($appendBatch) {
            $templateStmt = db()->prepare('SELECT * FROM reservations WHERE batch_id=? ORDER BY event_start ASC, id ASC LIMIT 1');
            $templateStmt->execute([$appendBatchId]);
            $appendTemplate = $templateStmt->fetch() ?: null;
        } else {
            $errors[] = 'The selected batch reservation could not be found.';
            $appendBatchId = 0;
        }
    } catch (Throwable $e) {
        $errors[] = 'The selected batch reservation could not be loaded.';
        $appendBatchId = 0;
    }
}

$defaults = [
    'schedule_mode' => 'recurring',
    'range_start' => date('Y-m-d'),
    'range_end' => date('Y-m-t'),
    'start_time' => '08:00',
    'duration_hours' => '2',
    'reservation_type' => 'basketball',
    'guest_count' => '',
    'cooling_option' => 'fan',
    'client_name' => '',
    'organization' => '',
    'email' => '',
    'phone' => '',
    'source' => 'walk_in',
    'status' => 'approved',
    'sport_purpose' => 'Practice',
    'event_purpose' => '',
    'setup_minutes' => '0',
    'cleanup_minutes' => '0',
    'additional_requests' => '',
];
if ($appendBatch) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $firstExistingDate = (string)$appendBatch['range_start'];
        $suggestedEnd = date('Y-m-d', strtotime($firstExistingDate . ' -1 day'));
        $defaults['range_end'] = $suggestedEnd;
        $defaults['range_start'] = date('Y-m-01', strtotime($suggestedEnd));
    }
    $defaults['start_time'] = substr((string)$appendBatch['start_time'], 0, 5);
    $defaults['duration_hours'] = (string)(float)$appendBatch['duration_hours'];
    $defaults['reservation_type'] = (string)$appendBatch['reservation_type'];
    $defaults['client_name'] = (string)$appendBatch['client_name'];
    $defaults['organization'] = (string)($appendBatch['organization'] ?? '');
    $defaults['email'] = (string)($appendBatch['email'] ?? '');
    $defaults['phone'] = (string)$appendBatch['phone'];
    if ($appendTemplate) {
        $defaults['guest_count'] = (string)($appendTemplate['guest_count'] ?? '');
        $defaults['cooling_option'] = (string)($appendTemplate['cooling_option'] ?? 'fan');
        $defaults['source'] = (string)($appendTemplate['source'] ?? 'walk_in');
        $defaults['setup_minutes'] = (string)($appendTemplate['setup_minutes'] ?? 0);
        $defaults['cleanup_minutes'] = (string)($appendTemplate['cleanup_minutes'] ?? 0);
        $purposeValue = (string)($appendTemplate['purpose'] ?? $appendBatch['purpose'] ?? '');
        if ($defaults['reservation_type'] === 'event') $defaults['event_purpose'] = $purposeValue;
        else $defaults['sport_purpose'] = $purposeValue !== '' ? $purposeValue : 'Practice';
    } else {
        $errors[] = 'This batch has no reservation occurrence to use as the commercial template. Create a new batch instead.';
    }
}
$form = array_merge($defaults, $_POST);
$appendWeekdays = $appendBatch && (string)$appendBatch['weekdays'] !== 'specific'
    ? array_values(array_filter(array_map('intval', explode(',', (string)$appendBatch['weekdays'])), static fn(int $day): bool => $day >= 1 && $day <= 7))
    : [];
$selectedWeekdays = isset($_POST['weekdays']) && is_array($_POST['weekdays'])
    ? array_values(array_unique(array_map('intval', $_POST['weekdays'])))
    : ($appendWeekdays ?: [1,2,3,4,5,6,7]);
$defaultShowerChecked = $appendBatch
    ? !empty($appendTemplate['shower_room_addon'])
    : ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['shower_room_addon']) : false);
$defaultShowerComplimentaryChecked = $appendBatch
    ? ($appendTemplate ? reservation_shower_is_complimentary($appendTemplate) : false)
    : ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shower_room_complimentary']) && $defaultShowerChecked);
$defaultEquipmentChecked = $appendBatch
    ? !empty($appendTemplate['equipment_bundle_addon'])
    : ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['equipment_bundle_addon']) : false);
$defaultEquipmentComplimentaryChecked = $appendBatch
    ? ($appendTemplate ? reservation_equipment_is_complimentary($appendTemplate) : false)
    : ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipment_bundle_complimentary']) && $defaultEquipmentChecked);
$scheduleMode = in_array((string)($form['schedule_mode'] ?? 'recurring'), ['recurring', 'specific'], true)
    ? (string)$form['schedule_mode']
    : 'recurring';
$specificPostedRows = [];
if (isset($_POST['specific_date']) && is_array($_POST['specific_date'])) {
    $specificDates = array_values($_POST['specific_date']);
    $specificStarts = isset($_POST['specific_start_time']) && is_array($_POST['specific_start_time']) ? array_values($_POST['specific_start_time']) : [];
    $specificDurations = isset($_POST['specific_duration_hours']) && is_array($_POST['specific_duration_hours']) ? array_values($_POST['specific_duration_hours']) : [];
    foreach ($specificDates as $index => $rawDate) {
        $date = trim((string)$rawDate);
        if ($date === '') continue;
        $specificPostedRows[] = [
            'date' => $date,
            'start_time' => trim((string)($specificStarts[$index] ?? $form['start_time'] ?? '08:00')),
            'duration_hours' => trim((string)($specificDurations[$index] ?? $form['duration_hours'] ?? '2')),
        ];
    }
}
$skipConflicts = isset($_POST['skip_conflicts']) || isset($_POST['create_available_batch']);

$normalize = static function () use (&$form, &$selectedWeekdays, &$errors, $startTimeSlots, $appendBatchId, $appendBatch, $appendTemplate, $allowPastDates): ?array {
    $scheduleMode = in_array((string)($form['schedule_mode'] ?? 'recurring'), ['recurring', 'specific'], true)
        ? (string)$form['schedule_mode']
        : 'recurring';
    $type = trim((string)($form['reservation_type'] ?? ''));
    $client = trim((string)($form['client_name'] ?? ''));
    $org = trim((string)($form['organization'] ?? ''));
    $email = trim((string)($form['email'] ?? ''));
    $phone = trim((string)($form['phone'] ?? ''));
    $source = trim((string)($form['source'] ?? 'walk_in'));
    $status = trim((string)($form['status'] ?? 'approved'));
    $guestRaw = trim((string)($form['guest_count'] ?? ''));
    $guests = ctype_digit($guestRaw) ? (int)$guestRaw : 0;
    $cooling = trim((string)($form['cooling_option'] ?? ''));
    $setup = $type === 'event' ? max(0, (int)($form['setup_minutes'] ?? 0)) : 0;
    $cleanup = $type === 'event' ? max(0, (int)($form['cleanup_minutes'] ?? 0)) : 0;
    $purpose = $type === 'event' ? trim((string)($form['event_purpose'] ?? '')) : trim((string)($form['sport_purpose'] ?? ''));
    $requests = trim((string)($form['additional_requests'] ?? ''));
    $shower = isset($_POST['shower_room_addon']);
    $showerComplimentary = $shower && isset($_POST['shower_room_complimentary']);
    $equipment = isset($_POST['equipment_bundle_addon']);
    $equipmentComplimentary = $equipment && isset($_POST['equipment_bundle_complimentary']);

    // Existing-batch append mode owns the client identity and commercial terms.
    // The administrator is adding schedule occurrences, not creating a second
    // commercial arrangement under the same batch reference.
    if ($appendBatchId > 0 && $appendBatch && $appendTemplate) {
        $type = (string)$appendBatch['reservation_type'];
        $client = (string)$appendBatch['client_name'];
        $org = (string)($appendBatch['organization'] ?? '');
        $email = (string)($appendBatch['email'] ?? '');
        $phone = (string)$appendBatch['phone'];
        $source = (string)($appendTemplate['source'] ?? 'walk_in');
        $guests = (int)($appendTemplate['guest_count'] ?? 0);
        $cooling = (string)($appendTemplate['cooling_option'] ?? 'fan');
        $setup = $type === 'event' ? max(0, (int)($appendTemplate['setup_minutes'] ?? 0)) : 0;
        $cleanup = $type === 'event' ? max(0, (int)($appendTemplate['cleanup_minutes'] ?? 0)) : 0;
        $purpose = (string)($appendTemplate['purpose'] ?? $appendBatch['purpose'] ?? '');
        $shower = !empty($appendTemplate['shower_room_addon']);
        $showerComplimentary = $shower && reservation_shower_is_complimentary($appendTemplate);
        $equipment = !empty($appendTemplate['equipment_bundle_addon']);
        $equipmentComplimentary = $equipment && reservation_equipment_is_complimentary($appendTemplate);
    }

    if (!in_array($type, ['basketball', 'volleyball', 'event'], true)) $errors[] = 'Choose a valid reservation type.';
    if ($client === '') $errors[] = 'Client name is required.';
    if ($phone === '') $errors[] = 'Mobile number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is invalid.';
    if ($guests < 1 || reservation_package_for_guests($guests) === null) $errors[] = 'Guest count must be between 1 and 400 guests.';
    if (!array_key_exists($cooling, reservation_cooling_options())) $errors[] = 'Choose Fan with Lights or Aircon with Lights.';
    if (!in_array($source, ['walk_in', 'internal', 'website'], true)) $source = 'walk_in';
    if (!in_array($status, ['pending', 'for_review', 'approved'], true)) $status = 'approved';

    $occurrences = [];
    $rangeStart = '';
    $rangeEnd = '';
    $summaryStartTime = '08:00';
    $summaryDuration = 1.0;

    if ($scheduleMode === 'specific') {
        $specificDates = isset($_POST['specific_date']) && is_array($_POST['specific_date']) ? array_values($_POST['specific_date']) : [];
        $specificStarts = isset($_POST['specific_start_time']) && is_array($_POST['specific_start_time']) ? array_values($_POST['specific_start_time']) : [];
        $specificDurations = isset($_POST['specific_duration_hours']) && is_array($_POST['specific_duration_hours']) ? array_values($_POST['specific_duration_hours']) : [];
        if (!$specificDates) {
            $errors[] = 'Add at least one specific reservation date.';
        } elseif (count($specificDates) > 200) {
            $errors[] = 'A single batch reservation is limited to 200 occurrences.';
        }

        foreach ($specificDates as $index => $rawDate) {
            $date = trim((string)$rawDate);
            $startTime = trim((string)($specificStarts[$index] ?? ''));
            $durationRaw = trim((string)($specificDurations[$index] ?? ''));
            $duration = reservation_duration_hours_from_input($durationRaw);
            if (!reservation_date_is_valid($date)) {
                $errors[] = 'Choose a valid date for every specific reservation.';
                continue;
            }
            if (!array_key_exists($startTime, $startTimeSlots)) {
                $errors[] = 'Choose a valid 30-minute start time for ' . $date . '.';
                continue;
            }
            if ($duration === null) {
                $errors[] = 'Choose a valid duration for ' . $date . '.';
                continue;
            }
            try {
                $testStart = new DateTimeImmutable($date . ' ' . $startTime . ':00');
                $testEnd = reservation_add_duration($testStart, $duration);
                if (!$allowPastDates && $testStart < new DateTimeImmutable()) {
                    $errors[] = 'Enable Past Date Selection to include ' . date('M j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($startTime)) . '.';
                    continue;
                }
                if ($testEnd->format('Y-m-d') !== $date || $testEnd->format('H:i') > '22:00') {
                    $errors[] = 'The reservation on ' . date('M j, Y', strtotime($date)) . ' extends beyond the 10:00 PM closing time.';
                    continue;
                }
            } catch (Throwable $e) {
                $errors[] = 'Choose a valid schedule for ' . $date . '.';
                continue;
            }
            $occurrences[] = ['date' => $date, 'start_time' => $startTime, 'duration' => $duration];
        }
        usort($occurrences, static function (array $a, array $b): int {
            return strcmp($a['date'] . ' ' . $a['start_time'], $b['date'] . ' ' . $b['start_time']);
        });
        if ($occurrences) {
            $rangeStart = $occurrences[0]['date'];
            $rangeEnd = $occurrences[count($occurrences) - 1]['date'];
            $summaryStartTime = $occurrences[0]['start_time'];
            $summaryDuration = (float)$occurrences[0]['duration'];
        }
        $selectedWeekdays = [];
    } else {
        $rangeStart = trim((string)($form['range_start'] ?? ''));
        $rangeEnd = trim((string)($form['range_end'] ?? ''));
        $startTime = trim((string)($form['start_time'] ?? ''));
        $durationRaw = trim((string)($form['duration_hours'] ?? ''));
        $duration = reservation_duration_hours_from_input($durationRaw);
        if (!reservation_date_is_valid($rangeStart) || !reservation_date_is_valid($rangeEnd)) $errors[] = 'Choose a valid batch date range.';
        if (!$errors && !$allowPastDates && $rangeStart < date('Y-m-d')) $errors[] = 'Enable Past Date Selection to use a batch range that starts before today.';
        if (!array_key_exists($startTime, $startTimeSlots)) $errors[] = 'Choose a valid 30-minute starting time.';
        if ($duration === null) $errors[] = 'Choose a valid reservation duration.';

        $selectedWeekdays = array_values(array_intersect(array_keys(reservation_batch_weekday_labels()), array_map('intval', $selectedWeekdays)));
        sort($selectedWeekdays);
        if (!$selectedWeekdays) $errors[] = 'Select at least one weekday.';

        if (!$errors) {
            try {
                $dates = reservation_batch_dates($rangeStart, $rangeEnd, $selectedWeekdays);
                foreach ($dates as $date) {
                    $occurrenceStart = new DateTimeImmutable($date . ' ' . $startTime . ':00');
                    if (!$allowPastDates && $occurrenceStart < new DateTimeImmutable()) {
                        $errors[] = 'Enable Past Date Selection to include a generated occurrence that has already passed.';
                        break;
                    }
                    $occurrences[] = ['date' => $date, 'start_time' => $startTime, 'duration' => $duration];
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (!$errors && $occurrences) {
            $testStart = new DateTimeImmutable($occurrences[0]['date'] . ' ' . $startTime . ':00');
            $testEnd = reservation_add_duration($testStart, $duration);
            if ($testEnd->format('Y-m-d') !== $occurrences[0]['date'] || $testEnd->format('H:i') > '22:00') {
                $errors[] = 'The selected duration extends beyond the 10:00 PM closing time.';
            }
        }
        $summaryStartTime = $startTime;
        $summaryDuration = $duration;
    }

    if (!$occurrences && !$errors) $errors[] = 'Add at least one reservation occurrence to the batch.';
    if ($errors) return null;
    return [
        'schedule_mode' => $scheduleMode,
        'type' => $type, 'client' => $client, 'org' => $org, 'email' => $email, 'phone' => $phone,
        'source' => $source, 'status' => $status, 'range_start' => $rangeStart, 'range_end' => $rangeEnd,
        'weekdays' => $selectedWeekdays, 'occurrences' => $occurrences,
        'start_time' => $summaryStartTime, 'duration' => $summaryDuration,
        'guests' => $guests, 'cooling' => $cooling, 'setup' => $setup, 'cleanup' => $cleanup,
        'purpose' => $purpose, 'requests' => $requests, 'shower' => $shower, 'shower_complimentary' => $showerComplimentary, 'equipment' => $equipment, 'equipment_complimentary' => $equipmentComplimentary,
        'existing_batch_id' => $appendBatchId,
    ];
};

$buildRows = static function (array $input, bool $checkConflicts = true): array {
    $rows = [];
    $acceptedBlocks = [];
    foreach ($input['occurrences'] as $occurrence) {
        $date = (string)$occurrence['date'];
        $start = new DateTimeImmutable($date . ' ' . $occurrence['start_time'] . ':00');
        $end = reservation_add_duration($start, (float)$occurrence['duration']);
        $blockedStart = $start->modify('-' . $input['setup'] . ' minutes');
        $blockedEnd = $end->modify('+' . $input['cleanup'] . ' minutes');
        $pricing = calculate_reservation_pricing(
            $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $input['guests'],
            $input['cooling'], $input['shower'], $input['equipment'], false, $input['shower_complimentary'], $input['equipment_complimentary']
        );
        $conflict = false;
        $conflictSource = null;
        if ($checkConflicts && reservation_conflict($blockedStart->format('Y-m-d H:i:s'), $blockedEnd->format('Y-m-d H:i:s'), null, $end < new DateTimeImmutable())) {
            $conflict = true;
            $conflictSource = 'existing';
        }
        if (!$conflict) {
            foreach ($acceptedBlocks as $accepted) {
                if ($accepted['start'] < $blockedEnd && $accepted['end'] > $blockedStart) {
                    $conflict = true;
                    $conflictSource = 'selected';
                    break;
                }
            }
        }
        if (!$conflict) {
            $acceptedBlocks[] = ['start' => $blockedStart, 'end' => $blockedEnd];
        }
        $rows[] = [
            'date' => $date, 'start' => $start, 'end' => $end, 'duration' => (float)$occurrence['duration'],
            'blocked_start' => $blockedStart, 'blocked_end' => $blockedEnd, 'pricing' => $pricing,
            'conflict' => $conflict, 'conflict_source' => $conflictSource,
        ];
    }
    return $rows;
};

// Batch POST processing has two concerns: normalize/generate occurrence rows,
// then create only after a final conflict recheck under the shared venue lock.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Use distinct submit names so Preview and Create can never fall through to
    // the same action when a browser omits a button value.
    $isCreate = isset($_POST['create_batch']) || isset($_POST['create_available_batch']);
    $isPreview = isset($_POST['preview_batch']) || !$isCreate;
    $paymentChoice = trim((string)($_POST['payment_choice'] ?? ''));
    $selectedPaymentChoice = $paymentChoice;
    $paymentMethod = trim((string)($_POST['booking_payment_method'] ?? ''));
    $paymentReference = trim((string)($_POST['booking_payment_reference'] ?? ''));
    $paymentAmountRaw = trim((string)($_POST['payment_amount'] ?? ''));
    $applyDiscount = isset($_POST['apply_discount']);
    $discountAmountRaw = trim((string)($_POST['flexible_discount_amount'] ?? ''));
    $discountReason = trim((string)($_POST['discount_reason'] ?? ''));
    $paidAtRaw = trim((string)($_POST['booking_paid_at'] ?? ''));
    $selectedBatchPaidAt = $paidAtRaw !== '' ? $paidAtRaw : date('Y-m-d\TH:i');
    $paidAt = null;
    if (!in_array($paymentChoice, ['none', 'partial', 'full'], true)) {
        $errors[] = 'Choose No payment yet, Partial payment, or Full payment.';
    } elseif ($paymentChoice !== 'none') {
        if (!in_array($paymentMethod, booking_payment_methods(), true)) {
            $errors[] = 'Choose a valid payment method.';
        }
        if ($paymentMethod !== '' && $paymentMethod !== 'Cash' && $paymentReference === '') {
            $errors[] = 'Enter a transaction reference or OR number for non-cash payments.';
        }
        if (strlen($paymentReference) > 120) {
            $errors[] = 'The payment reference number may not exceed 120 characters.';
        }
        if ($paymentChoice === 'partial' && (!is_numeric($paymentAmountRaw) || (float)$paymentAmountRaw <= 0)) {
            $errors[] = 'Enter a valid partial payment amount.';
        }
        try {
            $paidAt = $paidAtRaw !== '' ? new DateTimeImmutable($paidAtRaw) : new DateTimeImmutable();
        } catch (Throwable $e) {
            $errors[] = 'Enter a valid payment date and time.';
        }
    } else {
        $paymentMethod = '';
        $paymentReference = '';
    }
    if (strlen($discountReason) > 255) {
        $errors[] = 'The discount note may not exceed 255 characters.';
    }
    $input = $normalize();
    if ($input !== null) {
        try {
            $previewRows = $buildRows($input, true);
            foreach ($previewRows as $row) {
                if ($row['conflict']) $previewConflicts++;
                else $previewTotal += (float)$row['pricing']['total'];
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (!$errors && $isCreate) {
            $pdo = db();
            $lockAcquired = false;
            try {
                $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('tlh_shared_venue_booking',10)")->fetchColumn() === 1;
                if (!$lockAcquired) throw new RuntimeException('The booking calendar is busy. Please try again.');

                // Recheck every occurrence while the shared booking lock is held.
                $rows = $buildRows($input, true);
                $conflicted = array_values(array_filter($rows, static fn(array $row): bool => $row['conflict']));
                if ($conflicted && !$skipConflicts) {
                    throw new RuntimeException('Creation paused: one or more batch dates/times conflict with the booking calendar or another selected occurrence. No reservation has been created yet. Review the list below, select Skip conflicting dates if appropriate, or change the schedule.');
                }
                $rowsToCreate = array_values(array_filter($rows, static fn(array $row): bool => !$row['conflict']));
                if (!$rowsToCreate) throw new RuntimeException('There are no available dates to create in this batch.');

                $pdo->beginTransaction();
                $batchGrossTotal = round(array_sum(array_map(static fn(array $row): float => (float)$row['pricing']['total'], $rowsToCreate)), 2);
                $batchTotal = $batchGrossTotal;
                $batchDiscountAmount = 0.0;
                if ($applyDiscount) {
                    if (!is_numeric($discountAmountRaw)) {
                        throw new RuntimeException('Enter a valid discount amount.');
                    }
                    $batchDiscountAmount = round((float)$discountAmountRaw, 2);
                    if ($batchDiscountAmount < 0.01 || $batchDiscountAmount + 0.001 >= $batchGrossTotal) {
                        throw new RuntimeException('The discount must be at least ' . money(0.01) . ' and lower than the calculated total of ' . money($batchGrossTotal) . '.');
                    }
                    $batchTotal = round($batchGrossTotal - $batchDiscountAmount, 2);
                }

                // Allocate a batch-level discount proportionally across the dates being created.
                // The last row absorbs rounding so the child net totals equal the requested batch payable exactly.
                $remainingDiscount = $batchDiscountAmount;
                $remainingGross = $batchGrossTotal;
                foreach ($rowsToCreate as $rowIndex => &$discountRow) {
                    $rowGross = round((float)$discountRow['pricing']['total'], 2);
                    $rowDiscount = 0.0;
                    if ($batchDiscountAmount > 0.001) {
                        if ($rowIndex === array_key_last($rowsToCreate)) {
                            $rowDiscount = round($remainingDiscount, 2);
                        } elseif ($remainingGross > 0) {
                            $rowDiscount = round($remainingDiscount * ($rowGross / $remainingGross), 2);
                        }
                        $rowDiscount = min($rowGross, max(0, $rowDiscount));
                        $remainingDiscount = round($remainingDiscount - $rowDiscount, 2);
                        $remainingGross = round($remainingGross - $rowGross, 2);
                    }
                    $discountRow['discount_amount'] = $rowDiscount;
                    $discountRow['net_total'] = round($rowGross - $rowDiscount, 2);
                }
                unset($discountRow);

                $paymentAmount = 0.0;
                if ($paymentChoice === 'full') {
                    $paymentAmount = $batchTotal;
                } elseif ($paymentChoice === 'partial') {
                    $paymentAmount = round((float)$paymentAmountRaw, 2);
                    if ($paymentAmount + 0.001 >= $batchTotal) {
                        throw new RuntimeException('For the full client payable amount of ' . money($batchTotal) . ', choose Full Payment.');
                    }
                }

                $existingBatchId = (int)($input['existing_batch_id'] ?? 0);
                $batchRef = '';
                $batchId = 0;
                $startingOccurrence = 0;
                if ($existingBatchId > 0) {
                    $lockedBatchStmt = $pdo->prepare('SELECT * FROM reservation_batches WHERE id=? FOR UPDATE');
                    $lockedBatchStmt->execute([$existingBatchId]);
                    $lockedBatch = $lockedBatchStmt->fetch();
                    if (!$lockedBatch) throw new RuntimeException('The selected existing batch no longer exists.');
                    $batchId = (int)$lockedBatch['id'];
                    $batchRef = (string)$lockedBatch['batch_reference'];
                    $maxOccurrenceStmt = $pdo->prepare('SELECT COALESCE(MAX(batch_occurrence),0) FROM reservations WHERE batch_id=?');
                    $maxOccurrenceStmt->execute([$batchId]);
                    $startingOccurrence = (int)$maxOccurrenceStmt->fetchColumn();
                } else {
                    $batchRef = generate_batch_reference();
                    $batchStmt = $pdo->prepare('INSERT INTO reservation_batches(batch_reference,client_name,organization,phone,email,reservation_type,purpose,range_start,range_end,weekdays,start_time,duration_hours,occurrence_count,total_amount,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $batchStmt->execute([
                        $batchRef, $input['client'], $input['org'] !== '' ? $input['org'] : null, $input['phone'],
                        $input['email'] ?: 'walkin@local.invalid', $input['type'], $input['purpose'] !== '' ? $input['purpose'] : null,
                        $input['range_start'], $input['range_end'], $input['schedule_mode'] === 'specific' ? 'specific' : implode(',', $input['weekdays']), $input['start_time'] . ':00',
                        $input['duration'], count($rowsToCreate), $batchTotal, current_admin()['id'],
                    ]);
                    $batchId = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("INSERT INTO reservations(
                    reference_no,reservation_type,purpose,client_name,organization,email,phone,
                    booking_payment_method,booking_payment_reference,event_start,event_end,
                    setup_minutes,cleanup_minutes,blocked_start,blocked_end,guest_count,
                    pricing_package,cooling_option,rate_period,hourly_rate,billable_hours,
                    shower_room_addon,shower_room_fee,equipment_bundle_addon,equipment_bundle_fee,pricing_snapshot,
                    details,equipment_requests,special_instructions,estimated_amount,final_amount,discount_amount,discount_reason,amount_paid,payment_status,status,source,
                    archived_at,archived_by,batch_id,batch_occurrence,created_by
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

                $createdRows = [];
                $now = new DateTimeImmutable();
                foreach ($rowsToCreate as $index => $row) {
                    $pricing = $row['pricing'];
                    $ref = generate_reference();
                    $rowStatus = ($input['status'] === 'approved' && $row['end'] < $now) ? 'completed' : $input['status'];
                    $stmt->execute([
                        $ref, $input['type'], $input['purpose'] !== '' ? $input['purpose'] : null, $input['client'],
                        $input['org'] !== '' ? $input['org'] : null, $input['email'] ?: 'walkin@local.invalid', $input['phone'],
                        null, null, $row['start']->format('Y-m-d H:i:s'), $row['end']->format('Y-m-d H:i:s'),
                        $input['setup'], $input['cleanup'], $row['blocked_start']->format('Y-m-d H:i:s'), $row['blocked_end']->format('Y-m-d H:i:s'),
                        $input['guests'], $pricing['package'], $pricing['cooling_option'], $pricing['rate_period'], $pricing['hourly_rate'], $pricing['billable_hours'],
                        $pricing['shower_room_addon'] ? 1 : 0, $pricing['shower_room_fee'], $pricing['equipment_bundle_addon'] ? 1 : 0,
                        $pricing['equipment_bundle_fee'], reservation_pricing_snapshot($pricing), $input['requests'] !== '' ? $input['requests'] : null,
                        null, null, $pricing['total'], ((float)($row['discount_amount'] ?? 0) > 0.001 ? (float)$row['net_total'] : null), (float)($row['discount_amount'] ?? 0), ((float)($row['discount_amount'] ?? 0) > 0.001 ? ($discountReason !== '' ? $discountReason : null) : null), 0, 'unpaid', $rowStatus, $input['source'],
                        null, null, $batchId, $startingOccurrence + $index + 1, current_admin()['id'],
                    ]);
                    $createdRows[] = [
                        'id' => (int)$pdo->lastInsertId(),
                        'reference_no' => $ref,
                        'event_start' => $row['start']->format('Y-m-d H:i:s'),
                        'event_end' => $row['end']->format('Y-m-d H:i:s'),
                        'target' => round((float)($row['net_total'] ?? $pricing['total']), 2),
                    ];
                }

                // Keep occurrence numbering and batch summary values derived from the
                // reservations that were actually created. This also makes a skipped
                // conflict at the edge of a new range incapable of leaving a misleading
                // batch start/end date.
                $orderStmt = $pdo->prepare('SELECT id FROM reservations WHERE batch_id=? ORDER BY event_start ASC, id ASC');
                $orderStmt->execute([$batchId]);
                $renumberStmt = $pdo->prepare('UPDATE reservations SET batch_occurrence=? WHERE id=?');
                foreach ($orderStmt->fetchAll(PDO::FETCH_COLUMN) as $position => $reservationId) {
                    $renumberStmt->execute([$position + 1, (int)$reservationId]);
                }
                $batchUpdate = $pdo->prepare("UPDATE reservation_batches b SET
                    occurrence_count=(SELECT COUNT(*) FROM reservations r WHERE r.batch_id=b.id),
                    total_amount=(SELECT COALESCE(SUM(COALESCE(r.final_amount,r.estimated_amount)),0) FROM reservations r WHERE r.batch_id=b.id),
                    range_start=(SELECT MIN(DATE(r.event_start)) FROM reservations r WHERE r.batch_id=b.id),
                    range_end=(SELECT MAX(DATE(r.event_start)) FROM reservations r WHERE r.batch_id=b.id)
                    WHERE b.id=?");
                $batchUpdate->execute([$batchId]);

                if ($paymentAmount > 0.001) {
                    $batchPaymentNo = generate_batch_payment_no();
                    $coverageStart = substr($createdRows[0]['event_start'], 0, 10);
                    $coverageEnd = substr($createdRows[count($createdRows) - 1]['event_start'], 0, 10);
                    $coverageType = $existingBatchId > 0 ? 'selected_dates' : 'entire_batch';
                    $coverageLabel = $existingBatchId > 0
                        ? count($createdRows) . ' newly added batch date' . (count($createdRows) === 1 ? '' : 's') . ' · ' . batch_payment_date_range_label($coverageStart, $coverageEnd)
                        : 'Entire batch at creation · ' . batch_payment_date_range_label($coverageStart, $coverageEnd);
                    $remaining = $paymentAmount;
                    $allocationCount = 0;
                    $allocations = [];
                    foreach ($createdRows as $createdRow) {
                        $allocation = $remaining > 0.001 ? round(min((float)$createdRow['target'], $remaining), 2) : 0.0;
                        if ($allocation > 0) {
                            $remaining = round($remaining - $allocation, 2);
                            $allocationCount++;
                        }
                        $createdRow['allocation'] = $allocation;
                        $allocations[] = $createdRow;
                    }
                    if ($remaining > 0.009) throw new RuntimeException('The initial batch payment could not be fully allocated.');

                    $masterStmt = $pdo->prepare('INSERT INTO batch_payments(batch_id,batch_payment_no,amount,payment_method,payment_reference,notes,coverage_type,coverage_start,coverage_end,coverage_label,coverage_count,allocation_count,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $masterStmt->execute([
                        $batchId, $batchPaymentNo, $paymentAmount, $paymentMethod, $paymentReference !== '' ? $paymentReference : null,
                        $existingBatchId > 0 ? 'Payment recorded while adding dates to an existing batch.' : 'Initial payment recorded when the batch was created.',
                        $coverageType, $coverageStart, $coverageEnd, $coverageLabel, count($createdRows), $allocationCount,
                        current_admin()['id'], ($paidAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                    $batchPaymentId = (int)$pdo->lastInsertId();
                    $scopeStmt = $pdo->prepare('INSERT INTO batch_payment_scopes(batch_payment_id,reservation_id,reservation_reference,event_start_snapshot,event_end_snapshot,balance_before,allocated_amount) VALUES(?,?,?,?,?,?,?)');
                    $paymentStmt = $pdo->prepare('INSERT INTO payments(reservation_id,batch_payment_id,amount,payment_method,payment_reference,notes,recorded_by,paid_at) VALUES(?,?,?,?,?,?,?,?)');
                    $updatePaymentStmt = $pdo->prepare('UPDATE reservations SET amount_paid=?,payment_status=? WHERE id=?');
                    foreach ($allocations as $allocationRow) {
                        $scopeStmt->execute([
                            $batchPaymentId, $allocationRow['id'], $allocationRow['reference_no'], $allocationRow['event_start'], $allocationRow['event_end'],
                            $allocationRow['target'], $allocationRow['allocation'],
                        ]);
                        if ($allocationRow['allocation'] <= 0) continue;
                        $paymentStmt->execute([
                            $allocationRow['id'], $batchPaymentId, $allocationRow['allocation'], $paymentMethod, $paymentReference !== '' ? $paymentReference : null,
                            'Allocated from initial batch payment ' . $batchPaymentNo . '.', current_admin()['id'], ($paidAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
                        ]);
                        $updatePaymentStmt->execute([
                            $allocationRow['allocation'], payment_status_for_amount($allocationRow['allocation'], $allocationRow['target']), $allocationRow['id'],
                        ]);
                    }
                }

                $pdo->commit();
                $skipped = count($conflicted);
                $actionText = ((int)($input['existing_batch_id'] ?? 0) > 0) ? ' updated with ' : ' created with ';
                flash('success', 'Batch ' . $batchRef . $actionText . count($rowsToCreate) . ' reservation' . (count($rowsToCreate) === 1 ? '' : 's') . ($skipped ? '; ' . $skipped . ' conflicting occurrence' . ($skipped === 1 ? '' : 's') . ' skipped.' : '.'));
                redirect('batch-reservation-view.php?id=' . $batchId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Batch creation failed: ' . $e->getMessage();
                try {
                    $previewRows = $buildRows($input, true);
                    $previewTotal = 0.0;
                    $previewConflicts = 0;
                    foreach ($previewRows as $row) {
                        if ($row['conflict']) $previewConflicts++; else $previewTotal += (float)$row['pricing']['total'];
                    }
                } catch (Throwable $ignored) {}
            } finally {
                if ($lockAcquired) {
                    try { $pdo->query("SELECT RELEASE_LOCK('tlh_shared_venue_booking')"); } catch (Throwable $ignored) {}
                }
            }
        }
    }
}

include __DIR__ . '/_header.php';
?>
<?php if ($errors): ?><div class="alert alert-error"><strong>Please review the batch reservation.</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="panel reservation-step-card admin-reservation-step-card batch-step-card">
  <div class="panel-head batch-step-head">
    <div><h2><?= $appendBatch ? 'Add Dates to ' . e($appendBatch['batch_reference']) : 'Create Batch Reservation' ?></h2><p class="muted"><?= $appendBatch ? 'Add missing dates to this existing batch. Historical dates are available only when you explicitly enable Past Date Selection.' : 'Create one connected batch. Future dates are the default; historical dates require explicit admin activation.' ?></p></div>
    <a class="btn btn-outline btn-sm" href="reservations.php?view=batches">Back to Batch Groups</a>
  </div>

  <form method="post" class="reservation-step-form batch-reservation-form" id="batchReservationForm" data-reservation-stepper data-initial-step="<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) ? '3' : '1' ?>" data-batch-live-availability data-admin-past-date-control data-admin-allow-past="<?= $allowPastDates ? '1' : '0' ?>" data-today="<?= e($todayDate) ?>" data-batch-availability-url="batch-availability-check.php">
    <?php if ($appendBatch): ?><div class="alert alert-info" style="width:100%;margin:0 0 14px"><strong>Adding dates to <?= e((string)$appendBatch['batch_reference']) ?>.</strong> Client identity, reservation type, guest/package, cooling, purpose, and add-on terms are locked to the existing batch. Enter only the missing dates/times, status, optional notes, and any payment for the newly added dates.</div><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="create_batch" value="1">
    <input type="hidden" name="existing_batch_id" value="<?= (int)$appendBatchId ?>">

    <nav class="reservation-steps" aria-label="Batch reservation progress">
      <button type="button" class="reservation-step-indicator is-active" data-step-indicator="1"><span>1</span><strong>Schedule</strong></button>
      <button type="button" class="reservation-step-indicator" data-step-indicator="2"><span>2</span><strong>Details</strong></button>
      <button type="button" class="reservation-step-indicator" data-step-indicator="3"><span>3</span><strong>Review &amp; Create</strong></button>
    </nav>

    <section class="reservation-step-panel is-active" data-form-step="1" aria-labelledby="batchStepScheduleTitle">
      <div class="reservation-step-title"><span>Step 1 of 3</span><h3 id="batchStepScheduleTitle">Choose the batch schedule</h3><p>Use a recurring pattern or select individual dates. Historical dates stay locked unless you intentionally enable them.</p></div>
      <div class="form-group full past-date-access-control">
        <label class="checkbox-option"><input type="checkbox" name="allow_past_dates" value="1" data-past-date-toggle <?= $allowPastDates ? 'checked' : '' ?>><span><strong>Enable Past Date Selection</strong><small>Turn this on only when entering older batch occurrences. When it is off, past dates/times cannot be previewed or created.</small></span></label>
        <span class="field-help" data-past-date-note><?= $allowPastDates ? 'Historical entry enabled. Past batch dates can be selected.' : 'Historical entry is off. Only current or future batch schedules can be selected.' ?></span>
      </div>

      <div class="batch-schedule-mode" role="radiogroup" aria-label="Batch schedule type">
        <label class="batch-mode-option"><input type="radio" name="schedule_mode" value="recurring" data-batch-schedule-mode <?= $scheduleMode === 'recurring' ? 'checked' : '' ?>><span><strong>Recurring Schedule</strong><small>Date range + weekdays with one common time.</small></span></label>
        <label class="batch-mode-option"><input type="radio" name="schedule_mode" value="specific" data-batch-schedule-mode <?= $scheduleMode === 'specific' ? 'checked' : '' ?>><span><strong>Specific Dates</strong><small>Pick individual dates and set each time separately.</small></span></label>
      </div>

      <div class="batch-mode-panel" data-batch-recurring-panel <?= $scheduleMode === 'specific' ? 'hidden' : '' ?>>
        <div class="form-grid">
          <div class="form-group"><label>From *</label><input type="date" name="range_start" value="<?= e((string)$form['range_start']) ?>" <?= $allowPastDates ? '' : 'min="' . e($todayDate) . '" ' ?>data-admin-past-date-field data-batch-range-start data-batch-recurring-field required></div>
          <div class="form-group"><label>Until *</label><input type="date" name="range_end" value="<?= e((string)$form['range_end']) ?>" <?= $allowPastDates ? '' : 'min="' . e($todayDate) . '" ' ?>data-admin-past-date-field data-batch-range-end data-batch-recurring-field required></div>
          <div class="form-group full"><label>Recurring Days *</label>
            <div class="weekday-picker weekday-check-grid" data-weekday-picker><?php foreach ($weekdayLabels as $value => $label): ?><label class="weekday-check"><input type="checkbox" name="weekdays[]" value="<?= $value ?>" data-batch-recurring-field <?= in_array($value, $selectedWeekdays, true) ? 'checked' : '' ?>><span><?= e(substr($label, 0, 3)) ?></span></label><?php endforeach; ?></div>
            <div class="batch-inline-tools"><button type="button" class="link-button" data-select-weekdays="weekdays">Mon–Fri</button><button type="button" class="link-button" data-select-weekdays="all">Every day</button><button type="button" class="link-button" data-select-weekdays="none">Clear</button></div>
          </div>
          <div class="form-group"><label>Start Time *</label><select name="start_time" data-batch-start-time data-batch-recurring-field required><?php foreach ($startTimeSlots as $value => $label): ?><option value="<?= e($value) ?>" <?= (string)$form['start_time'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Duration *</label><select name="duration_hours" data-batch-duration data-batch-recurring-field required><?php foreach (reservation_duration_options() as $durationValue => $durationLabel): ?><option value="<?= e($durationValue) ?>" <?= abs((float)$form['duration_hours'] - (float)$durationValue) < 0.001 ? 'selected' : '' ?>><?= e($durationLabel) ?></option><?php endforeach; ?></select><span class="field-help" data-batch-end-label>Ends automatically based on the start time.</span></div>
        </div>
      </div>

      <div class="batch-mode-panel batch-specific-panel" data-batch-specific-panel <?= $scheduleMode === 'recurring' ? 'hidden' : '' ?>>
        <div class="batch-specific-intro"><strong>Add only the dates the client wants.</strong><span>New dates use the default time below. You can then change any individual row.</span></div>
        <div class="form-grid batch-specific-defaults">
          <div class="form-group"><label>Add Date</label><div class="batch-specific-add"><input type="date" <?= $allowPastDates ? '' : 'min="' . e($todayDate) . '" ' ?>data-admin-past-date-field data-specific-date-picker><button type="button" class="btn btn-outline btn-sm" data-specific-add-date>Add Date</button></div></div>
          <div class="form-group"><label>Default Start Time</label><select data-specific-default-start><?php foreach ($startTimeSlots as $value => $label): ?><option value="<?= e($value) ?>" <?= (string)$form['start_time'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Default Duration</label><select data-specific-default-duration><?php foreach (reservation_duration_options() as $durationValue => $durationLabel): ?><option value="<?= e($durationValue) ?>" <?= abs((float)$form['duration_hours'] - (float)$durationValue) < 0.001 ? 'selected' : '' ?>><?= e($durationLabel) ?></option><?php endforeach; ?></select></div>
          <div class="form-group batch-apply-all-wrap"><label>&nbsp;</label><button type="button" class="btn btn-outline btn-sm" data-specific-apply-all>Apply Time &amp; Duration to All Dates</button></div>
        </div>
        <div class="batch-specific-empty" data-specific-empty>No specific dates added yet. Choose a date above and click <strong>Add Date</strong>.</div>
        <div class="batch-specific-rows" data-specific-rows data-specific-initial='<?= e(json_encode($specificPostedRows, JSON_UNESCAPED_SLASHES)) ?>'></div>
        <template data-specific-row-template>
          <div class="batch-specific-row" data-specific-row>
            <div class="form-group batch-specific-date-field"><label>Date *</label><input type="date" name="specific_date[]" <?= $allowPastDates ? '' : 'min="' . e($todayDate) . '" ' ?>data-admin-past-date-field data-specific-row-date required></div>
            <div class="form-group"><label>Start Time *</label><select name="specific_start_time[]" data-specific-row-start required><?php foreach ($startTimeSlots as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Duration *</label><select name="specific_duration_hours[]" data-specific-row-duration required><?php foreach (reservation_duration_options() as $durationValue => $durationLabel): ?><option value="<?= e($durationValue) ?>"><?= e($durationLabel) ?></option><?php endforeach; ?></select></div>
            <div class="batch-specific-row-status" data-specific-row-status><span class="status-pill">Waiting</span></div>
            <button type="button" class="btn btn-outline btn-sm batch-specific-remove" data-specific-remove aria-label="Remove this date">Remove</button>
          </div>
        </template>
      </div>

      <div class="batch-live-feedback">
        <div class="batch-live-check" data-batch-live-status data-state="idle" role="status" aria-live="polite">
          <span class="batch-live-check-icon" aria-hidden="true">○</span>
          <div class="batch-live-check-copy"><strong>Live conflict check</strong><p>Complete the schedule to check all selected occurrences.</p></div>
          <div class="batch-live-check-counts" data-batch-live-counts hidden>
            <span><b data-batch-live-total>0</b> occurrences</span>
            <span><b data-batch-live-available>0</b> available</span>
            <span><b data-batch-live-conflicts>0</b> conflicts</span>
          </div>
        </div>
        <div class="batch-conflict-chips" data-batch-conflict-chips hidden></div>
      </div>

      <div class="form-grid batch-shared-schedule-fields">
        <div class="form-group"><label>Reservation Type *</label><select name="reservation_type" data-batch-type required <?= $appendBatch ? 'disabled' : '' ?>><option value="basketball" <?= $form['reservation_type']==='basketball'?'selected':'' ?>>Basketball Court</option><option value="volleyball" <?= $form['reservation_type']==='volleyball'?'selected':'' ?>>Volleyball Court</option><option value="event" <?= $form['reservation_type']==='event'?'selected':'' ?>>Events Reservation</option></select></div>
        <div class="form-group"><label>Expected Guests *</label><input type="number" min="1" max="400" name="guest_count" value="<?= e((string)$form['guest_count']) ?>" data-batch-guests required <?= $appendBatch ? 'disabled' : '' ?>><span class="field-help">1–29 Regular · 30–200 Tournament · 201–400 Big Event.</span></div>
        <div class="form-group"><label>Cooling Option *</label><select name="cooling_option" data-batch-cooling required <?= $appendBatch ? 'disabled' : '' ?>><?php foreach (reservation_cooling_options() as $value => $label): ?><option value="<?= e($value) ?>" <?= (string)$form['cooling_option']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group full"><div class="package-preview batch-quick-price-preview" data-batch-quick-price aria-live="polite"><span>Package</span><strong data-batch-quick-price-package>Enter guest count</strong><b data-batch-quick-price-total>—</b><p data-batch-quick-price-message>Enter the expected guests to calculate the batch price.</p></div></div>
      </div>

      <div class="reservation-step-actions"><span></span><button type="button" class="btn btn-primary" data-step-next>Continue to Details</button></div>
    </section>

    <section class="reservation-step-panel" data-form-step="2" aria-labelledby="batchStepDetailsTitle" hidden>
      <div class="reservation-step-title"><span>Step 2 of 3</span><h3 id="batchStepDetailsTitle">Client details and reservation options</h3><p>Enter the client information, purpose, add-ons, and calendar blocking time for events.</p></div>

      <div class="form-grid">
        <div class="form-group"><label>Client / Contact Name *</label><input name="client_name" value="<?= e((string)$form['client_name']) ?>" data-batch-client required <?= $appendBatch ? 'disabled' : '' ?>></div>
        <div class="form-group"><label>Mobile Number *</label><input name="phone" value="<?= e((string)$form['phone']) ?>" required <?= $appendBatch ? 'disabled' : '' ?>></div>
        <div class="form-group"><label>Email <span class="optional-label">Optional</span></label><input type="email" name="email" value="<?= e((string)$form['email']) ?>" <?= $appendBatch ? 'disabled' : '' ?>></div>
        <div class="form-group"><label>Organization / Team <span class="optional-label">Optional</span></label><input name="organization" value="<?= e((string)$form['organization']) ?>" <?= $appendBatch ? 'disabled' : '' ?>></div>
        <div class="form-group"><label>Source</label><select name="source" <?= $appendBatch ? 'disabled' : '' ?>><option value="walk_in" <?= $form['source']==='walk_in'?'selected':'' ?>>Walk-in</option><option value="internal" <?= $form['source']==='internal'?'selected':'' ?>>Internal</option><option value="website" <?= $form['source']==='website'?'selected':'' ?>>Website Assisted</option></select></div>
        <div class="form-group"><label>Initial Status</label><select name="status" data-batch-status><option value="approved" <?= $form['status']==='approved'?'selected':'' ?>>Approved</option><option value="for_review" <?= $form['status']==='for_review'?'selected':'' ?>>For Review</option><option value="pending" <?= $form['status']==='pending'?'selected':'' ?>>Pending</option></select></div>
        <div class="form-group full" data-batch-sport-fields><label>Booking Purpose</label><select name="sport_purpose" <?= $appendBatch ? 'disabled' : '' ?>><?php foreach (['Practice','Friendly Game','Training','League or Tournament','Other'] as $purpose): ?><option value="<?= e($purpose) ?>" <?= (string)$form['sport_purpose']===$purpose?'selected':'' ?>><?= e($purpose) ?></option><?php endforeach; ?></select></div>
        <div class="form-group full" data-batch-event-fields><label>Event Type</label><input name="event_purpose" value="<?= e((string)$form['event_purpose']) ?>" placeholder="Birthday, seminar, reception..." <?= $appendBatch ? 'disabled' : '' ?>></div>
        <div class="form-group" data-batch-event-fields><label>Setup Time</label><select name="setup_minutes" data-batch-setup <?= $appendBatch ? 'disabled' : '' ?>><?php foreach ([0=>'No setup block',30=>'30 minutes',60=>'1 hour',120=>'2 hours',180=>'3 hours'] as $minutes=>$label): ?><option value="<?= $minutes ?>" <?= (int)$form['setup_minutes']===$minutes?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><span class="field-help">Blocks the calendar but is not charged.</span></div>
        <div class="form-group" data-batch-event-fields><label>Cleanup Time</label><select name="cleanup_minutes" data-batch-cleanup <?= $appendBatch ? 'disabled' : '' ?>><?php foreach ([0=>'No cleanup block',30=>'30 minutes',60=>'1 hour',120=>'2 hours'] as $minutes=>$label): ?><option value="<?= $minutes ?>" <?= (int)$form['cleanup_minutes']===$minutes?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><span class="field-help">Blocks the calendar but is not charged.</span></div>
        <div class="form-group full pricing-addons"><label>Additional Services <span class="small muted">applied to every occurrence</span></label><div class="addon-grid">
          <label class="checkbox-option"><input type="checkbox" name="shower_room_addon" value="1" data-batch-shower <?= $appendBatch ? 'disabled' : '' ?> <?= $defaultShowerChecked?'checked':'' ?>><span><strong>Shower Room</strong><small><?= money($pricingConfig['shower_room_fee']) ?> per occurrence</small></span></label>
          <label class="checkbox-option"><input type="checkbox" name="shower_room_complimentary" value="1" data-batch-shower-complimentary <?= $appendBatch ? 'disabled' : '' ?> <?= $defaultShowerComplimentaryChecked?'checked':'' ?>><span><strong>Complimentary Shower Room</strong><small>Waive the shower fee on every occurrence while keeping access included</small></span></label>
          <label class="checkbox-option"><input type="checkbox" name="equipment_bundle_addon" value="1" data-batch-equipment <?= $appendBatch ? 'disabled' : '' ?> <?= $defaultEquipmentChecked?'checked':'' ?>><span><strong>Equipment Bundle</strong><small><?= money($pricingConfig['equipment_bundle_fee']) ?> per hour on each regular occurrence; included for Tournament/Big Event</small></span></label>
          <label class="checkbox-option"><input type="checkbox" name="equipment_bundle_complimentary" value="1" data-batch-equipment-complimentary <?= $appendBatch ? 'disabled' : '' ?> <?= $defaultEquipmentComplimentaryChecked?'checked':'' ?>><span><strong>Complimentary Equipment Bundle</strong><small>Waive the hourly equipment fee on every regular occurrence while keeping the bundle included</small></span></label>
        </div></div>
        <div class="form-group full"><label>Additional Requests or Instructions <span class="optional-label">Optional</span></label><textarea name="additional_requests" placeholder="Venue setup, equipment, or other instructions."><?= e((string)$form['additional_requests']) ?></textarea></div>
      </div>

      <div class="batch-create-note"><strong>Pricing:</strong> The same package, cooling option, and add-ons are applied to every generated occurrence, while date-dependent rates are calculated per occurrence.</div>
      <div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button type="button" class="btn btn-primary" data-step-next>Continue to Payment &amp; Review</button></div>
    </section>

    <section class="reservation-step-panel" data-form-step="3" aria-labelledby="batchStepReviewTitle" hidden>
      <div class="reservation-step-title"><span>Step 3 of 3</span><h3 id="batchStepReviewTitle">Payment and review</h3><p>Choose whether to record an initial payment, then review the calculated batch before creating the dates.</p></div>

      <div class="payment-required-notice" role="alert" aria-live="polite"><span class="payment-required-icon" aria-hidden="true">!</span><div><strong>Required Payment Selection</strong><p>Choose how the <?= $appendBatch ? 'new dates' : 'batch' ?> will be paid. You can still record additional batch or date-specific payments later.</p></div></div>
      <div class="payment-choice-grid" data-payment-choice-group>
        <label class="payment-choice"><input type="radio" name="payment_choice" value="none" data-payment-choice required <?= $selectedPaymentChoice === 'none' ? 'checked' : '' ?>><span><strong>No payment yet</strong><small>Create the <?= $appendBatch ? 'new dates' : 'batch' ?> as unpaid.</small></span></label>
        <label class="payment-choice"><input type="radio" name="payment_choice" value="partial" data-payment-choice <?= $selectedPaymentChoice === 'partial' ? 'checked' : '' ?>><span><strong>Partial payment</strong><small>Allocate an initial amount across the created dates, earliest first.</small></span></label>
        <label class="payment-choice"><input type="radio" name="payment_choice" value="full" data-payment-choice <?= $selectedPaymentChoice === 'full' ? 'checked' : '' ?>><span><strong>Full payment</strong><small>Use the exact calculated total for the dates being created.</small></span></label>
      </div>
      <div class="flexible-discount-card" data-discount-control>
        <label class="checkbox-option flexible-discount-toggle"><input type="checkbox" name="apply_discount" value="1" data-apply-discount <?= isset($_POST['apply_discount']) ? 'checked' : '' ?>><span><strong>Apply Flexible Discount</strong><small>Enter the discount amount to subtract from the calculated <?= $appendBatch ? 'dates being added' : 'batch' ?> total. TLH will allocate it automatically.</small></span></label>
        <div class="form-grid flexible-discount-fields" data-discount-fields <?= isset($_POST['apply_discount']) ? '' : 'hidden' ?>>
          <div class="form-group"><label>Discount Amount *</label><input type="number" min="0.01" step="0.01" name="flexible_discount_amount" value="<?= e($_POST['flexible_discount_amount'] ?? '') ?>" data-discount-input placeholder="Example: 500.00"><span class="field-help">Enter the amount to deduct. Example: ₱1,500 total minus ₱500 discount = ₱1,000 client payable.</span></div>
          <div class="form-group"><label>Discount Note <span class="optional-label">Optional</span></label><input type="text" maxlength="255" name="discount_reason" value="<?= e($_POST['discount_reason'] ?? '') ?>" placeholder="Courtesy discount, management approval, promo..."></div>
        </div>
        <div class="flexible-discount-summary" data-discount-summary hidden><span>Calculated Total <strong data-discount-gross>—</strong></span><span>Discount <strong data-discount-amount>—</strong></span><span>Client Payable <strong data-discount-net>—</strong></span></div>
      </div>
      <div class="form-grid payment-fields" data-payment-fields <?= in_array($selectedPaymentChoice, ['partial', 'full'], true) ? '' : 'hidden' ?>>
        <div class="form-group"><label>Payment Method *</label><select name="booking_payment_method" data-booking-payment-method><option value="">Select payment method</option><?php foreach (booking_payment_methods() as $method): ?><option value="<?= e($method) ?>" <?= ($_POST['booking_payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= e(booking_payment_method_label($method)) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference / OR Number</label><input name="booking_payment_reference" maxlength="120" value="<?= e($_POST['booking_payment_reference'] ?? '') ?>" data-booking-payment-reference data-allow-cash-reference placeholder="Transaction reference or OR no."><span class="field-help" data-booking-payment-reference-help>Optional for cash; required for non-cash payments.</span></div>
        <div class="form-group"><label>Payment Date &amp; Time *</label><input type="datetime-local" name="booking_paid_at" value="<?= e($selectedBatchPaidAt) ?>" required><span class="field-help">Use the actual date received for older bookings.</span></div>
        <div class="form-group" data-partial-payment-field><label>Partial Payment Amount *</label><input type="number" min="0.01" step="0.01" name="payment_amount" value="<?= e($_POST['payment_amount'] ?? '') ?>" data-payment-amount placeholder="Enter the amount already paid"><span class="field-help">Must be lower than the calculated total for the dates being created.</span></div>
        <div class="full-payment-note form-group full" data-full-payment-note hidden>The full payment amount will automatically match the client payable amount after discount for the dates being created.</div>
      </div>

      <div class="batch-review-summary">
        <div><span>Client</span><strong data-batch-review-client>—</strong></div>
        <div><span>Schedule</span><strong data-batch-review-schedule>—</strong></div>
        <div><span>Schedule Type</span><strong data-batch-review-mode>—</strong></div>
        <div><span>Booking</span><strong data-batch-review-booking>—</strong></div>
        <div><span>Available Occurrences</span><strong data-batch-review-available>—</strong></div>
        <div><span>Calculated Total</span><strong data-batch-review-total>—</strong></div>
        <div><span>Discount</span><strong data-review-discount>₱0.00</strong></div>
        <div><span>Client Payable</span><strong data-review-net-total>—</strong></div>
        <div><span>Payment</span><strong data-review-payment>No payment yet</strong></div>
        <div><span>Paid Now</span><strong data-review-paid>₱0.00</strong></div>
        <div><span>Balance After Payment</span><strong data-review-balance>—</strong></div>
      </div>

      <section class="batch-price-estimate batch-review-price-breakdown" data-batch-price-panel aria-live="polite">
        <div class="batch-price-estimate-head">
          <div><span class="small muted">Itemized Price Breakdown</span><h3>Estimated Batch Price</h3></div>
          <strong class="batch-price-estimate-total" data-batch-price-total>—</strong>
        </div>
        <div class="batch-price-estimate-meta">
          <span data-batch-price-package>Enter the guest count to calculate the package.</span>
          <span data-batch-price-scope>Complete the schedule to calculate the batch.</span>
        </div>
        <div class="batch-price-lines" data-batch-price-lines>
          <div class="batch-price-line is-placeholder"><span>Price breakdown</span><strong>Waiting for schedule</strong></div>
        </div>
        <p class="batch-price-note" data-batch-price-note>Rates are taken from the current Rates Settings. Setup and cleanup blocks are not billed.</p>
      </section>

      <div class="batch-review-availability" data-batch-review-availability>
        <div class="batch-review-availability-head"><div><span class="small muted">Live Calendar Cross-check</span><h3 data-batch-review-status>Checking selected dates…</h3></div><span class="small muted">Every date is checked again at creation.</span></div>
        <div class="table-wrap batch-live-table-wrap">
          <table class="admin-table batch-live-table mobile-card-table">
            <thead><tr><th>Date</th><th>Time</th><th>Amount</th><th>Availability</th></tr></thead>
            <tbody data-batch-live-rows><tr><td colspan="4" class="muted">Checking selected dates…</td></tr></tbody>
          </table>
        </div>
      </div>

      <label class="checkbox-option batch-skip-conflicts" data-batch-skip-wrap hidden><input type="checkbox" name="skip_conflicts" value="1" data-batch-skip-conflicts <?= $skipConflicts?'checked':'' ?>><span><strong>Skip conflicting occurrences</strong><small>Create only the dates/times that are currently available. Conflicting occurrences will be left out of the batch.</small></span></label>
      <div class="alert alert-error batch-all-conflicts" data-batch-all-conflicts hidden><strong>No selected occurrences are currently available.</strong> Go back and adjust the selected dates or times before creating the batch.</div>
      <div class="batch-final-note">Availability is checked live here and is <strong>checked again under the booking lock when you click Create Reservations</strong>.</div>

      <div class="reservation-step-actions"><button type="button" class="btn btn-outline" data-step-back>Back</button><button class="btn btn-primary" type="submit" data-batch-create-button>Create Reservations</button></div>
    </section>
  </form>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
