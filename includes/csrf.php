<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(mixed $submittedToken): void
{
    $knownToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !is_string($knownToken) || $knownToken === ''
        || !hash_equals($knownToken, $submittedToken)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}
