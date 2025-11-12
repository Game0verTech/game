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
        if (options.score !== null && options.score !== undefined) {
            score.text(options.score);
        } else {
            score.text('\u00a0');
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

        team.removeClass('status-win status-loss status-ready status-tbd');
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
            if (mode === 'admin') {
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
            if (mode === 'admin') {
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
                    });
                    matchEl.append(team);
                });

                matchesContainer.append(matchEl);
            });

            columns.append(roundEl);
        });

        container.data('bracketState', serialized);
        container.data('bracketData', data);

        if (mode === 'admin') {
            updateMatchSummary(container, data);
        }

        enableAdminControls(container, mode);

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
        if (!tournamentId || !shouldPoll(container, mode)) {
            return;
        }
        var interval = parseInt(container.data('refreshInterval'), 10);
        if (!interval || interval < 3000) {
            interval = 5000;
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

    function markWinner(container, tournamentId, matchId, playerId, token) {
        if (!token) {
            console.warn('Missing CSRF token for bracket update.');
            return;
        }
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
                if (response && response.bracket) {
                    renderBracket(container, response.bracket, 'admin');
                }
            })
            .fail(function (xhr) {
                var message = 'Unable to update match.';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    message = xhr.responseJSON.error;
                } else if (xhr.responseText) {
                    message = xhr.responseText;
                }
                var detail = ['Status ' + xhr.status, xhr.statusText].filter(Boolean).join(' ');
                if (detail) {
                    message += '\n(' + detail + ')';
                }
                window.alert(message);
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
        var mode = container.data('mode') || 'viewer';
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
});
