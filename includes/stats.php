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

function stats_normalize_match_context(array $match): array
{
    $meta = [];
    if (array_key_exists('meta', $match)) {
        $meta = stats_decode_match_meta($match['meta']);
    }

    $player1Meta = isset($meta['player1']) && is_array($meta['player1']) ? $meta['player1'] : null;
    $player2Meta = isset($meta['player2']) && is_array($meta['player2']) ? $meta['player2'] : null;

    $player1Id = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : 0;
    if ($player1Id <= 0) {
        $player1Id = stats_meta_player_id($player1Meta) ?? 0;
    }

    $player2Id = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : 0;
    if ($player2Id <= 0) {
        $player2Id = stats_meta_player_id($player2Meta) ?? 0;
    }

    $winnerId = isset($match['winner_user_id']) ? (int)$match['winner_user_id'] : 0;
    $winnerMeta = isset($meta['winner']) && is_array($meta['winner']) ? $meta['winner'] : null;
    if ($winnerId <= 0 && $winnerMeta) {
        $winnerId = stats_meta_player_id($winnerMeta) ?? 0;
        if ($winnerId <= 0 && isset($winnerMeta['slot'])) {
            $slot = (int)$winnerMeta['slot'];
            if ($slot === 1 && $player1Id > 0) {
                $winnerId = $player1Id;
            } elseif ($slot === 2 && $player2Id > 0) {
                $winnerId = $player2Id;
            }
        }
    }

    return [
        'meta' => $meta,
        'player1' => [
            'id' => $player1Id > 0 ? $player1Id : null,
            'name' => stats_meta_player_name($player1Meta),
        ],
        'player2' => [
            'id' => $player2Id > 0 ? $player2Id : null,
            'name' => stats_meta_player_name($player2Meta),
        ],
        'winner_id' => $winnerId > 0 ? $winnerId : null,
    ];
}

function calculate_user_stat_snapshot(int $userId): ?array
{
    try {
        $pdo = db();

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

        $tournamentStmt = $pdo->prepare(
            'SELECT t.id, t.status
             FROM tournaments t
             INNER JOIN tournament_players tp ON t.id = tp.tournament_id
             WHERE tp.user_id = :user'
        );
        $tournamentStmt->execute([':user' => $userId]);
        $statuses = [];
        foreach ($tournamentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tournamentId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($tournamentId <= 0) {
                continue;
            }
            $statuses[$tournamentId] = strtolower((string)($row['status'] ?? ''));
        }

        $matchStmt = $pdo->prepare(
            'SELECT DISTINCT tm.id, tm.tournament_id, tm.stage, tm.round, tm.match_index, tm.player1_user_id, tm.player2_user_id, tm.score1, tm.score2, tm.winner_user_id, tm.meta, t.status AS tournament_status
             FROM tournament_matches tm
             INNER JOIN tournaments t ON tm.tournament_id = t.id
             LEFT JOIN tournament_players tp ON tp.tournament_id = tm.tournament_id AND tp.user_id = :user
             WHERE (
                tp.user_id IS NOT NULL
                OR tm.player1_user_id = :user
                OR tm.player2_user_id = :user
                OR tm.winner_user_id = :user
             )
             ORDER BY tm.id ASC'
        );
        $matchStmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $matchStmt->execute();
        $matchRows = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

        $matches = [];
        $matchMap = [];

        foreach ($matchRows as $match) {
            if (!isset($match['id'])) {
                continue;
            }
            $matchId = (int)$match['id'];
            if ($matchId <= 0) {
                continue;
            }
            $matchMap[$matchId] = $match;

            if (isset($match['tournament_id'])) {
                $tournamentId = (int)$match['tournament_id'];
                if ($tournamentId > 0 && !isset($statuses[$tournamentId]) && isset($match['tournament_status'])) {
                    $statuses[$tournamentId] = strtolower((string)$match['tournament_status']);
                }
            }
        }

        if ($matchMap) {
            ksort($matchMap);
            $matches = array_values($matchMap);
        }

        $snapshot['tournaments_played'] = count($statuses);

        $snapshot['tournaments_completed'] = 0;
        $snapshot['tournaments_active'] = 0;
        foreach ($statuses as $status) {
            if ($status === 'completed') {
                $snapshot['tournaments_completed']++;
            } elseif (in_array($status, ['open', 'live'], true)) {
                $snapshot['tournaments_active']++;
            }
        }

        $wins = 0;
        $losses = 0;
        $matchesPlayed = 0;
        $pendingMatches = 0;
        $currentStreakType = null;
        $currentStreakLength = 0;
        $currentWinStreak = 0;
        $bestWinStreak = 0;
        $recentForm = [];

        foreach ($matches as $match) {
            $context = stats_normalize_match_context($match);
            $player1Id = $context['player1']['id'];
            $player2Id = $context['player2']['id'];

            if ($player1Id !== $userId && $player2Id !== $userId) {
                continue;
            }

            $winnerId = $context['winner_id'];
            if ($winnerId === null) {
                $pendingMatches++;
                continue;
            }

            $matchesPlayed++;
            $isWin = $winnerId === $userId;
            $recentForm[] = $isWin ? 'W' : 'L';

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
        $snapshot['recent_form'] = array_slice($recentForm, -10);

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
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT tm.id, tm.tournament_id, tm.stage, tm.round, tm.match_index, tm.player1_user_id, tm.player2_user_id, tm.winner_user_id, tm.meta, t.name AS tournament_name
             FROM tournament_matches tm
             INNER JOIN tournaments t ON tm.tournament_id = t.id
             LEFT JOIN tournament_players tp ON tp.tournament_id = tm.tournament_id AND tp.user_id = :user
             WHERE (
                tp.user_id IS NOT NULL
                OR tm.player1_user_id = :user
                OR tm.player2_user_id = :user
                OR tm.winner_user_id = :user
             )
             ORDER BY tm.id DESC
             LIMIT :limit'
        );
        $fetchLimit = max($limit * 3, $limit + 5);
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to load recent results for user ' . $userId . ': ' . $e->getMessage());
        return [];
    }

    if (!$rows) {
        return [];
    }

    $seenMatches = [];
    $opponentCache = [];
    $results = [];

    foreach ($rows as $row) {
        $matchId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($matchId <= 0 || isset($seenMatches[$matchId])) {
            continue;
        }

        $context = stats_normalize_match_context($row);
        $player1 = $context['player1'];
        $player2 = $context['player2'];

        $isParticipant = ($player1['id'] !== null && $player1['id'] === $userId)
            || ($player2['id'] !== null && $player2['id'] === $userId);

        if (!$isParticipant) {
            continue;
        }

        $seenMatches[$matchId] = true;

        $isPlayerOne = $player1['id'] === $userId;
        $opponentMetaName = $isPlayerOne ? $player2['name'] : $player1['name'];
        $opponentId = $isPlayerOne ? $player2['id'] : $player1['id'];
        $opponentName = $opponentMetaName ?: 'TBD';

        if ($opponentId !== null) {
            if (!array_key_exists($opponentId, $opponentCache)) {
                try {
                    $opponent = get_user_by_id($opponentId);
                    $opponentCache[$opponentId] = $opponent && !empty($opponent['username'])
                        ? (string)$opponent['username']
                        : null;
                } catch (Throwable $e) {
                    error_log('Failed to resolve opponent for match ' . $matchId . ': ' . $e->getMessage());
                    $opponentCache[$opponentId] = null;
                }
            }

            if (!empty($opponentCache[$opponentId])) {
                $opponentName = $opponentCache[$opponentId];
            }
        }

        $winnerId = $context['winner_id'];
        $isFinished = $winnerId !== null;
        $isWinner = $isFinished && $winnerId === $userId;

        $results[] = [
            'id' => $matchId,
            'tournament_id' => isset($row['tournament_id']) ? (int)$row['tournament_id'] : null,
            'tournament' => $row['tournament_name'] ?? 'Tournament',
            'opponent_id' => $opponentId,
            'opponent' => $opponentName,
            'result' => $isFinished ? ($isWinner ? 'win' : 'loss') : 'pending',
            'is_winner' => $isWinner,
            'score_for' => $isFinished ? ($isWinner ? 1 : 0) : null,
            'score_against' => $isFinished ? ($isWinner ? 0 : 1) : null,
            'stage' => $row['stage'] ?? null,
            'round' => isset($row['round']) ? (int)$row['round'] : null,
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
