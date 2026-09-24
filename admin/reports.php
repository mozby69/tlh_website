<?php
/**
 * FILE PURPOSE: Unified Admin Reports Center for operational and accounting reports.
 * DEBUGGING: Collections use payment.paid_at; reservation sales use event_start; outstanding balances use the live payment ledger.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$adminPageTitle = 'Reports Center';
$allowedReports = ['collections', 'outstanding', 'sales', 'daily', 'cancellations', 'management'];
$report = trim((string)($_GET['report'] ?? 'collections'));
if (!in_array($report, $allowedReports, true)) {
    $report = 'collections';
}

$reportCatalog = [
    'collections' => [
        'label' => 'Collections',
        'title' => 'Collections Report',
        'description' => 'Verified payment-ledger activity by the actual Paid At date, including refunds for reconciliation.',
        'basis' => 'Payment date',
    ],
    'outstanding' => [
        'label' => 'Outstanding',
        'title' => 'Outstanding Balances',
        'description' => 'Current collectible balances from the payment ledger, with past-event aging and upcoming balances kept separate.',
        'basis' => 'Event date',
    ],
    'sales' => [
        'label' => 'Sales',
        'title' => 'Combined Sales Report',
        'description' => 'Venue reservation sales and Admin-encoded rental sales in one report. This is separate from cash collection timing.',
        'basis' => 'Event date',
    ],
    'daily' => [
        'label' => 'Daily Reservations',
        'title' => 'Daily Reservation Report',
        'description' => 'Operational schedule with reservation status, client details, booking value, verified payments, and remaining balance.',
        'basis' => 'Event date',
    ],
    'cancellations' => [
        'label' => 'Cancellations & Refunds',
        'title' => 'Cancellation & Refund Report',
        'description' => 'Reservation and rental cancellations, amounts retained by TLH, refunds issued, and any remaining settlement obligations.',
        'basis' => 'Cancellation date',
    ],
    'management' => [
        'label' => 'Management Summary',
        'title' => 'Monthly Management Summary',
        'description' => 'A high-level view that keeps booking value, cash collections, refunds, and outstanding balances separate for management review.',
        'basis' => 'Selected period',
    ],
];

$today = new DateTimeImmutable('today');
$currentMonthStart = $today->modify('first day of this month');
$currentMonthEnd = $today->modify('last day of this month');
$lastMonthStart = $today->modify('first day of last month');
$lastMonthEnd = $today->modify('last day of last month');
$weekStart = $today->modify('monday this week');
$weekEnd = $weekStart->modify('+6 days');

$dateIsValid = static function (string $date): bool {
    if ($date === '') {
        return true;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};

$defaultFrom = '';
$defaultTo = '';
if ($report === 'daily') {
    $defaultFrom = $today->format('Y-m-d');
    $defaultTo = $today->format('Y-m-d');
} elseif ($report !== 'outstanding') {
    $defaultFrom = $currentMonthStart->format('Y-m-d');
    $defaultTo = $today->format('Y-m-d');
}

$dateFrom = trim((string)($_GET['from'] ?? $defaultFrom));
$dateTo = trim((string)($_GET['to'] ?? $defaultTo));
if (!$dateIsValid($dateFrom)) {
    $dateFrom = $defaultFrom;
}
if (!$dateIsValid($dateTo)) {
    $dateTo = $defaultTo;
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$type = trim((string)($_GET['type'] ?? ''));
if (!in_array($type, ['', 'basketball', 'volleyball', 'event'], true)) {
    $type = '';
}
$method = trim((string)($_GET['method'] ?? ''));
if ($method !== '' && !in_array($method, booking_payment_methods(), true)) {
    $method = '';
}
$aging = trim((string)($_GET['aging'] ?? 'all'));
if (!in_array($aging, ['all', 'past', 'upcoming', '1_7', '8_30', '31_60', '61_plus'], true)) {
    $aging = 'all';
}
$search = trim((string)($_GET['search'] ?? ''));
if (strlen($search) > 120) {
    $search = substr($search, 0, 120);
}
$format = trim((string)($_GET['format'] ?? ''));
if (!in_array($format, ['', 'csv', 'excel'], true)) {
    $format = '';
}

$statusLabel = static function (array $row): string {
    if (reservation_needs_resolution($row)) {
        return 'Needs Resolution';
    }
    return match ((string)($row['status'] ?? '')) {
        'for_review' => 'For Review',
        'no_show' => 'No Show',
        'approved' => 'Approved',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        default => ucfirst(str_replace('_', ' ', (string)($row['status'] ?? 'Pending'))),
    };
};

$paymentStateLabel = static function (float $paid, float $target): string {
    return match (payment_status_for_amount($paid, $target)) {
        'paid' => 'Paid',
        'partial' => 'Partial',
        default => 'Unpaid',
    };
};

$agingLabel = static function (string $eventEnd): string {
    try {
        $end = new DateTimeImmutable($eventEnd);
        $now = new DateTimeImmutable();
    } catch (Throwable $e) {
        return 'Schedule unavailable';
    }
    if ($end >= $now) {
        $days = (int)floor(($end->getTimestamp() - $now->getTimestamp()) / 86400);
        if ($days <= 0) return 'Event today';
        return $days === 1 ? 'Event in 1 day' : 'Event in ' . $days . ' days';
    }
    $days = (int)floor(($now->getTimestamp() - $end->getTimestamp()) / 86400);
    if ($days <= 0) return 'Ended today';
    return $days === 1 ? '1 day after event' : $days . ' days after event';
};

$dateClause = static function (string $column, string $from, string $to, array &$params): string {
    $parts = [];
    if ($from !== '') {
        $parts[] = "DATE({$column}) >= ?";
        $params[] = $from;
    }
    if ($to !== '') {
        $parts[] = "DATE({$column}) <= ?";
        $params[] = $to;
    }
    return $parts ? implode(' AND ', $parts) : '1=1';
};

$summaryCards = [];
$columns = [];
$rows = [];
$reportNote = '';
$reportError = '';

try {
    $pdo = db();

    if ($report === 'collections') {
        $params = [];
        $where = [$dateClause('p.paid_at', $dateFrom, $dateTo, $params)];
        if ($type !== '') { $where[] = 'r.reservation_type=?'; $params[] = $type; }
        if ($method !== '') { $where[] = 'p.payment_method=?'; $params[] = $method; }
        if ($search !== '') { $where[] = '(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR p.payment_reference LIKE ? OR b.batch_reference LIKE ?)'; $term='%'.$search.'%'; array_push($params,$term,$term,$term,$term,$term,$term); }
        $stmt=$pdo->prepare("SELECT p.id,p.transaction_type,p.amount,p.payment_method,p.payment_reference,p.notes,p.paid_at,
                r.id AS record_id,r.reference_no AS record_reference,r.client_name AS record_client,r.phone,r.event_start AS record_date,
                b.batch_reference,a.full_name AS recorded_by_name,'Reservation' AS record_type,
                CONCAT('reservation-view.php?id=',r.id) AS record_href
            FROM payments p INNER JOIN reservations r ON r.id=p.reservation_id
            LEFT JOIN reservation_batches b ON b.id=r.batch_id LEFT JOIN admins a ON a.id=p.recorded_by
            WHERE ".implode(' AND ',$where));
        $stmt->execute($params); $rows=$stmt->fetchAll();

        if ($type === '') {
            $rParams=[]; $rWhere=[$dateClause('rp.paid_at',$dateFrom,$dateTo,$rParams)];
            if($method!==''){ $rWhere[]='rp.payment_method=?'; $rParams[]=$method; }
            if($search!==''){ $rWhere[]='(rr.reference_no LIKE ? OR rr.client_name LIKE ? OR rr.contact_number LIKE ? OR rp.payment_reference LIKE ? OR EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id AND (x.code LIKE ? OR x.name LIKE ?)))'; $term='%'.$search.'%'; array_push($rParams,$term,$term,$term,$term,$term,$term); }
            $rStmt=$pdo->prepare("SELECT rp.id,rp.transaction_type,rp.amount,rp.payment_method,rp.payment_reference,rp.notes,rp.paid_at,
                    rr.id AS record_id,rr.reference_no AS record_reference,rr.client_name AS record_client,rr.contact_number AS phone,rr.start_date AS record_date,
                    NULL AS batch_reference,a.full_name AS recorded_by_name,'Rental' AS record_type,
                    CONCAT('rental-view.php?id=',rr.id) AS record_href
                FROM rental_payments rp INNER JOIN rentals rr ON rr.id=rp.rental_id
                LEFT JOIN admins a ON a.id=rp.recorded_by WHERE ".implode(' AND ',$rWhere));
            $rStmt->execute($rParams); $rows=array_merge($rows,$rStmt->fetchAll());
        }
        usort($rows,static fn($a,$b)=>strcmp((string)$b['paid_at'],(string)$a['paid_at']));
        $gross=0.0;$refunds=0.0;$net=0.0;$records=[];
        foreach($rows as &$row){$amt=round((float)$row['amount'],2);if($amt>0)$gross+=$amt;if($amt<0)$refunds+=abs($amt);$net+=$amt;$records[$row['record_type'].'-'.$row['record_id']]=true;$row['transaction_label']=((string)$row['transaction_type']==='refund'||$amt<0)?'Refund':'Payment';}unset($row);
        $summaryCards=[
            ['label'=>'Gross Collected','value'=>$gross,'type'=>'money','hint'=>'reservations + rentals'],
            ['label'=>'Refunds','value'=>$refunds,'type'=>'money','hint'=>'money returned'],
            ['label'=>'Net Collections','value'=>$net,'type'=>'money','hint'=>'combined payment ledgers'],
            ['label'=>'Transactions','value'=>count($rows),'type'=>'number','hint'=>count($records).' sales record'.(count($records)===1?'':'s')],
        ];
        $columns=[
            ['key'=>'transaction_label','label'=>'Transaction','type'=>'status'],['key'=>'paid_at','label'=>'Paid At','type'=>'datetime'],
            ['key'=>'record_reference','label'=>'Record','type'=>'record_link'],['key'=>'record_type','label'=>'Sales Type','type'=>'text_strong'],
            ['key'=>'record_client','label'=>'Client / Tenant','type'=>'client'],['key'=>'record_date','label'=>'Event / Rental Start','type'=>'date_only'],
            ['key'=>'payment_method','label'=>'Method','type'=>'text'],['key'=>'payment_reference','label'=>'Reference / OR No.','type'=>'text'],
            ['key'=>'recorded_by_name','label'=>'Recorded By','type'=>'text'],['key'=>'amount','label'=>'Amount','type'=>'signed_money'],
        ];
        $reportNote='Collections use the actual Paid At date. This report combines reservation payments and the generic Rentals payment ledger; refundable Security Deposits are tracked separately and are excluded from Sales and Collections.';
    }

    if ($report === 'outstanding') {
        $calendarBlockCondition = reservation_calendar_block_condition('r');
        $params = [];
        $where = ["(({$calendarBlockCondition}) OR r.status='completed')", "r.status NOT IN ('rejected','cancelled','no_show')", "NOT (r.status IN ('pending','for_review') AND r.event_end <= NOW())"];
        $where[] = $dateClause('r.event_start', $dateFrom, $dateTo, $params);
        if ($type !== '') {
            $where[] = 'r.reservation_type=?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR b.batch_reference LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }
        if ($aging === 'past') {
            $where[] = 'r.event_end < NOW()';
        } elseif ($aging === 'upcoming') {
            $where[] = 'r.event_end >= NOW()';
        } elseif ($aging === '1_7') {
            $where[] = 'r.event_end < NOW() AND DATEDIFF(NOW(),r.event_end) BETWEEN 0 AND 7';
        } elseif ($aging === '8_30') {
            $where[] = 'r.event_end < NOW() AND DATEDIFF(NOW(),r.event_end) BETWEEN 8 AND 30';
        } elseif ($aging === '31_60') {
            $where[] = 'r.event_end < NOW() AND DATEDIFF(NOW(),r.event_end) BETWEEN 31 AND 60';
        } elseif ($aging === '61_plus') {
            $where[] = 'r.event_end < NOW() AND DATEDIFF(NOW(),r.event_end) >= 61';
        }
        $sql = "SELECT q.* FROM (
            SELECT r.*,b.batch_reference,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid,
                (SELECT MAX(p2.paid_at) FROM payments p2 WHERE p2.reservation_id=r.id AND p2.amount>0) AS last_payment_at,
                COALESCE(r.final_amount,r.estimated_amount,0) AS payment_target
            FROM reservations r
            LEFT JOIN reservation_batches b ON b.id=r.batch_id
            WHERE " . implode(' AND ', $where) . "
        ) q
        WHERE GREATEST(q.payment_target-q.ledger_amount_paid,0) > 0.009
        ORDER BY CASE WHEN q.event_end < NOW() THEN 0 ELSE 1 END,q.event_end ASC,GREATEST(q.payment_target-q.ledger_amount_paid,0) DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalOutstanding = 0.0;
        $pastBalance = 0.0;
        $upcomingBalance = 0.0;
        foreach ($rows as &$row) {
            $target = round((float)$row['payment_target'], 2);
            $paid = round((float)$row['ledger_amount_paid'], 2);
            $balance = max(0, round($target - $paid, 2));
            $row['balance'] = $balance;
            $row['payment_state'] = $paymentStateLabel($paid, $target);
            $row['display_status'] = $statusLabel($row);
            $row['aging_label'] = $agingLabel((string)$row['event_end']);
            $totalOutstanding += $balance;
            if (strtotime((string)$row['event_end']) < time()) $pastBalance += $balance; else $upcomingBalance += $balance;
        }
        unset($row);
        $summaryCards = [
            ['label' => 'Outstanding', 'value' => $totalOutstanding, 'type' => 'money', 'hint' => count($rows) . ' balance' . (count($rows) === 1 ? '' : 's')],
            ['label' => 'Past-Event Balance', 'value' => $pastBalance, 'type' => 'money', 'hint' => 'collection priority'],
            ['label' => 'Upcoming Balance', 'value' => $upcomingBalance, 'type' => 'money', 'hint' => 'future events'],
            ['label' => 'Accounts to Collect', 'value' => count($rows), 'type' => 'number', 'hint' => 'current ledger balances'],
        ];
        $columns = [
            ['key' => 'reference_no', 'label' => 'Reservation', 'type' => 'reservation'],
            ['key' => 'client_name', 'label' => 'Client', 'type' => 'client'],
            ['key' => 'event_start', 'label' => 'Event', 'type' => 'datetime'],
            ['key' => 'display_status', 'label' => 'Status', 'type' => 'status'],
            ['key' => 'payment_state', 'label' => 'Payment', 'type' => 'status'],
            ['key' => 'payment_target', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'ledger_amount_paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money_emphasis'],
            ['key' => 'aging_label', 'label' => 'Aging', 'type' => 'text'],
            ['key' => 'last_payment_at', 'label' => 'Last Payment', 'type' => 'datetime_optional'],
        ];
        $reportNote = 'Outstanding balances are live ledger balances. Lapsed Pending / For Review reservations are excluded until Admin resolves the event outcome.';
    }

    if ($report === 'sales') {
        $params=[]; $where=["r.status IN ('approved','completed')",$dateClause('r.event_start',$dateFrom,$dateTo,$params)];
        if($type!==''){ $where[]='r.reservation_type=?';$params[]=$type; }
        if($search!==''){ $where[]='(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR b.batch_reference LIKE ?)';$term='%'.$search.'%';array_push($params,$term,$term,$term,$term,$term); }
        $stmt=$pdo->prepare("SELECT r.id AS record_id,r.reference_no AS record_reference,r.client_name AS record_client,r.phone,
                r.event_start AS record_date,COALESCE(r.final_amount,r.estimated_amount,0) AS sales_value,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS collected,
                CONCAT('Reservation · '," . "CASE r.reservation_type WHEN 'basketball' THEN 'Basketball Court' WHEN 'volleyball' THEN 'Volleyball Court' ELSE 'Event' END) AS sales_type,
                CONCAT('reservation-view.php?id=',r.id) AS record_href,
                CASE r.status WHEN 'completed' THEN 'Completed' ELSE 'Approved' END AS display_status
            FROM reservations r LEFT JOIN reservation_batches b ON b.id=r.batch_id WHERE ".implode(' AND ',$where));
        $stmt->execute($params);$rows=$stmt->fetchAll();
        $reservationValue=0.0;$stallValue=0.0;$outstandingValue=0.0;$collectedAgainst=0.0;
        foreach($rows as &$row){$row['balance']=max(0,round((float)$row['sales_value']-(float)$row['collected'],2));$reservationValue+=(float)$row['sales_value'];$collectedAgainst+=(float)$row['collected'];$outstandingValue+=(float)$row['balance'];}unset($row);
        if($type===''){
            // One-time rentals: recognize the complete rental value on the rental start date.
            $rp=[];$rw=["rr.status<>'cancelled'","rr.billing_mode='one_time'",$dateClause('rr.start_date',$dateFrom,$dateTo,$rp)];
            if($search!==''){ $rw[]='(rr.reference_no LIKE ? OR rr.client_name LIKE ? OR rr.contact_number LIKE ? OR rr.organization LIKE ? OR EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id AND (x.code LIKE ? OR x.name LIKE ?)))';$term='%'.$search.'%';array_push($rp,$term,$term,$term,$term,$term,$term); }
            $rs=$pdo->prepare("SELECT rr.id AS record_id,rr.reference_no AS record_reference,rr.client_name AS record_client,rr.contact_number AS phone,
                    rr.start_date AS record_date,(rr.base_amount+COALESCE((SELECT SUM(rc.amount) FROM rental_charges rc WHERE rc.rental_id=rr.id),0)) AS sales_value,
                    COALESCE((SELECT SUM(rp.amount) FROM rental_payments rp WHERE rp.rental_id=rr.id),0) AS collected,
                    CONCAT('Rental · ',COALESCE((SELECT GROUP_CONCAT(x.code ORDER BY ri.id SEPARATOR ', ') FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id),'Rentable')) AS sales_type,
                    CONCAT('rental-view.php?id=',rr.id) AS record_href,
                    CASE WHEN rr.status='completed' THEN 'Completed' WHEN rr.end_date<CURDATE() THEN 'For Final Billing' WHEN rr.start_date>CURDATE() THEN 'Upcoming' ELSE 'Active' END AS display_status
                FROM rentals rr WHERE ".implode(' AND ',$rw));
            $rs->execute($rp);$rentRows=$rs->fetchAll();
            foreach($rentRows as &$row){$row['balance']=max(0,round((float)$row['sales_value']-(float)$row['collected'],2));$stallValue+=(float)$row['sales_value'];$collectedAgainst+=(float)$row['collected'];$outstandingValue+=(float)$row['balance'];}unset($row);
            $rows=array_merge($rows,$rentRows);

            // Monthly rentals: recognize rent and finalized period charges by billing period.
            $mp=[];$mw=["rr.status<>'cancelled'","rr.billing_mode='monthly'",$dateClause('bp.period_start',$dateFrom,$dateTo,$mp)];
            if($search!==''){ $mw[]='(rr.reference_no LIKE ? OR rr.client_name LIKE ? OR rr.organization LIKE ? OR EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id AND (x.code LIKE ? OR x.name LIKE ?)))';$term='%'.$search.'%';array_push($mp,$term,$term,$term,$term,$term); }
            $ms=$pdo->prepare("SELECT rr.id AS record_id,CONCAT(rr.reference_no,' / M',bp.period_no) AS record_reference,rr.client_name AS record_client,rr.contact_number AS phone,
                    bp.period_start AS record_date,(bp.rent_amount+COALESCE((SELECT SUM(rc.amount) FROM rental_charges rc WHERE rc.billing_period_id=bp.id),0)) AS sales_value,
                    GREATEST(0,LEAST((bp.rent_amount+COALESCE((SELECT SUM(rc2.amount) FROM rental_charges rc2 WHERE rc2.billing_period_id=bp.id),0)),
                        COALESCE((SELECT SUM(rp.amount) FROM rental_payments rp WHERE rp.rental_id=rr.id),0)-COALESCE((SELECT SUM(bp2.rent_amount+COALESCE((SELECT SUM(rc3.amount) FROM rental_charges rc3 WHERE rc3.billing_period_id=bp2.id),0)) FROM rental_billing_periods bp2 WHERE bp2.rental_id=rr.id AND bp2.period_no<bp.period_no),0))) AS collected,
                    CONCAT('Monthly Rental · ',COALESCE((SELECT GROUP_CONCAT(x.code ORDER BY ri.id SEPARATOR ', ') FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id),'Rentable')) AS sales_type,
                    CONCAT('rental-view.php?id=',rr.id) AS record_href,
                    CASE WHEN bp.finalized_at IS NOT NULL THEN 'Finalized' WHEN bp.period_end<CURDATE() THEN 'For Billing' ELSE 'Scheduled' END AS display_status
                FROM rental_billing_periods bp JOIN rentals rr ON rr.id=bp.rental_id WHERE ".implode(' AND ',$mw));
            $ms->execute($mp);$monthlyRows=$ms->fetchAll();
            foreach($monthlyRows as &$row){$row['balance']=max(0,round((float)$row['sales_value']-(float)$row['collected'],2));$stallValue+=(float)$row['sales_value'];$collectedAgainst+=(float)$row['collected'];$outstandingValue+=(float)$row['balance'];}unset($row);
            $rows=array_merge($rows,$monthlyRows);

            // Cancelled rentals are excluded from Sales entirely. Refunds affect Net Collections only
            // and remain visible in the Cancellation & Refund report, not as negative Sales rows.
        }
        usort($rows,static fn($a,$b)=>strcmp((string)$a['record_date'],(string)$b['record_date']));
        $totalSales=$reservationValue+$stallValue;
        $summaryCards=[
            ['label'=>'Total Sales Value','value'=>$totalSales,'type'=>'money','hint'=>'venue + rentals'],
            ['label'=>'Venue Reservations','value'=>$reservationValue,'type'=>'money','hint'=>'approved + completed'],
            ['label'=>'Rentals','value'=>$stallValue,'type'=>'money','hint'=>'active + completed rentals'],
            ['label'=>'Outstanding','value'=>$outstandingValue,'type'=>'money','hint'=>'combined current balance'],
        ];
        $columns=[
            ['key'=>'record_date','label'=>'Date','type'=>'date_only'],['key'=>'record_reference','label'=>'Reference','type'=>'record_link'],
            ['key'=>'record_client','label'=>'Client / Tenant','type'=>'client'],['key'=>'sales_type','label'=>'Type','type'=>'text_strong'],
            ['key'=>'display_status','label'=>'Status','type'=>'status'],['key'=>'sales_value','label'=>'Sales','type'=>'money'],
            ['key'=>'collected','label'=>'Collected','type'=>'money'],['key'=>'balance','label'=>'Balance','type'=>'money_emphasis'],
        ];
        $reportNote='Cancelled rentals are excluded from Rental Sales. Refunds do not reduce Sales a second time; they reduce Net Collections on the actual refund date and remain visible in the Cancellation & Refund report. Any amount retained by TLH is shown there for settlement/audit purposes only. Refundable Security Deposits are excluded.';
    }

    if ($report === 'daily') {
        $params = [];
        $where = [$dateClause('r.event_start', $dateFrom, $dateTo, $params)];
        if ($type !== '') {
            $where[] = 'r.reservation_type=?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR b.batch_reference LIKE ? OR r.purpose LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term, $term, $term);
        }
        $stmt = $pdo->prepare("SELECT r.*,b.batch_reference,
                COALESCE(r.final_amount,r.estimated_amount,0) AS payment_target,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_amount_paid
            FROM reservations r
            LEFT JOIN reservation_batches b ON b.id=r.batch_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.event_start ASC,r.id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $approvedCount = 0;
        $completedCount = 0;
        $needsResolutionCount = 0;
        $cancelledCount = 0;
        foreach ($rows as &$row) {
            $target = round((float)$row['payment_target'], 2);
            $paid = round((float)$row['ledger_amount_paid'], 2);
            $row['balance'] = max(0, round($target - $paid, 2));
            $row['display_status'] = $statusLabel($row);
            if ($row['display_status'] === 'Needs Resolution') $needsResolutionCount++;
            if ((string)$row['status'] === 'approved') $approvedCount++;
            if ((string)$row['status'] === 'completed') $completedCount++;
            if ((string)$row['status'] === 'cancelled') $cancelledCount++;
        }
        unset($row);
        $summaryCards = [
            ['label' => 'Reservations', 'value' => count($rows), 'type' => 'number', 'hint' => 'selected schedule'],
            ['label' => 'Approved', 'value' => $approvedCount, 'type' => 'number', 'hint' => 'confirmed events'],
            ['label' => 'Completed', 'value' => $completedCount, 'type' => 'number', 'hint' => 'event occurred'],
            ['label' => 'Needs Resolution', 'value' => $needsResolutionCount, 'type' => 'number', 'hint' => 'past pending events'],
            ['label' => 'Cancelled', 'value' => $cancelledCount, 'type' => 'number', 'hint' => 'kept visible for context'],
        ];
        $columns = [
            ['key' => 'event_start', 'label' => 'Schedule', 'type' => 'datetime'],
            ['key' => 'reference_no', 'label' => 'Reservation', 'type' => 'reservation'],
            ['key' => 'client_name', 'label' => 'Client', 'type' => 'client'],
            ['key' => 'reservation_type', 'label' => 'Type', 'type' => 'reservation_type'],
            ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'text'],
            ['key' => 'display_status', 'label' => 'Status', 'type' => 'status'],
            ['key' => 'payment_target', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'ledger_amount_paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money_emphasis'],
        ];
        $reportNote = 'This is the operational schedule report. Cancelled and unresolved records stay visible so Admin can explain what happened on the selected date.';
    }

    if ($report === 'cancellations') {
        $params = [];
        $where = [$dateClause('c.cancelled_at', $dateFrom, $dateTo, $params)];
        if ($type !== '') {
            $where[] = 'r.reservation_type=?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(r.reference_no LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ? OR c.refund_reference LIKE ? OR c.cancellation_reason LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term, $term, $term);
        }
        $stmt = $pdo->prepare("SELECT c.cancelled_at,r.id AS record_id,r.reference_no AS record_reference,r.client_name AS record_client,r.phone,r.event_start AS record_date,
                'Reservation' AS record_type,CONCAT('reservation-view.php?id=',r.id) AS record_href,
                c.original_total AS original_value,c.cancellation_fee AS retained_value,c.paid_before_cancellation,
                c.refunded_amount,c.refund_due,c.refund_status,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0) AS ledger_net_paid
            FROM reservation_cancellations c
            INNER JOIN reservations r ON r.id=c.reservation_id
            WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['pending_refund'] = max(0, round((float)$row['refund_due'] - (float)$row['refunded_amount'], 2));
            $row['client_balance_due'] = max(0, round((float)$row['retained_value'] - (float)$row['ledger_net_paid'], 2));
            $row['refund_status_label'] = match ((string)$row['refund_status']) {
                'refunded' => 'Refunded',
                'pending' => 'Refund Pending',
                default => 'No Refund Due',
            };
        }
        unset($row);

        if ($type === '') {
            $rParams = [];
            $rWhere = [$dateClause('rc.cancelled_at',$dateFrom,$dateTo,$rParams)];
            if ($search !== '') {
                $rWhere[] = '(rr.reference_no LIKE ? OR rr.client_name LIKE ? OR rr.contact_number LIKE ? OR rr.organization LIKE ? OR rc.refund_reference LIKE ? OR rc.cancellation_reason LIKE ? OR EXISTS(SELECT 1 FROM rental_items ri JOIN rentables x ON x.id=ri.rentable_id WHERE ri.rental_id=rr.id AND (x.code LIKE ? OR x.name LIKE ?)))';
                $term = '%'.$search.'%';
                array_push($rParams,$term,$term,$term,$term,$term,$term,$term,$term);
            }
            $rStmt = $pdo->prepare("SELECT rc.cancelled_at,rr.id AS record_id,rr.reference_no AS record_reference,rr.client_name AS record_client,rr.contact_number AS phone,rr.start_date AS record_date,
                    'Rental' AS record_type,CONCAT('rental-view.php?id=',rr.id) AS record_href,
                    rc.original_total AS original_value,rc.retained_amount AS retained_value,rc.paid_before_cancellation,
                    rc.refund_amount AS refunded_amount,0 AS pending_refund,0 AS client_balance_due,
                    CASE WHEN rc.refund_amount>0 THEN 'Refunded' ELSE 'No Refund' END AS refund_status_label
                FROM rental_cancellations rc INNER JOIN rentals rr ON rr.id=rc.rental_id
                WHERE ".implode(' AND ',$rWhere));
            $rStmt->execute($rParams);
            $rows = array_merge($rows,$rStmt->fetchAll());
        }

        usort($rows,static fn($a,$b)=>strcmp((string)$b['cancelled_at'],(string)$a['cancelled_at']));
        $retainedValue = 0.0;
        $refundsIssued = 0.0;
        $pendingRefunds = 0.0;
        $clientBalances = 0.0;
        foreach ($rows as $row) {
            $retainedValue += (float)($row['retained_value'] ?? 0);
            $refundsIssued += (float)($row['refunded_amount'] ?? 0);
            $pendingRefunds += (float)($row['pending_refund'] ?? 0);
            $clientBalances += (float)($row['client_balance_due'] ?? 0);
        }
        $summaryCards = [
            ['label' => 'Cancellations', 'value' => count($rows), 'type' => 'number', 'hint' => 'reservations + rentals'],
            ['label' => 'Retained / Cancellation Value', 'value' => $retainedValue, 'type' => 'money', 'hint' => 'kept by TLH after cancellation'],
            ['label' => 'Refunds Issued', 'value' => $refundsIssued, 'type' => 'money', 'hint' => 'money returned'],
            ['label' => 'Pending Refunds', 'value' => $pendingRefunds, 'type' => 'money', 'hint' => 'reservation refunds still due'],
            ['label' => 'Client Balance Due', 'value' => $clientBalances, 'type' => 'money', 'hint' => 'reservation cancellation balance'],
        ];
        $columns = [
            ['key' => 'cancelled_at', 'label' => 'Cancelled At', 'type' => 'datetime'],
            ['key' => 'record_reference', 'label' => 'Record', 'type' => 'record_link'],
            ['key' => 'record_type', 'label' => 'Type', 'type' => 'text_strong'],
            ['key' => 'record_client', 'label' => 'Client / Tenant', 'type' => 'client'],
            ['key' => 'original_value', 'label' => 'Original Value', 'type' => 'money'],
            ['key' => 'retained_value', 'label' => 'Retained / Charge', 'type' => 'money'],
            ['key' => 'paid_before_cancellation', 'label' => 'Paid Before Cancel', 'type' => 'money'],
            ['key' => 'refunded_amount', 'label' => 'Refunded', 'type' => 'money'],
            ['key' => 'pending_refund', 'label' => 'Refund Pending', 'type' => 'money'],
            ['key' => 'client_balance_due', 'label' => 'Client Balance', 'type' => 'money_emphasis'],
            ['key' => 'refund_status_label', 'label' => 'Settlement', 'type' => 'status'],
        ];
        $reportNote = 'Rental refunds are decided by Admin at cancellation and are recorded as negative rental-payment transactions. Cancelled rentals are excluded from Rental Sales, while refunds reduce Net Collections on the actual refund date. Amounts retained by TLH are shown here for settlement/audit purposes only and are not counted as Rental Sales. Security Deposits remain separate.';
    }

    if ($report === 'management') {
        if ($dateFrom === '') $dateFrom = $currentMonthStart->format('Y-m-d');
        if ($dateTo === '') $dateTo = $today->format('Y-m-d');
        $typeWhere = $type !== '' ? ' AND r.reservation_type=?' : '';

        $salesParams = [$dateFrom, $dateTo];
        if ($type !== '') $salesParams[] = $type;
        $salesStmt = $pdo->prepare("SELECT DATE_FORMAT(r.event_start,'%Y-%m') AS month_key,
                COUNT(*) AS reservations,
                SUM(CASE WHEN r.status='completed' THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(COALESCE(r.final_amount,r.estimated_amount,0)),0) AS booking_value,
                COALESCE(SUM(GREATEST(COALESCE(r.final_amount,r.estimated_amount,0)-COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.reservation_id=r.id),0),0)),0) AS outstanding
            FROM reservations r
            WHERE r.status IN ('approved','completed')
              AND DATE(r.event_start) BETWEEN ? AND ?{$typeWhere}
            GROUP BY DATE_FORMAT(r.event_start,'%Y-%m')");
        $salesStmt->execute($salesParams);
        $salesByMonth = [];
        foreach ($salesStmt->fetchAll() as $item) $salesByMonth[$item['month_key']] = $item;

        if ($type === '') {
            $rentalSalesStmt=$pdo->prepare("SELECT month_key,SUM(rentals) rentals,SUM(rental_value) rental_value,SUM(rental_outstanding) rental_outstanding FROM (
                SELECT DATE_FORMAT(rr.start_date,'%Y-%m') month_key,COUNT(*) rentals,
                    COALESCE(SUM(rr.base_amount+COALESCE((SELECT SUM(rc.amount) FROM rental_charges rc WHERE rc.rental_id=rr.id),0)),0) rental_value,
                    COALESCE(SUM(GREATEST(rr.base_amount+COALESCE((SELECT SUM(rc2.amount) FROM rental_charges rc2 WHERE rc2.rental_id=rr.id),0)-COALESCE((SELECT SUM(rp.amount) FROM rental_payments rp WHERE rp.rental_id=rr.id),0),0)),0) rental_outstanding
                FROM rentals rr WHERE rr.status<>'cancelled' AND rr.billing_mode='one_time' AND DATE(rr.start_date) BETWEEN ? AND ? GROUP BY DATE_FORMAT(rr.start_date,'%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(bp.period_start,'%Y-%m') month_key,COUNT(*) rentals,
                    COALESCE(SUM(bp.rent_amount+COALESCE((SELECT SUM(rc.amount) FROM rental_charges rc WHERE rc.billing_period_id=bp.id),0)),0) rental_value,
                    COALESCE(SUM(GREATEST(0,
                        (bp.rent_amount+COALESCE((SELECT SUM(rc2.amount) FROM rental_charges rc2 WHERE rc2.billing_period_id=bp.id),0))
                        - GREATEST(0,LEAST(
                            (bp.rent_amount+COALESCE((SELECT SUM(rc3.amount) FROM rental_charges rc3 WHERE rc3.billing_period_id=bp.id),0)),
                            COALESCE((SELECT SUM(rp.amount) FROM rental_payments rp WHERE rp.rental_id=rr.id),0)
                            - COALESCE((SELECT SUM(bp2.rent_amount+COALESCE((SELECT SUM(rc4.amount) FROM rental_charges rc4 WHERE rc4.billing_period_id=bp2.id),0)) FROM rental_billing_periods bp2 WHERE bp2.rental_id=rr.id AND bp2.period_no<bp.period_no),0)
                        ))
                    )),0) rental_outstanding
                FROM rental_billing_periods bp JOIN rentals rr ON rr.id=bp.rental_id WHERE rr.status<>'cancelled' AND DATE(bp.period_start) BETWEEN ? AND ? GROUP BY DATE_FORMAT(bp.period_start,'%Y-%m')
            ) x GROUP BY month_key");
            $rentalSalesStmt->execute([$dateFrom,$dateTo,$dateFrom,$dateTo]);
            foreach($rentalSalesStmt->fetchAll() as $item){$key=$item['month_key'];if(!isset($salesByMonth[$key]))$salesByMonth[$key]=[];$salesByMonth[$key]['stall_rentals']=(int)$item['rentals'];$salesByMonth[$key]['stall_value']=(float)$item['rental_value'];$salesByMonth[$key]['stall_outstanding']=(float)$item['rental_outstanding'];}
        }

        $cancelParams = [$dateFrom, $dateTo];
        if ($type !== '') $cancelParams[] = $type;
        $cancelStmt = $pdo->prepare("SELECT DATE_FORMAT(c.cancelled_at,'%Y-%m') AS month_key,COUNT(*) AS cancellations
            FROM reservation_cancellations c
            INNER JOIN reservations r ON r.id=c.reservation_id
            WHERE DATE(c.cancelled_at) BETWEEN ? AND ?" . ($type !== '' ? ' AND r.reservation_type=?' : '') . "
            GROUP BY DATE_FORMAT(c.cancelled_at,'%Y-%m')");
        $cancelStmt->execute($cancelParams);
        $cancelByMonth = [];
        foreach ($cancelStmt->fetchAll() as $item) $cancelByMonth[$item['month_key']] = $item;
        if ($type === '') {
            $rentalCancelStmt = $pdo->prepare("SELECT DATE_FORMAT(cancelled_at,'%Y-%m') AS month_key,COUNT(*) AS cancellations FROM rental_cancellations WHERE DATE(cancelled_at) BETWEEN ? AND ? GROUP BY DATE_FORMAT(cancelled_at,'%Y-%m')");
            $rentalCancelStmt->execute([$dateFrom,$dateTo]);
            foreach ($rentalCancelStmt->fetchAll() as $item) {
                $key = (string)$item['month_key'];
                if (!isset($cancelByMonth[$key])) $cancelByMonth[$key] = ['month_key'=>$key,'cancellations'=>0];
                $cancelByMonth[$key]['cancellations'] = (int)($cancelByMonth[$key]['cancellations'] ?? 0) + (int)$item['cancellations'];
            }
        }

        $noShowParams = [$dateFrom, $dateTo];
        if ($type !== '') $noShowParams[] = $type;
        $noShowStmt = $pdo->prepare("SELECT DATE_FORMAT(r.event_start,'%Y-%m') AS month_key,COUNT(*) AS no_shows
            FROM reservations r
            WHERE r.status='no_show' AND DATE(r.event_start) BETWEEN ? AND ?" . ($type !== '' ? ' AND r.reservation_type=?' : '') . "
            GROUP BY DATE_FORMAT(r.event_start,'%Y-%m')");
        $noShowStmt->execute($noShowParams);
        $noShowByMonth = [];
        foreach ($noShowStmt->fetchAll() as $item) $noShowByMonth[$item['month_key']] = $item;

        $paymentParams = [$dateFrom, $dateTo];
        if ($type !== '') $paymentParams[] = $type;
        $paymentStmt = $pdo->prepare("SELECT DATE_FORMAT(p.paid_at,'%Y-%m') AS month_key,
                COALESCE(SUM(CASE WHEN p.amount>0 THEN p.amount ELSE 0 END),0) AS gross_collected,
                COALESCE(ABS(SUM(CASE WHEN p.amount<0 THEN p.amount ELSE 0 END)),0) AS refunds,
                COALESCE(SUM(p.amount),0) AS net_collections
            FROM payments p
            INNER JOIN reservations r ON r.id=p.reservation_id
            WHERE DATE(p.paid_at) BETWEEN ? AND ?" . ($type !== '' ? ' AND r.reservation_type=?' : '') . "
            GROUP BY DATE_FORMAT(p.paid_at,'%Y-%m')");
        $paymentStmt->execute($paymentParams);
        $paymentByMonth = [];
        foreach ($paymentStmt->fetchAll() as $item) $paymentByMonth[$item['month_key']] = $item;

        if ($type === '') {
            $rentalPayStmt=$pdo->prepare("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS month_key,COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) AS gross_collected,COALESCE(ABS(SUM(CASE WHEN amount<0 THEN amount ELSE 0 END)),0) AS refunds,COALESCE(SUM(amount),0) AS net_collections FROM rental_payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE_FORMAT(paid_at,'%Y-%m')");
            $rentalPayStmt->execute([$dateFrom,$dateTo]);
            foreach($rentalPayStmt->fetchAll() as $item){$key=$item['month_key'];if(!isset($paymentByMonth[$key]))$paymentByMonth[$key]=['gross_collected'=>0,'refunds'=>0,'net_collections'=>0];foreach(['gross_collected','refunds','net_collections'] as $f)$paymentByMonth[$key][$f]=(float)($paymentByMonth[$key][$f]??0)+(float)$item[$f];}
        }

        $cursor = new DateTimeImmutable($dateFrom . ' 00:00:00');
        $last = new DateTimeImmutable($dateTo . ' 00:00:00');
        $cursor = $cursor->modify('first day of this month');
        $lastMonth = $last->modify('first day of this month');
        while ($cursor <= $lastMonth) {
            $key = $cursor->format('Y-m');
            $salesMonth = $salesByMonth[$key] ?? [];
            $cancelMonth = $cancelByMonth[$key] ?? [];
            $noShowMonth = $noShowByMonth[$key] ?? [];
            $paymentMonth = $paymentByMonth[$key] ?? [];
            $rows[] = [
                'month_key' => $key,
                'month_label' => $cursor->format('F Y'),
                'reservations' => (int)($salesMonth['reservations'] ?? 0),
                'stall_rentals' => (int)($salesMonth['stall_rentals'] ?? 0),
                'completed' => (int)($salesMonth['completed'] ?? 0),
                'cancellations' => (int)($cancelMonth['cancellations'] ?? 0),
                'no_shows' => (int)($noShowMonth['no_shows'] ?? 0),
                'booking_value' => round((float)($salesMonth['booking_value'] ?? 0) + (float)($salesMonth['stall_value'] ?? 0), 2),
                'gross_collected' => round((float)($paymentMonth['gross_collected'] ?? 0), 2),
                'refunds' => round((float)($paymentMonth['refunds'] ?? 0), 2),
                'net_collections' => round((float)($paymentMonth['net_collections'] ?? 0), 2),
                'outstanding' => round((float)($salesMonth['outstanding'] ?? 0) + (float)($salesMonth['stall_outstanding'] ?? 0), 2),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        $totals = [
            'reservations' => 0,
            'stall_rentals' => 0,
            'completed' => 0,
            'cancellations' => 0,
            'no_shows' => 0,
            'booking_value' => 0.0,
            'gross_collected' => 0.0,
            'refunds' => 0.0,
            'net_collections' => 0.0,
            'outstanding' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach (['reservations', 'stall_rentals', 'completed', 'cancellations', 'no_shows'] as $key) $totals[$key] += (int)$row[$key];
            foreach (['booking_value', 'gross_collected', 'refunds', 'net_collections', 'outstanding'] as $key) $totals[$key] += (float)$row[$key];
        }
        $summaryCards = [
            ['label' => 'Combined Sales Value', 'value' => $totals['booking_value'], 'type' => 'money', 'hint' => $totals['reservations'] . ' reservations + ' . $totals['stall_rentals'] . ' rentals'],
            ['label' => 'Gross Collected', 'value' => $totals['gross_collected'], 'type' => 'money', 'hint' => 'by Paid At date'],
            ['label' => 'Refunds', 'value' => $totals['refunds'], 'type' => 'money', 'hint' => 'returned to clients'],
            ['label' => 'Net Collections', 'value' => $totals['net_collections'], 'type' => 'money', 'hint' => 'gross less refunds'],
            ['label' => 'Outstanding', 'value' => $totals['outstanding'], 'type' => 'money', 'hint' => 'current balance on selected bookings'],
            ['label' => 'Completed Events', 'value' => $totals['completed'], 'type' => 'number', 'hint' => $totals['cancellations'] . ' cancelled / ' . $totals['no_shows'] . ' no show'],
        ];
        $columns = [
            ['key' => 'month_label', 'label' => 'Month', 'type' => 'text_strong'],
            ['key' => 'reservations', 'label' => 'Reservations', 'type' => 'number'],
            ['key' => 'stall_rentals', 'label' => 'Rentals', 'type' => 'number'],
            ['key' => 'completed', 'label' => 'Completed', 'type' => 'number'],
            ['key' => 'cancellations', 'label' => 'Cancelled', 'type' => 'number'],
            ['key' => 'no_shows', 'label' => 'No Show', 'type' => 'number'],
            ['key' => 'booking_value', 'label' => 'Combined Sales', 'type' => 'money'],
            ['key' => 'gross_collected', 'label' => 'Gross Collected', 'type' => 'money'],
            ['key' => 'refunds', 'label' => 'Refunds', 'type' => 'money'],
            ['key' => 'net_collections', 'label' => 'Net Collections', 'type' => 'money_emphasis'],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money'],
        ];
        $reportNote = 'Management Summary combines venue reservation value and rental value while keeping sales-date value separate from payment-date collections. Outstanding includes both modules.';
    }
} catch (Throwable $e) {
    $rows = [];
    $summaryCards = [];
    $reportError = 'The report could not be loaded. Please try again or check the database connection.';
}

$spreadsheetSafeText = static function (string $value): string {
    // Prevent spreadsheet applications from treating client-controlled text as
    // a formula when CSV/XLS exports are opened. Numeric report fields are
    // handled separately and intentionally remain numeric.
    if ($value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }
    return $value;
};

$exportValue = static function (array $column, array $row) use ($spreadsheetSafeText): string {
    $key = (string)$column['key'];
    $typeName = (string)($column['type'] ?? 'text');
    $value = $row[$key] ?? '';
    if (in_array($typeName, ['money', 'money_emphasis', 'signed_money'], true)) {
        return number_format((float)$value, 2, '.', '');
    }
    if ($typeName === 'date_only') {
        if (!$value) return '';
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d', $ts) : $spreadsheetSafeText((string)$value);
    }
    if ($typeName === 'datetime' || $typeName === 'datetime_optional') {
        if (!$value) return '';
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d H:i', $ts) : $spreadsheetSafeText((string)$value);
    }
    if ($typeName === 'reservation_type') {
        return $spreadsheetSafeText(reservation_type_label((string)$value));
    }
    if ($typeName === 'text_title') {
        return $spreadsheetSafeText(ucwords(str_replace('_', ' ', (string)$value)));
    }
    return $spreadsheetSafeText((string)$value);
};

$periodText = 'All dates';
if ($dateFrom !== '' && $dateTo !== '') {
    $periodText = date('M j, Y', strtotime($dateFrom)) . ' - ' . date('M j, Y', strtotime($dateTo));
} elseif ($dateFrom !== '') {
    $periodText = 'From ' . date('M j, Y', strtotime($dateFrom));
} elseif ($dateTo !== '') {
    $periodText = 'Through ' . date('M j, Y', strtotime($dateTo));
}

if ($format !== '' && $reportError === '') {
    $safeReportName = str_replace('_', '-', $report);
    $fileDate = date('Ymd-His');
    $summaryForExport = [];
    foreach ($summaryCards as $card) {
        $value = (string)$card['value'];
        if (($card['type'] ?? '') === 'money') {
            $value = number_format((float)$card['value'], 2, '.', '');
        }
        $summaryForExport[] = [(string)$card['label'], $value];
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tlh-' . $safeReportName . '-' . $fileDate . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['The Leisure Hub', $reportCatalog[$report]['title']]);
        fputcsv($out, ['Basis', $reportCatalog[$report]['basis']]);
        fputcsv($out, ['Period', $periodText]);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);
        foreach ($summaryForExport as $summaryRow) fputcsv($out, $summaryRow);
        fputcsv($out, []);
        fputcsv($out, array_map(static fn(array $column): string => (string)$column['label'], $columns));
        foreach ($rows as $row) {
            $exportRow = [];
            foreach ($columns as $column) $exportRow[] = $exportValue($column, $row);
            fputcsv($out, $exportRow);
        }
        fclose($out);
        exit;
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tlh-' . $safeReportName . '-' . $fileDate . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px 8px}th{background:#eee}.title{font-size:18px;font-weight:bold}</style></head><body>';
    echo '<table><tr><td class="title" colspan="2">The Leisure Hub - ' . htmlspecialchars($reportCatalog[$report]['title'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    echo '<tr><td>Basis</td><td>' . htmlspecialchars($reportCatalog[$report]['basis'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    echo '<tr><td>Period</td><td>' . htmlspecialchars($periodText, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    echo '<tr><td>Generated</td><td>' . htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    foreach ($summaryForExport as $summaryRow) echo '<tr><td>' . htmlspecialchars($summaryRow[0], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($summaryRow[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    echo '</table><br><table><thead><tr>';
    foreach ($columns as $column) echo '<th>' . htmlspecialchars((string)$column['label'], ENT_QUOTES, 'UTF-8') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) echo '<td>' . htmlspecialchars($exportValue($column, $row), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$baseQuery = [
    'report' => $report,
    'from' => $dateFrom,
    'to' => $dateTo,
    'type' => $type,
    'method' => $method,
    'aging' => $aging,
    'search' => $search,
];
$baseQuery = array_filter($baseQuery, static fn($value): bool => $value !== '' && $value !== 'all');
$exportUrl = static function (string $targetFormat) use ($baseQuery): string {
    $query = $baseQuery;
    $query['format'] = $targetFormat;
    return 'reports.php?' . http_build_query($query);
};
$presetUrl = static function (string $from, string $to) use ($baseQuery): string {
    $query = $baseQuery;
    unset($query['format']);
    $query['from'] = $from;
    $query['to'] = $to;
    return 'reports.php?' . http_build_query($query);
};

$filterCount = 0;
if ($type !== '') $filterCount++;
if ($method !== '') $filterCount++;
if ($aging !== 'all') $filterCount++;
if ($search !== '') $filterCount++;

include __DIR__ . '/_header.php';
?>
<section class="reports-hero report-no-print" aria-labelledby="reportsCenterTitle">
  <div>
    <span class="dashboard-eyebrow">Admin &amp; Accounting</span>
    <h2 id="reportsCenterTitle">Reports Center</h2>
    <p>One reporting workspace for operations, collections, accounting reconciliation, and management review.</p>
  </div>
  <div class="reports-hero-actions">
    <a class="btn btn-outline" href="payments.php">Payment &amp; Collections</a>
    <button class="btn btn-dark" type="button" onclick="window.print()">Print Report</button>
  </div>
</section>

<nav class="reports-selector report-no-print" aria-label="Report types">
  <?php foreach ($reportCatalog as $key => $config): ?>
    <a class="report-selector-card<?= $report === $key ? ' active' : '' ?>" href="reports.php?report=<?= e($key) ?>"<?= $report === $key ? ' aria-current="page"' : '' ?>>
      <span><?= e($config['label']) ?></span>
      <small><?= e($config['basis']) ?></small>
    </a>
  <?php endforeach; ?>
</nav>

<section class="panel reports-filter-panel report-no-print">
  <div class="reports-filter-head">
    <div>
      <span class="dashboard-section-kicker">Filters</span>
      <h3><?= e($reportCatalog[$report]['title']) ?></h3>
      <p><?= e($reportCatalog[$report]['description']) ?></p>
    </div>
    <div class="reports-filter-meta"><span>Date basis</span><strong><?= e($reportCatalog[$report]['basis']) ?></strong></div>
  </div>

  <div class="reports-presets" aria-label="Quick date ranges">
    <?php if ($report === 'outstanding'): ?><a class="report-preset-chip<?= $dateFrom === '' && $dateTo === '' ? ' active' : '' ?>" href="<?= e($presetUrl('', '')) ?>">All Balances</a><?php endif; ?>
    <a class="report-preset-chip<?= $dateFrom === $today->format('Y-m-d') && $dateTo === $today->format('Y-m-d') ? ' active' : '' ?>" href="<?= e($presetUrl($today->format('Y-m-d'), $today->format('Y-m-d'))) ?>">Today</a>
    <a class="report-preset-chip<?= $dateFrom === $weekStart->format('Y-m-d') && $dateTo === $weekEnd->format('Y-m-d') ? ' active' : '' ?>" href="<?= e($presetUrl($weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'))) ?>">This Week</a>
    <a class="report-preset-chip<?= $dateFrom === $currentMonthStart->format('Y-m-d') && $dateTo === $today->format('Y-m-d') ? ' active' : '' ?>" href="<?= e($presetUrl($currentMonthStart->format('Y-m-d'), $today->format('Y-m-d'))) ?>">This Month</a>
    <a class="report-preset-chip<?= $dateFrom === $lastMonthStart->format('Y-m-d') && $dateTo === $lastMonthEnd->format('Y-m-d') ? ' active' : '' ?>" href="<?= e($presetUrl($lastMonthStart->format('Y-m-d'), $lastMonthEnd->format('Y-m-d'))) ?>">Last Month</a>
  </div>

  <form class="reports-filters" method="get">
    <input type="hidden" name="report" value="<?= e($report) ?>">
    <div class="form-group"><label for="reportFrom">From</label><input id="reportFrom" type="date" name="from" value="<?= e($dateFrom) ?>"></div>
    <div class="form-group"><label for="reportTo">To</label><input id="reportTo" type="date" name="to" value="<?= e($dateTo) ?>"></div>
    <div class="form-group"><label for="reportType">Reservation Type</label><select id="reportType" name="type"><option value="">All types</option><option value="basketball"<?= $type === 'basketball' ? ' selected' : '' ?>>Basketball Court</option><option value="volleyball"<?= $type === 'volleyball' ? ' selected' : '' ?>>Volleyball Court</option><option value="event"<?= $type === 'event' ? ' selected' : '' ?>>Events Reservation</option></select></div>
    <?php if ($report === 'collections'): ?>
      <div class="form-group"><label for="reportMethod">Payment Method</label><select id="reportMethod" name="method"><option value="">All methods</option><?php foreach (booking_payment_methods() as $option): ?><option value="<?= e($option) ?>"<?= $method === $option ? ' selected' : '' ?>><?= e(booking_payment_method_label($option)) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($report === 'outstanding'): ?>
      <div class="form-group"><label for="reportAging">Aging</label><select id="reportAging" name="aging"><option value="all"<?= $aging === 'all' ? ' selected' : '' ?>>All balances</option><option value="past"<?= $aging === 'past' ? ' selected' : '' ?>>Past event</option><option value="upcoming"<?= $aging === 'upcoming' ? ' selected' : '' ?>>Upcoming</option><option value="1_7"<?= $aging === '1_7' ? ' selected' : '' ?>>1-7 days after event</option><option value="8_30"<?= $aging === '8_30' ? ' selected' : '' ?>>8-30 days after event</option><option value="31_60"<?= $aging === '31_60' ? ' selected' : '' ?>>31-60 days after event</option><option value="61_plus"<?= $aging === '61_plus' ? ' selected' : '' ?>>61+ days after event</option></select></div>
    <?php endif; ?>
    <?php if ($report !== 'management'): ?>
      <div class="form-group reports-search-field"><label for="reportSearch">Search</label><input id="reportSearch" type="search" name="search" value="<?= e($search) ?>" placeholder="Reference, client, phone, email..."></div>
    <?php endif; ?>
    <div class="reports-filter-actions">
      <a class="btn btn-outline" href="reports.php?report=<?= e($report) ?>">Reset<?= $filterCount > 0 ? ' (' . $filterCount . ')' : '' ?></a>
      <button class="btn btn-primary" type="submit">Apply Filters</button>
    </div>
  </form>
</section>

<?php if ($reportError !== ''): ?>
  <div class="alert alert-danger"><?= e($reportError) ?></div>
<?php else: ?>
  <section class="reports-print-heading" aria-label="Printed report heading">
    <img src="../assets/img/tlh-logo.png" alt="The Leisure Hub">
    <div><strong><?= e($reportCatalog[$report]['title']) ?></strong><span><?= e($periodText) ?> &middot; <?= e($reportCatalog[$report]['basis']) ?></span></div>
  </section>

  <section class="reports-summary" aria-label="Report summary">
    <?php foreach ($summaryCards as $card): ?>
      <article class="report-summary-card">
        <span><?= e((string)$card['label']) ?></span>
        <strong><?= ($card['type'] ?? '') === 'money' ? money((float)$card['value']) : number_format((float)$card['value']) ?></strong>
        <small><?= e((string)($card['hint'] ?? '')) ?></small>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="panel reports-table-panel report-panel-<?= e($report) ?>">
    <div class="reports-table-head">
      <div>
        <span class="dashboard-section-kicker">Report Details</span>
        <h3><?= e($reportCatalog[$report]['title']) ?></h3>
        <p><?= e($periodText) ?><?php if ($type !== ''): ?> &middot; <?= e(reservation_type_label($type)) ?><?php endif; ?></p>
      </div>
      <div class="reports-export-actions report-no-print">
        <a class="btn btn-outline btn-sm" href="<?= e($exportUrl('csv')) ?>">Export CSV</a>
        <a class="btn btn-outline btn-sm" href="<?= e($exportUrl('excel')) ?>">Export Excel</a>
        <button class="btn btn-dark btn-sm" type="button" onclick="window.print()">Print</button>
      </div>
    </div>

    <div class="reports-accounting-note"><strong>Accounting basis:</strong> <?= e($reportNote) ?></div>

    <div class="reports-table-scroll report-table-scroll-<?= e($report) ?>">
      <table class="reports-table mobile-card-table reports-mobile-card-table report-table-<?= e($report) ?>">
        <thead><tr><?php foreach ($columns as $column): ?><th><?= e((string)$column['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <?php foreach ($columns as $column): ?>
              <?php $key = (string)$column['key']; $cellType = (string)($column['type'] ?? 'text'); $value = $row[$key] ?? ''; ?>
              <td class="report-cell-<?= e($cellType) ?>" data-label="<?= e((string)$column['label']) ?>">
                <?php if ($cellType === 'reservation'): ?>
                  <a class="report-reservation-link" href="reservation-view.php?id=<?= (int)($row['reservation_id'] ?? $row['id'] ?? 0) ?>"><strong><?= e((string)$value) ?></strong><?php if (!empty($row['batch_reference'])): ?><small><?= e((string)$row['batch_reference']) ?></small><?php endif; ?></a>
                <?php elseif ($cellType === 'record_link'): ?>
                  <a class="report-reservation-link" href="<?= e((string)($row['record_href'] ?? '#')) ?>"><strong><?= e((string)$value) ?></strong></a>
                <?php elseif ($cellType === 'client'): ?>
                  <strong><?= e((string)$value) ?></strong><?php if (!empty($row['phone'])): ?><small><?= e((string)$row['phone']) ?></small><?php endif; ?>
                <?php elseif ($cellType === 'date_only'): ?>
                  <?php if ($value): ?><span><?= e(date('M j, Y', strtotime((string)$value))) ?></span><?php else: ?><span class="muted">-</span><?php endif; ?>
                <?php elseif ($cellType === 'datetime' || $cellType === 'datetime_optional'): ?>
                  <?php if ($value): ?><span><?= e(date('M j, Y', strtotime((string)$value))) ?></span><small><?= e(date('g:i A', strtotime((string)$value))) ?></small><?php else: ?><span class="muted">-</span><?php endif; ?>
                <?php elseif ($cellType === 'money' || $cellType === 'money_emphasis'): ?>
                  <strong><?= money((float)$value) ?></strong>
                <?php elseif ($cellType === 'signed_money'): ?>
                  <?php $signedAmount = (float)$value; ?><strong class="<?= $signedAmount < 0 ? 'report-money-negative' : 'report-money-positive' ?>"><?= $signedAmount < 0 ? '-' : '' ?><?= money(abs($signedAmount)) ?></strong>
                <?php elseif ($cellType === 'status'): ?>
                  <span class="report-status-pill"><?= e((string)$value) ?></span>
                <?php elseif ($cellType === 'reservation_type'): ?>
                  <span><?= e(reservation_type_label((string)$value)) ?></span>
                <?php elseif ($cellType === 'text_title'): ?>
                  <span><?= e(ucwords(str_replace('_', ' ', (string)$value))) ?></span>
                <?php elseif ($cellType === 'text_strong'): ?>
                  <strong><?= e((string)$value) ?></strong>
                <?php elseif ($cellType === 'number'): ?>
                  <strong><?= number_format((float)$value) ?></strong>
                <?php else: ?>
                  <span><?= $value !== '' && $value !== null ? e((string)$value) : '<span class="muted">-</span>' ?></span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td class="reports-empty" colspan="<?= max(1, count($columns)) ?>"><strong>No records found.</strong><span>Try another date range or clear the filters.</span></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="reports-table-footer"><span><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span><span>Generated <?= e(date('M j, Y g:i A')) ?></span></div>
  </section>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
