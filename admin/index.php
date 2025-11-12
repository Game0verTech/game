<?php
require_login();
require_role('admin', 'manager');
$user = current_user();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../templates/header.php';

$tab = $_GET['t'] ?? 'dashboard';
$config = load_config();
?>
<div class="card">
    <nav class="admin-tabs">
        <a href="/?page=admin&t=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">Overview</a>
        <a href="/?page=admin&t=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
        <?php if (user_has_role('admin')): ?>
            <a href="/?page=admin&t=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <?php endif; ?>
        <a href="/?page=admin&t=manage" class="<?= $tab === 'manage' ? 'active' : '' ?>">Tournaments</a>
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
                                    <form method="post" action="/api/admin.php" class="inline">
                                        <input type="hidden" name="action" value="set_role">
                                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                        <label class="sr-only" for="role-<?= (int)$account['id'] ?>">Role</label>
                                        <select id="role-<?= (int)$account['id'] ?>" name="role">
                                            <option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                            <option value="manager" <?= $account['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                            <option value="player" <?= $account['role'] === 'player' ? 'selected' : '' ?>>Player</option>
                                        </select>
                                        <button type="submit">Update</button>
                                    </form>
                                    <form method="post" action="/api/admin.php" class="inline">
                                        <input type="hidden" name="action" value="<?= $isBanned ? 'unban_user' : 'ban_user' ?>">
                                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                        <button type="submit"><?= $isBanned ? 'Unban' : 'Ban' ?></button>
                                    </form>
                                    <form method="post" action="/api/admin.php" class="inline js-confirm" data-confirm="Delete <?= sanitize($account['username']) ?>? This cannot be undone.">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
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
<?php elseif ($tab === 'manage'): ?>
    <?php $tournaments = list_tournaments(); ?>
    <div class="card">
        <h2>Create Tournament</h2>
        <form method="post" action="/api/tournaments.php">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <label>Name
                <input type="text" name="name" required>
            </label>
            <label>Type
                <select name="type" required>
                    <option value="single">Single Elimination</option>
                    <option value="double">Double Elimination</option>
                    <option value="round-robin">Round Robin</option>
                </select>
            </label>
            <label>Description
                <textarea name="description"></textarea>
            </label>
            <button type="submit">Create</button>
        </form>
    </div>
    <div class="card">
        <h2>Manage Existing</h2>
        <ul>
            <?php foreach ($tournaments as $t): ?>
                <li><a href="/?page=admin&t=manage&id=<?= (int)$t['id'] ?>"><?= sanitize($t['name']) ?> (<?= sanitize($t['status']) ?>)</a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if (isset($_GET['id'])): ?>
        <?php $current = get_tournament((int)$_GET['id']); ?>
        <?php if ($current): ?>
            <?php $players = tournament_players($current['id']); ?>
            <?php $activeUsers = all_users(); ?>
            <div class="card">
                <h2><?= sanitize($current['name']) ?></h2>
                <p>Type: <?= sanitize($current['type']) ?> &middot; Status: <?= sanitize($current['status']) ?></p>
                <form method="post" action="/api/tournaments.php" class="inline">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                    <button type="submit" name="action" value="open">Open Registration</button>
                    <button type="submit" name="action" value="start">Start Tournament</button>
                    <button type="submit" name="action" value="complete">Mark Completed</button>
                </form>
                <h3>Players</h3>
                <ul>
                    <?php foreach ($players as $player): ?>
                        <li>
                            <?= sanitize($player['username']) ?>
                            <form method="post" action="/api/tournaments.php" class="inline">
                                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="remove_player_admin">
                                <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$player['user_id'] ?>">
                                <button type="submit">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="/api/tournaments.php">
                    <input type="hidden" name="action" value="add_player_admin">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                    <label>Select Player
                        <select name="user_id" required>
                            <option value="">Choose user</option>
                            <?php foreach ($activeUsers as $active): ?>
                                <option value="<?= (int)$active['id'] ?>"><?= sanitize($active['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Add Player</button>
                </form>
            </div>
            <?php $matches = tournament_matches($current['id']); ?>
            <?php if ($current['type'] === 'round-robin'): ?>
                <div class="card">
                    <h3>Round Robin Groups</h3>
                    <form method="post" action="/api/tournaments.php">
                        <input type="hidden" name="action" value="save_group">
                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                        <input type="hidden" id="group_json" name="group_json" value='<?= sanitize($current['groups_json'] ?? json_encode(generate_bracket_structure($current['id']))) ?>'>
                        <div class="group-container" data-group='<?= sanitize($current['groups_json'] ?? json_encode(generate_bracket_structure($current['id']))) ?>' data-mode="admin" data-target="group_json"></div>
                        <button type="submit">Save Groups</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card">
                    <h3>Bracket</h3>
                    <form method="post" action="/api/tournaments.php">
                        <input type="hidden" name="action" value="save_bracket">
                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                        <input type="hidden" id="bracket_json" name="bracket_json" value='<?= sanitize($current['bracket_json'] ?? json_encode(generate_bracket_structure($current['id']))) ?>'>
                        <div class="bracket-container" data-bracket='<?= sanitize($current['bracket_json'] ?? json_encode(generate_bracket_structure($current['id']))) ?>' data-mode="admin" data-target="bracket_json"></div>
                        <button type="submit">Save Bracket</button>
                    </form>
                </div>
            <?php endif; ?>
            <div class="card">
                <h3>Match Center</h3>
                <?php if ($matches): ?>
                    <?php $currentStage = null; ?>
                    <?php $currentRound = null; ?>
                    <?php foreach ($matches as $match): ?>
                        <?php $stageLabel = strtoupper($match['stage']); ?>
                        <?php $roundNumber = (int)$match['round']; ?>
                        <?php if ($stageLabel !== $currentStage || $roundNumber !== $currentRound): ?>
                            <?php if ($currentStage !== null): ?>
                                </div>
                            <?php endif; ?>
                            <div class="match-group">
                                <h4><?= sanitize($stageLabel) ?> &middot; Round <?= $roundNumber ?></h4>
                        <?php $currentStage = $stageLabel; ?>
                        <?php $currentRound = $roundNumber; ?>
                        <?php endif; ?>
                        <form method="post" action="/api/tournaments.php" class="match-row">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="report_match">
                            <input type="hidden" name="tournament_id" value="<?= (int)$current['id'] ?>">
                            <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                            <div class="match-meta">
                                <span>Match #<?= (int)$match['match_index'] ?></span>
                            </div>
                            <div class="match-players">
                                <label>
                                    <?= sanitize($match['player1_name'] ?? 'TBD') ?>
                                    <input type="number" name="score1" value="<?= sanitize((string)($match['score1'] ?? '')) ?>" min="0" <?= $current['status'] !== 'live' ? 'disabled' : '' ?>>
                                </label>
                                <span class="versus">vs</span>
                                <label>
                                    <?= sanitize($match['player2_name'] ?? 'TBD') ?>
                                    <input type="number" name="score2" value="<?= sanitize((string)($match['score2'] ?? '')) ?>" min="0" <?= $current['status'] !== 'live' ? 'disabled' : '' ?>>
                                </label>
                            </div>
                            <div class="match-winner">
                                <label>Winner
                                    <select name="winner_user_id" <?= $current['status'] !== 'live' ? 'disabled' : '' ?>>
                                        <option value="">--</option>
                                        <?php if ($match['player1_user_id']): ?>
                                            <option value="<?= (int)$match['player1_user_id'] ?>" <?= (int)($match['winner_user_id'] ?? 0) === (int)$match['player1_user_id'] ? 'selected' : '' ?>><?= sanitize($match['player1_name'] ?? 'Player 1') ?></option>
                                        <?php endif; ?>
                                        <?php if ($match['player2_user_id']): ?>
                                            <option value="<?= (int)$match['player2_user_id'] ?>" <?= (int)($match['winner_user_id'] ?? 0) === (int)$match['player2_user_id'] ? 'selected' : '' ?>><?= sanitize($match['player2_name'] ?? 'Player 2') ?></option>
                                        <?php endif; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="match-actions">
                                <button type="submit" <?= $current['status'] !== 'live' ? 'disabled' : '' ?>>Save</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                    <?php if ($currentStage !== null): ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No matches seeded yet.</p>
                <?php endif; ?>
                <?php if ($current['status'] !== 'live'): ?>
                    <p class="muted">Matches are editable while the tournament is live.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php';
