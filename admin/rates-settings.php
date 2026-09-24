<?php
/**
 * FILE PURPOSE: Admin editor for reservation pricing rates, introductory period, and add-on fees.
 * DEBUGGING: New reservations snapshot prices at booking time, so editing rates must not retroactively change existing reservations.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
if (!is_admin()) {
    flash('danger', 'Administrator access is required.');
    redirect('index.php');
}

$adminPageTitle = 'Rates Settings';
$fields = [
    'pricing_intro_start','pricing_intro_end','pricing_intro_fan_rate','pricing_intro_aircon_rate',
    'pricing_regular_fan_rate','pricing_regular_aircon_rate','pricing_tournament_fan_rate','pricing_tournament_aircon_rate',
    'pricing_big_event_fan_rate','pricing_big_event_aircon_rate','pricing_shower_room_fee','pricing_equipment_bundle_fee',
    'deposit_percent'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $introStart = trim($_POST['pricing_intro_start'] ?? '');
        $introEnd = trim($_POST['pricing_intro_end'] ?? '');
        if ($introStart === '' || $introEnd === '') {
            throw new RuntimeException('Introductory start and end dates are required.');
        }
        if ($introEnd < $introStart) {
            throw new RuntimeException('The introductory end date cannot be earlier than the start date.');
        }

        foreach ($fields as $field) {
            if (in_array($field, ['pricing_intro_start', 'pricing_intro_end'], true)) {
                continue;
            }
            $value = trim($_POST[$field] ?? '');
            if ($value === '' || !is_numeric($value) || (float)$value < 0) {
                throw new RuntimeException('All rates and fees must contain valid non-negative amounts.');
            }
        }

        $depositPercent = (float)($_POST['deposit_percent'] ?? 0);
        if ($depositPercent > 100) {
            throw new RuntimeException('The deposit percentage cannot exceed 100%.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach ($fields as $field) {
            $stmt->execute([$field, trim($_POST[$field] ?? '')]);
        }
        $pdo->commit();
        flash('success', 'Rates settings updated. New calculations will use the updated values; existing reservation price snapshots remain unchanged.');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update rates settings.');
    }
    redirect('rates-settings.php');
}

include __DIR__ . '/_header.php';
?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Reservation Rates & Fees</h2>
      <p class="muted">These values are used for new reservations and when staff explicitly choose “Apply Rate for the New Date” during a reschedule. Keeping the original booked rate uses the reservation’s saved price snapshot instead.</p>
    </div>
  </div>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="form-group full admin-section-label">
      <h3>Introductory Period</h3>
      <p class="muted">Regular bookings with 1–29 guests use these rates during the configured date range.</p>
    </div>
    <div class="form-group"><label>Introductory Start Date</label><input type="date" name="pricing_intro_start" required value="<?= e(setting('pricing_intro_start','2026-08-01')) ?>"></div>
    <div class="form-group"><label>Introductory End Date</label><input type="date" name="pricing_intro_end" required value="<?= e(setting('pricing_intro_end','2026-10-31')) ?>"></div>
    <div class="form-group"><label>Introductory Fan Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_intro_fan_rate" required value="<?= e(setting('pricing_intro_fan_rate','1500')) ?>"></div>
    <div class="form-group"><label>Introductory Aircon Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_intro_aircon_rate" required value="<?= e(setting('pricing_intro_aircon_rate','4500')) ?>"></div>

    <div class="form-group full admin-section-label">
      <h3>Regular Rates</h3>
      <p class="muted">Used for regular bookings with 1–29 guests outside the introductory period.</p>
    </div>
    <div class="form-group"><label>Original Fan Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_regular_fan_rate" required value="<?= e(setting('pricing_regular_fan_rate','2000')) ?>"></div>
    <div class="form-group"><label>Original Aircon Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_regular_aircon_rate" required value="<?= e(setting('pricing_regular_aircon_rate','5000')) ?>"></div>

    <div class="form-group full admin-section-label">
      <h3>Tournament Rates</h3>
      <p class="muted">Applies to reservations with 30–200 guests. The equipment bundle is included.</p>
    </div>
    <div class="form-group"><label>Tournament Fan Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_tournament_fan_rate" required value="<?= e(setting('pricing_tournament_fan_rate','2500')) ?>"></div>
    <div class="form-group"><label>Tournament Aircon Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_tournament_aircon_rate" required value="<?= e(setting('pricing_tournament_aircon_rate','5500')) ?>"></div>

    <div class="form-group full admin-section-label">
      <h3>Big Event Rates</h3>
      <p class="muted">Applies to reservations with 201–400 guests. The equipment bundle is included.</p>
    </div>
    <div class="form-group"><label>Big Event Fan Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_big_event_fan_rate" required value="<?= e(setting('pricing_big_event_fan_rate','6000')) ?>"></div>
    <div class="form-group"><label>Big Event Aircon Rate / Hour</label><input type="number" min="0" step="0.01" name="pricing_big_event_aircon_rate" required value="<?= e(setting('pricing_big_event_aircon_rate','8000')) ?>"></div>

    <div class="form-group full admin-section-label">
      <h3>Add-ons & Deposit</h3>
      <p class="muted">Shower Room is charged once per reservation. Equipment Bundle is charged per billable reservation hour for regular bookings. Setup and cleanup times block the calendar but are not billed.</p>
    </div>
    <div class="form-group"><label>Shower Room / Reservation</label><input type="number" min="0" step="0.01" name="pricing_shower_room_fee" required value="<?= e(setting('pricing_shower_room_fee','500')) ?>"></div>
    <div class="form-group"><label>Equipment Bundle / Hour (Regular Booking)</label><input type="number" min="0" step="0.01" name="pricing_equipment_bundle_fee" required value="<?= e(setting('pricing_equipment_bundle_fee','100')) ?>"><span class="field-help">Hourly rate for Regular bookings. Included automatically in Tournament and Big Event packages.</span></div>
    <div class="form-group"><label>Deposit Percentage</label><input type="number" min="0" max="100" step="0.01" name="deposit_percent" required value="<?= e(setting('deposit_percent','0')) ?>"></div>

    <div class="form-group full"><button class="btn btn-primary" type="submit">Save Rates Settings</button></div>
  </form>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
