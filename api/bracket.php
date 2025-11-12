<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
if ($tournamentId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid tournament']);
    exit;
}

$tournament = get_tournament($tournamentId);
if (!$tournament) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

$bracket = generate_bracket_structure($tournamentId);
header('Content-Type: application/json');
echo json_encode([
    'bracket' => $bracket,
    'checksum' => sha1(json_encode($bracket)),
    'updated_at' => $tournament['updated_at'],
    'status' => $tournament['status'],
]);
