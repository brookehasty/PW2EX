<?php
declare(strict_types=1);

/**
 * Session-based leaderboard. Fewest rolls wins; ties are broken by least time
 * played, then by who finished first.
 */

const LEADERBOARD_MAX = 100;

/**
 * Write the winner's score once per finished game. Safe to call repeatedly.
 * Takes the game by reference so it can mark the game as recorded.
 */
function record_score(array &$g): void
{
    if ($g['status'] !== 'finished' || $g['recorded'] || $g['winner'] === null) {
        return;
    }
    $w = $g['winner'];

    $_SESSION['leaderboard'][] = [
        'name'        => $g['players'][$w],
        'owner'       => $g['owner'],
        'level'       => $g['level'],
        'rolls'       => $g['roll_counts'][$w],
        'seconds'     => max(0, ($g['ended_at'] ?? time()) - $g['started_at']),
        'players'     => count($g['players']),
        'finished_at' => $g['ended_at'] ?? time(),
    ];
    // Keep the table bounded.
    if (count($_SESSION['leaderboard']) > LEADERBOARD_MAX) {
        $_SESSION['leaderboard'] = array_slice(ranked_leaderboard(), 0, LEADERBOARD_MAX);
    }
    $g['recorded'] = true;
}

/**
 * Ranked entries (best first), optionally for one level.
 *
 * @return list<array<string,mixed>>
 */
function ranked_leaderboard(?string $level = null): array
{
    $rows = $_SESSION['leaderboard'] ?? [];
    if ($level !== null) {
        $rows = array_filter($rows, fn(array $r): bool => $r['level'] === $level);
    }
    usort($rows, fn(array $a, array $b): int =>
        [$a['rolls'], $a['seconds'], $a['finished_at']] <=> [$b['rolls'], $b['seconds'], $b['finished_at']]);
    return array_values($rows);
}

/** Validate the ?level= filter from the URL. Returns the level key or null (= all). */
function leaderboard_filter(array $query): array
{
    $raw = $query['level'] ?? '';
    if (!is_string($raw) || $raw === '' || $raw === 'all') {
        return [null, null];
    }
    if (!isset(board_levels()[$raw])) {
        return [null, 'Unknown level filter - showing all levels.'];
    }
    return [$raw, null];
}
