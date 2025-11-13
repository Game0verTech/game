<?php
$pageTitle = 'Tournament Details';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tournament = $id ? get_tournament($id) : null;
$user = current_user();

if (!$tournament) {
    require __DIR__ . '/../../templates/header.php';
    echo '<div class="card"><p>Tournament not found.</p></div>';
    require __DIR__ . '/../../templates/footer.php';
    return;
}

$pageTitle = $tournament['name'];
require __DIR__ . '/../../templates/header.php';

$players = tournament_players($tournament['id']);
$isRegistered = $user ? is_user_registered($tournament['id'], $user['id']) : false;
$canRegister = $tournament['status'] === 'open';
$bracketData = tournament_bracket_snapshot($tournament);
$groupData = $tournament['groups_json'];
if (!$groupData && $tournament['type'] === 'round-robin' && $tournament['status'] === 'live') {
    $groupData = json_encode(generate_bracket_structure($tournament['id']));
}
?>
<div class="card">
    <h2><?= sanitize($tournament['name']) ?></h2>
    <p>
        Type: <?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?>
        &middot; Status: <?= sanitize(ucfirst($tournament['status'])) ?>
    </p>
    <?php if (!empty($tournament['description'])): ?>
        <p><?= nl2br(sanitize($tournament['description'])) ?></p>
    <?php endif; ?>
    <div class="actions">
        <?php if ($user): ?>
            <?php if ($canRegister): ?>
                <?php if ($isRegistered): ?>
                    <form method="post" action="/api/tournaments.php" class="inline">
                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="withdraw">
                        <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                        <button type="submit">Withdraw</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/api/tournaments.php" class="inline">
                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                        <button type="submit">Join Tournament</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">Registration is closed for this tournament.</p>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($canRegister): ?>
                <a class="btn" href="/?page=login">Login to register</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php if ($tournament['type'] === 'round-robin'): ?>
    <div class="card">
        <h3>Groups</h3>
        <?php if ($groupData): ?>
            <div class="group-container" data-group='<?= sanitize($groupData) ?>' data-mode="user"></div>
        <?php else: ?>
            <p class="muted">Groups will appear once the tournament is live.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <h3>Bracket</h3>
        <?php if ($bracketData): ?>
            <div
                class="bracket-container"
                data-bracket='<?= sanitize($bracketData) ?>'
                data-mode="user"
                data-tournament-id="<?= (int)$tournament['id'] ?>"
                data-status="<?= sanitize($tournament['status']) ?>"
                <?= in_array($tournament['status'], ['open', 'live'], true) ? 'data-live="1"' : '' ?>
            ></div>
        <?php else: ?>
            <p class="muted">The bracket will be displayed after the tournament starts.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="card">
    <h3>Participants (<?= count($players) ?>)</h3>
    <?php if ($players): ?>
        <ul>
            <?php foreach ($players as $player): ?>
                <li><?= sanitize($player['username']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No players have joined yet.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
