<?php

function default_tournament_location(): string
{
    return 'Kenton Moose Lodge Basement';
}

function ensure_tournament_schedule_columns(): bool
{
    static $ensured;

    if ($ensured !== null) {
        return $ensured;
    }

    try {
        $pdo = db();
        $statement = $pdo->query('SHOW COLUMNS FROM tournaments');
        $columns = $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];
        $hasScheduled = in_array('scheduled_at', $columns, true);
        $hasLocation = in_array('location', $columns, true);

        if (!$hasScheduled) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN scheduled_at DATETIME DEFAULT NULL AFTER status");
        }

        if (!$hasLocation) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER scheduled_at");
        }

        if (!$hasScheduled || !$hasLocation) {
            $statement = $pdo->query('SHOW COLUMNS FROM tournaments');
            $columns = $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];
            $hasScheduled = in_array('scheduled_at', $columns, true);
            $hasLocation = in_array('location', $columns, true);
        }

        $ensured = $hasScheduled && $hasLocation;
    } catch (Throwable $e) {
        error_log('Failed to ensure tournament schedule columns: ' . $e->getMessage());
        $ensured = false;
    }

    return $ensured;
}

function create_tournament(string $name, string $type, string $description, int $createdBy, ?string $scheduledAt = null, ?string $location = null): array
{
    $supportsSchedule = ensure_tournament_schedule_columns();

    if ($supportsSchedule) {
        $stmt = db()->prepare("INSERT INTO tournaments (name, type, description, status, scheduled_at, location, created_by) VALUES (:name, :type, :description, 'draft', :scheduled_at, :location, :created_by)");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':description' => $description,
            ':scheduled_at' => $scheduledAt,
            ':location' => $location,
            ':created_by' => $createdBy,
        ]);
    } else {
        $stmt = db()->prepare("INSERT INTO tournaments (name, type, description, status, created_by) VALUES (:name, :type, :description, 'draft', :created_by)");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':description' => $description,
            ':created_by' => $createdBy,
        ]);
    }

    return get_tournament((int)db()->lastInsertId());
}

function get_tournament(int $id): ?array
{
    ensure_tournament_schedule_columns();
    $stmt = db()->prepare('SELECT * FROM tournaments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $tournament = $stmt->fetch() ?: null;
    if (!$tournament) {
        return null;
    }

    return ensure_tournament_schedule($tournament, 0);
}

function list_tournaments(?string $status = null): array
{
    ensure_tournament_schedule_columns();
    if ($status) {
        $stmt = db()->prepare('SELECT * FROM tournaments WHERE status = :status ORDER BY created_at DESC');
        $stmt->execute([':status' => $status]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = db()->query('SELECT * FROM tournaments ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as $index => $row) {
        $rows[$index] = ensure_tournament_schedule($row, $index);
    }

    return $rows;
}

function update_tournament_status(int $id, string $status): void
{
    $stmt = db()->prepare('UPDATE tournaments SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);
}

function update_tournament_json(int $id, ?string $bracketJson, ?string $groupJson): void
{
    $stmt = db()->prepare('UPDATE tournaments SET bracket_json = :bracket, groups_json = :groups WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($bracketJson === null) {
        $stmt->bindValue(':bracket', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':bracket', $bracketJson, PDO::PARAM_STR);
    }
    if ($groupJson === null) {
        $stmt->bindValue(':groups', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':groups', $groupJson, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function tournament_bracket_snapshot(array $tournament): ?string
{
    $id = isset($tournament['id']) ? (int)$tournament['id'] : 0;
    if ($id <= 0) {
        return $tournament['bracket_json'] ?? null;
    }

    $status = $tournament['status'] ?? null;
    $existing = $tournament['bracket_json'] ?? null;
    $shouldRefresh = in_array($status, ['live', 'completed'], true) || empty($existing);

    if (!$shouldRefresh && $existing !== null) {
        return $existing;
    }

    $structure = generate_bracket_structure($id);
    if (empty($structure)) {
        return $existing;
    }

    try {
        return safe_json_encode($structure);
    } catch (Throwable $e) {
        error_log(sprintf('Failed to encode bracket for tournament %d: %s', $id, $e->getMessage()));
        return $existing;
    }
}

function normalize_tournament_schedule_input(?string $date, ?string $time): ?string
{
    $date = trim((string)($date ?? ''));
    $time = trim((string)($time ?? ''));

    if ($date === '' && $time === '') {
        return null;
    }

    if ($date === '') {
        return null;
    }

    if ($time === '') {
        $time = '18:00';
    }

    $dateTime = DateTime::createFromFormat('Y-m-d H:i', sprintf('%s %s', $date, $time));
    if (!$dateTime) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function update_tournament_schedule(int $id, ?string $scheduledAt, ?string $location): void
{
    if (!ensure_tournament_schedule_columns()) {
        return;
    }
    $stmt = db()->prepare('UPDATE tournaments SET scheduled_at = :scheduled_at, location = :location WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($scheduledAt === null) {
        $stmt->bindValue(':scheduled_at', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':scheduled_at', $scheduledAt, PDO::PARAM_STR);
    }
    if ($location === null) {
        $stmt->bindValue(':location', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':location', $location, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function ensure_tournament_schedule(array $tournament, int $offset): array
{
    if (!isset($tournament['id'])) {
        return $tournament;
    }

    $needsUpdate = false;
    $scheduledAt = $tournament['scheduled_at'] ?? null;
    $location = $tournament['location'] ?? null;

    if (empty($scheduledAt)) {
        $base = new DateTimeImmutable('+3 days');
        if ($offset > 0) {
            $base = $base->modify('+' . ($offset * 2) . ' days');
        }
        $base = $base->setTime(18, 0);
        $scheduledAt = $base->format('Y-m-d H:i:s');
        $needsUpdate = true;
    }

    if (empty($location)) {
        $location = default_tournament_location();
        $needsUpdate = true;
    }

    if ($needsUpdate) {
        update_tournament_schedule((int)$tournament['id'], $scheduledAt, $location);
        $tournament['scheduled_at'] = $scheduledAt;
        $tournament['location'] = $location;
    }

    return $tournament;
}

function update_tournament_details(int $id, string $name, string $type, string $description, ?string $scheduledAt, ?string $location): void
{
    if (ensure_tournament_schedule_columns()) {
        $stmt = db()->prepare('UPDATE tournaments SET name = :name, type = :type, description = :description, scheduled_at = :scheduled_at, location = :location WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        if ($scheduledAt === null) {
            $stmt->bindValue(':scheduled_at', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':scheduled_at', $scheduledAt, PDO::PARAM_STR);
        }
        if ($location === null) {
            $stmt->bindValue(':location', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':location', $location, PDO::PARAM_STR);
        }
        $stmt->execute();
        return;
    }

    $stmt = db()->prepare('UPDATE tournaments SET name = :name, type = :type, description = :description WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->execute();
}

function set_tournament_players(int $tournamentId, array $userIds): void
{
    $normalized = [];
    foreach ($userIds as $userId) {
        $userId = (int)$userId;
        if ($userId > 0) {
            $normalized[$userId] = $userId;
        }
    }

    $existing = tournament_players($tournamentId);
    $existingIds = array_map(static fn($player) => (int)$player['user_id'], $existing);

    $toAdd = array_diff($normalized, $existingIds);
    $toRemove = array_diff($existingIds, $normalized);

    foreach ($toAdd as $userId) {
        add_player_to_tournament($tournamentId, $userId);
    }

    foreach ($toRemove as $userId) {
        remove_player_from_tournament($tournamentId, $userId);
    }
}

function add_player_to_tournament(int $tournamentId, int $userId): bool
{
    $stmt = db()->prepare('INSERT IGNORE INTO tournament_players (tournament_id, user_id) VALUES (:tid, :uid)');
    return $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
}

function is_user_registered(int $tournamentId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM tournament_players WHERE tournament_id = :tid AND user_id = :uid');
    $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function remove_player_from_tournament(int $tournamentId, int $userId): void
{
    $stmt = db()->prepare('DELETE FROM tournament_players WHERE tournament_id = :tid AND user_id = :uid');
    $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
}

function tournament_players(int $tournamentId): array
{
    $sql = 'SELECT tp.*, u.username FROM tournament_players tp INNER JOIN users u ON tp.user_id = u.id WHERE tp.tournament_id = :tid ORDER BY COALESCE(tp.seed, tp.id)';
    $stmt = db()->prepare($sql);
    $stmt->execute([':tid' => $tournamentId]);
    return $stmt->fetchAll();
}

function user_tournaments(int $userId): array
{
    ensure_tournament_schedule_columns();
    $sql = 'SELECT t.* FROM tournaments t INNER JOIN tournament_players tp ON t.id = tp.tournament_id WHERE tp.user_id = :uid ORDER BY t.updated_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function available_tournaments_for_user(): array
{
    ensure_tournament_schedule_columns();
    $stmt = db()->query('SELECT * FROM tournaments WHERE status = "open" ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function generate_bracket_structure(int $tournamentId): array
{
    $tournament = get_tournament($tournamentId);
    if (!$tournament) {
        return [];
    }

    if ($tournament['type'] === 'round-robin') {
        $players = tournament_players($tournamentId);
        $teamNames = array_map(fn($p) => ['name' => $p['username']], $players);
        $results = [];
        foreach ($teamNames as $i => $team) {
            $row = [];
            foreach ($teamNames as $j => $opponent) {
                $row[] = $i === $j ? null : [null, null];
            }
            $results[] = $row;
        }
        return ['teams' => $teamNames, 'results' => $results];
    }

    if ($tournament['type'] === 'single') {
        return build_single_elimination_bracket($tournamentId);
    }

    if ($tournament['type'] === 'double') {
        return build_double_elimination_bracket($tournamentId);
    }

    return [];
}

function double_elimination_loser_round_match_count(int $round, int $slotCount): int
{
    if ($round <= 0) {
        return 0;
    }
    $slotCount = max(2, next_power_of_two($slotCount));
    if ($round % 2 === 1) {
        $exponent = (int)(((($round + 1) / 2)) + 1);
    } else {
        $exponent = (int)(($round / 2) + 1);
    }
    $divisor = (int)pow(2, max(1, $exponent));
    $matches = (int)max(1, $slotCount / $divisor);
    return $matches;
}

function assign_bracket_source(array &$layout, array $source, array $destination): void
{
    $stage = $destination['stage'] ?? null;
    $round = isset($destination['round']) ? (int)$destination['round'] : null;
    $matchIndex = isset($destination['match_index']) ? (int)$destination['match_index'] : null;
    $slot = isset($destination['slot']) ? (int)$destination['slot'] : 1;
    if (!$stage || !$round || !$matchIndex || !isset($layout[$stage][$round][$matchIndex])) {
        return;
    }
    $slot = $slot === 2 ? 2 : 1;
    $layout[$stage][$round][$matchIndex]['sources'][$slot] ??= [
        'stage' => $source['stage'],
        'round' => $source['round'],
        'match_index' => $source['match_index'],
    ];
}

function build_double_elimination_layout(int $slotCount, array $firstRoundPairs = []): array
{
    $slotCount = max(2, next_power_of_two($slotCount));
    $winnersRounds = (int)max(1, round(log($slotCount, 2)));
    $losersRoundsOriginal = $winnersRounds > 1 ? 2 * ($winnersRounds - 1) : 0;

    $firstRoundHasTwoPlayers = [];
    $meaningfulFirstRoundLosers = 0;
    foreach ($firstRoundPairs as $index => $pair) {
        $player1Id = $pair['player1']['id'] ?? null;
        $player2Id = $pair['player2']['id'] ?? null;
        $hasBoth = $player1Id && $player2Id;
        $firstRoundHasTwoPlayers[$index + 1] = $hasBoth;
        if ($hasBoth) {
            $meaningfulFirstRoundLosers++;
        }
    }

    $skipInitialLosersRound = $losersRoundsOriginal > 0 && $meaningfulFirstRoundLosers <= 1;
    $losersRoundOffset = $skipInitialLosersRound ? 1 : 0;
    $losersRounds = max(0, $losersRoundsOriginal - $losersRoundOffset);
    $maxEffectiveLosersRound = $losersRoundsOriginal;

    $losersRoundMap = [];
    for ($round = 1; $round <= $losersRounds; $round++) {
        $effectiveRound = $round + $losersRoundOffset;
        $losersRoundMap[$effectiveRound] = $round;
    }

    $layout = [
        'winners' => [],
        'losers' => [],
        'finals' => [],
        'slot_count' => $slotCount,
        'losers_rounds' => $losersRounds,
        'losers_round_offset' => $losersRoundOffset,
        'losers_round_map' => $losersRoundMap,
        'losers_rounds_effective' => $maxEffectiveLosersRound,
    ];

    for ($round = 1; $round <= $winnersRounds; $round++) {
        $matchCount = (int)max(1, $slotCount / pow(2, $round));
        $layout['winners'][$round] = [];
        for ($match = 1; $match <= $matchCount; $match++) {
            $node = [
                'stage' => 'winners',
                'round' => $round,
                'match_index' => $match,
                'sources' => [],
            ];
            if ($round > 1) {
                $node['sources'][1] = [
                    'stage' => 'winners',
                    'round' => $round - 1,
                    'match_index' => ($match * 2) - 1,
                ];
                $node['sources'][2] = [
                    'stage' => 'winners',
                    'round' => $round - 1,
                    'match_index' => $match * 2,
                ];
            }
            $layout['winners'][$round][$match] = $node;
        }
    }

    $resolveLosersRound = static function (array $map, int $effectiveRound): ?int {
        return $map[$effectiveRound] ?? null;
    };

    $findPreviousEffectiveRound = static function (array $map, int $current): ?int {
        for ($candidate = $current - 1; $candidate >= 1; $candidate--) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
        return null;
    };

    $findNextEffectiveRound = static function (array $map, int $current, int $maxEffective): ?int {
        for ($candidate = $current + 1; $candidate <= $maxEffective; $candidate++) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
        return null;
    };

    for ($round = 1; $round <= $losersRounds; $round++) {
        $effectiveRound = $round + $losersRoundOffset;
        $matchCount = double_elimination_loser_round_match_count($effectiveRound, $slotCount);
        $layout['losers'][$round] = [];
        for ($match = 1; $match <= $matchCount; $match++) {
            $node = [
                'stage' => 'losers',
                'round' => $round,
                'match_index' => $match,
                'sources' => [],
                'effective_round' => $effectiveRound,
            ];
            $previousEffective = $findPreviousEffectiveRound($losersRoundMap, $effectiveRound);
            if ($previousEffective !== null) {
                $previousRound = $resolveLosersRound($losersRoundMap, $previousEffective);
                if ($previousRound !== null) {
                    if ($effectiveRound % 2 === 1 && $effectiveRound > 1) {
                        $node['sources'][1] = [
                            'stage' => 'losers',
                            'round' => $previousRound,
                            'match_index' => ($match * 2) - 1,
                        ];
                        $node['sources'][2] = [
                            'stage' => 'losers',
                            'round' => $previousRound,
                            'match_index' => $match * 2,
                        ];
                    } elseif ($effectiveRound % 2 === 0) {
                        $node['sources'][1] = [
                            'stage' => 'losers',
                            'round' => $previousRound,
                            'match_index' => $match,
                        ];
                    }
                }
            }
            $layout['losers'][$round][$match] = $node;
        }
    }

    $layout['finals'][1] = [
        1 => [
            'stage' => 'finals',
            'round' => 1,
            'match_index' => 1,
            'sources' => [],
        ],
    ];

    $maxEffectiveRound = $layout['losers_rounds_effective'] ?? $losersRoundsOriginal;
    foreach ($layout['winners'] as $round => &$roundMatches) {
        foreach ($roundMatches as $matchIndex => &$node) {
            if ($round < $winnersRounds) {
                $node['next_winner'] = [
                    'stage' => 'winners',
                    'round' => $round + 1,
                    'match_index' => (int)ceil($matchIndex / 2),
                    'slot' => ($matchIndex % 2 === 1) ? 1 : 2,
                ];
            } else {
                $node['next_winner'] = [
                    'stage' => 'finals',
                    'round' => 1,
                    'match_index' => 1,
                    'slot' => 1,
                ];
            }

            if (!empty($layout['losers_rounds'])) {
                $destRound = null;
                $destMatch = null;
                $slot = 2;
                if ($round === 1) {
                    $targetEffective = 1 + ($layout['losers_round_offset'] ?? 0);
                    $destRound = $resolveLosersRound($layout['losers_round_map'] ?? [], $targetEffective);
                    $destMatch = (int)ceil($matchIndex / 2);
                    $slot = ($matchIndex % 2 === 1) ? 1 : 2;
                    $hasTwoPlayers = $firstRoundHasTwoPlayers[$matchIndex] ?? true;
                    if (!$hasTwoPlayers) {
                        $destRound = null;
                    } elseif (($layout['losers_round_offset'] ?? 0) > 0) {
                        $slot = 1;
                    }
                } else {
                    $targetEffective = min($maxEffectiveRound, 2 * ($round - 1));
                    while ($targetEffective >= 1 && !isset(($layout['losers_round_map'] ?? [])[$targetEffective])) {
                        $targetEffective--;
                    }
                    if ($targetEffective >= 1) {
                        $destRound = $resolveLosersRound($layout['losers_round_map'] ?? [], $targetEffective);
                        $destMatch = $matchIndex;
                        $slot = 2;
                    }
                }
                if ($destRound !== null && $destRound >= 1 && $destMatch !== null) {
                    $node['next_loser'] = [
                        'stage' => 'losers',
                        'round' => $destRound,
                        'match_index' => $destMatch,
                        'slot' => $slot,
                    ];
                }
            }
        }
        unset($node);
    }
    unset($roundMatches);

    foreach ($layout['losers'] as $round => &$roundMatches) {
        foreach ($roundMatches as $matchIndex => &$node) {
            $effectiveRound = $node['effective_round'] ?? $round;
            $nextEffective = $findNextEffectiveRound($layout['losers_round_map'] ?? [], $effectiveRound, $maxEffectiveRound);
            if ($nextEffective !== null) {
                $nextRound = $resolveLosersRound($layout['losers_round_map'] ?? [], $nextEffective);
                if ($nextRound !== null) {
                    if ($effectiveRound % 2 === 1) {
                        $node['next_winner'] = [
                            'stage' => 'losers',
                            'round' => $nextRound,
                            'match_index' => $matchIndex,
                            'slot' => 1,
                        ];
                    } else {
                        $node['next_winner'] = [
                            'stage' => 'losers',
                            'round' => $nextRound,
                            'match_index' => (int)ceil($matchIndex / 2),
                            'slot' => ($matchIndex % 2 === 1) ? 1 : 2,
                        ];
                    }
                }
            } else {
                $node['next_winner'] = [
                    'stage' => 'finals',
                    'round' => 1,
                    'match_index' => 1,
                    'slot' => 2,
                ];
            }
        }
        unset($node);
    }
    unset($roundMatches);

    foreach ($layout['winners'] as $roundMatches) {
        foreach ($roundMatches as $node) {
            if (!empty($node['next_loser'])) {
                assign_bracket_source($layout, $node, $node['next_loser']);
            }
            if (!empty($node['next_winner'])) {
                assign_bracket_source($layout, $node, $node['next_winner']);
            }
        }
    }

    foreach ($layout['losers'] as $roundMatches) {
        foreach ($roundMatches as $node) {
            if (!empty($node['next_winner'])) {
                assign_bracket_source($layout, $node, $node['next_winner']);
            }
        }
    }

    foreach ($layout['losers'] as $round => &$roundMatches) {
        foreach ($roundMatches as $matchIndex => &$node) {
            unset($node['effective_round']);
        }
        unset($node);
    }
    unset($roundMatches);

    unset($layout['losers_round_map'], $layout['losers_round_offset'], $layout['losers_rounds_effective']);

    return $layout;
}

function build_double_elimination_bracket(int $tournamentId): array
{
    $players = tournament_players($tournamentId);
    $playerCount = count($players);
    $slotCount = max(2, next_power_of_two(max(1, $playerCount)));
    $pairs = single_elimination_pairs($players);
    $layout = build_double_elimination_layout($slotCount, $pairs);

    $matches = tournament_matches($tournamentId);
    $matchMap = [];
    foreach ($matches as $match) {
        $stage = $match['stage'];
        $round = (int)$match['round'];
        $index = (int)$match['match_index'];
        $matchMap[$stage][$round][$index] = $match;
    }

    $teams = [];
    if (!empty($matchMap['winners'][1])) {
        $firstRound = $matchMap['winners'][1];
        ksort($firstRound);
        foreach ($firstRound as $match) {
            $teams[] = [
                $match['player1_name'] ?? 'TBD',
                $match['player2_name'] ?? 'TBD',
            ];
        }
    } else {
        foreach ($pairs as $pair) {
            $teams[] = [
                $pair['player1']['name'],
                $pair['player2']['name'],
            ];
        }
    }
    if (empty($teams)) {
        $teams[] = ['TBD', 'TBD'];
    }

    $stageOrder = [
        ['key' => 'winners', 'title' => 'Winners Bracket'],
        ['key' => 'losers', 'title' => 'Losers Bracket'],
        ['key' => 'finals', 'title' => 'Finals'],
    ];

    $results = [
        'winners' => [],
        'losers' => [],
        'finals' => [],
    ];

    $buildMatch = function (?array $match, array $node, int $roundIndex, int $zeroBasedMatch) use ($layout) {
        $score1 = null;
        $score2 = null;
        $player1 = null;
        $player2 = null;
        $winner = null;
        $matchId = null;
        $metaSources = $node['sources'] ?? [];
        if ($match) {
            $matchId = (int)$match['id'];
            $score1 = $match['score1'];
            $score2 = $match['score2'];
            if ($match['winner_user_id'] && $score1 === null && $score2 === null) {
                if ((int)$match['winner_user_id'] === (int)($match['player1_user_id'] ?? 0)) {
                    $score1 = 1;
                    $score2 = 0;
                } elseif ((int)$match['winner_user_id'] === (int)($match['player2_user_id'] ?? 0)) {
                    $score1 = 0;
                    $score2 = 1;
                }
            }
            $player1 = $match['player1_user_id'] ? [
                'id' => (int)$match['player1_user_id'],
                'name' => $match['player1_name'] ?? 'TBD',
            ] : null;
            $player2 = $match['player2_user_id'] ? [
                'id' => (int)$match['player2_user_id'],
                'name' => $match['player2_name'] ?? 'TBD',
            ] : null;
            $winner = $match['winner_user_id'] ? [
                'id' => (int)$match['winner_user_id'],
                'name' => $match['winner_name'] ?? null,
            ] : null;
            $storedMeta = $match['meta'] ?? null;
            if ($storedMeta) {
                if (is_string($storedMeta)) {
                    $decoded = json_decode($storedMeta, true);
                } elseif (is_array($storedMeta)) {
                    $decoded = $storedMeta;
                } else {
                    $decoded = null;
                }
                if (is_array($decoded) && !empty($decoded['sources']) && is_array($decoded['sources'])) {
                    $metaSources = $decoded['sources'];
                }
            }
        }

        $entry = [
            $score1 === null ? null : (int)$score1,
            $score2 === null ? null : (int)$score2,
            [
                'match_id' => $matchId,
                'stage' => $node['stage'],
                'round_index' => $roundIndex,
                'match_index' => $zeroBasedMatch,
                'round_number' => $node['round'],
                'match_number' => $node['match_index'],
                'player1' => $player1,
                'player2' => $player2,
                'winner' => $winner,
                'sources' => $metaSources,
            ],
        ];
        return $entry;
    };

    foreach ($layout['winners'] as $round => $roundMatches) {
        $roundData = [];
        ksort($roundMatches);
        foreach ($roundMatches as $matchIndex => $node) {
            $match = $matchMap['winners'][$round][$matchIndex] ?? null;
            $roundData[] = $buildMatch($match, $node, $round - 1, $matchIndex - 1);
        }
        $results['winners'][] = $roundData;
    }

    foreach ($layout['losers'] as $round => $roundMatches) {
        $roundData = [];
        ksort($roundMatches);
        foreach ($roundMatches as $matchIndex => $node) {
            $match = $matchMap['losers'][$round][$matchIndex] ?? null;
            $roundData[] = $buildMatch($match, $node, $round - 1, $matchIndex - 1);
        }
        $results['losers'][] = $roundData;
    }

    foreach ($layout['finals'] as $round => $roundMatches) {
        $roundData = [];
        ksort($roundMatches);
        foreach ($roundMatches as $matchIndex => $node) {
            $match = $matchMap['finals'][$round][$matchIndex] ?? null;
            $roundData[] = $buildMatch($match, $node, $round - 1, $matchIndex - 1);
        }
        $results['finals'][] = $roundData;
    }

    return [
        'teams' => $teams,
        'results' => $results,
        'meta' => [
            'format' => 'double',
            'stages' => $stageOrder,
        ],
    ];
}

function build_single_elimination_bracket(int $tournamentId): array
{
    $matches = tournament_matches($tournamentId);
    $rounds = [];
    $teams = [];
    if ($matches) {
        foreach ($matches as $match) {
            if ($match['stage'] !== 'main') {
                continue;
            }
            $roundIndex = max(0, (int)$match['round'] - 1);
            $rounds[$roundIndex] ??= [];
            $score1 = $match['score1'];
            $score2 = $match['score2'];
            if ($match['winner_user_id'] && $score1 === null && $score2 === null) {
                if ($match['winner_user_id'] === $match['player1_user_id']) {
                    $score1 = 1;
                    $score2 = 0;
                } elseif ($match['winner_user_id'] === $match['player2_user_id']) {
                    $score1 = 0;
                    $score2 = 1;
                }
            }
            $matchNumber = count($rounds[$roundIndex] ?? []) + 1;
            $sources = [];
            if ($roundIndex > 0) {
                $sources = [
                    1 => [
                        'stage' => 'main',
                        'round' => $roundIndex,
                        'match_index' => $matchNumber * 2 - 1,
                    ],
                    2 => [
                        'stage' => 'main',
                        'round' => $roundIndex,
                        'match_index' => $matchNumber * 2,
                    ],
                ];
            }

            $rounds[$roundIndex][] = [
                $score1 === null ? null : (int)$score1,
                $score2 === null ? null : (int)$score2,
                [
                    'match_id' => (int)$match['id'],
                    'round' => $roundIndex,
                    'match' => $matchNumber - 1,
                    'round_index' => $roundIndex,
                    'match_index' => $matchNumber - 1,
                    'round_number' => $roundIndex + 1,
                    'match_number' => $matchNumber,
                    'player1' => $match['player1_user_id'] ? [
                        'id' => (int)$match['player1_user_id'],
                        'name' => $match['player1_name'] ?? 'TBD',
                    ] : null,
                    'player2' => $match['player2_user_id'] ? [
                        'id' => (int)$match['player2_user_id'],
                        'name' => $match['player2_name'] ?? 'TBD',
                    ] : null,
                    'winner' => $match['winner_user_id'] ? [
                        'id' => (int)$match['winner_user_id'],
                        'name' => $match['winner_name'] ?? null,
                    ] : null,
                    'sources' => $sources,
                ],
            ];
            if ((int)$match['round'] === 1) {
                $teams[] = [
                    $match['player1_name'] ?? 'TBD',
                    $match['player2_name'] ?? 'TBD',
                ];
            }
        }
    }

    if (empty($teams)) {
        $players = tournament_players($tournamentId);
        $pairs = single_elimination_pairs($players);
        foreach ($pairs as $pair) {
            $teams[] = [$pair['player1']['name'], $pair['player2']['name']];
        }
        $matchesInRound = count($teams);
        while ($matchesInRound > 0) {
            $round = [];
            for ($i = 0; $i < $matchesInRound; $i++) {
                $round[] = [null, null, [
                    'match_id' => null,
                    'round' => count($rounds),
                    'match' => $i,
                    'round_index' => count($rounds),
                    'match_index' => $i,
                    'round_number' => count($rounds) + 1,
                    'match_number' => $i + 1,
                    'player1' => null,
                    'player2' => null,
                    'winner' => null,
                    'sources' => count($rounds) > 0 ? [
                        1 => [
                            'stage' => 'main',
                            'round' => count($rounds),
                            'match_index' => ($i + 1) * 2 - 1,
                        ],
                        2 => [
                            'stage' => 'main',
                            'round' => count($rounds),
                            'match_index' => ($i + 1) * 2,
                        ],
                    ] : [],
                ]];
            }
            $rounds[] = $round;
            if ($matchesInRound === 1) {
                break;
            }
            $matchesInRound = (int)ceil($matchesInRound / 2);
        }
    }

    ksort($rounds);
    $rounds = array_values(array_map(function (array $round) {
        return array_values($round);
    }, $rounds));

    return ['teams' => $teams, 'results' => $rounds];
}

function next_power_of_two(int $number): int
{
    if ($number < 1) {
        return 1;
    }
    $power = 1;
    while ($power < $number) {
        $power <<= 1;
    }
    return $power;
}

function single_elimination_pairs(array $players): array
{
    $slots = array_map(static function ($player) {
        return [
            'id' => isset($player['user_id']) ? (int)$player['user_id'] : null,
            'name' => $player['username'] ?? 'TBD',
        ];
    }, $players);

    $slotCount = max(2, next_power_of_two(count($slots)));
    $byeMatches = $slotCount - count($slots);
    $pairs = [];
    $index = 0;

    for ($i = 0; $i < $slotCount / 2; $i++) {
        $team1 = $slots[$index] ?? null;
        if ($team1 !== null) {
            $index++;
        }
        if ($team1 === null) {
            $team1 = ['id' => null, 'name' => 'BYE'];
        }

        if ($byeMatches > 0) {
            $team2 = ['id' => null, 'name' => 'BYE'];
            $byeMatches--;
        } else {
            $team2 = $slots[$index] ?? null;
            if ($team2 !== null) {
                $index++;
            }
            if ($team2 === null) {
                $team2 = ['id' => null, 'name' => 'BYE'];
            }
        }

        $pairs[] = [
            'player1' => $team1,
            'player2' => $team2,
        ];
    }

    return $pairs;
}

function seed_matches_for_tournament(int $tournamentId): void
{
    $tournament = get_tournament($tournamentId);
    $players = tournament_players($tournamentId);
    db()->prepare('DELETE FROM tournament_matches WHERE tournament_id = :tid')->execute([':tid' => $tournamentId]);

    if ($tournament['type'] === 'round-robin') {
        $teamIds = array_column($players, 'user_id');
        foreach ($teamIds as $i => $player1) {
            for ($j = $i + 1; $j < count($teamIds); $j++) {
                $player2 = $teamIds[$j];
                $stmt = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
                $stmt->execute([
                    ':tid' => $tournamentId,
                    ':stage' => 'group',
                    ':round' => $i + 1,
                    ':index' => $j,
                    ':p1' => $player1,
                    ':p2' => $player2,
                ]);
            }
        }
        return;
    }

    if ($tournament['type'] === 'single') {
        $pairs = single_elimination_pairs($players);
        $round = 1;
        $matchesInRound = count($pairs);
        $insert = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
        while ($matchesInRound >= 1) {
            for ($i = 0; $i < $matchesInRound; $i++) {
                if ($round === 1) {
                    $p1 = $pairs[$i]['player1']['id'];
                    $p2 = $pairs[$i]['player2']['id'];
                } else {
                    $p1 = null;
                    $p2 = null;
                }
                $insert->execute([
                    ':tid' => $tournamentId,
                    ':stage' => 'main',
                    ':round' => $round,
                    ':index' => $i + 1,
                    ':p1' => $p1,
                    ':p2' => $p2,
                ]);
            }
            if ($matchesInRound === 1) {
                break;
            }
            $matchesInRound = (int)ceil($matchesInRound / 2);
            $round++;
        }
        return;
    }

    if ($tournament['type'] === 'double') {
        seed_double_elimination_matches($tournamentId, $players);
        return;
    }

    $matches = array_chunk($players, 2);
    foreach ($matches as $index => $pair) {
        $p1 = $pair[0]['user_id'] ?? null;
        $p2 = $pair[1]['user_id'] ?? null;
        $stmt = db()->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id) VALUES (:tid, :stage, :round, :index, :p1, :p2)');
        $stmt->execute([
            ':tid' => $tournamentId,
            ':stage' => 'main',
            ':round' => 1,
            ':index' => $index + 1,
            ':p1' => $p1,
            ':p2' => $p2,
        ]);
    }
}

function seed_double_elimination_matches(int $tournamentId, array $players): void
{
    $pairs = single_elimination_pairs($players);
    $slotCount = max(2, next_power_of_two(max(1, count($players))));
    $layout = build_double_elimination_layout($slotCount, $pairs);

    $pdo = db();
    $insert = $pdo->prepare('INSERT INTO tournament_matches (tournament_id, stage, round, match_index, player1_user_id, player2_user_id, meta) VALUES (:tid, :stage, :round, :index, :p1, :p2, :meta)');

    foreach ($layout['winners'] as $round => $roundMatches) {
        foreach ($roundMatches as $matchIndex => $node) {
            $pair = ($round === 1) ? ($pairs[$matchIndex - 1] ?? null) : null;
            $p1 = $pair['player1']['id'] ?? null;
            $p2 = $pair['player2']['id'] ?? null;
            $meta = [
                'stage' => 'winners',
                'sources' => $node['sources'] ?? [],
            ];
            if (!empty($node['next_winner'])) {
                $meta['next_winner'] = $node['next_winner'];
            }
            if (!empty($node['next_loser'])) {
                $meta['next_loser'] = $node['next_loser'];
            }
            $insert->execute([
                ':tid' => $tournamentId,
                ':stage' => 'winners',
                ':round' => $round,
                ':index' => $matchIndex,
                ':p1' => $p1,
                ':p2' => $p2,
                ':meta' => json_encode($meta),
            ]);
        }
    }

    foreach ($layout['losers'] as $round => $roundMatches) {
        foreach ($roundMatches as $matchIndex => $node) {
            $meta = [
                'stage' => 'losers',
                'sources' => $node['sources'] ?? [],
            ];
            if (!empty($node['next_winner'])) {
                $meta['next_winner'] = $node['next_winner'];
            }
            $insert->execute([
                ':tid' => $tournamentId,
                ':stage' => 'losers',
                ':round' => $round,
                ':index' => $matchIndex,
                ':p1' => null,
                ':p2' => null,
                ':meta' => json_encode($meta),
            ]);
        }
    }

    foreach ($layout['finals'] as $round => $roundMatches) {
        foreach ($roundMatches as $matchIndex => $node) {
            $meta = [
                'stage' => 'finals',
                'sources' => $node['sources'] ?? [],
            ];
            $insert->execute([
                ':tid' => $tournamentId,
                ':stage' => 'finals',
                ':round' => $round,
                ':index' => $matchIndex,
                ':p1' => null,
                ':p2' => null,
                ':meta' => json_encode($meta),
            ]);
        }
    }
}

function find_match_by_coordinates(int $tournamentId, string $stage, int $round, int $matchIndex): ?array
{
    $stmt = db()->prepare('SELECT id, stage, round, match_index, player1_user_id, player2_user_id, score1, score2, winner_user_id, meta FROM tournament_matches WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':stage' => $stage,
        ':round' => $round,
        ':index' => $matchIndex,
    ]);
    $match = $stmt->fetch();
    return $match ?: null;
}

function decode_match_meta($meta): array
{
    if (is_array($meta)) {
        return $meta;
    }
    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function apply_match_destination(int $tournamentId, ?array $destination, ?int $playerId): void
{
    if (!$destination || empty($destination['stage']) || empty($destination['round']) || empty($destination['match_index'])) {
        return;
    }
    $stage = (string)$destination['stage'];
    $round = (int)$destination['round'];
    $matchIndex = (int)$destination['match_index'];
    $target = find_match_by_coordinates($tournamentId, $stage, $round, $matchIndex);
    if (!$target) {
        return;
    }
    $slot = isset($destination['slot']) ? (int)$destination['slot'] : 1;
    $slot = $slot === 2 ? 2 : 1;
    $column = $slot === 2 ? 'player2_user_id' : 'player1_user_id';

    if ($playerId) {
        $update = db()->prepare("UPDATE tournament_matches SET {$column} = :player, score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id");
        $update->execute([
            ':player' => $playerId,
            ':id' => $target['id'],
        ]);
    } else {
        $update = db()->prepare("UPDATE tournament_matches SET {$column} = NULL WHERE id = :id");
        $update->execute([':id' => $target['id']]);
        db()->prepare('UPDATE tournament_matches SET score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id')->execute([':id' => $target['id']]);
    }
}

function record_match_result(int $tournamentId, int $matchId, ?int $winnerId): array
{
    $pdo = db();
    $matchStmt = $pdo->prepare('SELECT * FROM tournament_matches WHERE id = :id AND tournament_id = :tid');
    $matchStmt->execute([':id' => $matchId, ':tid' => $tournamentId]);
    $match = $matchStmt->fetch();
    if (!$match) {
        throw new RuntimeException('Match not found.');
    }

    $score1 = null;
    $score2 = null;
    if ($winnerId) {
        $isPlayer1 = (int)($match['player1_user_id'] ?? 0) === $winnerId;
        $isPlayer2 = (int)($match['player2_user_id'] ?? 0) === $winnerId;
        if (!$isPlayer1 && !$isPlayer2) {
            throw new RuntimeException('Selected winner is not part of this match.');
        }
        $score1 = $isPlayer1 ? 1 : 0;
        $score2 = $isPlayer2 ? 1 : 0;
    }

    $update = $pdo->prepare('UPDATE tournament_matches SET score1 = :s1, score2 = :s2, winner_user_id = :winner WHERE id = :id AND tournament_id = :tid');
    $update->execute([
        ':s1' => $score1,
        ':s2' => $score2,
        ':winner' => $winnerId,
        ':id' => $matchId,
        ':tid' => $tournamentId,
    ]);

    propagate_winner_to_next_match($tournamentId, $match, $winnerId);
    refresh_player_stats_for_match($match, $winnerId);
    clear_following_results($tournamentId, $match);

    $bracket = generate_bracket_structure($tournamentId);
    $bracketJson = safe_json_encode($bracket);
    update_tournament_json($tournamentId, $bracketJson, null);
    touch_tournament($tournamentId);

    return $bracket;
}

function refresh_player_stats_for_match(array $match, ?int $winnerId): void
{
    $players = [];
    foreach (['player1_user_id', 'player2_user_id'] as $key) {
        if (!empty($match[$key])) {
            $players[] = (int)$match[$key];
        }
    }
    if ($winnerId) {
        $players[] = $winnerId;
    }
    foreach (array_unique($players) as $playerId) {
        update_user_stat($playerId);
    }
}

function propagate_winner_to_next_match(int $tournamentId, array $match, ?int $winnerId): void
{
    $meta = decode_match_meta($match['meta'] ?? null);
    if (!empty($meta['next_winner']) || !empty($meta['next_loser'])) {
        $player1Id = isset($match['player1_user_id']) ? (int)$match['player1_user_id'] : null;
        $player2Id = isset($match['player2_user_id']) ? (int)$match['player2_user_id'] : null;
        $loserId = null;
        if ($winnerId) {
            if ($player1Id && $player1Id === $winnerId) {
                $loserId = $player2Id;
            } elseif ($player2Id && $player2Id === $winnerId) {
                $loserId = $player1Id;
            }
        }

        if ($winnerId) {
            apply_match_destination($tournamentId, $meta['next_winner'] ?? null, $winnerId);
            apply_match_destination($tournamentId, $meta['next_loser'] ?? null, $loserId);
        } else {
            apply_match_destination($tournamentId, $meta['next_winner'] ?? null, null);
            apply_match_destination($tournamentId, $meta['next_loser'] ?? null, null);
        }
        return;
    }

    if (!$winnerId) {
        $slotColumn = ((int)$match['match_index'] % 2 === 1) ? 'player1_user_id' : 'player2_user_id';
        $nextRound = (int)$match['round'] + 1;
        $nextIndex = (int)ceil(((int)$match['match_index']) / 2);
        $sql = sprintf(
            'UPDATE tournament_matches SET %s = NULL WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index',
            $slotColumn
        );
        $stmt = db()->prepare($sql);
        $stmt->execute([
            ':tid' => $tournamentId,
            ':stage' => $match['stage'],
            ':round' => $nextRound,
            ':index' => $nextIndex,
        ]);
        return;
    }

    $nextRound = (int)$match['round'] + 1;
    $nextIndex = (int)ceil(((int)$match['match_index']) / 2);
    $stmt = db()->prepare('SELECT id, player1_user_id, player2_user_id FROM tournament_matches WHERE tournament_id = :tid AND stage = :stage AND round = :round AND match_index = :index');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':stage' => $match['stage'],
        ':round' => $nextRound,
        ':index' => $nextIndex,
    ]);
    $next = $stmt->fetch();
    if (!$next) {
        return;
    }

    $slotColumn = ((int)$match['match_index'] % 2 === 1) ? 'player1_user_id' : 'player2_user_id';
    $update = db()->prepare("UPDATE tournament_matches SET {$slotColumn} = :winner, score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id");
    $update->execute([
        ':winner' => $winnerId,
        ':id' => $next['id'],
    ]);
}

function clear_following_results(int $tournamentId, array $match, array &$visited = []): void
{
    $meta = decode_match_meta($match['meta'] ?? null);
    foreach (['next_winner', 'next_loser'] as $key) {
        if (empty($meta[$key]) || !is_array($meta[$key])) {
            continue;
        }
        $destination = $meta[$key];
        $stage = $destination['stage'] ?? null;
        $round = isset($destination['round']) ? (int)$destination['round'] : null;
        $matchIndex = isset($destination['match_index']) ? (int)$destination['match_index'] : null;
        if (!$stage || !$round || !$matchIndex) {
            continue;
        }
        $keyString = $stage . ':' . $round . ':' . $matchIndex;
        if (isset($visited[$keyString])) {
            continue;
        }
        $target = find_match_by_coordinates($tournamentId, $stage, $round, $matchIndex);
        if (!$target) {
            continue;
        }
        $visited[$keyString] = true;
        $targetMeta = decode_match_meta($target['meta'] ?? null);
        if (!empty($targetMeta['sources']) && is_array($targetMeta['sources'])) {
            foreach ($targetMeta['sources'] as $slot => $sourceMeta) {
                if (!is_array($sourceMeta)) {
                    continue;
                }
                $sourceStage = $sourceMeta['stage'] ?? null;
                $sourceRound = isset($sourceMeta['round']) ? (int)$sourceMeta['round'] : null;
                $sourceMatchIndex = isset($sourceMeta['match_index']) ? (int)$sourceMeta['match_index'] : null;
                if ($sourceStage !== ($match['stage'] ?? null)) {
                    continue;
                }
                if ($sourceRound === null || $sourceRound !== (int)($match['round'] ?? 0)) {
                    continue;
                }
                if ($sourceMatchIndex === null || $sourceMatchIndex !== (int)($match['match_index'] ?? 0)) {
                    continue;
                }
                $slotNumber = (int)$slot === 2 ? 2 : 1;
                $slotColumn = $slotNumber === 2 ? 'player2_user_id' : 'player1_user_id';
                db()->prepare("UPDATE tournament_matches SET {$slotColumn} = NULL WHERE id = :id")
                    ->execute([':id' => $target['id']]);
            }
        }
        db()->prepare('UPDATE tournament_matches SET score1 = NULL, score2 = NULL, winner_user_id = NULL WHERE id = :id')->execute([':id' => $target['id']]);
        clear_following_results($tournamentId, $target, $visited);
    }
}

function touch_tournament(int $tournamentId): void
{
    db()->prepare('UPDATE tournaments SET updated_at = NOW() WHERE id = :id')->execute([':id' => $tournamentId]);
}

function save_snapshot(int $tournamentId, string $type, array $payload, int $userId): void
{
    $stmt = db()->prepare('INSERT INTO snapshots (tournament_id, snapshot_type, payload, created_by) VALUES (:tid, :type, :payload, :uid)');
    $stmt->execute([
        ':tid' => $tournamentId,
        ':type' => $type,
        ':payload' => json_encode($payload),
        ':uid' => $userId,
    ]);
}

function tournament_matches(int $tournamentId): array
{
    $sql = 'SELECT tm.*, 
                   u1.username AS player1_name,
                   u2.username AS player2_name,
                   uw.username AS winner_name
            FROM tournament_matches tm
            LEFT JOIN users u1 ON tm.player1_user_id = u1.id
            LEFT JOIN users u2 ON tm.player2_user_id = u2.id
            LEFT JOIN users uw ON tm.winner_user_id = uw.id
            WHERE tm.tournament_id = :tid
            ORDER BY tm.stage, tm.round, tm.match_index';
    $stmt = db()->prepare($sql);
    $stmt->execute([':tid' => $tournamentId]);
    return $stmt->fetchAll();
}
