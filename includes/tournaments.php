<?php

function create_tournament(string $name, string $type, string $description, int $createdBy): array
{
    $stmt = db()->prepare("INSERT INTO tournaments (name, type, description, status, created_by) VALUES (:name, :type, :description, 'draft', :created_by)");
    $stmt->execute([
        ':name' => $name,
        ':type' => $type,
        ':description' => $description,
        ':created_by' => $createdBy,
    ]);
    return get_tournament((int)db()->lastInsertId());
}

function get_tournament(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tournaments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function list_tournaments(?string $status = null): array
{
    if ($status) {
        $stmt = db()->prepare('SELECT * FROM tournaments WHERE status = :status ORDER BY created_at DESC');
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll();
    }
    $stmt = db()->query('SELECT * FROM tournaments ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function update_tournament_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE tournaments SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);
}

function update_tournament_json(int $id, ?string $bracketJson, ?string $groupJson): void
{
    $stmt = db()->prepare('UPDATE tournaments SET bracket_json = :bracket, groups_json = :groupjson WHERE id = :id');
    $stmt->execute([
        ':bracket' => $bracketJson,
        ':groupjson' => $groupJson,
        ':id' => $id,
    ]);
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
    $players = tournament_players($tournamentId);
    $teams = [];
    foreach (array_chunk($players, 2) as $chunk) {
        $name1 = $chunk[0]['username'] ?? 'BYE';
        $name2 = $chunk[1]['username'] ?? 'BYE';
        $teams[] = [$name1, $name2];
    }
    if ($tournament['type'] !== 'round-robin' && count($teams) > 0) {
        $teamCount = count($teams);
        $target = 1;
        while ($target < $teamCount) {
            $target *= 2;
        }
        while ($teamCount < $target) {
            $teams[] = ['BYE', 'BYE'];
            $teamCount++;
        }
    }
    $results = [];
    if ($tournament['type'] === 'single') {
        $matches = count($teams);
        while ($matches > 0) {
            $round = [];
            for ($i = 0; $i < $matches; $i++) {
                $round[] = [null, null];
            }
            $results[] = $round;
            if ($matches === 1) {
                break;
            }
            $matches = (int)ceil($matches / 2);
        }
        return ['teams' => $teams, 'results' => $results];
    }
    if ($tournament['type'] === 'double') {
        $round = [];
        foreach ($teams as $_) {
            $round[] = [null, null];
        }
        $winners = [$round];
        $losers = [$round];
        $finals = [[null, null], [null, null]];
        return ['teams' => $teams, 'results' => [$winners, $losers, [$finals]]];
    }
    if ($tournament['type'] === 'round-robin') {
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
    return [];
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

function record_match_result(int $tournamentId, int $matchId, int $score1, int $score2, ?int $winnerId): void
{
    $stmt = db()->prepare('UPDATE tournament_matches SET score1 = :s1, score2 = :s2, winner_user_id = :winner WHERE id = :id AND tournament_id = :tid');
    $stmt->execute([
        ':s1' => $score1,
        ':s2' => $score2,
        ':winner' => $winnerId,
        ':id' => $matchId,
        ':tid' => $tournamentId,
    ]);
    $match = db()->prepare('SELECT player1_user_id, player2_user_id FROM tournament_matches WHERE id = :id AND tournament_id = :tid');
    $match->execute([':id' => $matchId, ':tid' => $tournamentId]);
    $players = $match->fetch();
    $updated = [];
    if ($players) {
        foreach (['player1_user_id', 'player2_user_id'] as $key) {
            if (!empty($players[$key])) {
                $userId = (int)$players[$key];
                update_user_stat($userId);
                $updated[] = $userId;
            }
        }
    }
    if ($winnerId && !in_array($winnerId, $updated, true)) {
        update_user_stat($winnerId);
    }
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
