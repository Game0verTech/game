$(function () {
    function parseJsonPayload(container, key) {
        var cached = container.data(key);
        if (cached && typeof cached !== 'string') {
            return cached;
        }

        var raw = typeof cached === 'string' ? cached : container.attr('data-' + key);
        if (!raw || typeof raw !== 'string') {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (e) {
            try {
                var decoded = $('<textarea/>').html(raw).text();
                return JSON.parse(decoded);
            } catch (inner) {
                console.error('Invalid ' + key + ' JSON', inner);
            }
        }

        return null;
    }

    function escapeHtml(value) {
        return $('<div/>').text(value || '').html();
    }

    function normalizeMode(mode) {
        if (!mode) {
            return 'viewer';
        }
        if (mode === 'user') {
            return 'viewer';
        }
        return mode;
    }

    function clearTeamHighlight(container) {
        if (!container || !container.length) {
            return;
        }
        var previous = container.data('activeTeam');
        if (previous && previous.length) {
            previous.removeClass('is-context-target');
        }
        container.removeData('activeTeam');
    }

    function parseJsonString(raw) {
        if (typeof raw !== 'string') {
            return null;
        }
        var sanitized = raw.replace(/^[\uFEFF\u200B]+/, '').trim();
        if (!sanitized) {
            return null;
        }
        try {
            return JSON.parse(sanitized);
        } catch (error) {
            var start = sanitized.indexOf('{');
            var end = sanitized.lastIndexOf('}');
            if (start !== -1 && end !== -1 && end > start) {
                var snippet = sanitized.slice(start, end + 1);
                try {
                    return JSON.parse(snippet);
                } catch (inner) {
                    console.error('Failed to parse JSON payload', inner);
                }
            } else {
                console.error('Failed to parse JSON payload', error);
            }
        }
        return null;
    }

    function flagBracketError(container, meta) {
        if (!container || !container.length) {
            return;
        }
        container.addClass('has-error');
        if (meta) {
            container.data('lastError', meta);
        }
        window.clearTimeout(container.data('errorTimer'));
        var timer = window.setTimeout(function () {
            container.removeClass('has-error');
            container.removeData('errorTimer');
        }, 1600);
        container.data('errorTimer', timer);
    }

    function ensureContextMenu(container) {
        var menu = container.data('contextMenu');
        if (menu && menu.length) {
            return menu;
        }
        menu = $('<div class="bracket-context-menu" role="menu" aria-hidden="true"></div>');
        var markBtn = $('<button type="button" class="bracket-context-menu__action" data-action="mark" role="menuitem"></button>');
        var cancelBtn = $('<button type="button" class="bracket-context-menu__action" data-action="cancel" role="menuitem">Cancel</button>');
        menu.append(markBtn, cancelBtn);
        $('body').append(menu);
        container.data('contextMenu', menu);
        return menu;
    }

    function hideContextMenu(container) {
        if (!container || !container.length) {
            return;
        }
        var menu = container.data('contextMenu');
        if (menu && menu.length) {
            menu.removeClass('is-visible');
            menu.attr('aria-hidden', 'true');
            menu.css({ left: '-9999px', top: '-9999px' });
            menu.removeData('payload');
        }
        clearTeamHighlight(container);
        container.removeData('menuOrigin');
    }

    function positionContextMenu(menu, x, y) {
        if (!menu || !menu.length) {
            return;
        }
        menu.removeClass('is-visible');
        menu.css({ left: '-9999px', top: '-9999px' });
        var width = menu.outerWidth();
        var height = menu.outerHeight();
        var scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
        var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight;

        if (x + width > scrollX + viewportWidth - 12) {
            x = scrollX + viewportWidth - width - 12;
        }
        if (y + height > scrollY + viewportHeight - 12) {
            y = scrollY + viewportHeight - height - 12;
        }

        if (x < scrollX + 12) {
            x = scrollX + 12;
        }
        if (y < scrollY + 12) {
            y = scrollY + 12;
        }

        menu.css({ left: x + 'px', top: y + 'px' });
    }

    function showContextMenu(container, payload, origin) {
        if (!container || !container.length || !payload) {
            return;
        }
        var menu = ensureContextMenu(container);
        var team = payload.team;
        if (!team || !team.length) {
            return;
        }

        clearTeamHighlight(container);
        team.addClass('is-context-target');
        container.data('activeTeam', team);

        menu.data('payload', payload);
        menu.attr('aria-hidden', 'false');
        var markBtn = menu.find('[data-action="mark"]');
        markBtn.text('Mark ' + payload.playerName + ' as winner');

        var x = null;
        var y = null;
        if (origin && typeof origin.pageX === 'number' && typeof origin.pageY === 'number') {
            x = origin.pageX;
            y = origin.pageY;
        }

        if (x === null || y === null) {
            var rect = team[0].getBoundingClientRect();
            var scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
            var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
            x = rect.left + rect.width / 2 + scrollX;
            y = rect.bottom + scrollY + 6;
        }

        positionContextMenu(menu, x, y);

        window.requestAnimationFrame(function () {
            menu.addClass('is-visible');
            markBtn.trigger('focus');
        });
    }

    function setupContextMenu(container) {
        if (!container || !container.length) {
            return;
        }
        if (container.data('contextMenuReady')) {
            return;
        }
        var menu = ensureContextMenu(container);
        var instanceId = container.data('contextMenuInstanceId');
        if (!instanceId) {
            instanceId = 'menu-' + Math.random().toString(36).slice(2);
            container.data('contextMenuInstanceId', instanceId);
        }

        menu.on('click', '[data-action="mark"]', function (event) {
            event.preventDefault();
            var payload = menu.data('payload');
            hideContextMenu(container);
            if (payload) {
                markWinner(payload.container, payload.tournamentId, payload.matchId, payload.playerId, payload.token);
            }
        });

        menu.on('click', '[data-action="cancel"]', function (event) {
            event.preventDefault();
            hideContextMenu(container);
        });

        $(document).on('click.' + instanceId, function (event) {
            var target = $(event.target);
            var menuEl = container.data('contextMenu');
            if (!menuEl || !menuEl.hasClass('is-visible')) {
                return;
            }
            if (target.closest(menuEl).length) {
                return;
            }
            hideContextMenu(container);
        });

        $(document).on('keydown.' + instanceId, function (event) {
            if (event.key === 'Escape') {
                hideContextMenu(container);
            }
        });

        $(window).on('resize.' + instanceId, function () {
            hideContextMenu(container);
        });

        container.on('scroll.' + instanceId, function () {
            hideContextMenu(container);
        });

        container.data('contextMenuReady', true);
    }

    function extractTeamPayload(container, team) {
        if (!team || !team.length) {
            return null;
        }

        var matchEl = team.closest('.bracket-match');
        var roundIndexAttr = team.attr('data-round-index');
        if (!roundIndexAttr && matchEl.length) {
            roundIndexAttr = matchEl.attr('data-round-index');
        }
        var matchIndexAttr = team.attr('data-match-index');
        if (!matchIndexAttr && matchEl.length) {
            matchIndexAttr = matchEl.attr('data-match-index');
        }
        var slotAttr = team.attr('data-slot');

        var matchIdValue = team.attr('data-match-id');
        if (!matchIdValue && matchEl.length) {
            matchIdValue = matchEl.attr('data-match-id');
        }
        var matchId = parseInt(matchIdValue, 10);
        if (isNaN(matchId)) {
            matchId = null;
        }

        var playerIdValue = team.attr('data-player-id');
        var playerId = parseInt(playerIdValue, 10);
        if (isNaN(playerId)) {
            playerId = null;
        }

        var tournamentIdRaw = container.data('tournamentId');
        if (tournamentIdRaw === undefined) {
            tournamentIdRaw = container.attr('data-tournament-id');
        }
        var tournamentId = parseInt(tournamentIdRaw, 10);
        if (isNaN(tournamentId)) {
            tournamentId = null;
        }

        var token = container.data('token') || container.attr('data-token');

        var roundIndex = parseInt(roundIndexAttr, 10);
        if (isNaN(roundIndex)) {
            roundIndex = null;
        }
        var matchIndex = parseInt(matchIndexAttr, 10);
        if (isNaN(matchIndex)) {
            matchIndex = null;
        }

        if ((matchId === null || playerId === null) && roundIndex !== null && matchIndex !== null) {
            var bracketData = container.data('bracketData');
            if (bracketData && Array.isArray(bracketData.results) && bracketData.results[roundIndex]) {
                var matchData = bracketData.results[roundIndex][matchIndex];
                if (Array.isArray(matchData) && matchData.length >= 3) {
                    var meta = matchData[2] || {};
                    if (matchId === null) {
                        if (meta.match_id !== undefined && meta.match_id !== null) {
                            matchId = parseInt(meta.match_id, 10);
                        } else if (meta.matchId !== undefined && meta.matchId !== null) {
                            matchId = parseInt(meta.matchId, 10);
                        }
                        if (!isNaN(matchId)) {
                            team.attr('data-match-id', matchId);
                        } else {
                            matchId = null;
                        }
                    }
                    if (playerId === null && slotAttr) {
                        var slotIndex = parseInt(slotAttr, 10);
                        if (!isNaN(slotIndex)) {
                            slotIndex = slotIndex - 1;
                            var playerMeta = slotIndex === 0 ? meta.player1 : meta.player2;
                            if (playerMeta && playerMeta.id !== undefined && playerMeta.id !== null) {
                                playerId = parseInt(playerMeta.id, 10);
                                if (!isNaN(playerId)) {
                                    team.attr('data-player-id', playerId);
                                } else {
                                    playerId = null;
                                }
                            }
                            if (!team.attr('data-player-name') && playerMeta && playerMeta.name) {
                                team.attr('data-player-name', playerMeta.name);
                            }
                        }
                    }
                }
            }
        }

        var playerName = $.trim(team.attr('data-player-name') || '') || $.trim(team.find('.label').text()) || 'this player';

        if (roundIndex !== null && !team.attr('data-round-index')) {
            team.attr('data-round-index', roundIndex);
        }
        if (matchIndex !== null && !team.attr('data-match-index')) {
            team.attr('data-match-index', matchIndex);
        }

        if (matchId === null || playerId === null || tournamentId === null || !token) {
            if (window.console && typeof window.console.warn === 'function') {
                console.warn('Bracket winner selection unavailable due to missing data', {
                    matchId: matchId,
                    playerId: playerId,
                    tournamentId: tournamentId,
                    hasToken: !!token,
                });
            }
            return null;
        }

        return {
            container: container,
            matchId: matchId,
            playerId: playerId,
            tournamentId: tournamentId,
            token: token,
            playerName: playerName,
            roundIndex: roundIndex,
            matchIndex: matchIndex,
            team: team,
        };
    }

    function requestWinnerSelection(container, team, origin) {
        var status = container.data('status');
        if (!status) {
            status = container.attr('data-status');
        }
        if (status && status !== 'live') {
            var statusMessage = 'Please start the tournament before recording match winners.';
            if (status === 'completed') {
                statusMessage = 'This tournament has already been completed.';
            }
            window.alert(statusMessage);
            return;
        }
        setupContextMenu(container);
        var payload = extractTeamPayload(container, team);
        if (!payload) {
            hideContextMenu(container);
            return;
        }

        showContextMenu(container, payload, origin || null);
    }

    function isMatchNode(node) {
        return Array.isArray(node) && node.length >= 2 && !Array.isArray(node[0]) && !Array.isArray(node[1]);
    }

    function flattenResults(results) {
        var matches = [];
        (function walk(node) {
            if (!Array.isArray(node)) {
                return;
            }
            if (isMatchNode(node)) {
                matches.push({
                    score1: node[0],
                    score2: node[1],
                    meta: node[2] || {},
                });
                return;
            }
            node.forEach(function (child) {
                walk(child);
            });
        })(results || []);
        return matches;
    }

    function computeStatusLabel(item, slotIndex, meta) {
        var score1 = parseInt(item.score1, 10);
        var score2 = parseInt(item.score2, 10);
        if (!isNaN(score1) && !isNaN(score2) && score1 !== score2) {
            if (slotIndex === 0) {
                return score1 > score2 ? 'WIN' : 'LOSS';
            }
            return score2 > score1 ? 'WIN' : 'LOSS';
        }
        var winner = meta.winner;
        if (winner && winner.id) {
            var playerMeta = slotIndex === 0 ? meta.player1 : meta.player2;
            if (playerMeta && playerMeta.id === winner.id) {
                return 'WIN';
            }
            if (playerMeta && playerMeta.id) {
                return 'LOSS';
            }
        }
        var hasPlayer = (slotIndex === 0 && meta.player1 && meta.player1.id) || (slotIndex === 1 && meta.player2 && meta.player2.id);
        return hasPlayer ? 'READY' : 'TBD';
    }

    function isSimpleRoundSet(results) {
        if (!Array.isArray(results) || !results.length) {
            return false;
        }
        return results.every(function (round) {
            if (!Array.isArray(round)) {
                return false;
            }
            return round.every(isMatchNode);
        });
    }

    function parseScore(value) {
        var score = parseInt(value, 10);
        return isNaN(score) ? null : score;
    }

    function buildTeamElement(options) {
        var team = $('<div class="team"></div>');
        team.attr('data-slot', options.slotIndex + 1);
        var label = $('<span class="label"></span>').text(options.name || 'TBD');
        var score = $('<span class="score"></span>');
        var rawScore = options.score;
        var normalizedScore = rawScore;
        if (typeof normalizedScore === 'string') {
            normalizedScore = normalizedScore.trim();
        }
        var shouldShowScore = false;
        if (normalizedScore !== null && normalizedScore !== undefined && normalizedScore !== '') {
            var numericScore = Number(normalizedScore);
            if (Number.isNaN(numericScore) || (numericScore !== 0 && numericScore !== 1)) {
                shouldShowScore = true;
            }
        }
        if (shouldShowScore) {
            score.text(String(normalizedScore));
            score.removeClass('is-hidden');
            score.removeAttr('aria-hidden');
        } else {
            score.text('');
            score.addClass('is-hidden');
            score.attr('aria-hidden', 'true');
        }
        team.append(label, score);

        if (options.matchId !== undefined && options.matchId !== null) {
            team.attr('data-match-id', options.matchId);
        }
        if (options.roundIndex !== undefined && options.roundIndex !== null) {
            team.attr('data-round-index', options.roundIndex);
        }
        if (options.matchIndex !== undefined && options.matchIndex !== null) {
            team.attr('data-match-index', options.matchIndex);
        }

        if (options.playerId) {
            team.attr('data-player-id', options.playerId);
            team.attr('data-player-name', options.playerName || options.name || '');
        }

        var statusLabel = options.statusLabel;
        if (statusLabel) {
            team.attr('data-status-label', statusLabel);
        } else {
            team.removeAttr('data-status-label');
        }

        team.removeClass('status-win status-loss status-ready status-tbd is-champion');
        if (statusLabel === 'WIN') {
            team.addClass('status-win');
        } else if (statusLabel === 'LOSS') {
            team.addClass('status-loss');
        } else if (statusLabel === 'READY') {
            team.addClass('status-ready');
        } else {
            team.addClass('status-tbd');
        }

        if (options.isSelectable) {
            team.addClass('is-selectable');
            team.attr('tabindex', '0');
            team.attr('role', 'button');
            team.attr('aria-label', 'Mark ' + (options.playerName || options.name || 'this competitor') + ' as winner');
        }

        if (options.isChampion) {
            team.addClass('is-champion');
            team.attr('data-champion', '1');
            var crown = $('<span class="champion-icon" role="img" aria-label="Champion" title="Champion"></span>').text('👑');
            label.append(' ').append(crown);
        } else {
            team.removeAttr('data-champion');
        }

        return team;
    }

    function detachBracketObservers(container) {
        var observer = container.data('bracketObserver');
        if (observer && typeof observer.disconnect === 'function') {
            observer.disconnect();
        }
        container.removeData('bracketObserver');
        var instanceId = container.data('bracketInstanceId');
        if (instanceId) {
            $(window).off('resize.' + instanceId);
            container.removeData('bracketInstanceId');
        }
    }

    function layoutBracket(view) {
        var rounds = view.find('.bracket-round');
        if (!rounds.length) {
            return;
        }

        var baseSpacing = 32;
        var centersByRound = [];
        var maxHeight = 0;

        rounds.each(function (roundIndex) {
            var roundEl = $(this);
            var matchesContainer = roundEl.find('.bracket-round__matches');
            if (!matchesContainer.length) {
                return;
            }
            var matches = matchesContainer.find('.bracket-match');
            var centers = [];
            matchesContainer.css({ position: 'relative' });

            matches.each(function (matchIndex) {
                var matchEl = $(this);
                matchEl.css({ position: 'absolute' });
                var matchHeight = matchEl.outerHeight();
                if (!matchHeight) {
                    matchHeight = parseFloat(matchEl.data('approxHeight') || 0) || 96;
                }
                var top;
                if (roundIndex === 0) {
                    if (matchIndex === 0) {
                        top = 0;
                    } else {
                        var prevEl = matches.eq(matchIndex - 1);
                        var prevTop = parseFloat(prevEl.css('top')) || 0;
                        var prevHeight = prevEl.outerHeight();
                        if (!prevHeight) {
                            prevHeight = parseFloat(prevEl.data('approxHeight') || 0) || matchHeight;
                        }
                        top = prevTop + prevHeight + baseSpacing;
                    }
                } else {
                    var prevCenters = centersByRound[roundIndex - 1] || [];
                    var sourceA = prevCenters[matchIndex * 2];
                    var sourceB = prevCenters[matchIndex * 2 + 1];
                    if (typeof sourceA === 'number' && typeof sourceB === 'number') {
                        var center = (sourceA + sourceB) / 2;
                        top = center - matchHeight / 2;
                    } else if (typeof sourceA === 'number') {
                        top = sourceA - matchHeight / 2;
                    } else if (typeof sourceB === 'number') {
                        top = sourceB - matchHeight / 2;
                    } else {
                        top = matchIndex * (matchHeight + baseSpacing) * Math.max(1, roundIndex);
                    }
                }
                if (top < 0) {
                    top = 0;
                }
                matchEl.css('top', top + 'px');
                matchEl.data('approxHeight', matchHeight);
                centers.push(top + matchHeight / 2);
            });

            centersByRound.push(centers);

            var roundHeight = 0;
            matches.each(function () {
                var matchEl = $(this);
                var top = parseFloat(matchEl.css('top')) || 0;
                var bottom = top + matchEl.outerHeight();
                if (bottom > roundHeight) {
                    roundHeight = bottom;
                }
            });

            if (!roundHeight) {
                roundHeight = (matches.length ? matches.length : 1) * 100;
            }

            matchesContainer.css('height', roundHeight + 'px');
            var titleHeight = roundEl.find('.bracket-round__title').outerHeight(true) || 0;
            var totalHeight = roundHeight + titleHeight;
            roundEl.css('height', totalHeight + 'px');
            if (totalHeight > maxHeight) {
                maxHeight = totalHeight;
            }
        });

        view.find('.bracket-columns').css('height', maxHeight + 'px');
    }

    function updateBracketConnectors(view) {
        var columns = view.find('.bracket-columns');
        var svg = view.find('.bracket-lines');
        if (!columns.length || !svg.length) {
            return;
        }

        var width = columns[0].scrollWidth;
        var height = columns[0].scrollHeight;
        svg.attr('width', width);
        svg.attr('height', height);
        svg.attr('viewBox', '0 0 ' + width + ' ' + height);
        svg.attr('preserveAspectRatio', 'none');
        svg.empty();

        var ns = 'http://www.w3.org/2000/svg';
        var rounds = columns.find('.bracket-round');

        rounds.each(function (roundIndex) {
            if (roundIndex === 0) {
                return;
            }
            var roundEl = $(this);
            var prevRound = rounds.eq(roundIndex - 1);
            var matches = roundEl.find('.bracket-match');
            matches.each(function () {
                var matchEl = $(this);
                var matchIndex = parseInt(matchEl.attr('data-match-index'), 10);
                if (isNaN(matchIndex)) {
                    return;
                }
                var sourceA = prevRound.find('.bracket-match[data-match-index="' + (matchIndex * 2) + '"]');
                var sourceB = prevRound.find('.bracket-match[data-match-index="' + (matchIndex * 2 + 1) + '"]');
                var targets = matchEl.find('.team');

                function connect(source, target) {
                    if (!source.length || !target.length) {
                        return;
                    }
                    var startRect = source[0].getBoundingClientRect();
                    var endRect = target[0].getBoundingClientRect();
                    var columnsRect = columns[0].getBoundingClientRect();
                    var startX = startRect.right - columnsRect.left;
                    var startY = startRect.top - columnsRect.top + startRect.height / 2;
                    var endX = endRect.left - columnsRect.left;
                    var endY = endRect.top - columnsRect.top + endRect.height / 2;
                    var midX = startX + (endX - startX) / 2;
                    var path = document.createElementNS(ns, 'path');
                    path.setAttribute('d', 'M' + startX + ' ' + startY + ' H' + midX + ' V' + endY + ' H' + endX);
                    path.setAttribute('class', 'bracket-connector');
                    svg[0].appendChild(path);
                }

                connect(sourceA, targets.eq(0));
                connect(sourceB, targets.eq(1));
            });
        });
    }

    function refreshBracketGeometry(container) {
        var view = container.find('.bracket-view');
        if (!view.length) {
            return;
        }
        layoutBracket(view);
        updateBracketConnectors(view);
    }

    function attachBracketObservers(container) {
        var view = container.find('.bracket-view');
        var columns = view.find('.bracket-columns');
        if (!view.length || !columns.length) {
            return;
        }

        var instanceId = container.data('bracketInstanceId');
        if (!instanceId) {
            instanceId = 'bracket-' + Math.random().toString(36).slice(2);
            container.data('bracketInstanceId', instanceId);
        }

        $(window)
            .off('resize.' + instanceId)
            .on('resize.' + instanceId, function () {
                refreshBracketGeometry(container);
            });

        if (typeof window.ResizeObserver === 'function') {
            var observer = new ResizeObserver(function () {
                refreshBracketGeometry(container);
            });
            observer.observe(columns[0]);
            container.data('bracketObserver', observer);
        }
    }

    function updateMatchSummary(container, data) {
        var tournamentId = container.data('tournamentId');
        if (!tournamentId) {
            return;
        }
        var table = $('.js-match-summary[data-tournament-id="' + tournamentId + '"]');
        if (!table.length) {
            return;
        }
        var rows = table.find('tbody tr');
        if (!rows.length) {
            return;
        }
        var matches = flattenResults(data && data.results ? data.results : []);
        var map = {};
        matches.forEach(function (item) {
            var meta = item.meta || {};
            if (!meta.match_id) {
                return;
            }
            var player1 = meta.player1 && meta.player1.name ? meta.player1.name : 'TBD';
            var player2 = meta.player2 && meta.player2.name ? meta.player2.name : 'TBD';
            var winnerName = meta.winner && meta.winner.name ? meta.winner.name : '';
            var score1 = parseInt(item.score1, 10);
            var score2 = parseInt(item.score2, 10);
            if (!winnerName && !isNaN(score1) && !isNaN(score2)) {
                if (score1 > score2 && meta.player1) {
                    winnerName = meta.player1.name;
                } else if (score2 > score1 && meta.player2) {
                    winnerName = meta.player2.name;
                }
            }
            map[meta.match_id] = {
                players: escapeHtml(player1) + ' <span class="versus">vs</span> ' + escapeHtml(player2),
                winner: winnerName ? winnerName : 'TBD',
            };
        });
        rows.each(function () {
            var row = $(this);
            var matchId = parseInt(row.data('matchId'), 10);
            if (!matchId || !map[matchId]) {
                return;
            }
            row.find('td').eq(3).html(map[matchId].players);
            row.find('td').eq(4).text(map[matchId].winner);
        });
    }

    function hasRenderableMatches(data) {
        if (!data) {
            return false;
        }
        if (Array.isArray(data.teams) && data.teams.length > 0) {
            return true;
        }
        if (Array.isArray(data.results)) {
            return data.results.some(function (round) {
                return Array.isArray(round) && round.length > 0;
            });
        }
        return false;
    }

    function renderBracket(container, data, mode) {
        hideContextMenu(container);
        var effectiveMode = normalizeMode(mode);
        container.data('mode', effectiveMode);
        container.attr('data-mode', effectiveMode);
        if (!hasRenderableMatches(data)) {
            container.empty();
            return;
        }

        detachBracketObservers(container);

        var serialized = JSON.stringify(data);
        var results = Array.isArray(data.results) ? data.results : [];
        if (!results.length) {
            container.empty().append(
                $('<p class="bracket-placeholder"></p>').text('Bracket will appear once matches are seeded.')
            );
            container.data('bracketState', serialized);
            container.data('bracketData', data);
            if (effectiveMode === 'admin') {
                updateMatchSummary(container, data);
            }
            return;
        }
        if (!isSimpleRoundSet(results)) {
            container.empty().append(
                $('<p class="bracket-placeholder"></p>').text('Bracket view is unavailable for this tournament format.')
            );
            container.data('bracketState', serialized);
            container.data('bracketData', data);
            if (effectiveMode === 'admin') {
                updateMatchSummary(container, data);
            }
            return;
        }

        var teams = Array.isArray(data.teams) ? data.teams : [];
        var view = $('<div class="bracket-view"></div>');
        var columns = $('<div class="bracket-columns"></div>');
        var svg = $('<svg class="bracket-lines" aria-hidden="true" focusable="false"></svg>');
        view.append(columns);
        view.append(svg);
        container.empty().append(view);

        results.forEach(function (round, roundIndex) {
            var roundEl = $('<div class="bracket-round"></div>').attr('data-round-index', roundIndex);
            var title = $('<div class="bracket-round__title"></div>').text('Round ' + (roundIndex + 1));
            var matchesContainer = $('<div class="bracket-round__matches"></div>');
            roundEl.append(title, matchesContainer);

            round.forEach(function (match, matchIndex) {
                if (!Array.isArray(match)) {
                    return;
                }
                var meta = match[2] || {};
                var matchId = meta.match_id || meta.matchId || null;
                var score1 = parseScore(match[0]);
                var score2 = parseScore(match[1]);
                var matchEl = $('<div class="bracket-match"></div>')
                    .attr('data-round-index', roundIndex)
                    .attr('data-match-index', matchIndex);
                if (matchId) {
                    matchEl.attr('data-match-id', matchId);
                }

                var fallbackPair = Array.isArray(teams[matchIndex]) ? teams[matchIndex] : [];

                var isFinalRound = roundIndex === results.length - 1;
                var winnerMeta = meta && meta.winner && meta.winner.id ? meta.winner : null;

                [0, 1].forEach(function (slotIndex) {
                    var playerMeta = slotIndex === 0 ? meta.player1 : meta.player2;
                    var fallbackName = roundIndex === 0 ? fallbackPair[slotIndex] : null;
                    var name = '';
                    var playerId = null;
                    if (playerMeta && playerMeta.name) {
                        name = playerMeta.name;
                    } else if (typeof fallbackName === 'string') {
                        name = fallbackName;
                    } else {
                        name = 'TBD';
                    }
                    if (playerMeta && playerMeta.id) {
                        playerId = playerMeta.id;
                    }
                    var info = { score1: score1, score2: score2, meta: meta };
                    var statusLabel = computeStatusLabel(info, slotIndex, meta);
                    var isChampion = false;
                    if (isFinalRound && winnerMeta && playerMeta && playerMeta.id === winnerMeta.id) {
                        isChampion = true;
                    }
                    var team = buildTeamElement({
                        slotIndex: slotIndex,
                        name: name,
                        playerId: playerId,
                        playerName: name,
                        score: slotIndex === 0 ? score1 : score2,
                        statusLabel: statusLabel,
                        isSelectable: !!(matchId && playerId),
                        matchId: matchId,
                        roundIndex: roundIndex,
                        matchIndex: matchIndex,
                        isChampion: isChampion,
                    });
                    matchEl.append(team);
                });

                matchesContainer.append(matchEl);
            });

            columns.append(roundEl);
        });

        container.data('bracketState', serialized);
        container.data('bracketData', data);

        if (effectiveMode === 'admin') {
            updateMatchSummary(container, data);
        }

        enableAdminControls(container, effectiveMode);

        window.requestAnimationFrame(function () {
            refreshBracketGeometry(container);
        });
        window.setTimeout(function () {
            refreshBracketGeometry(container);
        }, 60);
        attachBracketObservers(container);
    }

    function fetchBracket(container, tournamentId, mode) {
        $.getJSON('/api/bracket.php', { tournament_id: tournamentId })
            .done(function (response) {
                if (response && response.status) {
                    container.data('status', response.status);
                    container.attr('data-status', response.status);
                }
                if (!response || !response.bracket) {
                    return;
                }
                var serialized = JSON.stringify(response.bracket);
                if (container.data('bracketState') !== serialized) {
                    if (!hasRenderableMatches(response.bracket)) {
                        return;
                    }
                    renderBracket(container, response.bracket, mode);
                }
            })
            .fail(function (xhr) {
                console.error('Failed to refresh bracket', xhr);
            });
    }

    function shouldPoll(container, mode) {
        if (mode === 'admin') {
            return true;
        }
        var liveAttr = container.data('live');
        return liveAttr === true || liveAttr === 1 || liveAttr === '1';
    }

    function setupPolling(container, tournamentId, mode) {
        if (!tournamentId) {
            return;
        }
        fetchBracket(container, tournamentId, mode);
        if (!shouldPoll(container, mode)) {
            return;
        }
        var interval = parseInt(container.data('refreshInterval'), 10);
        if (!interval || interval < 1500) {
            interval = 2000;
        }
        var existing = container.data('poller');
        if (existing) {
            clearInterval(existing);
        }
        var poller = window.setInterval(function () {
            fetchBracket(container, tournamentId, mode);
        }, interval);
        container.data('poller', poller);
    }

    function applyBracketPayload(container, payload, mode) {
        if (!container || !container.length || !payload) {
            return false;
        }
        var effectiveMode = normalizeMode(mode || container.data('mode'));
        if (payload.status) {
            container.data('status', payload.status);
            container.attr('data-status', payload.status);
        }
        if (payload.bracket) {
            renderBracket(container, payload.bracket, effectiveMode);
            return true;
        }
        return false;
    }

    function markWinner(container, tournamentId, matchId, playerId, token) {
        if (!token) {
            console.warn('Missing CSRF token for bracket update.');
            return;
        }
        var mode = normalizeMode(container.data('mode') || 'admin');
        container.addClass('is-updating');
        $.ajax({
            method: 'POST',
            url: '/api/tournaments.php',
            dataType: 'json',
            data: {
                action: 'set_match_winner',
                _token: token,
                tournament_id: tournamentId,
                match_id: matchId,
                winner_user_id: playerId,
            },
        })
            .done(function (response) {
                if (!applyBracketPayload(container, response, mode)) {
                    fetchBracket(container, tournamentId, mode);
                }
                container.removeClass('has-error');
                container.removeData('lastError');
            })
            .fail(function (xhr, textStatus) {
                if (textStatus === 'parsererror') {
                    var parsed = parseJsonString(xhr.responseText);
                    if (applyBracketPayload(container, parsed, mode)) {
                        return;
                    }
                }
                if (xhr.status && xhr.status < 400) {
                    console.warn('Bracket update request returned a non-JSON response.', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        textStatus: textStatus,
                    });
                    fetchBracket(container, tournamentId, mode);
                    return;
                }
                var response = xhr.responseJSON || parseJsonString(xhr.responseText);
                var message = 'Unable to update match.';
                var detail = xhr.status
                    ? 'Status ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '')
                    : '';
                if (response) {
                    if (response.error) {
                        message = response.error;
                    }
                    if (response.detail) {
                        detail = response.detail;
                    }
                }
                console.error('Bracket winner update failed', {
                    message: message,
                    detail: detail,
                    status: xhr.status,
                    statusText: xhr.statusText,
                });
                flagBracketError(container, { message: message, detail: detail });
                fetchBracket(container, tournamentId, mode);
            })
            .always(function () {
                container.removeClass('is-updating');
            });
    }

    function enableAdminControls(container, mode) {
        if (mode !== 'admin') {
            return;
        }
        container.off('contextmenu.bracketAction').on('contextmenu.bracketAction', '.team.is-selectable', function (event) {
            event.preventDefault();
            requestWinnerSelection(container, $(this), { pageX: event.pageX, pageY: event.pageY, type: 'contextmenu' });
        });

        container.off('click.bracketAction').on('click.bracketAction', '.team.is-selectable', function (event) {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            requestWinnerSelection(container, $(this), { pageX: event.pageX, pageY: event.pageY, type: 'click' });
        });

        container.off('keydown.bracketAction').on('keydown.bracketAction', '.team.is-selectable', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            requestWinnerSelection(container, $(this), { type: 'keyboard' });
        });

        container.off('touchend.bracketAction').on('touchend.bracketAction', '.team.is-selectable', function (event) {
            var touch = event.originalEvent && event.originalEvent.changedTouches ? event.originalEvent.changedTouches[0] : null;
            if (touch) {
                event.preventDefault();
            }
            requestWinnerSelection(container, $(this), touch ? { pageX: touch.pageX, pageY: touch.pageY, type: 'touch' } : { type: 'touch' });
        });
    }

    $('.bracket-container').each(function () {
        var container = $(this);
        var data = parseJsonPayload(container, 'bracket');
        if (!data) {
            return;
        }
        var mode = normalizeMode(container.data('mode') || 'viewer');
        renderBracket(container, data, mode);
        var tournamentId = container.data('tournamentId');
        setupPolling(container, tournamentId, mode);
    });

    $('.group-container').each(function () {
        var container = $(this);
        var data = parseJsonPayload(container, 'group');
        var mode = container.data('mode');
        var field = container.data('target');
        if (!data) {
            return;
        }
        var options = { data: data };
        if (mode !== 'admin') {
            options.readonly = true;
        } else if (field) {
            options.onChange = function (updated) {
                $('#' + field).val(JSON.stringify(updated));
            };
        }
        container.group(options);
    });

    $('.js-confirm').on('submit', function (e) {
        var message = $(this).data('confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
            e.preventDefault();
        }
    });

    var tournamentDirectory = {};

    function parseScheduleDate(value) {
        if (!value || typeof value !== 'string') {
            return null;
        }
        var normalized = value.replace(' ', 'T');
        var date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            date = new Date(normalized + 'Z');
        }
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        return date;
    }

    function registerTournament(payload) {
        if (!payload || typeof payload.id === 'undefined') {
            return null;
        }
        var id = parseInt(payload.id, 10);
        if (!id) {
            return null;
        }
        var copy = $.extend(true, {}, payload);
        copy.id = id;
        var scheduled = copy.scheduled_at || copy.scheduledAt;
        copy.scheduledDate = parseScheduleDate(scheduled);
        copy.location = copy.location || 'Kenton Moose Lodge Basement';
        copy.players = Array.isArray(copy.players)
            ? copy.players.map(function (playerId) {
                return parseInt(playerId, 10);
            }).filter(function (playerId) {
                return !Number.isNaN(playerId) && playerId > 0;
            })
            : [];
        tournamentDirectory[id] = copy;
        return copy;
    }

    function keyForDate(date) {
        if (!date) {
            return '';
        }
        var month = (date.getMonth() + 1).toString().padStart(2, '0');
        var day = date.getDate().toString().padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    function formatDateTime(date) {
        if (!date) {
            return 'Schedule TBD';
        }
        var datePart = date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        var timePart = date.toLocaleTimeString(undefined, {
            hour: 'numeric',
            minute: '2-digit'
        });
        return datePart + ' at ' + timePart;
    }

    function formatTournamentSchedule(tournament) {
        if (!tournament) {
            return '';
        }
        var schedule = formatDateTime(tournament.scheduledDate);
        var location = tournament.location || 'Kenton Moose Lodge Basement';
        return schedule + ' • ' + location;
    }

    function toDateInputValue(date) {
        if (!date) {
            return '';
        }
        var month = (date.getMonth() + 1).toString().padStart(2, '0');
        var day = date.getDate().toString().padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    function toTimeInputValue(date) {
        if (!date) {
            return '';
        }
        var hours = date.getHours().toString().padStart(2, '0');
        var minutes = date.getMinutes().toString().padStart(2, '0');
        return hours + ':' + minutes;
    }

    var activeModal = null;

    function openModal(modal) {
        if (!modal || !modal.length) {
            return;
        }
        modal.data('trigger', document.activeElement);
        modal.removeAttr('hidden');
        modal.attr('aria-hidden', 'false');
        modal.addClass('is-visible');
        $('body').addClass('modal-open');
        activeModal = modal;
        window.requestAnimationFrame(function () {
            var focusTarget = modal.find('[data-modal-focus]').first();
            if (!focusTarget.length) {
                focusTarget = modal.find('input, select, textarea, button, a').filter(':visible');
            }
            if (focusTarget.length) {
                focusTarget.first().trigger('focus');
            }
        });
    }

    function closeModal(modal, suppressFocus) {
        if (!modal || !modal.length) {
            return;
        }
        modal.attr('aria-hidden', 'true');
        modal.attr('hidden', 'hidden');
        modal.removeClass('is-visible');
        if ($('.modal-overlay.is-visible').length === 0) {
            $('body').removeClass('modal-open');
        }
        if (!suppressFocus) {
            var trigger = modal.data('trigger');
            if (trigger && typeof trigger.focus === 'function') {
                trigger.focus();
            }
        }
        modal.removeData('trigger');
        if (activeModal && modal[0] === activeModal[0]) {
            activeModal = null;
        }
    }

    function ensurePlayerChecklist(modal) {
        if (!modal || !modal.length) {
            return;
        }
        var list = modal.find('[data-player-list]');
        if (!list.length || list.children().length) {
            return;
        }
        var players = parseJsonPayload(modal, 'all-players') || [];
        var directory = {};
        players.forEach(function (player) {
            var id = parseInt(player.id, 10);
            if (!id) {
                return;
            }
            directory[id] = player;
            var label = $('<label class="player-option"></label>');
            var checkbox = $('<input type="checkbox" name="players[]">').val(id);
            var name = $('<span class="player-option__name"></span>').text(player.username);
            label.append(checkbox, name);
            if (player.role && player.role !== 'player') {
                var readableRole = (player.role || '').replace(/[-_]/g, ' ');
                readableRole = readableRole.charAt(0).toUpperCase() + readableRole.slice(1);
                var role = $('<span class="player-option__role"></span>').text(readableRole);
                label.append(role);
            }
            list.append(label);
        });
        modal.data('playerDirectory', directory);
    }

    function renderSelectedPlayers(modal) {
        if (!modal || !modal.length) {
            return;
        }
        var chips = modal.find('.js-selected-players');
        if (!chips.length) {
            return;
        }
        var directory = modal.data('playerDirectory');
        if (!directory) {
            var players = parseJsonPayload(modal, 'all-players') || [];
            directory = {};
            players.forEach(function (player) {
                var id = parseInt(player.id, 10);
                if (id) {
                    directory[id] = player;
                }
            });
            modal.data('playerDirectory', directory);
        }
        chips.empty();
        var checked = modal.find('[data-player-list] input[type="checkbox"]:checked');
        if (!checked.length) {
            chips.append($('<p class="muted small"></p>').text('No players selected yet.'));
            return;
        }
        checked.each(function () {
            var id = parseInt(this.value, 10);
            if (!id) {
                return;
            }
            var player = directory[id];
            var label = player ? player.username : 'Player #' + id;
            chips.append($('<span class="chip"></span>').text(label));
        });
    }

    function openSettingsModal(modal, tournamentId) {
        if (!modal || !modal.length) {
            return;
        }
        var tournament = tournamentDirectory[tournamentId];
        if (!tournament) {
            return;
        }
        ensurePlayerChecklist(modal);
        var form = modal.find('form');
        if (form.length && typeof form[0].reset === 'function') {
            form[0].reset();
        }
        form.find('[name="tournament_id"]').val(tournament.id);
        form.find('[name="name"]').val(tournament.name || '');
        form.find('[name="type"]').val(tournament.type || 'single');
        form.find('[name="description"]').val(tournament.description || '');
        form.find('[name="scheduled_date"]').val(toDateInputValue(tournament.scheduledDate));
        form.find('[name="scheduled_time"]').val(toTimeInputValue(tournament.scheduledDate));
        form.find('[name="location"]').val(tournament.location || 'Kenton Moose Lodge Basement');

        var list = modal.find('[data-player-list]');
        var toggleButton = modal.find('[data-toggle-player-list]');
        if (list.attr('hidden') === undefined) {
            list.attr('hidden', 'hidden');
        }
        toggleButton.attr('aria-expanded', 'false').text('Add Players');

        list.find('input[type="checkbox"]').each(function () {
            var checkbox = $(this);
            var value = parseInt(checkbox.val(), 10);
            var isChecked = tournament.players.indexOf(value) !== -1;
            checkbox.prop('checked', isChecked);
        });
        renderSelectedPlayers(modal);
        openModal(modal);
    }

    function determineInitialMonth(tournaments) {
        var today = new Date();
        var candidate = null;
        tournaments.forEach(function (tournament) {
            if (!tournament || !tournament.scheduledDate) {
                return;
            }
            if (!candidate || tournament.scheduledDate < candidate) {
                candidate = tournament.scheduledDate;
            }
        });
        var base = candidate || today;
        return new Date(base.getFullYear(), base.getMonth(), 1);
    }

    function buildCalendar(container, state) {
        if (!container || !container.length || !state) {
            return;
        }
        state.tournaments = state.ids.map(function (id) {
            return tournamentDirectory[id];
        }).filter(Boolean);

        container.empty();
        var header = $('<div class="calendar-header"></div>');
        var prev = $('<button type="button" class="calendar-nav" data-nav="prev" aria-label="Previous month">&#10094;</button>');
        var next = $('<button type="button" class="calendar-nav" data-nav="next" aria-label="Next month">&#10095;</button>');
        var title = $('<div class="calendar-month"></div>').text(state.currentMonth.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'long'
        }));
        header.append(prev, title, next);

        var labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var labelsRow = $('<div class="calendar-labels"></div>');
        labels.forEach(function (label) {
            labelsRow.append($('<div class="calendar-label"></div>').text(label));
        });

        var daysGrid = $('<div class="calendar-days"></div>');
        var firstOfMonth = new Date(state.currentMonth.getFullYear(), state.currentMonth.getMonth(), 1);
        var start = new Date(firstOfMonth);
        start.setDate(firstOfMonth.getDate() - firstOfMonth.getDay());

        var eventsByDate = {};
        state.tournaments.forEach(function (tournament) {
            if (!tournament || !tournament.scheduledDate) {
                return;
            }
            var key = keyForDate(tournament.scheduledDate);
            eventsByDate[key] = eventsByDate[key] || [];
            eventsByDate[key].push(tournament);
        });
        Object.keys(eventsByDate).forEach(function (key) {
            eventsByDate[key].sort(function (a, b) {
                if (!a.scheduledDate || !b.scheduledDate) {
                    return 0;
                }
                return a.scheduledDate - b.scheduledDate;
            });
        });

        for (var index = 0; index < 42; index++) {
            var cellDate = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
            var dayCell = $('<div class="calendar-day"></div>');
            if (cellDate.getMonth() !== state.currentMonth.getMonth()) {
                dayCell.addClass('is-outside');
            }
            var today = new Date();
            if (cellDate.getFullYear() === today.getFullYear() && cellDate.getMonth() === today.getMonth() && cellDate.getDate() === today.getDate()) {
                dayCell.addClass('is-today');
            }
            dayCell.append($('<span class="calendar-day__date"></span>').text(cellDate.getDate()));
            var eventsWrapper = $('<div class="calendar-events"></div>');
            var dayKey = keyForDate(cellDate);
            var events = eventsByDate[dayKey] || [];
            events.forEach(function (tournament) {
                var eventEl = $('<button type="button" class="calendar-event"></button>');
                eventEl.addClass('status-' + (tournament.status || 'draft'));
                eventEl.attr('data-tournament-id', tournament.id);
                eventEl.append($('<span class="calendar-event__name"></span>').text(tournament.name || 'Tournament'));
                if (tournament.scheduledDate) {
                    eventEl.append($('<span class="calendar-event__time"></span>').text(tournament.scheduledDate.toLocaleTimeString(undefined, {
                        hour: 'numeric',
                        minute: '2-digit'
                    })));
                }
                eventEl.attr('title', formatTournamentSchedule(tournament));
                eventsWrapper.append(eventEl);
            });
            dayCell.append(eventsWrapper);
            daysGrid.append(dayCell);
        }

        container.append(header, labelsRow, daysGrid);

        header.find('[data-nav="prev"]').on('click', function () {
            state.currentMonth = new Date(state.currentMonth.getFullYear(), state.currentMonth.getMonth() - 1, 1);
            buildCalendar(container, state);
        });
        header.find('[data-nav="next"]').on('click', function () {
            state.currentMonth = new Date(state.currentMonth.getFullYear(), state.currentMonth.getMonth() + 1, 1);
            buildCalendar(container, state);
        });
    }

    $('.tournament-overview').each(function () {
        var payload = parseJsonPayload($(this), 'tournament');
        if (payload) {
            registerTournament(payload);
        }
    });

    var calendarContainer = $('#tournamentCalendar');
    if (calendarContainer.length) {
        var payload = parseJsonPayload(calendarContainer, 'tournaments') || [];
        var registered = [];
        payload.forEach(function (entry) {
            var stored = registerTournament(entry);
            if (stored) {
                registered.push(stored);
            }
        });
        var calendarState = {
            ids: registered.map(function (entry) {
                return entry.id;
            }),
            currentMonth: determineInitialMonth(registered)
        };
        if (!calendarState.ids.length) {
            calendarState.currentMonth = determineInitialMonth([]);
        }
        buildCalendar(calendarContainer, calendarState);
        calendarContainer.on('click', '.calendar-event', function (event) {
            event.preventDefault();
            var tournamentId = $(this).data('tournamentId');
            openActionsModal(tournamentId);
        });
    }

    function openActionsModal(tournamentId) {
        var modal = $('#tournamentActionsModal');
        var tournament = tournamentDirectory[tournamentId];
        if (!modal.length || !tournament) {
            return;
        }
        modal.data('tournamentId', tournamentId);
        modal.find('.js-action-title').text(tournament.name || 'Tournament');
        modal.find('.js-action-schedule').text(formatTournamentSchedule(tournament));
        modal.find('.js-open-tournament').attr('href', '/?page=admin&t=view&id=' + tournamentId);
        openModal(modal);
    }

    $(document).on('click', '[data-modal-trigger]', function (event) {
        var targetId = $(this).data('modalTrigger');
        if (!targetId) {
            return;
        }
        event.preventDefault();
        var modal = $('#' + targetId);
        if (!modal.length) {
            return;
        }
        if (targetId === 'tournamentSettingsModal') {
            var source = $(this);
            var dataPayload = parseJsonPayload(source, 'tournament');
            if (!dataPayload) {
                var parentWithData = source.closest('[data-tournament]');
                if (parentWithData.length) {
                    dataPayload = parseJsonPayload(parentWithData, 'tournament');
                }
            }
            if (dataPayload) {
                registerTournament(dataPayload);
            }
            var tournamentId = source.data('settingsId');
            if (!tournamentId && dataPayload && typeof dataPayload.id !== 'undefined') {
                tournamentId = dataPayload.id;
            }
            closeModal($('#tournamentActionsModal'), true);
            openSettingsModal(modal, tournamentId);
        } else {
            openModal(modal);
        }
    });

    $(document).on('click', '[data-close-modal]', function (event) {
        event.preventDefault();
        var overlay = $(this).closest('.modal-overlay');
        closeModal(overlay);
    });

    $(document).on('click', '.modal-overlay', function (event) {
        if (event.target === this) {
            closeModal($(this));
        }
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            var openModals = $('.modal-overlay.is-visible');
            if (openModals.length) {
                closeModal($(openModals[openModals.length - 1]));
            }
        }
    });

    $(document).on('click', '[data-open-settings]', function (event) {
        event.preventDefault();
        var modal = $('#tournamentSettingsModal');
        var actions = $('#tournamentActionsModal');
        var tournamentId = actions.data('tournamentId');
        closeModal(actions, true);
        openSettingsModal(modal, tournamentId);
    });

    $('#tournamentSettingsModal').on('click', '[data-toggle-player-list]', function (event) {
        event.preventDefault();
        var modal = $('#tournamentSettingsModal');
        ensurePlayerChecklist(modal);
        var list = modal.find('[data-player-list]');
        var button = $(this);
        if (list.attr('hidden') !== undefined) {
            list.removeAttr('hidden');
            button.attr('aria-expanded', 'true').text('Hide Player List');
        } else {
            list.attr('hidden', 'hidden');
            button.attr('aria-expanded', 'false').text('Add Players');
        }
    });

    $('#tournamentSettingsModal').on('change', '[data-player-list] input[type="checkbox"]', function () {
        renderSelectedPlayers($('#tournamentSettingsModal'));
    });
});
