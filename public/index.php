<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$user = current_user();
if ($user === null) {
    redirect('login.php');
}
redirect(current_game($user) !== null ? 'game.php' : 'setup.php');
