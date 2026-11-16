<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$g    = current_game($user);
if ($g === null) {
    redirect('setup.php');
}
if ($g['status'] !== 'finished') {
    redirect('game.php');
}

$winner  = $g['players'][$g['winner']];
$seconds = max(0, ($g['ended_at'] ?? time()) - $g['started_at']);

page_header('Adventure Recap');
?>
<p class="flash success" role="status">🏆 <strong><?= e($winner) ?></strong> wins on <?= e(board_for($g['level'])['label']) ?>
  after <?= (int)$g['roll_counts'][$g['winner']] ?> rolls (<?= e(fmt_duration($seconds)) ?> played).</p>

<p>
  <a href="leaderboard.php?level=<?= e($g['level']) ?>">View the leaderboard</a> ·
  <a href="setup.php">Play again</a>
</p>

<section aria-labelledby="events-h">
  <h2 id="events-h">Every event this adventure</h2>
  <?php if ($g['events_log'] === []): ?>
    <p class="muted">No board events were triggered.</p>
  <?php else: ?>
    <ol>
    <?php foreach ($g['events_log'] as $ev): ?>
      <li>Turn <?= (int)$ev['turn'] ?>, cell <?= (int)$ev['cell'] ?>: <?= e($ev['text']) ?></li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>

<section aria-labelledby="final-h">
  <h2 id="final-h">Final board</h2>
  <?= render_board($g) ?>
</section>
<?php page_footer();
