<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

if (current_user() !== null) {
    redirect('index.php');
}

$errors   = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if (!csrf_valid()) {
        $errors[] = 'Your form expired. Please try again.';
    } else {
        $errors = validate_registration($username, $password, $confirm);
    }
    if ($errors === []) {
        register_user($username, $password);
        flash('success', 'Account created. Please log in.');
        redirect('login.php');
    }
}

page_header('Register');
error_list($errors);
?>
<form method="post" action="register.php" novalidate>
  <?= csrf_field() ?>
  <label for="username">Username</label>
  <input id="username" name="username" value="<?= e($username) ?>" required minlength="3" maxlength="20" autocomplete="username">
  <p class="hint">3-20 letters, numbers or underscores.</p>

  <label for="password">Password</label>
  <input id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
  <p class="hint">At least 8 characters.</p>

  <label for="confirm">Confirm password</label>
  <input id="confirm" name="confirm" type="password" required autocomplete="new-password">

  <button type="submit">Create account</button>
</form>
<p>Already registered? <a href="login.php">Log in</a>.</p>
<?php page_footer();
