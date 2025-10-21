<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->load();

// Only display errors when DEBUG is true
if (isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

// Start session for CSRF protection and user state
session_start();

/**
 * Simple helper to get env value with default
 */
function env(string $key, $default=null) {
    return $_ENV[$key] ?? $default;
}

// short alias to obfuscate email for privacy on front‑end
function safe_email(string $email): string {
    if (!strpos($email,'@')) return $email;
    [$u,$d] = explode('@',$email,2);
    return substr($u,0,2).str_repeat('*', max(strlen($u)-2,0)).'@'.substr($d,0,1).str_repeat('*', max(strlen($d)-2,0));
}

/**
 * Generate a CSRF token
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>
