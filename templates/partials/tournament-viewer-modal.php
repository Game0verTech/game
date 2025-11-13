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
                <section class="tournament-viewer__section">
                    <h3>Registered Players</h3>
                    <ul class="player-roster js-viewer-roster"></ul>
                </section>
                <section class="tournament-viewer__section tournament-viewer__section--bracket">
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
            </div>
        </div>
    </div>
</div>
