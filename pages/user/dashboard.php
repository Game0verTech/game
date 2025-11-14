<?php
require_login();
$user = current_user();
$pageTitle = 'Dashboard';
require __DIR__ . '/../../templates/header.php';

$loadErrors = [];

try {
    $stats = get_user_stat($user['id']);
} catch (Throwable $e) {
    $stats = [];
    $message = 'Player statistics could not be loaded: ' . $e->getMessage();
    $loadErrors[] = $message;
    error_log('[dashboard] ' . $message);
}
if (!isset($stats) || !is_array($stats)) {
    $stats = [];
}

try {
    $recentMatchesRaw = recent_results($user['id']);
} catch (Throwable $e) {
    $recentMatchesRaw = [];
    $message = 'Recent match history failed to load: ' . $e->getMessage();
    $loadErrors[] = $message;
    error_log('[dashboard] ' . $message);
}

try {
    $userTournamentsRaw = user_tournaments($user['id']);
} catch (Throwable $e) {
    $userTournamentsRaw = [];
    $message = 'Unable to load your tournaments: ' . $e->getMessage();
    $loadErrors[] = $message;
    error_log('[dashboard] ' . $message);
}

try {
    $allTournaments = list_tournaments();
} catch (Throwable $e) {
    $allTournaments = [];
    $message = 'Upcoming tournaments list is unavailable: ' . $e->getMessage();
    $loadErrors[] = $message;
    error_log('[dashboard] ' . $message);
}
$tournamentWins = $stats['tournaments_won'] ?? count_user_tournament_titles($user['id']);

$memberSince = isset($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : null;

$winCount = isset($stats['wins']) ? (int)$stats['wins'] : 0;
$lossCount = isset($stats['losses']) ? (int)$stats['losses'] : 0;
$totalMatches = isset($stats['matches_played']) ? (int)$stats['matches_played'] : ($winCount + $lossCount);
$tournamentsPlayed = isset($stats['tournaments_played']) ? (int)$stats['tournaments_played'] : 0;
$winRate = isset($stats['win_rate']) ? number_format((float)$stats['win_rate'], 2) : '0.00';
$pointsFor = isset($stats['points_for']) ? (int)$stats['points_for'] : 0;
$pointsAgainst = isset($stats['points_against']) ? (int)$stats['points_against'] : 0;
$pointDifferential = isset($stats['point_differential']) ? (int)$stats['point_differential'] : ($pointsFor - $pointsAgainst);
$averageMargin = isset($stats['average_margin']) ? number_format((float)$stats['average_margin'], 2) : '0.00';
$pointDifferentialDisplay = ($pointDifferential > 0 ? '+' : '') . (string)$pointDifferential;
$averagePointsFor = isset($stats['average_points_for']) ? number_format((float)$stats['average_points_for'], 2) : '0.00';
$averagePointsAgainst = isset($stats['average_points_against']) ? number_format((float)$stats['average_points_against'], 2) : '0.00';
$currentStreak = isset($stats['current_streak']) && is_array($stats['current_streak']) ? $stats['current_streak'] : ['type' => null, 'length' => 0];
$currentStreakLabel = '—';
if (!empty($currentStreak['type']) && !empty($currentStreak['length'])) {
    $currentStreakLabel = strtoupper(substr((string)$currentStreak['type'], 0, 1)) . (int)$currentStreak['length'];
}
$bestWinStreak = isset($stats['best_win_streak']) ? (int)$stats['best_win_streak'] : 0;
$shutoutWins = isset($stats['shutout_wins']) ? (int)$stats['shutout_wins'] : 0;
$finalsAppearances = isset($stats['finals_appearances']) ? (int)$stats['finals_appearances'] : 0;
$runnerUpFinishes = isset($stats['runner_up_finishes']) ? (int)$stats['runner_up_finishes'] : 0;
$pendingMatches = isset($stats['pending_matches']) ? (int)$stats['pending_matches'] : 0;
$activeTournaments = isset($stats['tournaments_active']) ? (int)$stats['tournaments_active'] : 0;
$completedTournaments = isset($stats['tournaments_completed']) ? (int)$stats['tournaments_completed'] : 0;
$recentForm = isset($stats['recent_form']) && is_array($stats['recent_form']) ? $stats['recent_form'] : [];

$buildTournamentPayload = static function (array $tournament, array $players, bool $isRegistered) use (&$loadErrors): string {
    $playerIds = array_map(static fn($player) => (int)$player['user_id'], $players);
    $playerRoster = array_map(
        static fn($player) => [
            'id' => (int)$player['user_id'],
            'name' => $player['username'],
        ],
        $players
    );

    try {
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
    } catch (Throwable $e) {
        $message = sprintf(
            'Failed to encode tournament payload for tournament %d: %s',
            isset($tournament['id']) ? (int)$tournament['id'] : 0,
            $e->getMessage()
        );
        $loadErrors[] = $message;
        error_log('[dashboard] ' . $message);
        return '{}';
    }
};

$upcomingTournaments = [];
foreach ($allTournaments as $tournament) {
    if (!in_array($tournament['status'], ['draft', 'open', 'live'], true)) {
        continue;
    }
    $tournamentId = (int)$tournament['id'];
    try {
        $players = tournament_players($tournamentId);
    } catch (Throwable $e) {
        $players = [];
        $message = sprintf(
            'Unable to load players for tournament %d: %s',
            $tournamentId,
            $e->getMessage()
        );
        $loadErrors[] = $message;
        error_log('[dashboard] ' . $message);
    }
    try {
        $isRegistered = is_user_registered($tournamentId, $user['id']);
    } catch (Throwable $e) {
        $isRegistered = false;
        $message = sprintf(
            'Failed to check registration status for tournament %d: %s',
            $tournamentId,
            $e->getMessage()
        );
        $loadErrors[] = $message;
        error_log('[dashboard] ' . $message);
    }
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
    try {
        $players = tournament_players($tournamentId);
    } catch (Throwable $e) {
        $players = [];
        $message = sprintf(
            'Unable to load your tournament roster for event %d: %s',
            $tournamentId,
            $e->getMessage()
        );
        $loadErrors[] = $message;
        error_log('[dashboard] ' . $message);
    }
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
    try {
        $opponent = $opponentId ? get_user_by_id($opponentId) : null;
    } catch (Throwable $e) {
        $opponent = null;
        $message = sprintf(
            'Failed to load opponent details for match %s: %s',
            isset($match['id']) ? (string)$match['id'] : 'unknown',
            $e->getMessage()
        );
        $loadErrors[] = $message;
        error_log('[dashboard] ' . $message);
    }
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

$loadErrors = array_values(array_unique($loadErrors));
?>
<div class="player-dashboard">
    <?php if ($loadErrors): ?>
        <section class="card player-dashboard__card player-dashboard__errors" role="alert">
            <h2>Dashboard Errors</h2>
            <p class="muted">Some information could not be loaded. Details are shown below:</p>
            <ul class="error-list">
                <?php foreach ($loadErrors as $errorMessage): ?>
                    <li><?= sanitize($errorMessage) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
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
                <span class="stat-card__value"><?= sanitize($winRate) ?>%</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Current Streak</span>
                <span class="stat-card__value"><?= sanitize($currentStreakLabel) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Best Win Streak</span>
                <span class="stat-card__value"><?= $bestWinStreak > 0 ? $bestWinStreak : '&mdash;' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Point Differential</span>
                <span class="stat-card__value"><?= sanitize($pointDifferentialDisplay) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Average Margin</span>
                <span class="stat-card__value"><?= sanitize($averageMargin) ?></span>
            </div>
        </div>
        <?php if ($recentForm): ?>
            <?php $formSamples = array_slice(array_reverse($recentForm), 0, 8); ?>
            <div class="player-recent-form">
                <span class="player-recent-form__label">Recent Form</span>
                <ul class="player-recent-form__list" aria-label="Recent results">
                    <?php foreach ($formSamples as $result): ?>
                        <?php $resultClass = $result === 'W' ? 'player-recent-form__item--win' : 'player-recent-form__item--loss'; ?>
                        <li class="player-recent-form__item <?= $resultClass ?>" aria-label="<?= $result === 'W' ? 'Win' : 'Loss' ?>"><?= sanitize($result) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($pendingMatches > 0): ?>
            <p class="player-dashboard__notice">You have <?= sanitize((string)$pendingMatches) ?> match<?= $pendingMatches === 1 ? '' : 'es' ?> awaiting results.</p>
        <?php endif; ?>
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
                            $stageLabelParts = array_values(array_filter([$stage, $round]));
                            $stageLabel = $stageLabelParts ? implode(' • ', $stageLabelParts) : null;
                        ?>
                        <li class="recent-results__item">
                            <div class="recent-results__meta">
                                <span class="recent-results__tournament"><?= sanitize($match['tournament']) ?></span>
                                <span class="recent-results__opponent">vs. <?= sanitize($match['opponent']) ?></span>
                                <?php if ($stageLabel): ?>
                                    <span class="recent-results__stage muted"><?= sanitize($stageLabel) ?></span>
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
        <section class="card player-dashboard__card player-dashboard__insights">
            <h2>Performance Highlights</h2>
            <dl class="insight-grid">
                <div class="insight-grid__item">
                    <dt>Points For</dt>
                    <dd><?= sanitize((string)$pointsFor) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Points Against</dt>
                    <dd><?= sanitize((string)$pointsAgainst) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Average Points For</dt>
                    <dd><?= sanitize($averagePointsFor) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Average Points Against</dt>
                    <dd><?= sanitize($averagePointsAgainst) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Shutout Wins</dt>
                    <dd><?= sanitize((string)$shutoutWins) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Finals Appearances</dt>
                    <dd><?= sanitize((string)$finalsAppearances) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Runner-up Finishes</dt>
                    <dd><?= sanitize((string)$runnerUpFinishes) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Active Tournaments</dt>
                    <dd><?= sanitize((string)$activeTournaments) ?></dd>
                </div>
                <div class="insight-grid__item">
                    <dt>Completed Events</dt>
                    <dd><?= sanitize((string)$completedTournaments) ?></dd>
                </div>
            </dl>
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
<?php
require __DIR__ . '/../../templates/partials/tournament-viewer-modal.php';
require __DIR__ . '/../../templates/footer.php';
