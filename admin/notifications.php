<?php
/**
 * FILE PURPOSE: Admin notification inbox and mark-read controls.
 * DEBUGGING: Website reservation notifications are created by create_website_reservation_notification().
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$admin = current_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        mark_all_admin_notifications_read(isset($admin['id']) ? (int)$admin['id'] : null);
        flash('success', 'All notifications marked as read.');
    }
    redirect('notifications.php');
}
$adminPageTitle = 'Notifications';
$notifications = admin_recent_notifications(50);
include __DIR__ . '/_header.php';
?>
<section class="panel admin-notifications-page">
  <div class="panel-head">
    <div><h2>Website Reservation Notifications</h2><p class="small muted">New alerts are created only for reservations submitted through the public website.</p></div>
    <?php if (admin_notification_unread_count() > 0): ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_all_read"><button class="btn btn-outline btn-sm" type="submit">Mark all as read</button></form>
    <?php endif; ?>
  </div>
  <div class="notification-page-list">
    <?php foreach ($notifications as $item): ?>
      <a class="notification-page-item<?= empty($item['is_read']) ? ' is-unread' : '' ?>" href="notification-open.php?id=<?= (int)$item['id'] ?>">
        <span class="notification-page-dot" aria-hidden="true"></span>
        <span class="notification-page-copy"><strong><?= e($item['title']) ?></strong><span><?= e($item['message'] ?? '') ?></span><small><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></small></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$notifications): ?><div class="empty-state">No website reservation notifications yet.</div><?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/_footer.php'; ?>
