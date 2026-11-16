<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$g    = current_game($user);
if ($g === null) {
    redirect('setup.php');
}
if ($g['status'] === 'finished') {
    redirect('recap.php');
}

$board   = board_for($g['level']);
$i       = $g['current'];
$name    = $g['players'][$i];
$pos     = $g['positions'][$i];
$last    = $g['last_move'];

page_header('Game on — ' . $board['label']);
?>
<p class="status" aria-live="polite">
  <strong>Turn <?= (int)$g['turn_number'] + 1 ?>:</strong> <?= e($name) ?>
  (P<?= $i + 1 ?>) at <?= $pos === 0 ? 'the start' : 'cell ' . $pos ?>.
  <?php foreach ($g['players'] as $k => $p): ?>
    <span class="chip">P<?= $k + 1 ?> <?= e($p) ?>: <?= (int)$g['positions'][$k] ?><?= $g['skips'][$k] ? ' 💤' : '' ?></span>
  <?php endforeach; ?>
</p>

<?php if ($last !== null): ?>
<section class="narrator" aria-labelledby="narrator-h">
  <h2 id="narrator-h">AI Narrator</h2>
  <?php foreach ($last['narration'] as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
</section>
<?php endif; ?>

<form method="post" action="roll.php" class="roll-form">
  <?= csrf_field() ?>
  <button type="submit" class="primary">🎲 Roll the die — <?= e($name) ?></button>
</form>

<?= render_board($g) ?>

<section aria-labelledby="hist-h">
  <h2 id="hist-h">Roll history</h2>
  <?php if ($g['roll_history'] === []): ?>
    <p class="muted">No rolls yet.</p>
  <?php else: ?>
    <ol class="history">
    <?php foreach (array_slice(array_reverse($g['roll_history']), 0, 10) as $h): ?>
      <li>Turn <?= (int)$h['turn'] ?> — <?= e($g['players'][$h['player']]) ?> rolled <?= (int)$h['roll'] ?></li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
<?php page_footer();
