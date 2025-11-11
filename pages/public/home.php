<?php
$pageTitle = 'Home';
require __DIR__ . '/../../templates/header.php';

$upcoming = list_tournaments('open');
?>
<div class="card">
    <h2>Upcoming Tournaments</h2>
    <?php if ($upcoming): ?>
        <ul>
            <?php foreach ($upcoming as $tournament): ?>
                <li>
                    <strong><?= sanitize($tournament['name']) ?></strong>
                    (<?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?>)
                    <a href="/?page=tournaments">View details</a>
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
        <p><a class="btn" href="/?page=register">Create an account</a> or <a href="/?page=login">log in</a>.</p>
    <?php else: ?>
        <p><a class="btn" href="/?page=dashboard">Go to your dashboard</a></p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
