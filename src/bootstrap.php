<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->load();

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
?>
