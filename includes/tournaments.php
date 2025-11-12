<?php

function create_tournament(string $name, string $type, string $description, int $createdBy, ?string $scheduledAt = null, ?string $location = null): array
{
    $stmt = db()->prepare("INSERT INTO tournaments (name, type, description, status, scheduled_at, location, created_by) VALUES (:name, :type, :description, 'draft', :scheduled_at, :location, :created_by)");
    $stmt->execute([
        ':name' => $name,
        ':type' => $type,
        ':description' => $description,
        ':scheduled_at' => $scheduledAt,
        ':location' => $location,
        ':created_by' => $createdBy,
    ]);
    return get_tournament((int)db()->lastInsertId());
}

function get_tournament(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tournaments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $tournament = $stmt->fetch() ?: null;
    if (!$tournament) {
        return null;
    }

    return ensure_tournament_schedule($tournament, 0);
}

function list_tournaments(?string $status = null): array
{
    if ($status) {
        $stmt = db()->prepare('SELECT * FROM tournaments WHERE status = :status ORDER BY created_at DESC');
        $stmt->execute([':status' => $status]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = db()->query('SELECT * FROM tournaments ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as $index => $row) {
        $rows[$index] = ensure_tournament_schedule($row, $index);
    }

    return $rows;
}

function update_tournament_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE tournaments SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);
}

function update_tournament_json(int $id, ?string $bracketJson, ?string $groupJson): void
{
    $stmt = db()->prepare('UPDATE tournaments SET bracket_json = :bracket, groups_json = :groups WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($bracketJson === null) {
        $stmt->bindValue(':bracket', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':bracket', $bracketJson, PDO::PARAM_STR);
    }
    if ($groupJson === null) {
        $stmt->bindValue(':groups', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':groups', $groupJson, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function tournament_bracket_snapshot(array $tournament): ?string
{
    $id = isset($tournament['id']) ? (int)$tournament['id'] : 0;
    if ($id <= 0) {
        return $tournament['bracket_json'] ?? null;
    }

    $status = $tournament['status'] ?? null;
    $existing = $tournament['bracket_json'] ?? null;
    $shouldRefresh = in_array($status, ['live', 'completed'], true) || empty($existing);

    if (!$shouldRefresh && $existing !== null) {
        return $existing;
    }

    $structure = generate_bracket_structure($id);
    if (empty($structure)) {
        return $existing;
    }

    try {
        return safe_json_encode($structure);
    } catch (Throwable $e) {
        error_log(sprintf('Failed to encode bracket for tournament %d: %s', $id, $e->getMessage()));
        return $existing;
    }
}

function normalize_tournament_schedule_input(?string $date, ?string $time): ?string
{
    $date = trim((string)($date ?? ''));
    $time = trim((string)($time ?? ''));

    if ($date === '' && $time === '') {
        return null;
    }

    if ($date === '') {
        return null;
    }

    if ($time === '') {
        $time = '18:00';
    }

    $dateTime = DateTime::createFromFormat('Y-m-d H:i', sprintf('%s %s', $date, $time));
    if (!$dateTime) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function update_tournament_schedule(int $id, ?string $scheduledAt, ?string $location): void
{
    $stmt = db()->prepare('UPDATE tournaments SET scheduled_at = :scheduled_at, location = :location WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($scheduledAt === null) {
        $stmt->bindValue(':scheduled_at', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':scheduled_at', $scheduledAt, PDO::PARAM_STR);
    }
    if ($location === null) {
        $stmt->bindValue(':location', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':location', $location, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function ensure_tournament_schedule(array $tournament, int $offset): array
{
    if (!isset($tournament['id'])) {
        return $tournament;
    }

    $needsUpdate = false;
    $scheduledAt = $tournament['scheduled_at'] ?? null;
    $location = $tournament['location'] ?? null;

    if (empty($scheduledAt)) {
        $base = new DateTimeImmutable('+3 days');
        if ($offset > 0) {
            $base = $base->modify('+' . ($offset * 2) . ' days');
        }
        $base = $base->setTime(18, 0);
        $scheduledAt = $base->format('Y-m-d H:i:s');
        $needsUpdate = true;
    }

    if (empty($location)) {
        $location = 'Kenton Moose Lodge Basement';
        $needsUpdate = true;
    }

    if ($needsUpdate) {
        update_tournament_schedule((int)$tournament['id'], $scheduledAt, $location);
        $tournament['scheduled_at'] = $scheduledAt;
        $tournament['location'] = $location;
    }

    return $tournament;
}

function update_tournament_details(int $id, string $name, string $type, string $description, ?string $scheduledAt, ?string $location): void
{
    $stmt = db()->prepare('UPDATE tournaments SET name = :name, type = :type, description = :description, scheduled_at = :scheduled_at, location = :location WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    if ($scheduledAt === null) {
        $stmt->bindValue(':scheduled_at', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':scheduled_at', $scheduledAt, PDO::PARAM_STR);
    }
    if ($location === null) {
        $stmt->bindValue(':location', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':location', $location, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function set_tournament_players(int $tournamentId, array $userIds): void
{
    $normalized = [];
    foreach ($userIds as $userId) {
        $userId = (int)$userId;
        if ($userId > 0) {
            $normalized[$userId] = $userId;
        }
    }

    $existing = tournament_players($tournamentId);
    $existingIds = array_map(static fn($player) => (int)$player['user_id'], $existing);

    $toAdd = array_diff($normalized, $existingIds);
    $toRemove = array_diff($existingIds, $normalized);

    foreach ($toAdd as $userId) {
        add_player_to_tournament($tournamentId, $userId);
    }

    foreach ($toRemove as $userId) {
        remove_player_from_tournament($tournamentId, $userId);
    }
}

function add_player_to_tournament(int $tournamentId, int $userId): bool
{
    $stmt = db()->prepare('INSERT IGNORE INTO tournament_players (tournament_id, user_id) VALUES (:tid, :uid)');
    return $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
}

function is_user_registered(int $tournamentId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM tournament_players WHERE tournament_id = :tid AND user_id = :uid');
    $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function remove_player_from_tournament(int $tournamentId, int $userId): void
{
    $stmt = db()->prepare('DELETE FROM tournament_players WHERE tournament_id = :tid AND user_id = :uid');
    $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
}

function tournament_players(int $tournamentId): array
{
    $sql = 'SELECT tp.*, u.username FROM tournament_players tp INNER JOIN users u ON tp.user_id = u.id WHERE tp.tournament_id = :tid ORDER BY COALESCE(tp.seed, tp.id)';
    $stmt = db()->prepare($sql);
    $stmt->execute([':tid' => $tournamentId]);
    return $stmt->fetchAll();
}

function user_tournaments(int $userId): array
{
    $sql = 'SELECT t.* FROM tournaments t INNER JOIN tournament_players tp ON t.id = tp.tournament_id WHERE tp.user_id = :uid ORDER BY t.updated_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function available_tournaments_for_user(): array
{
    $stmt = db()->query('SELECT * FROM tournaments WHERE status = "open" ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function generate_bracket_structure(int $tournamentId): array
{
    $tournament = get_tournament($tournamentId);
    if (!$tournament) {
        return [];
    }

    if ($tournament['type'] === 'round-robin') {
        $players = tournament_players($tournamentId);
        $teamNames = array_map(fn($p) => ['name' => $p['username']], $players);
        $results = [];
        foreach ($teamNames as $i => $team) {
            $row = [];
            foreach ($teamNames as $j => $opponent) {
                $row[] = $i === $j ? null : [null, null];
            }
            $results[] = $row;
        }
        return ['teams' => $teamNames, 'results' => $results];
    }

    if ($tournament['type'] === 'single') {
        return build_single_elimination_bracket($tournamentId);
    }

    if ($tournament['type'] === 'double') {
        $players = tournament_players($tournamentId);
        $slots = array_map(fn($p) => $p['username'], $players);
        if (empty($slots)) {
            $slots = ['TBD', 'TBD'];
        }
        while (count($slots) % 2 !== 0) {
            $slots[] = 'BYE';
        }
        $teams = [];
        for ($i = 0; $i < count($slots); $i += 2) {
            $teams[] = [$slots[$i], $slots[$i + 1] ?? 'BYE'];
        }
        $roundTemplate = [];
        foreach ($teams as $_) {
            $roundTemplate[] = [null, null];
        }
        $winners = [$roundTemplate];
        $losers = [$roundTemplate];
        $finals = [[null, null], [null, null]];
        return ['teams' => $teams, 'results' => [$winners, $losers, [$finals]]];
    }

    return [];
}

function build_single_elimination_bracket(int $tournamentId): array
{
    $matches = tournament_matches($tournamentId);
    $rounds = [];
    $teams = [];
    if ($matches) {
        foreach ($matches as $match) {
            if ($match['stage'] !== 'main') {
                continue;
            }
            $roundIndex = max(0, (int)$match['round'] - 1);
            $rounds[$roundIndex] ??= [];
            $score1 = $match['score1'];
            $score2 = $match['score2'];
            if ($match['winner_user_id'] && $score1 === null && $score2 === null) {
                if ($match['winner_user_id'] === $match['player1_user_id']) {
                    $score1 = 1;
                    $score2 = 0;
                } elseif ($match['winner_user_id'] === $match['player2_user_id']) {
                    $score1 = 0;
                    $score2 = 1;
                }
            }
            $rounds[$roundIndex][] = [
                $score1 === null ? null : (int)$score1,
                $score2 === null ? null : (int)$score2,
                [
                    'match_id' => (int)$match['id'],
                    'round' => $roundIndex,
                    'match' => count($rounds[$roundIndex]),
                    'player1' => $match['player1_user_id'] ? [
                        'id' => (int)$match['player1_user_id'],
                        'name' => $match['player1_name'] ?? 'TBD',
                    ] : null,
                    'player2' => $match['player2_user_id'] ? [
                        'id' => (int)$match['player2_user_id'],
                        'name' => $match['player2_name'] ?? 'TBD',
                    ] : null,
                    'winner' => $match['winner_user_id'] ? [
                        'id' => (int)$match['winner_user_id'],
                        'name' => $match['winner_name'] ?? null,
                    ] : null,
                ],
            ];
            if ((int)$match['round'] === 1) {
                $teams[] = [
                    $match['player1_name'] ?? 'TBD',
                    $match['player2_name'] ?? 'TBD',
                ];
            }
        }
    }

    if (empty($teams)) {
        $players = tournament_players($tournamentId);
        $pairs = single_elimination_pairs($players);
        foreach ($pairs as $pair) {
            $teams[] = [$pair['player1']['name'], $pair['player2']['name']];
        }
        $matchesInRound = count($teams);
        while ($matchesInRound > 0) {
            $round = [];
            for ($i = 0; $i < $matchesInRound; $i++) {
                $round[] = [null, null, [
                    'match_id' => null,
                    'round' => count($rounds),
                    'match' => $i,
                    'player1' => null,
                    'player2' => null,
                    'winner' => null,
                ]];
            }
            $rounds[] = $round;
            if ($matchesInRound === 1) {
                break;
            }
            $matchesInRound = (int)ceil($matchesInRound / 2);
        }
    }

    ksort($rounds);
    $rounds = array_values(array_map(function (array $round) {
        return array_values($round);
    }, $rounds));

    return ['teams' => $teams, 'results' => $rounds];
}

function next_power_of_two(int $number): int
{
    if ($number < 1) {
        return 1;
    }
    $power = 1;
    while ($power < $number) {
        $power <<= 1;
    }
    return $power;
}

function single_elimination_pairs(array $players): array
{
    $slots = array_map(static function ($player) {
        return [
            'id' => isset($player['user_id']) ? (int)$player['user_id'] : null,
            'name' => $player['username'] ?? 'TBD',
        ];
    }, $players);

    $slotCount = max(2, next_power_of_two(count($slots)));
    $byeMatches = $slotCount - count($slots);
    $pairs = [];
    $index = 0;

    for ($i = 0; $i < $slotCount / 2; $i++) {
        $team1 = $slots[$index] ?? null;
        if ($team1 !== null) {
            $index++;
        }
        if ($team1 === null) {
            $team1 = ['id' => null, 'name' => 'BYE'];
        }

        if ($byeMatches > 0) {
            $team2 = ['id' => null, 'name' => 'BYE'];
            $byeMatches--;
        } else {
            $team2 = $slots[$index] ?? null;
            if ($team2 !== null) {
                $index++;
            }
            if ($team2 === null) {
                $team2 = ['id' => null, 'name' => 'BYE'];
            }
        }

        $pairs[] = [
            'player1' => $team1,
            'player2' => $team2,
        ];
    }

    return $pairs;
}

function seed_matches_for_tournament(int $tournamentId): void
{
    $tournament = get_tournament($tournamentId);
    $players = tournament_players($tournamentId);
    db()->prepare('DELETE FROM tournament_matches WHERE tournament_id = :tid')->execute([':tid' => $tournamentId]);

    if ($tournament['type'] === 'round-robin') {
        $teamIds = array_column($players, 'user_id');
        foreach ($teamIds as $i => $player1) {
            for ($j = $i + 1; $j < count($teamIds); $j++) {
                $player2 = $teamIds[$j];
                $stmt = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
                $stmt->execute([
                    ':tid' => $tournamentId,
                    ':stage' => 'group',
                    ':round' => $i + 1,
                    ':index' => $j,
                    ':p1' => $player1,
                    ':p2' => $player2,
                ]);
            }
        }
        return;
    }

    if ($tournament['type'] === 'single') {
        $pairs = single_elimination_pairs($players);
        $round = 1;
        $matchesInRound = count($pairs);
        $insert = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
        while ($matchesInRound >= 1) {
            for ($i = 0; $i < $matchesInRound; $i++) {
                if ($round === 1) {
                    $p1 = $pairs[$i]['player1']['id'];
                    $p2 = $pairs[$i]['player2']['id'];
                } else {
                    $p1 = null;
                    $p2 = null;
                }
                $insert->execute([
                    ':tid' => $tournamentId,
                    ':stage' => 'main',
                    ':round' => $round,
                    ':index' => $i + 1,
                    ':p1' => $p1,
                    ':p2' => $p2,
                ]);
            }
            if ($matchesInRound === 1) {
                break;
            }
            $matchesInRound = (int)ceil($matchesInRound / 2);
            $round++;
        }
        return;
    }

    $matches = array_chunk($players, 2);
    foreach ($matches as $index => $pair) {
        $p1 = $pair[0]['user_id'] ?? null;
        $p2 = $pair[1]['user_id'] ?? null;
        $stmt = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
        $stmt->execute([
            ':tid' => $tournamentId,
            ':stage' => 'main',
            ':round' => 1,
            ':index' => $index + 1,
            ':p1' => $p1,
            ':p2' => $p2,
        ]);
    }
}

function record_match_result(int $tournamentId, int $matchId, ?int $winnerId): array
{
    $pdo = db();
    $matchStmt = $pdo->prepare('SELECT * FROM tournament_matches WHERE id = :id AND tournament_id = :tid');
    $matchStmt->execute([':id' => $matchId, ':tid' => $tournamentId]);
    $match = $matchStmt->fetch();
    if (!$match) {
        throw new RuntimeException('Match not found.');
    }

    $score1 = null;
    $score2 = null;
    if ($winnerId) {
        $isPlayer1 = (int)($match['player1_user_id'] ?? 0) === $winnerId;
        $isPlayer2 = (int)($match['player2_user_id'] ?? 0) === $winnerId;
        if (!$isPlayer1 && !$isPlayer2) {
            throw new RuntimeException('Selected winner is not part of this match.');
        }
        $score1 = $isPlayer1 ? 1 : 0;
        $score2 = $isPlayer2 ? 1 : 0;
    }

    $update = $pdo->prepare('UPDATE tournament_matches SET score1 = :s1, score2 = :s2, winner_user_id = :winner WHERE id = :id AND tournament_id = :tid');
    $update->execute([
        ':s1' => $score1,
        ':s2' => $score2,
        ':winner' => $winnerId,
        ':id' => $matchId,
        ':tid' => $tournamentId,
    ]);

    propagate_winner_to_next_match($tournamentId, $match, $winnerId);
    refresh_player_stats_for_match($match, $winnerId);
    clear_following_results($tournamentId, $match['stage'], (int)$match['round'], (int)$match['match_index']);

    $bracket = generate_bracket_structure($tournamentId);
    $bracketJson = safe_json_encode($bracket);
    update_tournament_json($tournamentId, $bracketJson, null);
    touch_tournament($tournamentId);

    return $bracket;
}

function refresh_player_stats_for_match(array $match, ?int $winnerId): void
{
    $players = [];
    foreach (['player1_user_id', 'player2_user_id'] as $key) {
        if (!empty($match[$key])) {
            $players[] = (int)$match[$key];
        }
    }
    if ($winnerId) {
        $players[] = $winnerId;
    }
    foreach (array_unique($players) as $playerId) {
        update_user_stat($playerId);
    }
}

function propagate_winner_to_next_match(int $tournamentId, array $match, ?int $winnerId): void
{
    if (!$winnerId) {
        $slotColumn = ((int)$match['match_index'] % 2 === 1) ? 'player1_user_id' : 'player2_user_id';
        $nextRound = (int)$match['round'] + 1;
        $nextIndex = (int)ceil(((int)$match['match_index']) / 2);
        $sql = sprintf(
            'UPDATE tournament_matches SET %s = NULL WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index',
            $slotColumn
        );
        $stmt = db()->prepare($sql);
        $stmt->execute([
            ':tid' => $tournamentId,
            ':stage' => $match['stage'],
            ':round' => $nextRound,
            ':index' => $nextIndex,
        ]);
        return;
    }

    $nextRound = (int)$match['round'] + 1;
    $nextIndex = (int)ceil(((int)$match['match_index']) / 2);
    $stmt = db()->prepare('SELECT id, player1_user_id, player2_user_id FROM tournament_matches WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':stage' => $match['stage'],
        ':round' => $nextRound,
        ':index' => $nextIndex,
    ]);
    $next = $stmt->fetch();
    if (!$next) {
        return;
    }

    $slotColumn = ((int)$match['match_index'] % 2 === 1) ? 'player1_user_id' : 'player2_user_id';
    $update = db()->prepare("UPDATE tournament_matches SET {$slotColumn} = :winner, score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id");
    $update->execute([
        ':winner' => $winnerId,
        ':id' => $next['id'],
    ]);
}

function clear_following_results(int $tournamentId, string $stage, int $round, int $matchIndex): void
{
    $nextRound = $round + 1;
    $nextIndex = (int)ceil($matchIndex / 2);
    $stmt = db()->prepare('SELECT id, stage, round, match_index FROM tournament_matches WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':stage' => $stage,
        ':round' => $nextRound,
        ':index' => $nextIndex,
    ]);
    $next = $stmt->fetch();
    if (!$next) {
        return;
    }
    db()->prepare('UPDATE tournament_matches SET score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id')->execute([':id' => $next['id']]);
    clear_following_results($tournamentId, $stage, (int)$next['round'], (int)$next['match_index']);
}

function touch_tournament(int $tournamentId): void
{
    db()->prepare('UPDATE tournaments SET updated_at = NOW() WHERE id = :id')->execute([':id' => $tournamentId]);
}

function save_snapshot(int $tournamentId, string $type, array $payload, int $userId): void
{
    $stmt = db()->prepare('INSERT INTO snapshots (tournament_id, snapshot_type, payload, created_by) VALUES (:tid, :type, :payload, :uid)');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':type' => $type,
        ':payload' => json_encode($payload),
        ':uid' => $userId,
    ]);
}

function tournament_matches(int $tournamentId): array
{
    $sql = 'SELECT tm.*, 
                   u1.username AS player1_name,
                   u2.username AS player2_name,
                   uw.username AS winner_name
            FROM tournament_matches tm
            LEFT JOIN users u1 ON tm.player1_user_id = u1.id
            LEFT JOIN users u2 ON tm.player2_user_id = u2.id
            LEFT JOIN users uw ON tm.winner_user_id = uw.id
            WHERE tm.tournament_id = :tid
            ORDER BY tm.stage, tm.round, tm.match_index';
    $stmt = db()->prepare($sql);
    $stmt->execute([':tid' => $tournamentId]);
    return $stmt->fetchAll();
}
