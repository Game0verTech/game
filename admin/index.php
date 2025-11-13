<?php
require_login();
require_role('admin', 'manager');
$user = current_user();

$tab = $_GET['t'] ?? 'dashboard';
$viewTournament = null;
if ($tab === 'view' && isset($_GET['id'])) {
    $viewTournament = get_tournament((int)$_GET['id']);
}

$pageTitle = 'Admin Dashboard';
if ($tab === 'manage') {
    $pageTitle = 'Tournament Calendar';
} elseif ($tab === 'view' && $viewTournament) {
    $pageTitle = 'Tournament: ' . $viewTournament['name'];
}
require __DIR__ . '/../templates/header.php';
$config = load_config();
?>
<?php $isTournamentTab = in_array($tab, ['manage', 'view'], true); ?>
<div class="card">
    <nav class="admin-tabs">
        <a href="/?page=admin&t=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">Overview</a>
        <a href="/?page=admin&t=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
        <?php if (user_has_role('admin')): ?>
            <a href="/?page=admin&t=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <?php endif; ?>
        <a href="/?page=admin&t=manage" class="<?= $isTournamentTab ? 'active' : '' ?>">Tournaments</a>
    </nav>
</div>

<?php
$allPlayers = [];
$playerPayload = '[]';
if ($isTournamentTab) {
    $allPlayers = all_users();
    $playerPayload = safe_json_encode(array_map(static fn($player) => [
        'id' => (int)$player['id'],
        'username' => $player['username'],
        'role' => $player['role'],
    ], $allPlayers));
}
?>

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
<?php elseif ($tab === 'manage'): ?>
    <?php
        $tournaments = list_tournaments();
        $calendarData = [];
        foreach ($tournaments as $tournament) {
            $players = tournament_players((int)$tournament['id']);
            $calendarData[] = [
                'id' => (int)$tournament['id'],
                'name' => $tournament['name'],
                'status' => $tournament['status'],
                'type' => $tournament['type'],
                'description' => $tournament['description'],
                'scheduled_at' => $tournament['scheduled_at'],
                'location' => $tournament['location'],
                'player_count' => count($players),
                'players' => array_map(static fn($player) => (int)$player['user_id'], $players),
            ];
        }
        $calendarJson = safe_json_encode($calendarData);
    ?>
    <div class="card tournament-calendar-card">
        <div class="tournament-calendar-card__header">
            <div>
                <h2>Tournament Calendar</h2>
                <p class="muted">Click a scheduled tournament to launch the bracket or adjust settings.</p>
            </div>
            <button type="button" class="btn primary" data-modal-trigger="createTournamentModal">Create New Tournament</button>
        </div>
        <div id="tournamentCalendar" class="tournament-calendar" data-tournaments='<?= sanitize($calendarJson) ?>' data-default-location="<?= sanitize(default_tournament_location()) ?>"></div>
    </div>
<?php elseif ($tab === 'view'): ?>
    <?php if (!$viewTournament): ?>
        <div class="card">
            <p>Tournament not found. <a href="/?page=admin&t=manage">Return to calendar.</a></p>
        </div>
    <?php else: ?>
        <?php $players = tournament_players((int)$viewTournament['id']); ?>
        <?php $matches = tournament_matches((int)$viewTournament['id']); ?>
        <?php
            $currentPayload = safe_json_encode([
                'id' => (int)$viewTournament['id'],
                'name' => $viewTournament['name'],
                'status' => $viewTournament['status'],
                'type' => $viewTournament['type'],
                'description' => $viewTournament['description'],
                'scheduled_at' => $viewTournament['scheduled_at'],
                'location' => $viewTournament['location'],
                'players' => array_map(static fn($player) => (int)$player['user_id'], $players),
            ]);
            $scheduledLabel = $viewTournament['scheduled_at'] ? date('F j, Y \a\t g:i A', strtotime($viewTournament['scheduled_at'])) : 'TBD';
        ?>
        <div class="card tournament-overview" data-tournament='<?= sanitize($currentPayload) ?>'>
            <div class="tournament-overview__meta">
                <a href="/?page=admin&t=manage" class="btn-link">&larr; Back to Calendar</a>
                <span class="status-pill status-<?= sanitize($viewTournament['status']) ?>"><?= ucfirst($viewTournament['status']) ?></span>
            </div>
            <h2><?= sanitize($viewTournament['name']) ?></h2>
            <p class="muted"><?= sanitize($scheduledLabel) ?> &middot; <?= sanitize($viewTournament['location']) ?></p>
            <?php if (!empty($viewTournament['description'])): ?>
                <p><?= nl2br(sanitize($viewTournament['description'])) ?></p>
            <?php endif; ?>
            <div class="tournament-overview__actions">
                <form method="post" action="/api/tournaments.php" class="inline">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="tournament_id" value="<?= (int)$viewTournament['id'] ?>">
                    <button type="submit" name="action" value="open" class="btn secondary">Open Registration</button>
                    <button type="submit" name="action" value="start" class="btn secondary">Start Tournament</button>
                    <button type="submit" name="action" value="complete" class="btn secondary">Mark Completed</button>
                </form>
                <button type="button" class="btn primary" data-modal-trigger="tournamentSettingsModal" data-settings-id="<?= (int)$viewTournament['id'] ?>">Edit Settings</button>
            </div>
        </div>
        <?php if ($viewTournament['type'] === 'round-robin'): ?>
            <?php $groupJson = $viewTournament['groups_json'] ?? json_encode(generate_bracket_structure($viewTournament['id'])); ?>
            <div class="card">
                <h3>Round Robin</h3>
                <p class="muted">Click or tap a competitor to mark the winner. Changes are saved automatically.</p>
                <div
                    class="group-container"
                    data-group='<?= sanitize($groupJson) ?>'
                    data-mode="admin"
                    data-token="<?= csrf_token() ?>"
                    data-tournament-id="<?= (int)$viewTournament['id'] ?>"
                    data-status="<?= sanitize($viewTournament['status']) ?>"
                    data-live="1"
                ></div>
            </div>
        <?php else: ?>
            <div class="card">
                <h3>Bracket</h3>
                <?php $bracketJson = tournament_bracket_snapshot($viewTournament); ?>
                <p class="muted">Click or right-click a competitor to advance them. You can also press Enter when a match is focused. Changes are saved automatically.</p>
                <div
                    class="bracket-container"
                    data-bracket='<?= $bracketJson ? sanitize($bracketJson) : '' ?>'
                    data-mode="admin"
                    data-token="<?= csrf_token() ?>"
                    data-tournament-id="<?= (int)$viewTournament['id'] ?>"
                    data-status="<?= sanitize($viewTournament['status']) ?>"
                    data-live="1"
                ></div>
            </div>
        <?php endif; ?>
        <div class="card">
            <h3>Match Center</h3>
            <?php if ($matches): ?>
                <p class="muted">This list updates automatically. Use the bracket to change winners.</p>
                <div class="table-responsive">
                    <table class="data-table js-match-summary" data-tournament-id="<?= (int)$viewTournament['id'] ?>">
                        <thead>
                            <tr>
                                <th>Stage</th>
                                <th>Round</th>
                                <th>Match</th>
                                <th>Players</th>
                                <th>Winner</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $match): ?>
                                <tr data-match-id="<?= (int)$match['id'] ?>">
                                    <td><?= sanitize(strtoupper($match['stage'])) ?></td>
                                    <td><?= (int)$match['round'] ?></td>
                                    <td>#<?= (int)$match['match_index'] ?></td>
                                    <td>
                                        <?= sanitize($match['player1_name'] ?? 'TBD') ?>
                                        <span class="versus">vs</span>
                                        <?= sanitize($match['player2_name'] ?? 'TBD') ?>
                                    </td>
                                    <td><?= sanitize($match['winner_name'] ?? 'TBD') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No matches seeded yet.</p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Players</h3>
            <?php if ($players): ?>
                <ul class="player-list">
                    <?php foreach ($players as $player): ?>
                        <li><?= sanitize($player['username']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">No players registered yet. Use the settings modal to add participants.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isTournamentTab): ?>
    <div id="createTournamentModal" class="modal-overlay" hidden aria-hidden="true">
        <div class="modal modal--md" role="dialog" aria-modal="true" aria-labelledby="createTournamentTitle">
            <button type="button" class="modal__close" data-close-modal aria-label="Close create tournament modal">&times;</button>
            <h3 id="createTournamentTitle">Create Tournament</h3>
            <form method="post" action="/api/tournaments.php" class="modal-form">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <label>Name
                    <input type="text" name="name" required data-modal-focus>
                </label>
                <label>Type
                    <select name="type" required>
                        <option value="single">Single Elimination</option>
                        <option value="double">Double Elimination</option>
                        <option value="round-robin">Round Robin</option>
                    </select>
                </label>
                <label>Description
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="form-grid">
                    <label>Date
                        <input type="date" name="scheduled_date">
                    </label>
                    <label>Time
                        <input type="time" name="scheduled_time">
                    </label>
                </div>
                <label>Location
                    <input type="text" name="location" value="<?= sanitize(default_tournament_location()) ?>" placeholder="<?= sanitize(default_tournament_location()) ?>">
                </label>
                <div class="modal-actions">
                    <button type="submit" class="btn primary">Create Tournament</button>
                    <button type="button" class="btn link" data-close-modal>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="tournamentActionsModal" class="modal-overlay modal-overlay--compact" hidden aria-hidden="true">
        <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="tournamentActionsTitle">
            <button type="button" class="modal__close" data-close-modal aria-label="Close tournament actions">&times;</button>
            <div class="modal__body">
                <h3 id="tournamentActionsTitle" class="modal__title js-action-title">Tournament</h3>
                <p class="js-action-schedule muted"></p>
                <div class="modal-actions">
                    <a href="#" class="btn primary js-open-tournament" data-open-tournament data-modal-focus>Open Tournament</a>
                    <button type="button" class="btn secondary" data-open-settings>Open Settings</button>
                </div>
            </div>
        </div>
    </div>

    <div id="tournamentSettingsModal" class="modal-overlay" hidden aria-hidden="true" data-all-players='<?= sanitize($playerPayload) ?>'>
        <div class="modal modal--lg" role="dialog" aria-modal="true" aria-labelledby="tournamentSettingsTitle">
            <button type="button" class="modal__close" data-close-modal aria-label="Close tournament settings">&times;</button>
            <form method="post" action="/api/tournaments.php" class="modal-form" data-settings-form>
                <input type="hidden" name="action" value="update_settings">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="tournament_id" value="">
                <h3 id="tournamentSettingsTitle">Tournament Settings</h3>
                <div class="form-grid">
                    <label>Name
                        <input type="text" name="name" required data-modal-focus>
                    </label>
                    <label>Type
                        <select name="type" required>
                            <option value="single">Single Elimination</option>
                            <option value="double">Double Elimination</option>
                            <option value="round-robin">Round Robin</option>
                        </select>
                    </label>
                </div>
                <label>Description
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="form-grid">
                    <label>Date
                        <input type="date" name="scheduled_date">
                    </label>
                    <label>Time
                        <input type="time" name="scheduled_time">
                    </label>
                </div>
                <label>Location
                    <input type="text" name="location" placeholder="<?= sanitize(default_tournament_location()) ?>">
                </label>
                <div class="player-section">
                    <div class="player-section__header">
                        <h4>Players</h4>
                        <button type="button" class="btn tertiary" data-toggle-player-list>Add Players</button>
                    </div>
                    <p class="muted small">Players already registered are checked. Select additional players and save to update the roster.</p>
                    <div class="player-chip-list js-selected-players"></div>
                    <div class="player-checkbox-list" data-player-list hidden></div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php';
