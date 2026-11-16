<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

/**
 * The dice-roll engine endpoint. The browser sends NO game data - only a CSRF
 * token. Whose turn it is, where they stand, and the roll itself are all
 * decided here from session state, so a player cannot forge a roll or move.
 * Post/Redirect/Get means refreshing the page can never re-roll.
 */
$user = require_login();
require_post('game.php');

$g = current_game($user);
if ($g === null) {
    flash('error', 'Start a game first.');
    redirect('setup.php');
}
if ($g['status'] !== 'playing') {
    redirect('recap.php');
}

play_turn($g);
record_score($g);          // no-op unless this roll just won the game
$_SESSION['game'] = $g;

redirect($g['status'] === 'finished' ? 'recap.php' : 'game.php');
