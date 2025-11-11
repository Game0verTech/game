<?php
require_login();
$user = current_user();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../templates/header.php';

$tab = $_GET['t'] ?? 'dashboard';
$config = load_config();
?>
<div class="card">
    <nav class="admin-tabs">
        <a href="/?page=admin&t=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">Overview</a>
        <a href="/?page=admin&t=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
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
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php';
