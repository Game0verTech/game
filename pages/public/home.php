<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/../../templates/header.php';

$upcoming = list_tournaments('open');
?>
<div class="card">
    <h2>Upcoming Tournaments</h2>
    <?php if ($upcoming): ?>
        <ul class="public-tournament-list">
            <?php foreach ($upcoming as $tournament): ?>
                <?php
                    $scheduledAt = $tournament['scheduled_at'] ? date('F j, Y \a\t g:i A', strtotime($tournament['scheduled_at'])) : 'Schedule TBD';
                    $location = $tournament['location'] ?: default_tournament_location();
                ?>
                <li>
                    <strong><?= sanitize($tournament['name']) ?></strong>
                    <span class="muted">&middot; <?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?></span>
                    <div class="muted"><?= sanitize($scheduledAt) ?> &middot; <?= sanitize($location) ?></div>
                    <div class="muted">Sign in to register from your dashboard.</div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No open tournaments right now. Check back soon!</p>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Play for Purpose Ohio</h2>
    <p>Join our community and compete in Single Elimination, Double Elimination, and Round-Robin tournaments. Track your stats and climb the leaderboard.</p>
    <?php if (!current_user()): ?>
        <p><a class="btn" href="/?page=register">Create an account</a> or <a href="/?page=login">log in</a> to see your personalized dashboard.</p>
    <?php else: ?>
        <p><a class="btn" href="/?page=dashboard">Go to your dashboard</a></p>
    <?php endif; ?>
    <p class="muted">After signing in you can explore the full events calendar and live brackets.</p>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
