<?php
require_login();
$user = current_user();
$pageTitle = 'Dashboard';
require __DIR__ . '/../../templates/header.php';

$myTournaments = user_tournaments($user['id']);
$stats = get_user_stat($user['id']);
$recent = recent_results($user['id']);
$openTournaments = list_tournaments('open');
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
    <h2>Open Tournaments</h2>
    <?php if ($openTournaments): ?>
        <ul class="tournament-list">
            <?php foreach ($openTournaments as $tournament): ?>
                <li>
                    <div>
                        <strong><?= sanitize($tournament['name']) ?></strong>
                        <span class="type">(<?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?>)</span>
                        <p class="description"><?= nl2br(sanitize($tournament['description'] ?? '')) ?></p>
                    </div>
                    <div class="actions">
                        <?php if (is_user_registered($tournament['id'], $user['id'])): ?>
                            <form method="post" action="/api/tournaments.php">
                                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="withdraw">
                                <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                                <button type="submit">Withdraw</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/api/tournaments.php">
                                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="register">
                                <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                                <button type="submit">Join Tournament</button>
                            </form>
                        <?php endif; ?>
                        <a href="/?page=tournament&id=<?= (int)$tournament['id'] ?>">View Details</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No tournaments are open for registration right now.</p>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Your Tournaments</h2>
    <?php if ($myTournaments): ?>
        <?php foreach ($myTournaments as $tournament): ?>
            <?php $userBracket = tournament_bracket_snapshot($tournament); ?>
            <div class="tournament-view">
                <h3><?= sanitize($tournament['name']) ?> (<?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?>)</h3>
                <p>Status: <?= sanitize(ucfirst($tournament['status'])) ?></p>
                <?php if ($tournament['type'] === 'round-robin' && $tournament['groups_json']): ?>
                    <div class="group-container" data-group='<?= sanitize($tournament['groups_json']) ?>' data-mode="user"></div>
                <?php elseif ($userBracket): ?>
                    <div
                        class="bracket-container"
                        data-bracket='<?= sanitize($userBracket) ?>'
                        data-mode="user"
                        data-tournament-id="<?= (int)$tournament['id'] ?>"
                        <?= in_array($tournament['status'], ['live'], true) ? 'data-live="1"' : '' ?>
                    ></div>
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
