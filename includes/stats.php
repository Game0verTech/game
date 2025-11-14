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
            'SELECT t.status
             FROM tournaments t
             INNER JOIN tournament_players tp ON t.id = tp.tournament_id
             WHERE tp.user_id = :user'
        );
        $tournamentStmt->execute([':user' => $userId]);
        $statuses = $tournamentStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($statuses as $status) {
            $normalized = strtolower((string)$status);
            if ($normalized === 'completed') {
                $snapshot['tournaments_completed']++;
            } elseif (in_array($normalized, ['open', 'live'], true)) {
                $snapshot['tournaments_active']++;
            }
        }

        $snapshot['tournaments_played'] = count($statuses);

        $matchStmt = $pdo->prepare(
            'SELECT id, tournament_id, stage, round, winner_user_id, player1_user_id, player2_user_id
             FROM tournament_matches
             WHERE player1_user_id = :user OR player2_user_id = :user
             ORDER BY id ASC'
        );
        $matchStmt->execute([':user' => $userId]);
        $matches = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

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
            $winnerId = isset($match['winner_user_id']) ? (int)$match['winner_user_id'] : null;
            if ($winnerId === null || $winnerId === 0) {
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
            'SELECT tm.id, tm.tournament_id, tm.stage, tm.round, tm.player1_user_id, tm.player2_user_id, tm.winner_user_id, t.name AS tournament_name
             FROM tournament_matches tm
             INNER JOIN tournaments t ON tm.tournament_id = t.id
             WHERE tm.player1_user_id = :user OR tm.player2_user_id = :user
             ORDER BY tm.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to load recent results for user ' . $userId . ': ' . $e->getMessage());
        return [];
    }

    if (!$rows) {
        return [];
    }

    $opponentCache = [];
    $results = [];

    foreach ($rows as $row) {
        $player1Id = isset($row['player1_user_id']) ? (int)$row['player1_user_id'] : 0;
        $player2Id = isset($row['player2_user_id']) ? (int)$row['player2_user_id'] : 0;

        if ($player1Id !== $userId && $player2Id !== $userId) {
            continue;
        }

        $opponentId = $player1Id === $userId ? $player2Id : $player1Id;
        $opponentName = 'TBD';

        if ($opponentId > 0) {
            if (!array_key_exists($opponentId, $opponentCache)) {
                try {
                    $opponent = get_user_by_id($opponentId);
                    $opponentCache[$opponentId] = $opponent && isset($opponent['username']) && $opponent['username'] !== ''
                        ? (string)$opponent['username']
                        : null;
                } catch (Throwable $e) {
                    error_log('Failed to resolve opponent for match ' . ($row['id'] ?? 'unknown') . ': ' . $e->getMessage());
                    $opponentCache[$opponentId] = null;
                }
            }

            if (!empty($opponentCache[$opponentId])) {
                $opponentName = $opponentCache[$opponentId];
            }
        }

        $winnerId = isset($row['winner_user_id']) ? (int)$row['winner_user_id'] : 0;
        $isWinner = $winnerId > 0 && $winnerId === $userId;
        $isFinished = $winnerId > 0;

        $results[] = [
            'id' => (int)$row['id'],
            'tournament_id' => isset($row['tournament_id']) ? (int)$row['tournament_id'] : null,
            'tournament' => $row['tournament_name'] ?? 'Tournament',
            'opponent_id' => $opponentId > 0 ? $opponentId : null,
            'opponent' => $opponentName,
            'result' => $isFinished ? ($isWinner ? 'win' : 'loss') : 'pending',
            'is_winner' => $isWinner,
            'score_for' => $isFinished ? ($isWinner ? 1 : 0) : null,
            'score_against' => $isFinished ? ($isWinner ? 0 : 1) : null,
            'stage' => $row['stage'] ?? null,
            'round' => isset($row['round']) ? (int)$row['round'] : null,
        ];
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
