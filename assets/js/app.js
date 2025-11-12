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

    function tagBracketMatches(container, data) {
        var matches = flattenResults(data && data.results ? data.results : []);
        var matchNodes = container.find('.jQBracket .match');
        matchNodes.each(function (index) {
            var info = matches[index] || {};
            var meta = info.meta || {};
            var matchId = meta.match_id || meta.matchId || null;
            var matchEl = $(this);
            if (matchId) {
                matchEl.attr('data-match-id', matchId);
            } else {
                matchEl.removeAttr('data-match-id');
            }
            matchEl.data('matchMeta', meta);
            var teams = matchEl.find('.team');
            teams.each(function (slotIndex) {
                var team = $(this);
                var playerMeta = slotIndex === 0 ? meta.player1 : meta.player2;
                if (playerMeta && playerMeta.id) {
                    team.attr('data-player-id', playerMeta.id);
                } else {
                    team.removeAttr('data-player-id');
                }
                team.attr('data-slot', slotIndex + 1);
                var label = computeStatusLabel(info, slotIndex, meta);
                if (label) {
                    team.attr('data-status-label', label);
                } else {
                    team.removeAttr('data-status-label');
                }
                team.removeClass('status-win status-loss status-ready status-tbd');
                if (label === 'WIN') {
                    team.addClass('status-win');
                } else if (label === 'LOSS') {
                    team.addClass('status-loss');
                } else if (label === 'READY') {
                    team.addClass('status-ready');
                } else {
                    team.addClass('status-tbd');
                }
            });
        });
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
            return;
        }

        hideContextMenu();

        var serialized = JSON.stringify(data);
        var fallbackMarkup = container.children().detach();
        var fallbackState = container.data('bracketState');
        var wrapper = $('<div class="bracket-root"></div>');
        var options = {
            init: data,
            teamWidth: 220,
            scoreWidth: 0,
            matchMargin: 28,
            roundMargin: 120,
            disableToolbar: true,
            disableTeamEdit: true,
            save: function () {}
        };

        container.empty().append(wrapper);

        try {
            wrapper.bracket(options);
        } catch (error) {
            console.error('Bracket rendering failed', error);
            container.empty().append(fallbackMarkup);
            if (typeof fallbackState !== 'undefined') {
                container.data('bracketState', fallbackState);
            }
            return;
        }

        fallbackMarkup.remove();
        tagBracketMatches(container, data);
        container.data('bracketState', serialized);
        container.data('bracketData', data);
        if (mode === 'admin') {
            updateMatchSummary(container, data);
        }
        enableAdminControls(container, mode);
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
        container.off('contextmenu.bracket').on('contextmenu.bracket', '.team', function (event) {
            event.preventDefault();
            hideContextMenu();
            var team = $(this);
            var playerId = parseInt(team.attr('data-player-id'), 10);
            if (!playerId) {
                return;
            }
            var matchEl = team.closest('.match');
            var matchId = parseInt(matchEl.attr('data-match-id'), 10);
            if (!matchId) {
                return;
            }
            var tournamentId = container.data('tournamentId');
            var token = container.data('token');
            if (!tournamentId || !token) {
                return;
            }
            var playerName = $.trim(team.find('.label').text()) || 'this player';
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
            positionContextMenu(menu, event.pageX, event.pageY);
            if (bracketContextMenuAction && bracketContextMenuAction.length) {
                window.requestAnimationFrame(function () {
                    bracketContextMenuAction.trigger('focus');
                });
            }
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
