<?php

function ensure_user_stats_table(): bool
{
    static $ensured;

    if ($ensured !== null) {
        return $ensured;
    }

    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_stats (
                user_id INT NOT NULL,
                tournaments_played INT NOT NULL DEFAULT 0,
                wins INT NOT NULL DEFAULT 0,
                losses INT NOT NULL DEFAULT 0,
                win_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_user_stats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ensured = true;
    } catch (Throwable $e) {
        error_log('Failed to ensure user_stats table: ' . $e->getMessage());
        $ensured = false;
    }

    return $ensured;
}

function stats_decode_match_meta($meta): array
{
    if (function_exists('decode_match_meta')) {
        return decode_match_meta($meta);
    }

    if (is_array($meta)) {
        return $meta;
    }

    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function stats_lookup_user_id_by_name(string $name): ?int
{
    static $cache = [];

    $normalized = strtolower(trim($name));
    if ($normalized === '' || $normalized === 'bye' || $normalized === 'tbd') {
        return null;
    }

    if (array_key_exists($normalized, $cache)) {
        return $cache[$normalized];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $name]);
        $row = $stmt->fetch();

        if (!$row) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
            $stmt->execute([':username' => $name]);
            $row = $stmt->fetch();
        }

        $cache[$normalized] = $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        error_log('Failed to lookup user id for stats: ' . $e->getMessage());
        $cache[$normalized] = null;
    }

    return $cache[$normalized];
}

function stats_meta_player_id(?array $meta): ?int
{
    if (!$meta) {
        return null;
    }

    foreach (['id', 'user_id', 'player_id'] as $key) {
        if (isset($meta[$key]) && is_numeric($meta[$key])) {
            $id = (int)$meta[$key];
            if ($id > 0) {
                return $id;
            }
        }
    }

    foreach (['username', 'name'] as $nameKey) {
        if (!empty($meta[$nameKey]) && is_string($meta[$nameKey])) {
            $resolved = stats_lookup_user_id_by_name($meta[$nameKey]);
            if ($resolved) {
                return $resolved;
            }
        }
    }

    return null;
}

function stats_meta_player_name(?array $meta): ?string
{
    if (!$meta) {
        return null;
    }

    foreach (['display_name', 'name', 'username', 'team', 'label'] as $key) {
        if (!empty($meta[$key]) && is_string($meta[$key])) {
            $value = trim((string)$meta[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    if (!empty($meta['seed']) && is_scalar($meta['seed'])) {
        return 'Seed ' . $meta['seed'];
    }

    return null;
}

function stats_build_tournament_context(int $tournamentId): array
{
    static $cache = [];

    if (isset($cache[$tournamentId])) {
        return $cache[$tournamentId];
    }

    $teamIndexMap = [];
    $usernamesById = [];
    $usernameLookup = [];

    try {
        $players = tournament_players($tournamentId);
    } catch (Throwable $e) {
        error_log('Failed to load tournament players for stats context: ' . $e->getMessage());
        $players = [];
    }

    $index = 0;
    foreach ($players as $player) {
        if (!isset($player['user_id'])) {
            $index++;
            continue;
        }

        $userId = (int)$player['user_id'];
        if ($userId <= 0) {
            $index++;
            continue;
        }

        $teamIndexMap[$index] = $userId;
        if (!empty($player['username'])) {
            $usernamesById[$userId] = (string)$player['username'];
            $usernameLookup[strtolower((string)$player['username'])] = $userId;
        }

        $index++;
    }

    return $cache[$tournamentId] = [
        'team_index_map' => $teamIndexMap,
        'usernames_by_id' => $usernamesById,
        'username_lookup' => $usernameLookup,
    ];
}

function stats_resolve_player_slot(?int $columnId, ?array $meta, array $context, ?string $fallbackName): array
{
    $id = ($columnId && $columnId > 0) ? $columnId : null;
    $name = $fallbackName !== null ? trim((string)$fallbackName) : null;
    $teamIndex = null;

    if ($meta && isset($meta['team_index']) && is_numeric($meta['team_index'])) {
        $teamIndex = (int)$meta['team_index'];
    }

    if ($meta) {
        foreach (['id', 'user_id', 'player_id'] as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                $candidate = (int)$meta[$key];
                if ($candidate > 0) {
                    $id = $id ?? $candidate;
                    break;
                }
            }
        }
    }

    if ($id === null && $teamIndex !== null && isset($context['team_index_map'][$teamIndex])) {
        $id = (int)$context['team_index_map'][$teamIndex];
    }

    if ($id === null && $meta) {
        $metaName = stats_meta_player_name($meta);
        if ($metaName) {
            $normalized = strtolower($metaName);
            if (isset($context['username_lookup'][$normalized])) {
                $id = (int)$context['username_lookup'][$normalized];
            } else {
                $resolved = stats_lookup_user_id_by_name($metaName);
                if ($resolved) {
                    $id = $resolved;
                }
            }
        }
    }

    if ($id === null && $name) {
        $normalized = strtolower($name);
        if (isset($context['username_lookup'][$normalized])) {
            $id = (int)$context['username_lookup'][$normalized];
        } else {
            $resolved = stats_lookup_user_id_by_name($name);
            if ($resolved) {
                $id = $resolved;
            }
        }
    }

    if ($teamIndex === null && $meta && isset($meta['team']) && is_numeric($meta['team'])) {
        $teamIndex = (int)$meta['team'];
    }

    if ($teamIndex === null && $id !== null && !empty($context['team_index_map'])) {
        $foundIndex = array_search($id, $context['team_index_map'], true);
        if ($foundIndex !== false) {
            $teamIndex = (int)$foundIndex;
        }
    }

    if ($name === null && $meta) {
        $metaName = stats_meta_player_name($meta);
        if ($metaName !== null) {
            $name = $metaName;
        }
    }

    if ($name === null && $id !== null && isset($context['usernames_by_id'][$id])) {
        $name = $context['usernames_by_id'][$id];
    }

    return [
        'id' => $id,
        'name' => $name,
        'team_index' => $teamIndex,
    ];
}

function stats_normalize_match_context(array $match, array $tournamentContext = []): array
{
    $meta = [];
    if (array_key_exists('meta', $match)) {
        $meta = stats_decode_match_meta($match['meta']);
    }

    $player1Meta = null;
    $player2Meta = null;

    if (isset($meta['player1']) && is_array($meta['player1'])) {
        $player1Meta = $meta['player1'];
    }

    if (isset($meta['player2']) && is_array($meta['player2'])) {
        $player2Meta = $meta['player2'];
    }

    if ($player1Meta === null && !empty($meta['players']) && is_array($meta['players'])) {
        if (isset($meta['players'][1]) && is_array($meta['players'][1])) {
            $player1Meta = $meta['players'][1];
        } elseif (isset($meta['players'][0]) && is_array($meta['players'][0])) {
            $player1Meta = $meta['players'][0];
        }
    }

    if ($player2Meta === null && !empty($meta['players']) && is_array($meta['players'])) {
        if (isset($meta['players'][2]) && is_array($meta['players'][2])) {
            $player2Meta = $meta['players'][2];
        } elseif (isset($meta['players'][1]) && is_array($meta['players'][1])) {
            $player2Meta = $meta['players'][1];
        }
    }

    if ($player1Meta === null && isset($meta['home']) && is_array($meta['home'])) {
        $player1Meta = $meta['home'];
    }

    if ($player2Meta === null && isset($meta['away']) && is_array($meta['away'])) {
        $player2Meta = $meta['away'];
    }

    $player1ColumnId = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : null;
    if ($player1ColumnId !== null && $player1ColumnId <= 0) {
        $player1ColumnId = null;
    }

    $player2ColumnId = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : null;
    if ($player2ColumnId !== null && $player2ColumnId <= 0) {
        $player2ColumnId = null;
    }

    $player1 = stats_resolve_player_slot($player1ColumnId, $player1Meta, $tournamentContext, $match['player1_name'] ?? null);
    $player2 = stats_resolve_player_slot($player2ColumnId, $player2Meta, $tournamentContext, $match['player2_name'] ?? null);

    $score1 = isset($match['score1']) && is_numeric($match['score1']) ? (int)$match['score1'] : null;
    $score2 = isset($match['score2']) && is_numeric($match['score2']) ? (int)$match['score2'] : null;

    $winnerId = isset($match['winner_user_id']) ? (int)$match['winner_user_id'] : 0;
    $winnerId = $winnerId > 0 ? $winnerId : null;
    $winnerSlot = null;

    $winnerMeta = null;
    if (isset($meta['winner']) && is_array($meta['winner'])) {
        $winnerMeta = $meta['winner'];
    } elseif (!empty($meta['winners']) && is_array($meta['winners'])) {
        $firstWinner = reset($meta['winners']);
        if (is_array($firstWinner)) {
            $winnerMeta = $firstWinner;
        }
    }

    if ($winnerMeta) {
        foreach (['id', 'user_id', 'player_id'] as $key) {
            if ($winnerId === null && isset($winnerMeta[$key]) && is_numeric($winnerMeta[$key])) {
                $candidate = (int)$winnerMeta[$key];
                if ($candidate > 0) {
                    $winnerId = $candidate;
                    break;
                }
            }
        }

        if (isset($winnerMeta['slot'])) {
            $slotValue = $winnerMeta['slot'];
            if (is_numeric($slotValue)) {
                $winnerSlot = (int)$slotValue;
            } elseif (is_string($slotValue)) {
                $normalizedSlot = strtolower(trim($slotValue));
                if (in_array($normalizedSlot, ['player1', 'p1', 'home', 'one'], true)) {
                    $winnerSlot = 1;
                } elseif (in_array($normalizedSlot, ['player2', 'p2', 'away', 'two'], true)) {
                    $winnerSlot = 2;
                }
            }
        }

        if ($winnerSlot === null && isset($winnerMeta['team_index']) && is_numeric($winnerMeta['team_index'])) {
            $winnerIndex = (int)$winnerMeta['team_index'];
            if ($player1['team_index'] !== null && $winnerIndex === (int)$player1['team_index']) {
                $winnerSlot = 1;
            } elseif ($player2['team_index'] !== null && $winnerIndex === (int)$player2['team_index']) {
                $winnerSlot = 2;
            }
        }
    }

    if ($winnerSlot === null && !empty($meta['result']) && is_string($meta['result'])) {
        $normalized = strtolower(trim($meta['result']));
        if (in_array($normalized, ['player1', 'p1', 'home', 'one'], true)) {
            $winnerSlot = 1;
        } elseif (in_array($normalized, ['player2', 'p2', 'away', 'two'], true)) {
            $winnerSlot = 2;
        }
    }

    if ($winnerId !== null) {
        if ($player1['id'] !== null && $winnerId === $player1['id']) {
            $winnerSlot = $winnerSlot ?? 1;
        } elseif ($player2['id'] !== null && $winnerId === $player2['id']) {
            $winnerSlot = $winnerSlot ?? 2;
        }
    }

    if ($winnerSlot === null && $score1 !== null && $score2 !== null && $score1 !== $score2) {
        $winnerSlot = $score1 > $score2 ? 1 : 2;
    }

    if ($winnerId === null && $winnerSlot !== null) {
        if ($winnerSlot === 1 && $player1['id'] !== null) {
            $winnerId = $player1['id'];
        } elseif ($winnerSlot === 2 && $player2['id'] !== null) {
            $winnerId = $player2['id'];
        }
    }

    $player1['score'] = $score1;
    $player2['score'] = $score2;

    if ($player1['name'] === null && isset($match['player1_name']) && $match['player1_name'] !== '') {
        $player1['name'] = (string)$match['player1_name'];
    }

    if ($player2['name'] === null && isset($match['player2_name']) && $match['player2_name'] !== '') {
        $player2['name'] = (string)$match['player2_name'];
    }

    return [
        'meta' => $meta,
        'player1' => $player1,
        'player2' => $player2,
        'winner_id' => $winnerId,
        'winner_slot' => $winnerSlot,
    ];
}

function stats_normalize_match_for_user(int $userId, array $match, array $tournament, array $context): ?array
{
    if (!isset($match['id'])) {
        return null;
    }

    $normalized = stats_normalize_match_context($match, $context);
    $player1 = $normalized['player1'];
    $player2 = $normalized['player2'];
    $meta = $normalized['meta'];

    $userSlot = null;
    if ($player1['id'] !== null && $player1['id'] === $userId) {
        $userSlot = 1;
    } elseif ($player2['id'] !== null && $player2['id'] === $userId) {
        $userSlot = 2;
    } else {
        if (!empty($meta['players']) && is_array($meta['players'])) {
            foreach ($meta['players'] as $slot => $details) {
                if (!is_array($details)) {
                    continue;
                }
                $candidate = null;
                foreach (['id', 'user_id', 'player_id'] as $key) {
                    if (isset($details[$key]) && is_numeric($details[$key])) {
                        $candidate = (int)$details[$key];
                        break;
                    }
                }
                if ($candidate !== null && $candidate === $userId) {
                    if (is_numeric($slot)) {
                        $userSlot = (int)$slot === 2 ? 2 : 1;
                    } elseif (is_string($slot)) {
                        $normalizedSlot = strtolower(trim($slot));
                        $userSlot = in_array($normalizedSlot, ['player2', 'p2', 'away', 'two'], true) ? 2 : 1;
                    } else {
                        $userSlot = 1;
                    }
                    break;
                }
            }
        }

        if ($userSlot === null && $normalized['winner_id'] === $userId && $normalized['winner_slot'] !== null) {
            $userSlot = (int)$normalized['winner_slot'];
        }
    }

    if ($userSlot === null) {
        return null;
    }

    $opponent = $userSlot === 1 ? $player2 : $player1;
    $opponentId = $opponent['id'];
    $opponentName = $opponent['name'] ?? null;

    if ($opponentName === null && $opponentId !== null && isset($context['usernames_by_id'][$opponentId])) {
        $opponentName = $context['usernames_by_id'][$opponentId];
    }

    if ($opponentName === null) {
        $opponentName = 'TBD';
    }

    $winnerId = $normalized['winner_id'];
    $winnerSlot = $normalized['winner_slot'];
    $score1 = $player1['score'];
    $score2 = $player2['score'];

    if ($winnerId === null && $winnerSlot !== null) {
        if ($winnerSlot === 1 && $player1['id'] !== null) {
            $winnerId = $player1['id'];
        } elseif ($winnerSlot === 2 && $player2['id'] !== null) {
            $winnerId = $player2['id'];
        }
    }

    $hasScores = $score1 !== null && $score2 !== null;
    $isFinished = false;
    $isWin = false;

    if ($winnerId !== null) {
        $isFinished = true;
        $isWin = $winnerId === $userId;
    } elseif ($winnerSlot !== null) {
        $isFinished = true;
        $isWin = $winnerSlot === $userSlot;
    } elseif ($hasScores && $score1 !== $score2) {
        $isFinished = true;
        if ($userSlot === 1) {
            $isWin = $score1 > $score2;
            $winnerSlot = $score1 > $score2 ? 1 : 2;
        } else {
            $isWin = $score2 > $score1;
            $winnerSlot = $score1 > $score2 ? 1 : 2;
        }

        if ($winnerSlot === 1 && $player1['id'] !== null) {
            $winnerId = $player1['id'];
        } elseif ($winnerSlot === 2 && $player2['id'] !== null) {
            $winnerId = $player2['id'];
        }
    }

    $scoreFor = $hasScores ? ($userSlot === 1 ? $score1 : $score2) : null;
    $scoreAgainst = $hasScores ? ($userSlot === 1 ? $score2 : $score1) : null;

    if (!$hasScores && $isFinished) {
        $scoreFor = $isWin ? 1 : 0;
        $scoreAgainst = $isWin ? 0 : 1;
    }

    $tournamentId = isset($tournament['id']) ? (int)$tournament['id'] : null;

    return [
        'id' => (int)$match['id'],
        'tournament_id' => $tournamentId,
        'tournament_name' => $tournament['name'] ?? 'Tournament',
        'tournament_status' => $tournament['status'] ?? '',
        'stage' => $match['stage'] ?? null,
        'round' => isset($match['round']) ? (int)$match['round'] : null,
        'match_index' => isset($match['match_index']) ? (int)$match['match_index'] : null,
        'user_slot' => $userSlot,
        'opponent_id' => $opponentId,
        'opponent_name' => $opponentName,
        'score_for' => $scoreFor,
        'score_against' => $scoreAgainst,
        'raw_score1' => $score1,
        'raw_score2' => $score2,
        'winner_id' => $winnerId,
        'winner_slot' => $winnerSlot,
        'is_finished' => $isFinished,
        'is_win' => $isWin,
        'result' => $isFinished ? ($isWin ? 'win' : 'loss') : 'pending',
        'meta' => $meta,
    ];
}

function stats_user_match_history(int $userId): array
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $history = [
        'tournaments' => [],
        'matches' => [],
    ];

    try {
        $registered = user_tournaments($userId);
    } catch (Throwable $e) {
        error_log('Failed to load user tournaments for stats: ' . $e->getMessage());
        $registered = [];
    }

    foreach ($registered as $tournament) {
        if (!isset($tournament['id'])) {
            continue;
        }
        $tid = (int)$tournament['id'];
        if ($tid <= 0) {
            continue;
        }

        $history['tournaments'][$tid] = [
            'id' => $tid,
            'name' => (string)($tournament['name'] ?? 'Tournament'),
            'status' => strtolower((string)($tournament['status'] ?? '')),
            'type' => $tournament['type'] ?? null,
        ];
    }

    $directMatches = [];
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT tm.*, t.name AS tournament_name, t.status AS tournament_status
             FROM tournament_matches tm
             INNER JOIN tournaments t ON t.id = tm.tournament_id
             WHERE tm.player1_user_id = :user OR tm.player2_user_id = :user OR tm.winner_user_id = :user
             ORDER BY tm.id ASC'
        );
        $stmt->execute([':user' => $userId]);
        $directMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to query tournament matches for stats: ' . $e->getMessage());
        $directMatches = [];
    }

    foreach ($directMatches as $row) {
        $tid = isset($row['tournament_id']) ? (int)$row['tournament_id'] : 0;
        if ($tid <= 0) {
            continue;
        }

        if (!isset($history['tournaments'][$tid])) {
            $history['tournaments'][$tid] = [
                'id' => $tid,
                'name' => (string)($row['tournament_name'] ?? 'Tournament'),
                'status' => strtolower((string)($row['tournament_status'] ?? '')),
                'type' => $row['stage'] ?? null,
            ];
        } else {
            if (empty($history['tournaments'][$tid]['name']) && !empty($row['tournament_name'])) {
                $history['tournaments'][$tid]['name'] = (string)$row['tournament_name'];
            }
            if (empty($history['tournaments'][$tid]['status']) && !empty($row['tournament_status'])) {
                $history['tournaments'][$tid]['status'] = strtolower((string)$row['tournament_status']);
            }
        }
    }

    $matches = [];
    $contextCache = [];

    foreach ($history['tournaments'] as $tid => $info) {
        $contextCache[$tid] = stats_build_tournament_context($tid);
        try {
            $records = tournament_matches($tid);
        } catch (Throwable $e) {
            error_log('Failed to load matches for tournament ' . $tid . ': ' . $e->getMessage());
            $records = [];
        }

        foreach ($records as $record) {
            if (!isset($record['id'])) {
                continue;
            }

            $normalized = stats_normalize_match_for_user($userId, $record, $info, $contextCache[$tid]);
            if (!$normalized) {
                continue;
            }

            $matchId = $normalized['id'];
            if (!isset($matches[$matchId])) {
                $matches[$matchId] = $normalized;
            }
        }
    }

    foreach ($directMatches as $row) {
        $matchId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($matchId <= 0 || isset($matches[$matchId])) {
            continue;
        }

        $tid = isset($row['tournament_id']) ? (int)$row['tournament_id'] : 0;
        if ($tid <= 0) {
            continue;
        }

        if (!isset($history['tournaments'][$tid])) {
            try {
                $tournament = get_tournament($tid);
            } catch (Throwable $e) {
                $tournament = null;
            }

            if ($tournament) {
                $history['tournaments'][$tid] = [
                    'id' => $tid,
                    'name' => (string)($tournament['name'] ?? 'Tournament'),
                    'status' => strtolower((string)($tournament['status'] ?? '')),
                    'type' => $tournament['type'] ?? null,
                ];
            } else {
                $history['tournaments'][$tid] = [
                    'id' => $tid,
                    'name' => (string)($row['tournament_name'] ?? 'Tournament'),
                    'status' => strtolower((string)($row['tournament_status'] ?? '')),
                    'type' => $row['stage'] ?? null,
                ];
            }
        }

        $context = $contextCache[$tid] ?? ($contextCache[$tid] = stats_build_tournament_context($tid));
        $normalized = stats_normalize_match_for_user($userId, $row, $history['tournaments'][$tid], $context);
        if ($normalized) {
            $matches[$matchId] = $normalized;
        }
    }

    ksort($matches);
    $history['matches'] = array_values($matches);

    return $cache[$userId] = $history;
}

function calculate_user_stat_snapshot(int $userId): ?array
{
    try {
        $snapshot = [
            'user_id' => $userId,
            'tournaments_played' => 0,
            'tournaments_completed' => 0,
            'tournaments_active' => 0,
            'wins' => 0,
            'losses' => 0,
            'matches_played' => 0,
            'pending_matches' => 0,
            'win_rate' => 0.0,
            'best_win_streak' => 0,
            'current_streak' => [
                'type' => null,
                'length' => 0,
            ],
            'recent_form' => [],
        ];

        $history = stats_user_match_history($userId);
        $tournaments = $history['tournaments'] ?? [];
        $matches = $history['matches'] ?? [];

        $snapshot['tournaments_played'] = count($tournaments);

        $completed = 0;
        $active = 0;
        foreach ($tournaments as $info) {
            $status = strtolower((string)($info['status'] ?? ''));
            if ($status === 'completed') {
                $completed++;
            } elseif (in_array($status, ['open', 'live'], true)) {
                $active++;
            }
        }
        $snapshot['tournaments_completed'] = $completed;
        $snapshot['tournaments_active'] = $active;

        $wins = 0;
        $losses = 0;
        $matchesPlayed = 0;
        $pendingMatches = 0;
        $currentStreakType = null;
        $currentStreakLength = 0;
        $currentWinStreak = 0;
        $bestWinStreak = 0;
        $recentOutcomes = [];

        foreach ($matches as $match) {
            if (!empty($match['is_finished'])) {
                $matchesPlayed++;
                $isWin = !empty($match['is_win']);
                $recentOutcomes[] = $isWin ? 'W' : 'L';

                if ($isWin) {
                    $wins++;
                    $currentWinStreak++;
                    if ($currentWinStreak > $bestWinStreak) {
                        $bestWinStreak = $currentWinStreak;
                    }
                } else {
                    $losses++;
                    $currentWinStreak = 0;
                }

                $resultType = $isWin ? 'win' : 'loss';
                if ($currentStreakType === $resultType) {
                    $currentStreakLength++;
                } else {
                    $currentStreakType = $resultType;
                    $currentStreakLength = 1;
                }
            } else {
                $pendingMatches++;
            }
        }

        $snapshot['wins'] = $wins;
        $snapshot['losses'] = $losses;
        $snapshot['matches_played'] = $matchesPlayed;
        $snapshot['pending_matches'] = $pendingMatches;
        $snapshot['win_rate'] = $matchesPlayed > 0 ? round(($wins / $matchesPlayed) * 100, 2) : 0.0;
        $snapshot['best_win_streak'] = $bestWinStreak;
        $snapshot['current_streak'] = [
            'type' => $currentStreakType,
            'length' => $currentStreakLength,
        ];
        $snapshot['recent_form'] = array_slice($recentOutcomes, -10);

        try {
            $snapshot['tournaments_won'] = count_user_tournament_titles($userId);
        } catch (Throwable $e) {
            error_log('Failed to count tournament titles for user ' . $userId . ': ' . $e->getMessage());
            $snapshot['tournaments_won'] = 0;
        }

        return $snapshot;
    } catch (Throwable $e) {
        error_log('Failed to calculate stats for user ' . $userId . ': ' . $e->getMessage());
        return null;
    }
}

function persist_user_stat_snapshot(array $snapshot): void
{
    if (!isset($snapshot['user_id'])) {
        return;
    }

    if (!ensure_user_stats_table()) {
        return;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('REPLACE INTO user_stats (user_id, tournaments_played, wins, losses, win_rate, last_updated) VALUES (:user, :played, :wins, :losses, :rate, NOW())');
        $stmt->execute([
            ':user' => $snapshot['user_id'],
            ':played' => $snapshot['tournaments_played'] ?? 0,
            ':wins' => $snapshot['wins'] ?? 0,
            ':losses' => $snapshot['losses'] ?? 0,
            ':rate' => $snapshot['win_rate'] ?? 0,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to persist user stats snapshot for user ' . $snapshot['user_id'] . ': ' . $e->getMessage());
    }
}

function rebuild_user_stats(): void
{
    if (!ensure_user_stats_table()) {
        return;
    }

    $pdo = db();
    $pdo->exec('DELETE FROM user_stats');
    $users = $pdo->query('SELECT id FROM users WHERE is_active = 1 AND is_banned = 0');
    foreach ($users as $user) {
        update_user_stat((int)$user['id']);
    }
}

function update_user_stat(int $userId): void
{
    $snapshot = calculate_user_stat_snapshot($userId);
    if (!$snapshot) {
        return;
    }

    persist_user_stat_snapshot($snapshot);
}

function get_user_stat(int $userId): ?array
{
    $snapshot = calculate_user_stat_snapshot($userId);
    if (!$snapshot) {
        return null;
    }

    persist_user_stat_snapshot($snapshot);

    return $snapshot;
}

function recent_results(int $userId, int $limit = 5): array
{
    if ($limit <= 0) {
        return [];
    }

    try {
        $history = stats_user_match_history($userId);
    } catch (Throwable $e) {
        error_log('Failed to load recent results for user ' . $userId . ': ' . $e->getMessage());
        return [];
    }

    $matches = $history['matches'] ?? [];
    if (!$matches) {
        return [];
    }

    usort($matches, static function (array $a, array $b): int {
        $idA = isset($a['id']) ? (int)$a['id'] : 0;
        $idB = isset($b['id']) ? (int)$b['id'] : 0;
        return $idB <=> $idA;
    });

    $results = [];
    foreach ($matches as $match) {
        $results[] = [
            'id' => $match['id'] ?? null,
            'tournament_id' => $match['tournament_id'] ?? null,
            'tournament' => $match['tournament_name'] ?? 'Tournament',
            'opponent_id' => $match['opponent_id'] ?? null,
            'opponent' => $match['opponent_name'] ?? 'TBD',
            'result' => $match['result'] ?? (!empty($match['is_finished']) ? (!empty($match['is_win']) ? 'win' : 'loss') : 'pending'),
            'is_winner' => !empty($match['is_win']),
            'score_for' => $match['score_for'] ?? null,
            'score_against' => $match['score_against'] ?? null,
            'stage' => $match['stage'] ?? null,
            'round' => $match['round'] ?? null,
        ];

        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function count_user_tournament_titles(int $userId): int
{
    $tournaments = list_tournaments();
    $titles = 0;

    foreach ($tournaments as $tournament) {
        if (($tournament['status'] ?? '') !== 'completed') {
            continue;
        }
        $championId = determine_tournament_champion($tournament);
        if ($championId !== null && $championId === $userId) {
            $titles++;
        }
    }

    return $titles;
}
