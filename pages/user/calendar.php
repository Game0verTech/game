<?php
require_login();
$user = current_user();
$pageTitle = 'Calendar';
require __DIR__ . '/../../templates/header.php';

$tournaments = list_tournaments();
$calendarData = [];
$canManageTournaments = user_has_role('admin') || user_has_role('manager');
foreach ($tournaments as $tournament) {
    $tournamentId = (int)$tournament['id'];
    $players = tournament_players($tournamentId);
    $isRegistered = is_user_registered($tournamentId, $user['id']);
    $calendarData[] = [
        'id' => $tournamentId,
        'name' => $tournament['name'],
        'status' => $tournament['status'],
        'type' => $tournament['type'],
        'description' => $tournament['description'],
        'scheduled_at' => $tournament['scheduled_at'],
        'location' => $tournament['location'],
        'player_count' => count($players),
        'players' => array_map(static fn($player) => (int)$player['user_id'], $players),
        'player_roster' => array_map(
            static function ($player) {
                $username = $player['username'] ?? 'Player';
                return [
                    'id' => (int)$player['user_id'],
                    'name' => $username,
                    'username' => $username,
                    'display_name' => $player['display_name'] ?? null,
                    'profile_url' => user_profile_url($username),
                    'icon_url' => resolve_user_icon_url($player['icon_path'] ?? null),
                ];
            },
            $players
        ),
        'is_registered' => $isRegistered,
    ];
}
$calendarJson = safe_json_encode($calendarData);
?>
<?php if ($canManageTournaments): ?>
    <div class="calendar-actions">
        <button type="button" class="btn primary" data-modal-trigger="createTournamentModal">Create New Tournament</button>
    </div>
<?php endif; ?>
<div class="card player-calendar">
    <div class="player-calendar__header">
        <div>
            <h2>Events Calendar</h2>
            <p class="muted">Select an event to review the details, roster, and live bracket.</p>
        </div>
    </div>
    <div
        id="tournamentCalendar"
        class="tournament-calendar"
        data-tournaments='<?= sanitize($calendarJson) ?>'
        data-default-location="<?= sanitize(default_tournament_location()) ?>"
    ></div>
</div>
<?php if ($canManageTournaments): ?>
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
<?php endif; ?>
<?php require __DIR__ . '/../../templates/partials/tournament-viewer-modal.php'; ?>
<?php require __DIR__ . '/../../templates/footer.php';
