<?php
require_login();
$user = current_user();
$pageTitle = 'Dashboard';
require __DIR__ . '/../../templates/header.php';

$myTournaments = user_tournaments($user['id']);
$stats = get_user_stat($user['id']);
$recent = recent_results($user['id']);
?>
<div class="dashboard-grid">
    <div class="card">
        <h2>Your Stats</h2>
        <?php if ($stats): ?>
            <ul>
                <li>Tournaments Played: <?= (int)$stats['tournaments_played'] ?></li>
                <li>Wins: <?= (int)$stats['wins'] ?></li>
                <li>Losses: <?= (int)$stats['losses'] ?></li>
                <li>Win Rate: <?= number_format($stats['win_rate'], 2) ?>%</li>
            </ul>
        <?php else: ?>
            <p>No stats yet. Join a tournament!</p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Recent Matches</h2>
        <?php if ($recent): ?>
            <ul>
                <?php foreach ($recent as $match): ?>
                    <li><?= sanitize($match['tournament_name']) ?> &mdash; <?= (int)$match['score1'] ?> : <?= (int)$match['score2'] ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No matches recorded.</p>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <h2>Your Tournaments</h2>
    <?php if ($myTournaments): ?>
        <?php foreach ($myTournaments as $tournament): ?>
            <div class="tournament-view">
                <h3><?= sanitize($tournament['name']) ?> (<?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?>)</h3>
                <p>Status: <?= sanitize(ucfirst($tournament['status'])) ?></p>
                <?php if ($tournament['type'] === 'round-robin' && $tournament['groups_json']): ?>
                    <div class="group-container" data-group='<?= sanitize($tournament['groups_json']) ?>' data-mode="user"></div>
                <?php elseif ($tournament['bracket_json']): ?>
                    <div class="bracket-container" data-bracket='<?= sanitize($tournament['bracket_json']) ?>' data-mode="user"></div>
                <?php else: ?>
                    <p>Bracket not generated yet.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>You have not joined any tournaments yet.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
