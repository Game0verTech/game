<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

require_csrf();
$action = $_POST['action'] ?? '';
header('Content-Type: application/json');

switch ($action) {
    case 'list_tournaments':
        $tournaments = list_tournaments('open');
        echo json_encode($tournaments);
        break;
    case 'tournament_details':
        $id = (int)($_POST['tournament_id'] ?? 0);
        $tournament = get_tournament($id);
        if (!$tournament) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            break;
        }
        $tournament['players'] = tournament_players($id);
        echo json_encode($tournament);
        break;
    case 'stats':
        if (!current_user()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            break;
        }
        $user = current_user();
        $stats = get_user_stat($user['id']);
        $recent = recent_results($user['id']);
        echo json_encode(['stats' => $stats, 'recent' => $recent]);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
