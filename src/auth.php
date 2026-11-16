<?php
declare(strict_types=1);

/**
 * Registration / login / logout.
 *
 * Accounts live in $_SESSION['users'] because the project forbids a database.
 * Passwords are ONLY ever stored as password_hash() output.
 */

/**
 * Validate registration input. Returns a list of error messages (empty = OK).
 *
 * @return list<string>
 */
function validate_registration(string $username, string $password, string $confirm): array
{
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        $errors[] = 'Username must be 3-20 characters: letters, numbers, or underscore.';
    } elseif (isset($_SESSION['users'][strtolower($username)])) {
        $errors[] = 'That username is already taken.';
    }

    // bcrypt only uses the first 72 bytes, so cap there.
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Password must be 8-72 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    return $errors;
}

/** Create the account. Call only after validate_registration() returns no errors. */
function register_user(string $username, string $password): void
{
    $_SESSION['users'][strtolower($username)] = [
        'username' => $username,
        'hash'     => password_hash($password, PASSWORD_DEFAULT),
        'created'  => time(),
    ];
}

/** Check credentials and, on success, log the user in. */
function attempt_login(string $username, string $password): bool
{
    static $dummy = null;
    $user = $_SESSION['users'][strtolower($username)] ?? null;

    // Verify against a dummy hash for unknown users so timing doesn't reveal them.
    $hash = $user['hash'] ?? ($dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
    $ok   = password_verify($password, $hash);

    if ($user === null || !$ok) {
        return false;
    }
    if (password_needs_rehash($user['hash'], PASSWORD_DEFAULT)) {
        $_SESSION['users'][strtolower($username)]['hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true); // prevent session fixation
    }
    $_SESSION['user'] = $user['username'];
    return true;
}

/**
 * Log out: drop the login and the CSRF token and rotate the session id.
 * Accounts, finished scores and the leaderboard stay in the session store.
 */
function logout_user(): void
{
    unset($_SESSION['user'], $_SESSION['csrf']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
