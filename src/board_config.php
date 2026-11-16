<?php
declare(strict_types=1);

/**
 * Board data + pure board rules.
 *
 * Everything the board "knows" lives here as PHP arrays:
 *   snakes  => [head => tail]
 *   ladders => [base => top]
 *   events  => [cell => [event, event, ...]]   (pool; one is picked per landing)
 *
 * Narrator text is generated FROM these arrays (see ai.php), so messages and
 * board logic cannot drift out of sync when the board changes.
 */

const BOARD_SIZE = 100;

/** Small helper to declare an event definition. */
function ev(string $type, string $msg, array $extra = []): array
{
    return ['type' => $type, 'msg' => $msg] + $extra;
}

/** All difficulty levels. */
function board_levels(): array
{
    static $levels = null;
    if ($levels !== null) {
        return $levels;
    }

    // Reusable event definitions (type: bonus | penalty | skip | warp).
    $shortcut  = ev('bonus',   'A hidden shortcut opens through the reeds!', ['move' => 5]);
    $breeze    = ev('bonus',   'A warm breeze pushes you onward.',           ['move' => 3]);
    $goldenDie = ev('bonus',   'A golden die glints in the grass.',          ['move' => 0, 'extra_roll' => true]);
    $stumble   = ev('penalty', 'You stumble over a loose stone.',            ['move' => 3]);
    $fog       = ev('penalty', 'Thick fog turns you around.',                ['move' => 5]);
    $nap       = ev('skip',    'A drowsy spell settles over you.');
    $portal    = fn(int $to): array => ev('warp', 'A mystery portal swirls open!', ['to' => $to]);

    $levels = [
        'beginner' => [
            'label'   => 'Beginner',
            'snakes'  => [32 => 10, 62 => 19, 95 => 75],                 // 3 snakes
            'ladders' => [4 => 25, 21 => 42, 51 => 67],                  // 3 ladders
            'events'  => [
                15 => [$shortcut, $goldenDie, $stumble],
                42 => [$shortcut, $nap, $fog],
                67 => [$breeze, $portal(77), $stumble],
            ],
        ],
        'standard' => [
            'label'   => 'Standard',
            'snakes'  => [27 => 5, 40 => 3, 62 => 18, 74 => 53, 87 => 24, 95 => 75], // 6 snakes
            'ladders' => [4 => 25, 9 => 31, 20 => 38, 44 => 63, 71 => 91],            // 5 ladders
            'events'  => [
                15 => [$breeze, $goldenDie, $stumble],
                38 => [$shortcut, $nap, $fog],
                67 => [$breeze, $portal(77), $stumble],
                83 => [$shortcut, $goldenDie, $fog],
            ],
        ],
        'expert' => [
            'label'   => 'Expert',
            'snakes'  => [16 => 6, 27 => 5, 33 => 13, 47 => 26, 56 => 19,
                          62 => 18, 74 => 53, 87 => 24, 98 => 78],       // 9 snakes
            'ladders' => [3 => 22, 11 => 40, 36 => 55, 60 => 81],         // 4 ladders
            'events'  => [
                12 => [$breeze, $goldenDie, $stumble],
                29 => [$shortcut, $nap, $fog],
                52 => [$breeze, $portal(66), $stumble],
                79 => [$shortcut, $nap, $stumble],
            ],
        ],
    ];

    return $levels;
}

/** Board for a level key, or throws on an unknown key. */
function board_for(string $level): array
{
    $levels = board_levels();
    if (!isset($levels[$level])) {
        throw new InvalidArgumentException("Unknown level: $level");
    }
    return $levels[$level];
}

/**
 * Resolve one die roll from a position (pure; no randomness here).
 * Reaching or exceeding 100 finishes the game. No chaining: a snake tail or
 * ladder top is never itself a hazard (enforced by validate_board()).
 *
 * @return array{raw:int, landing:int, hazard:?array}
 */
function resolve_landing(array $board, int $from, int $roll): array
{
    $raw = $from + $roll;
    if ($raw >= BOARD_SIZE) {
        return ['raw' => $raw, 'landing' => BOARD_SIZE, 'hazard' => null];
    }
    if (isset($board['snakes'][$raw])) {
        $to = $board['snakes'][$raw];
        return ['raw' => $raw, 'landing' => $to, 'hazard' => ['type' => 'snake', 'from' => $raw, 'to' => $to]];
    }
    if (isset($board['ladders'][$raw])) {
        $to = $board['ladders'][$raw];
        return ['raw' => $raw, 'landing' => $to, 'hazard' => ['type' => 'ladder', 'from' => $raw, 'to' => $to]];
    }
    return ['raw' => $raw, 'landing' => $raw, 'hazard' => null];
}

/**
 * Sanity-check a board definition. Returns a list of problems (empty = valid).
 */
function validate_board(array $b): array
{
    $errors = [];
    $snakes  = $b['snakes'];
    $ladders = $b['ladders'];

    foreach ($snakes as $head => $tail) {
        if ($head < 3 || $head > BOARD_SIZE - 1) {
            $errors[] = "Snake head $head is out of range.";
        }
        if ($tail < 1 || $tail >= $head) {
            $errors[] = "Snake $head->$tail must slide down.";
        }
    }
    foreach ($ladders as $base => $top) {
        if ($base < 2 || $base > BOARD_SIZE - 2) {
            $errors[] = "Ladder base $base is out of range.";
        }
        if ($top <= $base || $top > BOARD_SIZE - 1) {
            $errors[] = "Ladder $base->$top must climb and end at or below 99.";
        }
    }
    foreach (array_intersect(array_keys($snakes), array_keys($ladders)) as $cell) {
        $errors[] = "Cell $cell is both a snake head and a ladder base.";
    }
    foreach (array_merge(array_values($snakes), array_values($ladders)) as $dest) {
        if (isset($snakes[$dest]) || isset($ladders[$dest])) {
            $errors[] = "Hazard destination $dest is itself a hazard (no chaining allowed).";
        }
    }

    foreach ($b['events'] as $cell => $pool) {
        if ($cell < 2 || $cell > BOARD_SIZE - 1) {
            $errors[] = "Event cell $cell is out of range.";
        }
        if (isset($snakes[$cell]) || isset($ladders[$cell])) {
            $errors[] = "Event cell $cell overlaps a snake head or ladder base.";
        }
        if ($pool === []) {
            $errors[] = "Event cell $cell has an empty pool.";
        }
        foreach ($pool as $def) {
            $problem = event_definition_problem($def);
            if ($problem !== null) {
                $errors[] = "Event at $cell: $problem";
                continue;
            }
            $dest = apply_event($def, $cell)['final'];
            if ($dest < BOARD_SIZE && (isset($snakes[$dest]) || isset($ladders[$dest]))) {
                $errors[] = "Event at $cell ({$def['type']}) lands on hazard cell $dest.";
            }
        }
    }
    return $errors;
}

/** Returns a description of what is wrong with an event definition, or null. */
function event_definition_problem(array $def): ?string
{
    if (!in_array($def['type'] ?? null, ['bonus', 'penalty', 'skip', 'warp'], true)) {
        return 'unknown type';
    }
    if (!is_string($def['msg'] ?? null) || trim($def['msg']) === '') {
        return 'missing msg';
    }
    return match ($def['type']) {
        'bonus'   => (is_int($def['move'] ?? null) && $def['move'] >= 0) ? null : 'bonus needs move >= 0',
        'penalty' => (is_int($def['move'] ?? null) && $def['move'] >= 1) ? null : 'penalty needs move >= 1',
        'warp'    => (is_int($def['to'] ?? null) && $def['to'] >= 1 && $def['to'] < BOARD_SIZE) ? null : 'warp needs to in 1..99',
        'skip'    => null,
    };
}
