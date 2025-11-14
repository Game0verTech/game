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

function is_final_stage_label(?string $stage): bool
{
    if ($stage === null) {
        return false;
    }

    $normalized = strtolower(trim($stage));
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, 'semi') !== false || strpos($normalized, 'quarter') !== false) {
        return false;
    }

    return strpos($normalized, 'final') !== false || strpos($normalized, 'championship') !== false;
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
            'points_for' => 0,
            'points_against' => 0,
            'point_differential' => 0,
            'average_margin' => 0.0,
            'average_points_for' => 0.0,
            'average_points_against' => 0.0,
            'best_win_streak' => 0,
            'current_win_streak' => 0,
            'current_streak' => [
                'type' => null,
                'length' => 0,
            ],
            'recent_form' => [],
            'shutout_wins' => 0,
            'finals_appearances' => 0,
            'runner_up_finishes' => 0,
            'tournaments_won' => 0,
        ];

        $tournamentStmt = $pdo->prepare('SELECT t.id, t.status FROM tournaments t INNER JOIN tournament_players tp ON t.id = tp.tournament_id WHERE tp.user_id = :user');
        $tournamentStmt->execute([':user' => $userId]);
        $tournaments = $tournamentStmt->fetchAll();
        $snapshot['tournaments_played'] = count($tournaments);

        foreach ($tournaments as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if ($status === 'completed') {
                $snapshot['tournaments_completed']++;
            } elseif (in_array($status, ['open', 'live'], true)) {
                $snapshot['tournaments_active']++;
            }
        }

        $matchesStmt = $pdo->prepare(
            'SELECT tm.*
             FROM tournament_matches tm
             WHERE tm.player1_user_id = :user OR tm.player2_user_id = :user
             ORDER BY tm.id ASC'
        );
        $matchesStmt->execute([':user' => $userId]);
        $matches = $matchesStmt->fetchAll();

        $wins = 0;
        $losses = 0;
        $matchesPlayed = 0;
        $pendingMatches = 0;
        $pointsFor = 0;
        $pointsAgainst = 0;
        $shutouts = 0;
        $finals = 0;
        $runnerUps = 0;
        $recentForm = [];
        $currentWinStreak = 0;
        $bestWinStreak = 0;
        $currentResultType = null;
        $currentResultStreak = 0;

        foreach ($matches as $match) {
            $player1Id = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : 0;
            $player2Id = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : 0;

            if ($player1Id !== $userId && $player2Id !== $userId) {
                continue;
            }

            $winnerId = isset($match['winner_user_id']) ? (int)$match['winner_user_id'] : null;
            $isFinalStage = is_final_stage_label($match['stage'] ?? null);

            $scoreFor = null;
            $scoreAgainst = null;

            if ($player1Id === $userId) {
                if (isset($match['score1']) && $match['score1'] !== null && $match['score1'] !== '') {
                    $scoreFor = (int)$match['score1'];
                }
                if (isset($match['score2']) && $match['score2'] !== null && $match['score2'] !== '') {
                    $scoreAgainst = (int)$match['score2'];
                }
            } elseif ($player2Id === $userId) {
                if (isset($match['score2']) && $match['score2'] !== null && $match['score2'] !== '') {
                    $scoreFor = (int)$match['score2'];
                }
                if (isset($match['score1']) && $match['score1'] !== null && $match['score1'] !== '') {
                    $scoreAgainst = (int)$match['score1'];
                }
            }

            if ($winnerId === null) {
                $pendingMatches++;
                continue;
            }

            if ($scoreFor !== null) {
                $pointsFor += $scoreFor;
            }
            if ($scoreAgainst !== null) {
                $pointsAgainst += $scoreAgainst;
            }

            $matchesPlayed++;

            if ($winnerId === $userId) {
                $wins++;
                $recentForm[] = 'W';
                $currentWinStreak++;
                if ($currentWinStreak > $bestWinStreak) {
                    $bestWinStreak = $currentWinStreak;
                }
                if ($scoreAgainst === 0 && $scoreFor !== null) {
                    $shutouts++;
                }
                if ($currentResultType === 'win') {
                    $currentResultStreak++;
                } else {
                    $currentResultType = 'win';
                    $currentResultStreak = 1;
                }
            } else {
                $losses++;
                $recentForm[] = 'L';
                $currentWinStreak = 0;
                if ($currentResultType === 'loss') {
                    $currentResultStreak++;
                } else {
                    $currentResultType = 'loss';
                    $currentResultStreak = 1;
                }
                if ($isFinalStage && $winnerId !== null) {
                    $runnerUps++;
                }
            }

            if ($isFinalStage) {
                $finals++;
            }
        }

        $recentForm = array_slice($recentForm, -10);

        $snapshot['wins'] = $wins;
        $snapshot['losses'] = $losses;
        $snapshot['matches_played'] = $matchesPlayed;
        $snapshot['pending_matches'] = $pendingMatches;
        $snapshot['win_rate'] = $matchesPlayed > 0 ? round(($wins / $matchesPlayed) * 100, 2) : 0.0;
        $snapshot['points_for'] = $pointsFor;
        $snapshot['points_against'] = $pointsAgainst;
        $snapshot['point_differential'] = $pointsFor - $pointsAgainst;
        $snapshot['average_margin'] = $matchesPlayed > 0 ? round(($pointsFor - $pointsAgainst) / $matchesPlayed, 2) : 0.0;
        $snapshot['average_points_for'] = $matchesPlayed > 0 ? round($pointsFor / $matchesPlayed, 2) : 0.0;
        $snapshot['average_points_against'] = $matchesPlayed > 0 ? round($pointsAgainst / $matchesPlayed, 2) : 0.0;
        $snapshot['best_win_streak'] = $bestWinStreak;
        $snapshot['current_win_streak'] = $currentWinStreak;
        $snapshot['current_streak'] = [
            'type' => $currentResultType,
            'length' => $currentResultStreak,
        ];
        $snapshot['recent_form'] = $recentForm;
        $snapshot['shutout_wins'] = $shutouts;
        $snapshot['finals_appearances'] = $finals;
        $snapshot['runner_up_finishes'] = $runnerUps;
        $snapshot['tournaments_won'] = count_user_tournament_titles($userId);

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
    $sql = 'SELECT tm.*, t.name as tournament_name
            FROM tournament_matches tm
            INNER JOIN tournaments t ON tm.tournament_id = t.id
            WHERE (tm.player1_user_id = :user1 OR tm.player2_user_id = :user2)
            ORDER BY tm.id DESC
            LIMIT :limit';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':user1', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':user2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
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
