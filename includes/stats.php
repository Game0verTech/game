<?php

function rebuild_user_stats(): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM user_stats');
    $users = $pdo->query('SELECT id FROM users WHERE is_active = 1 AND is_banned = 0');
    foreach ($users as $user) {
        update_user_stat((int)$user['id']);
    }
}

function update_user_stat(int $userId): void
{
    $pdo = db();
    $tournaments = $pdo->prepare('SELECT COUNT(DISTINCT tournament_id) as cnt FROM tournament_players WHERE user_id = :user');
    $tournaments->execute([':user' => $userId]);
    $played = (int)$tournaments->fetchColumn();

    $wins = $pdo->prepare('SELECT COUNT(*) FROM tournament_matches WHERE winner_user_id = :user');
    $wins->execute([':user' => $userId]);
    $winsCount = (int)$wins->fetchColumn();

    $matches = $pdo->prepare('SELECT COUNT(*) FROM tournament_matches WHERE (player1_user_id = :user OR player2_user_id = :user) AND winner_user_id IS NOT NULL');
    $matches->execute([':user' => $userId]);
    $totalMatches = (int)$matches->fetchColumn();

    $losses = max(0, $totalMatches - $winsCount);
    $winRate = $totalMatches > 0 ? round(($winsCount / $totalMatches) * 100, 2) : 0.0;

    $stmt = $pdo->prepare('REPLACE INTO user_stats (user_id, tournaments_played, wins, losses, win_rate, last_updated) VALUES (:user, :played, :wins, :losses, :rate, NOW())');
    $stmt->execute([
        ':user' => $userId,
        ':played' => $played,
        ':wins' => $winsCount,
        ':losses' => $losses,
        ':rate' => $winRate,
    ]);
}

function get_user_stat(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM user_stats WHERE user_id = :user');
    $stmt->execute([':user' => $userId]);
    $stat = $stmt->fetch();
    if (!$stat) {
        update_user_stat($userId);
        $stmt->execute([':user' => $userId]);
        $stat = $stmt->fetch();
    }
    return $stat ?: null;
}

function recent_results(int $userId, int $limit = 5): array
{
    $sql = 'SELECT tm.*, t.name as tournament_name
            FROM tournament_matches tm
            INNER JOIN tournaments t ON tm.tournament_id = t.id
            WHERE (tm.player1_user_id = :user OR tm.player2_user_id = :user)
            ORDER BY tm.id DESC
            LIMIT :limit';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
