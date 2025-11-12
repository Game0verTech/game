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

    var bracketContextMenu = null;
    var bracketContextMenuAction = null;
    var bracketContextPayload = null;

    function hideContextMenu() {
        if (!bracketContextMenu) {
            bracketContextPayload = null;
            return;
        }
        if (bracketContextPayload && bracketContextPayload.team && bracketContextPayload.team.length) {
            bracketContextPayload.team.removeClass('is-context-target');
        }
        bracketContextPayload = null;
        bracketContextMenu.removeClass('is-visible').attr('aria-hidden', 'true');
    }

    function getContextMenu() {
        if (bracketContextMenu) {
            return bracketContextMenu;
        }
        bracketContextMenu = $('<div class="bracket-context-menu" role="menu" aria-hidden="true"></div>');
        bracketContextMenuAction = $('<button type="button" class="bracket-context-menu__item"></button>');
        bracketContextMenu.append(bracketContextMenuAction);
        bracketContextMenu.on('contextmenu', function (event) {
            event.preventDefault();
        });
        $('body').append(bracketContextMenu);

        bracketContextMenuAction.on('click', function () {
            var payload = bracketContextPayload;
            hideContextMenu();
            if (!payload) {
                return;
            }
            markWinner(payload.container, payload.tournamentId, payload.matchId, payload.playerId, payload.token);
        });

        $(document).on('click.bracketContext', function (event) {
            if (!bracketContextMenu || !bracketContextMenu.hasClass('is-visible')) {
                return;
            }
            if ($(event.target).closest('.bracket-context-menu').length) {
                return;
            }
            hideContextMenu();
        });

        $(document).on('keydown.bracketContext', function (event) {
            if (event.key === 'Escape') {
                hideContextMenu();
            }
        });

        $(window).on('resize.bracketContext scroll.bracketContext', hideContextMenu);
        $(document).on('wheel.bracketContext', hideContextMenu);

        return bracketContextMenu;
    }

    function positionContextMenu(menu, pageX, pageY) {
        if (!menu || !menu.length) {
            return;
        }

        var scrollLeft = $(window).scrollLeft();
        var scrollTop = $(window).scrollTop();
        var viewportWidth = $(window).width();
        var viewportHeight = $(window).height();
        var minLeft = scrollLeft + 12;
        var minTop = scrollTop + 12;

        menu.css({ left: pageX + 'px', top: pageY + 'px' });
        menu.addClass('is-visible').attr('aria-hidden', 'false');

        var menuWidth = menu.outerWidth();
        var menuHeight = menu.outerHeight();
        var maxLeft = Math.max(minLeft, scrollLeft + viewportWidth - menuWidth - 12);
        var maxTop = Math.max(minTop, scrollTop + viewportHeight - menuHeight - 12);
        var adjustedLeft = Math.min(Math.max(pageX, minLeft), maxLeft);
        var adjustedTop = Math.min(Math.max(pageY, minTop), maxTop);

        menu.css({ left: adjustedLeft + 'px', top: adjustedTop + 'px' });
    }

    function openTeamAction(container, team, coords) {
        hideContextMenu();

        if (!team || !team.length || !team.hasClass('is-selectable')) {
            return;
        }

        var playerId = parseInt(team.attr('data-player-id'), 10);
        if (!playerId) {
            return;
        }

        var matchEl = team.closest('.bracket-match');
        var matchId = parseInt(matchEl.attr('data-match-id'), 10);
        if (!matchId) {
            return;
        }

        var tournamentId = container.data('tournamentId');
        var token = container.data('token');
        if (!tournamentId || !token) {
            return;
        }

        var playerName = $.trim(team.attr('data-player-name') || '') || $.trim(team.find('.label').text()) || 'this player';
        var menu = getContextMenu();
        bracketContextPayload = {
            container: container,
            tournamentId: tournamentId,
            matchId: matchId,
            playerId: playerId,
            token: token,
            team: team,
        };

        bracketContextMenuAction.text('Mark ' + playerName + ' as winner');
        team.addClass('is-context-target');

        var pageX = coords && typeof coords.pageX === 'number' ? coords.pageX : null;
        var pageY = coords && typeof coords.pageY === 'number' ? coords.pageY : null;
        if (pageX === null || pageY === null) {
            var offset = team.offset();
            if (offset) {
                pageX = offset.left + team.outerWidth();
                pageY = offset.top + team.outerHeight() / 2;
            } else {
                pageX = 0;
                pageY = 0;
            }
        }

        positionContextMenu(menu, pageX, pageY);

        if (bracketContextMenuAction && bracketContextMenuAction.length) {
            window.requestAnimationFrame(function () {
                bracketContextMenuAction.trigger('focus');
            });
        }
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
        if (!hasRenderableMatches(data)) {
            container.empty();
            return;
        }

        hideContextMenu();
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
        container.off('scroll.bracketContextMenu').on('scroll.bracketContextMenu', hideContextMenu);
        container.off('contextmenu.bracketAction').on('contextmenu.bracketAction', '.team', function (event) {
            event.preventDefault();
            openTeamAction(container, $(this), event);
        });

        container.off('click.bracketAction').on('click.bracketAction', '.team', function (event) {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            openTeamAction(container, $(this), event);
        });

        container.off('keydown.bracketAction').on('keydown.bracketAction', '.team', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            openTeamAction(container, $(this), {
                pageX: null,
                pageY: null,
            });
        });

        container.off('touchend.bracketAction').on('touchend.bracketAction', '.team', function (event) {
            var touch = event.originalEvent && event.originalEvent.changedTouches ? event.originalEvent.changedTouches[0] : null;
            var coords = touch
                ? { pageX: touch.pageX, pageY: touch.pageY }
                : { pageX: event.pageX || null, pageY: event.pageY || null };
            event.preventDefault();
            openTeamAction(container, $(this), coords);
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
