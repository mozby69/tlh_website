<?php
/**
 * FILE PURPOSE: Admin UI for creating, downloading, and deleting protected SQL backups.
 * DEBUGGING: Backup generation helpers live in includes/database-backup.php. Backup files use the private DB_BACKUP_DIR location and must never be public.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database-backup.php';
admin_required();
if (!is_admin()) {
    flash('danger', 'Administrator access is required.');
    redirect('index.php');
}

if (isset($_GET['download'])) {
    try {
        $filename = basename((string)$_GET['download']);
        $path = database_backup_path($filename);
        session_write_close();
        header('Content-Type: application/sql; charset=binary');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string)(filesize($path) ?: 0));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($path);
        exit;
    } catch (Throwable $e) {
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The database backup could not be downloaded.');
        redirect('database-backup.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');

    try {
        if ($action === 'delete') {
            $filename = basename((string)($_POST['filename'] ?? ''));
            $path = database_backup_path($filename);
            if (!@unlink($path)) {
                throw new RuntimeException('The selected backup could not be deleted. Check the backup folder permissions.');
            }
            flash('success', 'Database backup deleted: ' . $filename);
        } else {
            @set_time_limit(0);
            $result = database_backup_create();
            flash(
                'success',
                'Database backup created: ' . $result['filename'] . ' (' . database_backup_format_bytes((int)$result['size']) . ', '
                . (int)$result['tables'] . ' tables, ' . number_format((int)$result['rows']) . ' rows).'
            );
        }
    } catch (Throwable $e) {
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'The database backup could not be completed.');
    }

    redirect('database-backup.php');
}

$adminPageTitle = 'Database Backup';
$backupError = null;
$backups = [];
try {
    $backups = database_backup_list();
} catch (Throwable $e) {
    $backupError = $e->getMessage();
}
$totalSize = array_sum(array_column($backups, 'size'));
$latest = $backups[0] ?? null;

include __DIR__ . '/_header.php';
?>
<section class="panel database-backup-hero">
  <div class="panel-head">
    <div>
      <h2>Database Backups</h2>
      <p class="muted">Create a complete SQL copy of reservations, payments, users, settings, audit history, and images stored in the database. Backups are stored outside the public web root by default.</p>
    </div>
    <?php if ($backupError === null): ?>
      <form method="post" class="inline-action-form" onsubmit="return confirm('Create a complete database backup now?');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <button class="btn btn-primary" type="submit">Create Backup</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($backupError !== null): ?>
    <div class="alert alert-danger"><?= e($backupError) ?></div>
  <?php else: ?>
    <div class="database-backup-summary">
      <div class="metric-card"><span>Saved backups</span><strong><?= count($backups) ?></strong></div>
      <div class="metric-card"><span>Total storage</span><strong><?= e(database_backup_format_bytes((int)$totalSize)) ?></strong></div>
      <div class="metric-card"><span>Latest backup</span><strong class="database-backup-latest"><?= $latest ? e(date('M j, Y g:i A', (int)$latest['created_at'])) : 'None yet' ?></strong></div>
    </div>
  <?php endif; ?>
</section>

<?php if ($backupError === null): ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Saved Backup Files</h2>
      <p class="muted">Download a copy to another computer or secure cloud storage. Do not keep your only backup on the same server as the website.</p>
    </div>
  </div>

  <?php if ($backups === []): ?>
    <div class="empty-state">
      <strong>No database backups yet.</strong>
      <p>Create the first backup before making major website or database changes.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table mobile-card-table database-backup-table">
        <thead><tr><th>Backup file</th><th>Created</th><th>Size</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($backups as $backup): ?>
          <tr>
            <td data-label="Backup file" data-priority="primary"><strong><?= e($backup['filename']) ?></strong></td>
            <td data-label="Created"><?= e(date('M j, Y g:i:s A', (int)$backup['created_at'])) ?></td>
            <td data-label="Size"><?= e(database_backup_format_bytes((int)$backup['size'])) ?></td>
            <td data-label="Actions">
              <div class="admin-actions database-backup-actions">
                <a class="btn btn-outline btn-sm" href="database-backup.php?download=<?= rawurlencode($backup['filename']) ?>">Download</a>
                <form method="post" class="inline-action-form" onsubmit="return confirm('Permanently delete this backup file?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="filename" value="<?= e($backup['filename']) ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="panel database-backup-guidance">
  <div class="panel-head"><h2>Backup Guidance</h2></div>
  <div class="detail-grid">
    <div class="detail-item"><span>Before updates</span><strong>Create and download a fresh backup before replacing website files or changing rates.</strong></div>
    <div class="detail-item"><span>Off-site copy</span><strong>Keep a downloaded copy on another computer or secure cloud drive.</strong></div>
    <div class="detail-item"><span>Restore method</span><strong>Import the SQL file through phpMyAdmin or the MySQL command line when recovery is required.</strong></div>
  </div>
  <p class="muted database-backup-warning">Backup files contain private client, payment, and administrator information. Store downloaded copies securely.</p>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
