<?php
$canManageTournamentsInViewer = $canManageTournaments ?? (user_has_role('admin') || user_has_role('manager'));
$allPlayersForManagement = [];
if ($canManageTournamentsInViewer) {
    $allPlayersForManagement = array_map(
        static function (array $player): array {
            return [
                'id' => (int)$player['id'],
                'username' => $player['username'],
                'role' => $player['role'],
            ];
        },
        all_users()
    );
}
$allPlayersJson = $canManageTournamentsInViewer ? safe_json_encode($allPlayersForManagement) : '[]';
?>
<div id="tournamentViewerModal" class="modal-overlay js-tournament-viewer" hidden aria-hidden="true">
    <div class="modal modal--fullscreen tournament-viewer">
        <button type="button" class="modal__close" data-close-modal data-modal-focus aria-label="Close tournament viewer">&times;</button>
        <header class="tournament-viewer__header">
            <div>
                <span class="status-pill js-viewer-status" aria-live="polite"></span>
                <h2 class="modal__title js-viewer-title">Tournament</h2>
                <p class="muted js-viewer-schedule"></p>
            </div>
            <div class="tournament-viewer__meta">
                <span class="tournament-viewer__type js-viewer-type"></span>
            </div>
        </header>
        <div class="modal__body tournament-viewer__body">
            <div class="tournament-viewer__description js-viewer-description" hidden></div>
            <div class="tournament-viewer__registration js-viewer-registration" aria-live="polite"></div>
            <div class="tournament-viewer__grid">
                <section class="tournament-viewer__section tournament-viewer__section--bracket">
                    <?php if ($canManageTournamentsInViewer): ?>
                        <div class="tournament-viewer__actions">
                            <button type="button" class="btn secondary" data-viewer-open-settings>Manage Tournament</button>
                        </div>
                    <?php endif; ?>
                    <div
                        class="viewer-bracket js-viewer-bracket bracket-container"
                        data-mode="viewer"
                        data-refresh-interval="3000"
                    ></div>
                    <div
                        class="viewer-groups js-viewer-groups group-container"
                        data-mode="viewer"
                        data-refresh-interval="3000"
                        hidden
                    ></div>
                </section>
                <section class="tournament-viewer__section tournament-viewer__section--roster">
                    <h3>Registered Players</h3>
                    <ul class="player-roster js-viewer-roster"></ul>
                </section>
            </div>
        </div>
    </div>
</div>
<?php if ($canManageTournamentsInViewer): ?>
    <div
        id="tournamentSettingsModal"
        class="modal-overlay"
        hidden
        aria-hidden="true"
        data-all-players='<?= sanitize($allPlayersJson) ?>'
    >
        <div class="modal modal--lg" role="dialog" aria-modal="true" aria-labelledby="tournamentSettingsTitle">
            <button type="button" class="modal__close" data-close-modal aria-label="Close tournament settings">&times;</button>
            <h3 id="tournamentSettingsTitle">Edit Tournament</h3>
            <div class="tournament-settings__meta">
                <div>
                    <span class="status-pill js-settings-status" hidden></span>
                    <p class="muted small js-settings-schedule"></p>
                </div>
                <div class="tournament-settings__toolbar">
                    <a href="#" class="btn link js-settings-open-admin" target="_blank" rel="noopener">Open in admin dashboard</a>
                    <div class="tournament-settings__actions">
                        <form method="post" action="/api/tournaments.php" class="tournament-settings__action-form">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="open">
                            <input type="hidden" name="tournament_id" value="" class="js-settings-tournament-id">
                            <button type="submit" class="btn subtle">Open registration</button>
                        </form>
                        <form method="post" action="/api/tournaments.php" class="tournament-settings__action-form">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="tournament_id" value="" class="js-settings-tournament-id">
                            <button type="submit" class="btn subtle">Start tournament</button>
                        </form>
                        <form method="post" action="/api/tournaments.php" class="tournament-settings__action-form">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="tournament_id" value="" class="js-settings-tournament-id">
                            <button type="submit" class="btn subtle">Complete tournament</button>
                        </form>
                        <form
                            method="post"
                            action="/api/tournaments.php"
                            class="tournament-settings__action-form"
                            onsubmit="return confirm('Delete this tournament? This cannot be undone.');"
                        >
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="tournament_id" value="" class="js-settings-tournament-id">
                            <button type="submit" class="btn danger">Delete tournament</button>
                        </form>
                    </div>
                </div>
            </div>
            <p class="management-feedback" data-feedback hidden aria-live="polite"></p>
            <p class="muted">Update tournament details and manage the registered roster.</p>
            <form method="post" action="/api/tournaments.php" class="modal-form">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_settings">
                <input type="hidden" name="tournament_id" value="">
                <label>Name
                    <input type="text" name="name" required>
                </label>
                <label>Type
                    <select name="type" required>
                        <option value="single">Single Elimination</option>
                        <option value="double">Double Elimination</option>
                        <option value="round-robin">Round Robin</option>
                    </select>
                </label>
                <label>Description
                    <textarea name="description" rows="4"></textarea>
                </label>
                <div class="form-grid">
                    <label>Date
                        <input type="date" name="scheduled_date">
                    </label>
                    <label>Time
                        <input type="time" name="scheduled_time">
                    </label>
                </div>
                <label>Location
                    <input type="text" name="location" value="<?= sanitize(default_tournament_location()) ?>">
                </label>
                <div class="player-management">
                    <div>
                        <div class="section-label">Selected players</div>
                        <div class="player-chip-list js-selected-players">
                            <p class="muted small">No players selected yet.</p>
                        </div>
                    </div>
                    <button type="button" class="btn secondary" data-toggle-player-list aria-expanded="false">Add Players</button>
                    <div class="player-checkbox-list" data-player-list hidden></div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn primary">Save changes</button>
                    <button type="button" class="btn link" data-close-modal>Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <div id="tournamentActionsModal" class="modal-overlay" hidden aria-hidden="true">
        <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="tournamentActionsTitle">
            <button type="button" class="modal__close" data-close-modal aria-label="Close tournament management">&times;</button>
            <h3 id="tournamentActionsTitle"><span class="js-action-title">Manage Tournament</span></h3>
            <p class="muted js-action-schedule"></p>
            <div class="tournament-actions">
                <a href="#" class="btn link js-open-tournament" target="_blank" rel="noopener">Open in admin dashboard</a>
                <button type="button" class="btn primary" data-open-settings>Edit tournament settings</button>
            </div>
            <p class="management-feedback" data-feedback hidden aria-live="polite"></p>
            <div class="tournament-actions-list">
                <form method="post" action="/api/tournaments.php">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="open">
                    <input type="hidden" name="tournament_id" value="" class="js-action-tournament-id">
                    <button type="submit" class="btn subtle">Open registration</button>
                </form>
                <form method="post" action="/api/tournaments.php">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="tournament_id" value="" class="js-action-tournament-id">
                    <button type="submit" class="btn subtle">Start tournament</button>
                </form>
                <form method="post" action="/api/tournaments.php">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="tournament_id" value="" class="js-action-tournament-id">
                    <button type="submit" class="btn subtle">Complete tournament</button>
                </form>
                <form
                    method="post"
                    action="/api/tournaments.php"
                    onsubmit="return confirm('Delete this tournament? This cannot be undone.');"
                >
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tournament_id" value="" class="js-action-tournament-id">
                    <button type="submit" class="btn danger">Delete tournament</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
