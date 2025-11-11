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
        add_player_to_tournament($tournamentId, $user['id']);
        flash('success', 'You have registered for ' . $tournament['name'] . '.');
        redirect('/?page=dashboard');

    case 'withdraw':
        require_login();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'open') {
            flash('error', 'Withdrawals are only allowed while registration is open.');
            redirect('/?page=dashboard');
        }
        remove_player_from_tournament($tournamentId, $user['id']);
        flash('success', 'You have been removed from ' . $tournament['name'] . '.');
        redirect('/?page=dashboard');

    case 'create':
        require_admin();
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $description = trim($_POST['description'] ?? '');
        if ($name === '' || !in_array($type, ['single', 'double', 'round-robin'], true)) {
            flash('error', 'Provide a name and valid tournament type.');
            redirect('/?page=admin&t=manage');
        }
        $tournament = create_tournament($name, $type, $description, $user['id']);
        flash('success', 'Tournament created.');
        redirect('/?page=admin&t=manage&id=' . $tournament['id']);

    case 'open':
        require_admin();
        $id = (int)($_POST['tournament_id'] ?? 0);
        update_tournament_status($id, 'open');
        flash('success', 'Tournament opened for registration.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'start':
        require_admin();
        $id = (int)($_POST['tournament_id'] ?? 0);
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
        seed_matches_for_tournament($id);
        update_tournament_status($id, 'live');
        flash('success', 'Tournament started.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'complete':
        require_admin();
        $id = (int)($_POST['tournament_id'] ?? 0);
        update_tournament_status($id, 'completed');
        flash('success', 'Tournament completed.');
        redirect('/?page=admin&t=manage&id=' . $id);

    case 'add_player_admin':
        require_admin();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        add_player_to_tournament($tournamentId, $userId);
        flash('success', 'Player added.');
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'remove_player_admin':
        require_admin();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        remove_player_from_tournament($tournamentId, $userId);
        flash('success', 'Player removed.');
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    case 'save_bracket':
        require_admin();
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
        require_admin();
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
        require_admin();
        $tournamentId = (int)($_POST['tournament_id'] ?? 0);
        $matchId = (int)($_POST['match_id'] ?? 0);
        $tournament = get_tournament($tournamentId);
        if (!$tournament || $tournament['status'] !== 'live') {
            flash('error', 'Matches can only be updated while the tournament is live.');
            redirect('/?page=admin&t=manage&id=' . $tournamentId);
        }
        $score1 = (int)($_POST['score1'] ?? 0);
        $score2 = (int)($_POST['score2'] ?? 0);
        $winner = (int)($_POST['winner_user_id'] ?? 0) ?: null;
        record_match_result($tournamentId, $matchId, $score1, $score2, $winner);
        flash('success', 'Match updated.');
        redirect('/?page=admin&t=manage&id=' . $tournamentId);

    default:
        http_response_code(400);
        echo 'Unknown action';
}
