<?php
/**
 * FILE PURPOSE: Admin user/account management screen.
 * DEBUGGING: Treat passwords, account status, and deletion as security-sensitive;
 * admin_required() revalidates active sessions on every protected request.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if (!is_admin()) {
    flash('danger', 'Administrator access is required.');
    redirect('index.php');
}

$adminPageTitle = 'Users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    try {
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }
            if ($id === (int)current_admin()['id']) {
                throw new RuntimeException('You cannot disable your own account.');
            }

            $stmt = db()->prepare('UPDATE admins SET is_active = 1 - is_active WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('The user account could not be found.');
            }
            flash('success', 'User status changed.');
        } elseif ($action === 'password') {
            $id = (int)($_POST['id'] ?? 0);
            $password = $_POST['password'] ?? '';
            if ($id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Password must contain at least 8 characters.');
            }

            $stmt = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            if ($stmt->rowCount() < 1) {
                $check = db()->prepare('SELECT id FROM admins WHERE id = ? AND deleted_at IS NULL LIMIT 1');
                $check->execute([$id]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('The user account could not be found.');
                }
            }
            flash('success', 'Password updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user account.');
            }
            if ($id === (int)current_admin()['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $targetStmt = $pdo->prepare('SELECT id, full_name, username, role FROM admins WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
                $targetStmt->execute([$id]);
                $target = $targetStmt->fetch();
                if (!$target) {
                    throw new RuntimeException('The user account could not be found.');
                }

                if ((string)$target['role'] === 'admin') {
                    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND deleted_at IS NULL")->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new RuntimeException('The last Administrator account cannot be deleted.');
                    }
                }

                // Keep the row as an audit tombstone so historical reservations,
                // payments, cancellations, and batch records still show who acted.
                // The original username is released for reuse and the credentials
                // are irreversibly replaced, so this account can never sign in again.
                $deletedUsername = '__deleted_' . $id . '_' . date('YmdHis');
                $disabledHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $delete = $pdo->prepare(
                    'UPDATE admins SET username = ?, password_hash = ?, is_active = 0, deleted_at = NOW() '
                    . 'WHERE id = ? AND deleted_at IS NULL'
                );
                $delete->execute([$deletedUsername, $disabledHash, $id]);
                if ($delete->rowCount() !== 1) {
                    throw new RuntimeException('The user account could not be deleted.');
                }

                $pdo->commit();
                flash(
                    'success',
                    'User ' . (string)$target['full_name'] . ' was deleted. Historical reservation and payment records were preserved.'
                );
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } else {
            $name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'staff';

            if ($name === '' || $username === '' || strlen($password) < 8) {
                throw new RuntimeException('Name, username, and a password of at least 8 characters are required.');
            }
            if (!in_array($role, ['admin', 'staff', 'calendar_viewer'], true)) {
                $role = 'staff';
            }

            $stmt = db()->prepare('INSERT INTO admins(full_name, username, password_hash, role) VALUES(?,?,?,?)');
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
            flash('success', 'User account created.');
        }
    } catch (Throwable $e) {
        flash(
            'danger',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to update the user account. The username may already exist.'
        );
    }

    redirect('users.php');
}

try {
    $users = db()->query('SELECT * FROM admins WHERE deleted_at IS NULL ORDER BY full_name')->fetchAll();
} catch (Throwable $e) {
    // Compatibility fallback if the database account cannot apply the lightweight
    // v1.2.138 migration immediately. The migration retries on the next request.
    try {
        $users = db()->query('SELECT * FROM admins ORDER BY full_name')->fetchAll();
    } catch (Throwable $ignored) {
        $users = [];
    }
}

$currentAdminId = (int)current_admin()['id'];
include __DIR__ . '/_header.php';
?>
<div class="admin-grid">
  <section class="panel">
    <div class="panel-head admin-users-panel-head">
      <div>
        <h2>Admin &amp; Staff Accounts</h2>
        <p class="small muted">Disable an account temporarily, or delete accounts you no longer need. Deleted users lose access permanently while their historical activity remains traceable.</p>
      </div>
    </div>

    <div class="table-wrap">
      <table class="admin-table mobile-card-table admin-users-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Role</th>
            <th>Status</th>
            <th>Reset Password</th>
            <th>Account Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <?php $isCurrentAccount = (int)$user['id'] === $currentAdminId; ?>
            <tr>
              <td data-label="Name" data-priority="primary">
                <strong><?= e($user['full_name']) ?></strong>
                <?php if ($isCurrentAccount): ?><span class="admin-current-account-badge">You</span><?php endif; ?>
              </td>
              <td data-label="Username"><?= e($user['username']) ?></td>
              <td data-label="Role"><?= e(admin_role_label((string)$user['role'])) ?></td>
              <td data-label="Status">
                <span class="status-pill status-<?= $user['is_active'] ? 'success' : 'danger' ?>">
                  <?= $user['is_active'] ? 'Active' : 'Disabled' ?>
                </span>
              </td>
              <td data-label="Reset Password">
                <form method="post" class="admin-actions admin-user-reset-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="password">
                  <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                  <input type="password" name="password" placeholder="New password" minlength="8" required style="max-width:180px">
                  <button class="btn btn-outline btn-sm" type="submit">Reset</button>
                </form>
              </td>
              <td data-label="Account Actions">
                <?php if ($isCurrentAccount): ?>
                  <span class="small muted admin-current-account-note">Current account cannot be disabled or deleted.</span>
                <?php else: ?>
                  <div class="admin-actions admin-user-account-actions">
                    <form method="post" class="inline-action-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                      <button class="btn <?= $user['is_active'] ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="submit">
                        <?= $user['is_active'] ? 'Disable' : 'Enable' ?>
                      </button>
                    </form>
                    <form method="post" class="inline-action-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                      <button
                        class="btn btn-danger btn-sm admin-user-delete-btn"
                        type="submit"
                        data-confirm="Delete this user account permanently? The user will no longer be able to sign in, but historical reservation and payment activity will be preserved."
                        aria-label="Delete user <?= e($user['full_name']) ?>"
                      >Delete</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <aside class="panel">
    <h2>Create User</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="form-group" style="margin-bottom:12px">
        <label>Full Name</label>
        <input name="full_name" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Username</label>
        <input name="username" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Password</label>
        <input type="password" name="password" minlength="8" required>
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label>Role</label>
        <select name="role">
          <option value="staff">Staff</option>
          <option value="calendar_viewer">Calendar Viewer (Read Only)</option>
          <option value="admin">Administrator</option>
        </select>
        <span class="field-help">Calendar Viewer accounts can sign in and view the Booking Calendar and Rental Calendar only. They cannot create, edit, approve, cancel, extend, or record payments.</span>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Create Account</button>
    </form>
  </aside>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
