<?php
$pageTitle = 'Tournaments';
require __DIR__ . '/../../templates/header.php';

$open = list_tournaments('open');
$live = list_tournaments('live');
$completed = list_tournaments('completed');
?>
<div class="card">
    <h2>Open Tournaments</h2>
    <?php if ($open): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($open as $tournament): ?>
                    <tr>
                        <td><?= sanitize($tournament['name']) ?></td>
                        <td><?= sanitize(ucwords(str_replace('-', ' ', $tournament['type']))) ?></td>
                        <td><?= nl2br(sanitize($tournament['description'])) ?></td>
                        <td>
                            <?php if (current_user()): ?>
                                <form method="post" action="/api/tournaments.php">
                                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="register">
                                    <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                                    <button type="submit">Join</button>
                                </form>
                            <?php else: ?>
                                <a href="/?page=login">Login to join</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No open tournaments at the moment.</p>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Live Tournaments</h2>
    <?php if ($live): ?>
        <?php foreach ($live as $tournament): ?>
            <div class="tournament-view">
                <h3><?= sanitize($tournament['name']) ?></h3>
                <?php if ($tournament['type'] === 'round-robin' && $tournament['groups_json']): ?>
                    <div class="group-container" data-group='<?= sanitize($tournament['groups_json']) ?>' data-mode="user"></div>
                <?php elseif ($tournament['bracket_json']): ?>
                    <div class="bracket-container" data-bracket='<?= sanitize($tournament['bracket_json']) ?>' data-mode="user"></div>
                <?php else: ?>
                    <p>Bracket not yet available.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No tournaments are live right now.</p>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Completed Tournaments</h2>
    <?php if ($completed): ?>
        <ul>
            <?php foreach ($completed as $tournament): ?>
                <li><?= sanitize($tournament['name']) ?> &mdash; Completed <?= sanitize($tournament['updated_at']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No completed tournaments yet.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
