<?php
require_login();
$user = current_user();
$pageTitle = 'Calendar';
require __DIR__ . '/../../templates/header.php';

$tournaments = list_tournaments();
$calendarData = [];
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
            static fn($player) => [
                'id' => (int)$player['user_id'],
                'name' => $player['username'],
            ],
            $players
        ),
        'is_registered' => $isRegistered,
    ];
}
$calendarJson = safe_json_encode($calendarData);
?>
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
<?php require __DIR__ . '/../../templates/partials/tournament-viewer-modal.php'; ?>
<?php require __DIR__ . '/../../templates/footer.php';
