<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_URL === '' ? '/' : BASE_URL . '/',
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect_to(string $path): void
{
    header('Location: ' . app_url($path), true, 302);
    exit;
}

function render_error_page(int $status): void
{
    if ($status !== 403 && $status !== 404) {
        throw new InvalidArgumentException('Unsupported HTTP error page.');
    }

    http_response_code($status);
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . $status . '.php';
    exit;
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'], $user['name'])) {
        return null;
    }

    return $user;
}

function require_role(string $role): array
{
    $user = current_user();
    if ($user === null) {
        redirect_to('/login.php');
    }

    $accountQuery = db()->prepare(
        'SELECT id, name, role, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $accountQuery->execute(['id' => $user['id']]);
    $account = $accountQuery->fetch();
    if ($account === false || (int) $account['is_active'] !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect_to('/login.php');
    }

    $_SESSION['user']['name'] = $account['name'];
    $_SESSION['user']['role'] = $account['role'];
    if ($account['role'] !== $role) {
        if ($account['role'] === 'admin' || $account['role'] === 'employee') {
            redirect_to($account['role'] === 'admin' ? '/admin/index.php' : '/learner/dashboard.php');
        }
        render_error_page(403);
    }

    return $_SESSION['user'];
}
