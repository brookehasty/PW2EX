<?php
declare(strict_types=1);

/**
 * Shared bootstrap: loads modules, starts a hardened session, and provides
 * small helpers (escaping, redirects, CSRF, flash messages, login guards).
 * Every public/*.php page requires this file first.
 */

error_reporting(E_ALL);

require_once __DIR__ . '/board_config.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leaderboard.php';
require_once __DIR__ . '/view.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('DICESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; frame-ancestors 'none'");
    start_app_session();
}

// ---- Output ---------------------------------------------------------------

/** HTML-escape any user-controlled value before printing it. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

// ---- CSRF -----------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

// ---- Flash messages -------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function take_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

// ---- Login guards ---------------------------------------------------------

/** Logged-in username, or null. A user who no longer exists is logged out. */
function current_user(): ?string
{
    $u = $_SESSION['user'] ?? null;
    if (!is_string($u) || !isset($_SESSION['users'][mb_strtolower($u)])) {
        unset($_SESSION['user']);
        return null;
    }
    return $u;
}

/** Protect a page: redirect guests to the login page. */
function require_login(): string
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    return $user;
}

/** The current user's game, or null (a game belongs to the user who started it). */
function current_game(string $user): ?array
{
    $g = $_SESSION['game'] ?? null;
    return (is_array($g) && ($g['owner'] ?? null) === $user) ? $g : null;
}

/** Require a POST request with a valid CSRF token, else flash + redirect back. */
function require_post(string $backTo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirect($backTo);
    }
    if (!csrf_valid()) {
        flash('error', 'Your form expired. Please try again.');
        redirect($backTo);
    }
}
