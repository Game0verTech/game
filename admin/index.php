<?php
require_login();
require_role('admin', 'manager');
$user = current_user();

$tab = $_GET['t'] ?? 'dashboard';
if (!in_array($tab, ['dashboard', 'settings', 'users'], true)) {
    $tab = 'dashboard';
}

$pageTitle = 'Admin Dashboard';
if ($tab === 'settings') {
    $pageTitle = 'Admin Settings';
} elseif ($tab === 'users' && user_has_role('admin')) {
    $pageTitle = 'User Management';
}
require __DIR__ . '/../templates/header.php';
$config = load_config();
?>
<div class="card">
    <nav class="admin-tabs">
        <a href="/?page=admin&t=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">Overview</a>
        <a href="/?page=admin&t=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
        <?php if (user_has_role('admin')): ?>
            <a href="/?page=admin&t=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <?php endif; ?>
    </nav>
</div>

<?php if ($tab === 'dashboard'): ?>
    <div class="card">
        <h2>System Information</h2>
        <ul>
            <li>PHP Version: <?= sanitize(PHP_VERSION) ?></li>
            <li>Database Status: Connected</li>
            <li>Site Version: <?= sanitize(get_version()) ?></li>
        </ul>
    </div>
<?php elseif ($tab === 'settings'): ?>
    <div class="card">
        <h2>SMTP Settings</h2>
        <?php if (user_has_role('admin')): ?>
            <form method="post" action="/api/admin.php">
                <input type="hidden" name="action" value="update_smtp">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <label>Host
                    <input type="text" name="smtp_host" value="<?= sanitize($config['smtp']['host'] ?? '') ?>" required>
                </label>
                <label>Port
                    <input type="number" name="smtp_port" value="<?= sanitize($config['smtp']['port'] ?? 587) ?>" required>
                </label>
                <label>Encryption
                    <input type="text" name="smtp_encryption" value="<?= sanitize($config['smtp']['encryption'] ?? '') ?>">
                </label>
                <label>Username
                    <input type="text" name="smtp_username" value="<?= sanitize($config['smtp']['username'] ?? '') ?>">
                </label>
                <label>Password
                    <input type="password" name="smtp_password" value="<?= sanitize($config['smtp']['password'] ?? '') ?>">
                </label>
                <label>From Name
                    <input type="text" name="smtp_from_name" value="<?= sanitize($config['smtp']['from_name'] ?? '') ?>" required>
                </label>
                <label>From Email
                    <input type="email" name="smtp_from_email" value="<?= sanitize($config['smtp']['from_email'] ?? '') ?>" required>
                </label>
                <button type="submit">Save</button>
            </form>
            <form method="post" action="/api/admin.php" class="inline">
                <input type="hidden" name="action" value="test_smtp">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="smtp_host" value="<?= sanitize($config['smtp']['host'] ?? '') ?>">
                <input type="hidden" name="smtp_port" value="<?= sanitize($config['smtp']['port'] ?? 587) ?>">
                <input type="hidden" name="smtp_encryption" value="<?= sanitize($config['smtp']['encryption'] ?? '') ?>">
                <input type="hidden" name="smtp_username" value="<?= sanitize($config['smtp']['username'] ?? '') ?>">
                <input type="hidden" name="smtp_password" value="<?= sanitize($config['smtp']['password'] ?? '') ?>">
                <input type="hidden" name="smtp_from_name" value="<?= sanitize($config['smtp']['from_name'] ?? '') ?>">
                <input type="hidden" name="smtp_from_email" value="<?= sanitize($config['smtp']['from_email'] ?? '') ?>">
                <label>Test Recipient
                    <input type="email" name="test_recipient" value="<?= sanitize($user['email']) ?>">
                </label>
                <button type="submit">Send Test Email</button>
            </form>
        <?php else: ?>
            <p>Managers cannot modify SMTP settings. Contact an administrator for changes.</p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Maintenance</h2>
        <form method="post" action="/api/admin.php" class="inline">
            <input type="hidden" name="action" value="bump_version">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <button type="submit">Bump Version</button>
        </form>
        <form method="post" action="/api/admin.php" class="inline">
            <input type="hidden" name="action" value="rebuild_stats">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <button type="submit">Rebuild Player Stats</button>
        </form>
    </div>
<?php elseif ($tab === 'users' && user_has_role('admin')): ?>
    <?php $users = list_users(); ?>
    <div class="card">
        <h2>User Management</h2>
        <p>Assign roles to manage access. Administrators can change any account except their own.</p>
        <h3>Create User</h3>
        <form method="post" action="/api/admin.php" class="form-grid">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <label>Username
                <input type="text" name="username" maxlength="50" required>
            </label>
            <label>Email
                <input type="email" name="email" maxlength="120" required>
            </label>
            <label>Password
                <input type="password" name="password" minlength="10" required>
            </label>
            <label>Confirm Password
                <input type="password" name="password_confirmation" minlength="10" required>
            </label>
            <label>Role
                <select name="role">
                    <option value="player">Player</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Administrator</option>
                </select>
            </label>
            <button type="submit">Create User</button>
        </form>
        <h3>Generate Test Player</h3>
        <form method="post" action="/api/admin.php" class="inline">
            <input type="hidden" name="action" value="seed_test_player">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <button type="submit">Add Test Player</button>
        </form>
        <p class="muted">Creates PlayerN accounts with email playerN@example.com and password <code>playinggame</code>.</p>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $account): ?>
                        <?php
                            $isBanned = (int)$account['is_banned'] === 1;
                            $status = 'Pending';
                            if ($isBanned) {
                                $status = 'Banned';
                            } elseif ((int)$account['is_active'] === 1) {
                                $status = 'Active';
                            }
                        ?>
                        <tr>
                            <td><?= (int)$account['id'] ?></td>
                            <td><?= sanitize($account['username']) ?></td>
                            <td><?= sanitize($account['email']) ?></td>
                            <td><?= $status ?></td>
                            <td><?= ucfirst($account['role']) ?></td>
                            <td><?= sanitize(date('Y-m-d H:i', strtotime($account['created_at']))) ?></td>
                            <td><?= sanitize(date('Y-m-d H:i', strtotime($account['updated_at']))) ?></td>
                            <td>
                                <?php if ($account['id'] !== $user['id']): ?>
                                    <div class="action-cell">
                                        <form method="post" action="/api/admin.php" class="inline role-form">
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                            <label class="sr-only" for="role-<?= (int)$account['id'] ?>">Role</label>
                                            <select id="role-<?= (int)$account['id'] ?>" name="role">
                                                <option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                                <option value="manager" <?= $account['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                                <option value="player" <?= $account['role'] === 'player' ? 'selected' : '' ?>>Player</option>
                                            </select>
                                            <button type="submit" class="icon-btn" data-tooltip="Save role" aria-label="Save role for <?= sanitize($account['username']) ?>">
                                                <span aria-hidden="true">💾</span>
                                                <span class="sr-only">Save role</span>
                                            </button>
                                        </form>
                                        <?php if ((int)$account['is_active'] !== 1): ?>
                                            <form method="post" action="/api/admin.php" class="inline">
                                                <input type="hidden" name="action" value="verify_user">
                                                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                                <button type="submit" class="icon-btn" data-tooltip="Verify user" aria-label="Verify <?= sanitize($account['username']) ?>">
                                                    <span aria-hidden="true">✔️</span>
                                                    <span class="sr-only">Verify user</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="/api/admin.php" class="inline">
                                            <input type="hidden" name="action" value="<?= $isBanned ? 'unban_user' : 'ban_user' ?>">
                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                            <?php if ($isBanned): ?>
                                                <button type="submit" class="icon-btn" data-tooltip="Unban user" aria-label="Unban <?= sanitize($account['username']) ?>">
                                                    <span aria-hidden="true">🔓</span>
                                                    <span class="sr-only">Unban user</span>
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="icon-btn" data-tooltip="Ban user" aria-label="Ban <?= sanitize($account['username']) ?>">
                                                    <span aria-hidden="true">🚫</span>
                                                    <span class="sr-only">Ban user</span>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="post" action="/api/admin.php" class="inline js-confirm" data-confirm="Delete <?= sanitize($account['username']) ?>? This cannot be undone.">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                            <button type="submit" class="icon-btn danger" data-tooltip="Delete user" aria-label="Delete <?= sanitize($account['username']) ?>">
                                                <span aria-hidden="true">🗑️</span>
                                                <span class="sr-only">Delete user</span>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Cannot modify own account</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($tab === 'users'): ?>
    <div class="card">
        <p>Only administrators can manage user accounts.</p>
    </div>
<?php else: ?>
    <div class="card">
        <p>The requested admin section is not available.</p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php';
