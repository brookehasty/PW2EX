<?php
declare(strict_types=1);

/**
 * Game engine: rules, state transitions, scoring. Operates on a plain array
 * ($g) that lives in $_SESSION['game'] - no globals, so it is easy to test.
 */

/** One fair die roll. random_int() is CSPRNG-backed and unaffected by mt_srand(). */
function roll_die(): int
{
    return random_int(1, 6);
}

/**
 * Validate the new-game form. Returns [errors, clean].
 *
 * @return array{0:list<string>, 1:array{level:string, players:list<string>}}
 */
function validate_setup(array $input, string $defaultName = ''): array
{
    $errors = [];
    $level  = (string)($input['level'] ?? '');
    if (!isset(board_levels()[$level])) {
        $errors[] = 'Choose a difficulty level.';
    }

    $count = (string)($input['player_count'] ?? '');
    if (!in_array($count, ['1', '2'], true)) {
        $errors[] = 'Choose one or two players.';
        $count = '1';
    }

    $names = [];
    for ($i = 1; $i <= (int)$count; $i++) {
        $name = trim((string)($input["player$i"] ?? ''));
        if ($i === 1 && $name === '') {
            $name = $defaultName;
        }
        if (!preg_match('/^[\p{L}\p{N} _\-]{1,20}$/u', $name)) {
            $errors[] = "Player $i name must be 1-20 letters, numbers, spaces, - or _.";
        }
        $names[] = $name;
    }
    if (count($names) === 2 && mb_strtolower($names[0]) === mb_strtolower($names[1])) {
        $errors[] = 'Player names must be different.';
    }

    return [$errors, ['level' => $level, 'players' => $names]];
}

/** Create a fresh game state. $seed makes event picks reproducible (tests/debug). */
function new_game(array $players, string $level, string $owner = '', ?int $seed = null): array
{
    board_for($level); // throws on unknown level
    $n = count($players);
    if ($n < 1 || $n > 2) {
        throw new InvalidArgumentException('1 or 2 players only.');
    }

    return [
        'id'           => bin2hex(random_bytes(8)),
        'owner'        => $owner,
        'level'        => $level,
        'players'      => array_values($players),
        'positions'    => array_fill(0, $n, 0),     // 0 = not on the board yet
        'current'      => 0,                        // whose turn it is
        'turn_number'  => 0,                        // total rolls so far
        'skips'        => array_fill(0, $n, false), // pending "miss a turn" flags
        'roll_counts'  => array_fill(0, $n, 0),
        'roll_history' => [],                       // ['turn','player','roll']
        'moves'        => [],                       // full move records
        'events_log'   => [],                       // Adventure Recap source
        'last_event'   => null,
        'last_move'    => null,
        'status'       => 'playing',                // playing | finished
        'winner'       => null,
        'started_at'   => time(),
        'ended_at'     => null,
        'seed'         => $seed ?? random_int(1, 1_000_000),
        'recorded'     => false,                    // leaderboard entry written?
    ];
}

/**
 * Pass the turn to the next player, consuming any pending skip.
 * Returns notice strings for the narrator.
 *
 * @return list<string>
 */
function advance_turn(array &$g): array
{
    $notices = [];
    $n    = count($g['players']);
    $next = $g['current'];

    for ($guard = 0; $guard <= $n; $guard++) {
        $next = ($next + 1) % $n;
        if ($g['skips'][$next]) {
            $g['skips'][$next] = false;
            $notices[] = "💤 {$g['players'][$next]} is still dozing and loses this turn.";
            continue;
        }
        break;
    }
    $g['current'] = $next;
    return $notices;
}

/**
 * Play one turn for the current player: roll, move, resolve snake/ladder,
 * resolve event, check win, pass the turn. Returns the move record.
 *
 * $dice is injectable ONLY so tests can script rolls; the app always uses
 * roll_die().
 */
function play_turn(array &$g, ?callable $dice = null): array
{
    if ($g['status'] !== 'playing') {
        throw new LogicException('The game is already over.');
    }
    $dice ??= 'roll_die';

    $board = board_for($g['level']);
    $i     = $g['current'];
    $name  = $g['players'][$i];
    $from  = $g['positions'][$i];
    $turn  = ++$g['turn_number'];

    $roll = $dice();
    if (!is_int($roll) || $roll < 1 || $roll > 6) {
        throw new UnexpectedValueException('Die returned an invalid value.');
    }

    $res     = resolve_landing($board, $from, $roll);
    $landing = $res['landing'];
    $final   = $landing;
    $won     = $final >= BOARD_SIZE;
    $event   = null;
    $extra   = false;

    if (!$won && isset($board['events'][$landing])) {
        $def = pick_event($board['events'][$landing], $g['seed'], $turn, $landing);
        $out = apply_event($def, $landing);

        $final = $out['final'];
        $extra = $out['extra_roll'];
        if ($out['skip']) {
            $g['skips'][$i] = true;
        }
        $event = [
            'type'       => $def['type'],
            'msg'        => $def['msg'],
            'cell'       => $landing,
            'to'         => $final,
            'delta'      => $final - $landing,
            'extra_roll' => $extra,
            'skip'       => $out['skip'],
        ];
        $won = $final >= BOARD_SIZE;
    }

    $g['positions'][$i] = $final;
    $g['roll_counts'][$i]++;
    $g['roll_history'][] = ['turn' => $turn, 'player' => $i, 'roll' => $roll];

    $move = [
        'turn'        => $turn,
        'player'      => $i,
        'name'        => $name,
        'from'        => $from,
        'roll'        => $roll,
        'raw'         => $res['raw'],
        'landing'     => $landing,          // after snake/ladder, before events
        'hazard'      => $res['hazard'],
        'event'       => $event,
        'final'       => $final,
        'won'         => $won,
        'narration'   => [],
    ];
    $move['narration'] = narrate_move($move, $name);

    if ($event !== null) {
        $g['last_event']   = $event + ['turn' => $turn, 'player' => $name];
        $g['events_log'][] = $event + ['turn' => $turn, 'player' => $name,
                                       'text' => narrate_event($event, $name)];
    }

    if ($won) {
        $g['status']   = 'finished';
        $g['winner']   = $i;
        $g['ended_at'] = time();
    } elseif (!$extra) {
        array_push($move['narration'], ...advance_turn($g));
    }

    $g['moves'][]   = $move;
    $g['last_move'] = $move;
    return $move;
}
