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

function stats_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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

    $nameKeys = ['username', 'name'];
    foreach ($nameKeys as $nameKey) {
        if (!empty($meta[$nameKey]) && is_string($meta[$nameKey])) {
            $resolved = stats_lookup_user_id_by_name($meta[$nameKey]);
            if ($resolved) {
                return $resolved;
            }
        }
    }

    return null;
}

function stats_resolve_username(int $userId): ?string
{
    try {
        $user = get_user_by_id($userId);
        if ($user && isset($user['username']) && $user['username'] !== '') {
            return (string)$user['username'];
        }
    } catch (Throwable $e) {
        error_log('Failed to resolve username for stats: ' . $e->getMessage());
    }

    return null;
}

function stats_build_user_match_filter(int $userId): array
{
    $clauses = [
        'tm.player1_user_id = :user_id',
        'tm.player2_user_id = :user_id',
        'tm.winner_user_id = :user_id',
    ];

    $bindings = [
        [
            'name' => ':user_id',
            'value' => $userId,
            'type' => PDO::PARAM_INT,
        ],
    ];

    $userIdStr = (string)$userId;
    $quote = chr(34);

    $likeEscape = " ESCAPE '\\\\'";

    if ($userId > 0) {
        $metaKeys = ['id', 'user_id', 'player_id'];
        foreach ($metaKeys as $key) {
            $placeholderBase = ':meta_' . str_replace('_', '', $key);
            $placeholderInt = $placeholderBase . '_int';
            $placeholderStr = $placeholderBase . '_str';

            $clauses[] = '(tm.meta IS NOT NULL AND CAST(tm.meta AS CHAR) LIKE ' . $placeholderInt . $likeEscape . ')';
            $bindings[] = [
                'name' => $placeholderInt,
                'value' => '%' . stats_escape_like($quote . $key . $quote . ':' . $userIdStr) . '%',
                'type' => PDO::PARAM_STR,
            ];

            $clauses[] = '(tm.meta IS NOT NULL AND CAST(tm.meta AS CHAR) LIKE ' . $placeholderStr . $likeEscape . ')';
            $bindings[] = [
                'name' => $placeholderStr,
                'value' => '%' . stats_escape_like($quote . $key . $quote . ':' . $quote . $userIdStr . $quote) . '%',
                'type' => PDO::PARAM_STR,
            ];
        }
    }

    $username = stats_resolve_username($userId);
    if ($username !== null) {
        $usernameLower = strtolower($username);
        $clauses[] = '(tm.meta IS NOT NULL AND LOWER(CAST(tm.meta AS CHAR)) LIKE :meta_username' . $likeEscape . ')';
        $bindings[] = [
            'name' => ':meta_username',
            'value' => '%' . stats_escape_like($quote . 'username' . $quote . ':' . $quote . $usernameLower . $quote) . '%',
            'type' => PDO::PARAM_STR,
        ];

        $clauses[] = '(tm.meta IS NOT NULL AND LOWER(CAST(tm.meta AS CHAR)) LIKE :meta_name' . $likeEscape . ')';
        $bindings[] = [
            'name' => ':meta_name',
            'value' => '%' . stats_escape_like($quote . 'name' . $quote . ':' . $quote . $usernameLower . $quote) . '%',
            'type' => PDO::PARAM_STR,
        ];
    }

    return [
        'condition' => '(' . implode(' OR ', $clauses) . ')',
        'bindings' => $bindings,
    ];
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

        $tournamentStatuses = [];
        foreach ($tournaments as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $tournamentId = (int)$row['id'];
            if ($tournamentId <= 0) {
                continue;
            }
            $tournamentStatuses[$tournamentId] = strtolower((string)($row['status'] ?? ''));
        }

        $matches = [];
        $matchMap = [];

        $filter = stats_build_user_match_filter($userId);
        $matchSql = 'SELECT tm.*, t.status AS tournament_status
                     FROM tournament_matches tm
                     INNER JOIN tournaments t ON tm.tournament_id = t.id
                     WHERE ' . $filter['condition'] . '
                     ORDER BY tm.id ASC';
        $matchesStmt = $pdo->prepare($matchSql);
        foreach ($filter['bindings'] as $binding) {
            $type = $binding['type'] ?? PDO::PARAM_STR;
            $matchesStmt->bindValue($binding['name'], $binding['value'], $type);
        }
        $matchesStmt->execute();
        foreach ($matchesStmt->fetchAll() as $match) {
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
                if ($tournamentId > 0 && !isset($tournamentStatuses[$tournamentId]) && isset($match['tournament_status'])) {
                    $tournamentStatuses[$tournamentId] = strtolower((string)$match['tournament_status']);
                }
            }
        }

        if ($matchMap) {
            ksort($matchMap);
            $matches = array_values($matchMap);
        }

        foreach ($tournamentStatuses as $status) {
            if ($status === 'completed') {
                $snapshot['tournaments_completed']++;
            } elseif (in_array($status, ['open', 'live'], true)) {
                $snapshot['tournaments_active']++;
            }
        }

        $snapshot['tournaments_played'] = count($tournamentStatuses);

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
            $meta = [];
            if (array_key_exists('meta', $match)) {
                $meta = stats_decode_match_meta($match['meta']);
            }

            $player1Id = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : 0;
            if ($player1Id <= 0) {
                $player1Id = stats_meta_player_id(isset($meta['player1']) && is_array($meta['player1']) ? $meta['player1'] : null) ?? 0;
            }

            $player2Id = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : 0;
            if ($player2Id <= 0) {
                $player2Id = stats_meta_player_id(isset($meta['player2']) && is_array($meta['player2']) ? $meta['player2'] : null) ?? 0;
            }

            if ($player1Id !== $userId && $player2Id !== $userId) {
                continue;
            }

            $winnerId = isset($match['winner_user_id']) ? (int)$match['winner_user_id'] : null;
            if ($winnerId === null && isset($meta['winner']) && is_array($meta['winner'])) {
                $winnerId = stats_meta_player_id($meta['winner']);
                if ($winnerId === null && isset($meta['winner']['slot'])) {
                    $slot = (int)$meta['winner']['slot'];
                    if ($slot === 1 && $player1Id > 0) {
                        $winnerId = $player1Id;
                    } elseif ($slot === 2 && $player2Id > 0) {
                        $winnerId = $player2Id;
                    }
                }
            }

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
    if ($limit <= 0) {
        return [];
    }

    $filter = stats_build_user_match_filter($userId);
    $sql = 'SELECT tm.*, t.name as tournament_name
            FROM tournament_matches tm
            INNER JOIN tournaments t ON tm.tournament_id = t.id
            WHERE ' . $filter['condition'] . '
            ORDER BY tm.id DESC
            LIMIT :limit';
    $stmt = db()->prepare($sql);
    foreach ($filter['bindings'] as $binding) {
        $type = $binding['type'] ?? PDO::PARAM_STR;
        $stmt->bindValue($binding['name'], $binding['value'], $type);
    }
    $fetchLimit = max($limit * 5, $limit + 5);
    $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
        if (!isset($row['id'])) {
            continue;
        }
        $matchId = (int)$row['id'];
        if ($matchId <= 0 || isset($seen[$matchId])) {
            continue;
        }
        $seen[$matchId] = true;
        $unique[] = $row;
    }

    if (!$unique) {
        return [];
    }

    $filtered = [];
    foreach ($unique as $row) {
        $meta = [];
        if (array_key_exists('meta', $row)) {
            $meta = stats_decode_match_meta($row['meta']);
        }

        $player1Id = isset($row['player1_user_id']) ? (int)$row['player1_user_id'] : 0;
        if ($player1Id <= 0 && isset($meta['player1']) && is_array($meta['player1'])) {
            $player1Id = stats_meta_player_id($meta['player1']) ?? 0;
        }

        $player2Id = isset($row['player2_user_id']) ? (int)$row['player2_user_id'] : 0;
        if ($player2Id <= 0 && isset($meta['player2']) && is_array($meta['player2'])) {
            $player2Id = stats_meta_player_id($meta['player2']) ?? 0;
        }

        if ($player1Id !== $userId && $player2Id !== $userId) {
            continue;
        }

        $filtered[] = $row;

        if (count($filtered) >= $limit) {
            break;
        }
    }

    return $filtered;
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
