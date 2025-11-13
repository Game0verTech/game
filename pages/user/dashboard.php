<?php
require_login();
$user = current_user();
$pageTitle = 'Dashboard';
require __DIR__ . '/../../templates/header.php';

$stats = get_user_stat($user['id']);
$recentMatchesRaw = recent_results($user['id']);
$userTournamentsRaw = user_tournaments($user['id']);
$allTournaments = list_tournaments();
$tournamentWins = count_user_tournament_titles($user['id']);

$memberSince = isset($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : null;

$winCount = $stats ? (int)$stats['wins'] : 0;
$lossCount = $stats ? (int)$stats['losses'] : 0;
$totalMatches = $winCount + $lossCount;
$tournamentsPlayed = $stats ? (int)$stats['tournaments_played'] : 0;
$winRate = $stats ? number_format((float)$stats['win_rate'], 2) : '0.00';

$buildTournamentPayload = static function (array $tournament, array $players, bool $isRegistered): string {
    $playerIds = array_map(static fn($player) => (int)$player['user_id'], $players);
    $playerRoster = array_map(
        static fn($player) => [
            'id' => (int)$player['user_id'],
            'name' => $player['username'],
        ],
        $players
    );

    return safe_json_encode([
        'id' => (int)$tournament['id'],
        'name' => $tournament['name'],
        'status' => $tournament['status'],
        'type' => $tournament['type'],
        'description' => $tournament['description'],
        'scheduled_at' => $tournament['scheduled_at'],
        'location' => $tournament['location'],
        'players' => $playerIds,
        'player_roster' => $playerRoster,
        'is_registered' => $isRegistered,
    ]);
};

$upcomingTournaments = [];
foreach ($allTournaments as $tournament) {
    if (!in_array($tournament['status'], ['draft', 'open', 'live'], true)) {
        continue;
    }
    $tournamentId = (int)$tournament['id'];
    $players = tournament_players($tournamentId);
    $isRegistered = is_user_registered($tournamentId, $user['id']);
    $upcomingTournaments[] = [
        'tournament' => $tournament,
        'players' => $players,
        'is_registered' => $isRegistered,
        'payload' => $buildTournamentPayload($tournament, $players, $isRegistered),
    ];
}

$userTournaments = [];
foreach ($userTournamentsRaw as $tournament) {
    $tournamentId = (int)$tournament['id'];
    $players = tournament_players($tournamentId);
    $userTournaments[] = [
        'tournament' => $tournament,
        'players' => $players,
        'payload' => $buildTournamentPayload($tournament, $players, true),
    ];
}

$recentMatches = [];
foreach ($recentMatchesRaw as $match) {
    $player1Id = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : 0;
    $player2Id = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : 0;
    $side = null;
    if ($player1Id === $user['id']) {
        $side = 1;
    } elseif ($player2Id === $user['id']) {
        $side = 2;
    }
    $opponentId = $side === 1 ? $player2Id : ($side === 2 ? $player1Id : 0);
    $opponent = $opponentId ? get_user_by_id($opponentId) : null;
    $scoreFor = $side === 1 ? ($match['score1'] ?? null) : ($side === 2 ? ($match['score2'] ?? null) : null);
    $scoreAgainst = $side === 1 ? ($match['score2'] ?? null) : ($side === 2 ? ($match['score1'] ?? null) : null);
    $recentMatches[] = [
        'tournament' => $match['tournament_name'] ?? 'Tournament',
        'opponent' => $opponent['username'] ?? 'TBD',
        'score_for' => $scoreFor,
        'score_against' => $scoreAgainst,
        'is_winner' => isset($match['winner_user_id']) ? (int)$match['winner_user_id'] === $user['id'] : false,
        'stage' => $match['stage'] ?? null,
        'round' => $match['round'] ?? null,
    ];
}
?>
<div class="player-dashboard">
    <section class="card player-dashboard__summary">
        <div class="player-summary">
            <div>
                <p class="muted">Welcome back</p>
                <h2><?= sanitize($user['username']) ?></h2>
                <?php if ($memberSince): ?>
                    <div class="player-summary__meta">
                        <span><?= sanitize('Member since ' . $memberSince) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="player-accolade" aria-label="Tournament wins">
                <span class="player-accolade__icon" aria-hidden="true">👑</span>
                <span class="player-accolade__count"><?= $tournamentWins ?></span>
                <span class="player-accolade__label">Tournament Wins</span>
            </div>
        </div>
        <div class="player-stats-grid">
            <div class="stat-card">
                <span class="stat-card__label">Win / Loss</span>
                <span class="stat-card__value"><?= $winCount ?> &ndash; <?= $lossCount ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Matches Played</span>
                <span class="stat-card__value"><?= $totalMatches ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Tournaments Entered</span>
                <span class="stat-card__value"><?= $tournamentsPlayed ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Win Rate</span>
                <span class="stat-card__value"><?= $winRate ?>%</span>
            </div>
        </div>
    </section>

    <div class="player-dashboard__grid">
        <section class="card player-dashboard__card">
            <h2>Recent Results</h2>
            <?php if ($recentMatches): ?>
                <ul class="recent-results">
                    <?php foreach ($recentMatches as $match): ?>
                        <?php
                            $resultLabel = $match['is_winner'] ? 'Win' : 'Loss';
                            $resultClass = $match['is_winner'] ? 'is-win' : 'is-loss';
                            $scoreReady = $match['score_for'] !== null && $match['score_against'] !== null;
                            $stage = $match['stage'] ? strtoupper($match['stage']) : null;
                            $round = $match['round'] ? 'Round ' . (int)$match['round'] : null;
                        ?>
                        <li class="recent-results__item">
                            <div class="recent-results__meta">
                                <span class="recent-results__tournament"><?= sanitize($match['tournament']) ?></span>
                                <span class="recent-results__opponent">vs. <?= sanitize($match['opponent']) ?></span>
                                <?php if ($stage || $round): ?>
                                    <span class="recent-results__stage muted"><?= sanitize(trim(($stage ?? '') . ' ' . ($round ?? ''))) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="recent-results__score <?= $resultClass ?>">
                                <span class="recent-results__label"><?= $resultLabel ?></span>
                                <?php if ($scoreReady): ?>
                                    <span class="recent-results__values"><?= (int)$match['score_for'] ?> &ndash; <?= (int)$match['score_against'] ?></span>
                                <?php else: ?>
                                    <span class="recent-results__values muted">Pending</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">No matches recorded yet. Join a tournament to start building your record.</p>
            <?php endif; ?>
        </section>
        <section class="card player-dashboard__card">
            <h2>Your Tournaments</h2>
            <?php if ($userTournaments): ?>
                <ul class="tournament-summary-list">
                    <?php foreach ($userTournaments as $entry): ?>
                        <?php
                            $tournament = $entry['tournament'];
                            $status = strtolower($tournament['status']);
                            $statusLabel = ucfirst($status);
                            $schedule = $tournament['scheduled_at']
                                ? date('F j, Y \a\t g:i A', strtotime($tournament['scheduled_at']))
                                : 'Schedule TBD';
                            $location = $tournament['location'] ?: default_tournament_location();
                        ?>
                        <li class="tournament-summary">
                            <div class="tournament-summary__header">
                                <div>
                                    <h3><?= sanitize($tournament['name']) ?></h3>
                                    <div class="tournament-summary__meta">
                                        <?= sanitize($schedule) ?> &middot; <?= sanitize($location) ?>
                                    </div>
                                </div>
                                <span class="status-pill status-<?= sanitize($status) ?>"><?= sanitize($statusLabel) ?></span>
                            </div>
                            <div class="tournament-summary__footer">
                                <span class="muted">
                                    <?= count($entry['players']) ?> player<?= count($entry['players']) === 1 ? '' : 's' ?> registered
                                </span>
                                <div class="tournament-summary__actions">
                                    <button
                                        type="button"
                                        class="btn secondary"
                                        data-view-bracket
                                        data-tournament='<?= sanitize($entry['payload']) ?>'
                                    >
                                        View current bracket
                                    </button>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">You haven&rsquo;t joined any tournaments yet. Browse the upcoming list to get started.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="card upcoming-tournaments">
        <h2>Upcoming Tournaments</h2>
        <?php if ($upcomingTournaments): ?>
            <ul class="tournament-summary-list">
                <?php foreach ($upcomingTournaments as $entry): ?>
                    <?php
                        $tournament = $entry['tournament'];
                        $status = strtolower($tournament['status']);
                        $statusLabel = ucfirst($status);
                        $schedule = $tournament['scheduled_at']
                            ? date('F j, Y \a\t g:i A', strtotime($tournament['scheduled_at']))
                            : 'Schedule TBD';
                        $location = $tournament['location'] ?: default_tournament_location();
                        $isRegistered = $entry['is_registered'];
                        $playerCount = count($entry['players']);
                        if ($status === 'draft') {
                            $statusMessage = $isRegistered
                                ? 'You will be notified when registration opens.'
                                : 'Registration opens soon.';
                        } elseif ($status === 'open') {
                            $statusMessage = $isRegistered
                                ? 'You are registered for this event.'
                                : 'Registration is open now.';
                        } elseif ($status === 'live') {
                            $statusMessage = $isRegistered
                                ? 'Tournament is live. Check the bracket for updates.'
                                : 'Tournament is currently live.';
                        } else {
                            $statusMessage = 'Tournament status: ' . $statusLabel;
                        }
                    ?>
                    <li class="tournament-summary">
                        <div class="tournament-summary__header">
                            <div>
                                <h3><?= sanitize($tournament['name']) ?></h3>
                                <div class="tournament-summary__meta">
                                    <?= sanitize($schedule) ?> &middot; <?= sanitize($location) ?>
                                </div>
                                <div class="muted">
                                    <?= $playerCount ?> player<?= $playerCount === 1 ? '' : 's' ?> registered
                                </div>
                            </div>
                            <span class="status-pill status-<?= sanitize($status) ?>"><?= sanitize($statusLabel) ?></span>
                        </div>
                        <?php if (!empty($tournament['description'])): ?>
                            <p class="tournament-summary__description"><?= nl2br(sanitize($tournament['description'])) ?></p>
                        <?php endif; ?>
                        <div class="tournament-summary__footer">
                            <div class="tournament-summary__status <?= $isRegistered ? 'is-registered' : '' ?>">
                                <?= sanitize($statusMessage) ?>
                            </div>
                            <div class="tournament-summary__actions">
                                <?php if ($status === 'open'): ?>
                                    <?php if ($isRegistered): ?>
                                        <form method="post" action="/api/tournaments.php" class="inline">
                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="withdraw">
                                            <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                                            <button type="submit" class="btn subtle">Withdraw</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/api/tournaments.php" class="inline">
                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="register">
                                            <input type="hidden" name="tournament_id" value="<?= (int)$tournament['id'] ?>">
                                            <button type="submit" class="btn primary">Register</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($isRegistered): ?>
                                    <span class="tag tag-success">Registered</span>
                                <?php endif; ?>
                                <button
                                    type="button"
                                    class="btn secondary"
                                    data-view-bracket
                                    data-tournament='<?= sanitize($entry['payload']) ?>'
                                >
                                    View current bracket
                                </button>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">No upcoming tournaments are scheduled right now. Check back soon!</p>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../../templates/partials/tournament-viewer-modal.php'; ?>
<?php require __DIR__ . '/../../templates/footer.php';
