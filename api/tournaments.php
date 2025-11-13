<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

require_csrf();
$action = $_POST['action'] ?? '';
$user = current_user();

switch ($action) {
    case 'register':
        require_login();
        $user = current_user();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'open') {
            flash('error', 'Tournament is not open for registration.');
            redirect('/?page=tournaments');
        }
        if (is_user_registered($tournamentId, $user['id'])) {
            flash('error', 'You are already registered for this tournament.');
            redirect('/?page=dashboard');
        }
        if (add_player_to_tournament($tournamentId, $user['id'])) {
            refresh_tournament_bracket_snapshot($tournamentId);
        }
        flash('success', 'You have registered for ' . $tournament['name'] . '.');
        redirect('/?page=dashboard');

    case 'withdraw':
        require_login();
        $user = current_user();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'open') {
            flash('error', 'Withdrawals are only allowed while registration is open.');
            redirect('/?page=dashboard');
        }
        if (remove_player_from_tournament($tournamentId, $user['id'])) {
            refresh_tournament_bracket_snapshot($tournamentId);
        }
        flash('success', 'You have been removed from ' . $tournament['name'] . '.');
        redirect('/?page=dashboard');

    case 'create':
        require_role('admin', 'manager');
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['scheduled_date'] ?? '';
        $time = $_POST['scheduled_time'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $scheduledAt = normalize_tournament_schedule_input($date, $time);
        if ($location === '') {
            $location = default_tournament_location();
        }
        if ($name === '' || !in_array($type, ['single', 'double', 'round-robin'], true)) {
            flash('error', 'Provide a name and valid tournament type.');
            redirect('/?page=admin&t=manage');
        }
        $tournament = create_tournament($name, $type, $description, $user['id'], $scheduledAt, $location);
        flash('success', 'Tournament created.');
        redirect('/?page=admin&t=view&id=' . $tournament['id']);

    case 'update_settings':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament) {
            flash('error', 'Tournament not found.');
            redirect('/?page=admin&t=manage');
        }
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? $tournament['type'];
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['scheduled_date'] ?? '';
        $time = $_POST['scheduled_time'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $selectedPlayers = $_POST['players'] ?? [];
        if (!is_array($selectedPlayers)) {
            $selectedPlayers = [];
        }
        $scheduledAt = normalize_tournament_schedule_input($date, $time);
        if ($scheduledAt === null) {
            $scheduledAt = $tournament['scheduled_at'] ?? null;
        }
        if ($location === '') {
            $location = $tournament['location'] ?: default_tournament_location();
        }
        if ($name === '' || !in_array($type, ['single', 'double', 'round-robin'], true)) {
            flash('error', 'Please provide valid tournament details.');
            redirect('/?page=admin&t=view&id=' . $tournamentId);
        }
        $typeChanged = $type !== $tournament['type'];
        update_tournament_details($tournamentId, $name, $type, $description, $scheduledAt, $location);
        $playersChanged = set_tournament_players($tournamentId, $selectedPlayers);
        if ($typeChanged && !$playersChanged) {
            refresh_tournament_bracket_snapshot($tournamentId);
        }
        flash('success', 'Tournament settings updated.');
        redirect('/?page=admin&t=view&id=' . $tournamentId);

    case 'open':
        require_role('admin', 'manager');
        $id = (int)($_POST['tournament_id'] ?? 0);
        update_tournament_status($id, 'open');
        flash('success', 'Tournament opened for registration.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'start':
        require_role('admin', 'manager');
        $id = (int)($_POST['tournament_id'] ?? 0);
        seed_matches_for_tournament($id);
        $structure = generate_bracket_structure($id);
        if (!$structure) {
            flash('error', 'Unable to generate bracket. Ensure there are players registered.');
            redirect('/?page=admin&t=manage&id=' . $id);
        }
        $tournament = get_tournament($id);
        if ($tournament['type'] === 'round-robin') {
            update_tournament_json($id, null, json_encode($structure));
        } else {
            update_tournament_json($id, json_encode($structure), null);
        }
        update_tournament_status($id, 'live');
        flash('success', 'Tournament started.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'complete':
        require_role('admin', 'manager');
        $id = (int)($_POST['tournament_id'] ?? 0);
        update_tournament_status($id, 'completed');
        flash('success', 'Tournament completed.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'add_player_admin':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = get_user_by_id($userId);
        if (!$target || (int)$target['is_banned'] === 1) {
            flash('error', 'Cannot add banned or unknown users.');
            redirect('/?page=admin&t=manage&id=' . $tournamentId);
        }
        if (add_player_to_tournament($tournamentId, $userId)) {
            refresh_tournament_bracket_snapshot($tournamentId);
            flash('success', 'Player added.');
        } else {
            flash('info', 'Player was already registered.');
        }
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'remove_player_admin':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        if (remove_player_from_tournament($tournamentId, $userId)) {
            refresh_tournament_bracket_snapshot($tournamentId);
            flash('success', 'Player removed.');
        } else {
            flash('info', 'Player was not registered.');
        }
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'save_bracket':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $data = $_POST['bracket_json'] ?? '';
        json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            flash('error', 'Invalid bracket data.');
            redirect('/?page=admin&t=manage&id=' . $tournamentId);
        }
        update_tournament_json($tournamentId, $data, null);
        save_snapshot($tournamentId, 'bracket', json_decode($data, true), $user['id']);
        flash('success', 'Bracket saved.');
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'save_group':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $data = $_POST['group_json'] ?? '';
        json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            flash('error', 'Invalid group data.');
            redirect('/?page=admin&t=manage&id=' . $tournamentId);
        }
        update_tournament_json($tournamentId, null, $data);
        save_snapshot($tournamentId, 'group', json_decode($data, true), $user['id']);
        flash('success', 'Group standings saved.');
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'report_match':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $matchId = (int)($_POST['match_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'live') {
            flash('error', 'Matches can only be updated while the tournament is live.');
            redirect('/?page=admin&t=manage&id=' . $tournamentId);
        }
        $winner = (int)($_POST['winner_user_id'] ?? 0) ?: null;
        try {
            record_match_result($tournamentId, $matchId, $winner);
            flash('success', 'Match updated.');
        } catch (Throwable $e) {
            error_log('Match update failed: ' . $e->getMessage());
            flash('error', 'Unable to update match: ' . $e->getMessage());
        }
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'set_match_winner':
        require_role('admin', 'manager');
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $matchId = (int)($_POST['match_id'] ?? 0);
        $winner = (int)($_POST['winner_user_id'] ?? 0) ?: null;
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'live') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo safe_json_encode(['error' => 'Tournament is not live.']);
            exit;
        }
        try {
            $bracket = record_match_result($tournamentId, $matchId, $winner);
        } catch (Throwable $e) {
            error_log('Match update failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo safe_json_encode([
                'error' => 'Unable to update match.',
                'detail' => $e->getMessage(),
            ]);
            exit;
        }
        header('Content-Type: application/json');
        echo safe_json_encode([
            'ok' => true,
            'bracket' => $bracket,
        ]);
        exit;

    default:
        http_response_code(400);
        echo 'Unknown action';
}
