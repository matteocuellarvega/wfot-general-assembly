<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Services\TokenService;

$bookingId = $_GET['booking'] ?? null;
$token = $_GET['tok'] ?? null;

if (!$bookingId || !$token) {
    http_response_code(400);
    echo 'Missing parameters.';
    exit;
}

$validToken = TokenService::check($bookingId, $token);
if (!$validToken) {
    http_response_code(403);
    echo 'Invalid token.';
    exit;
}

$confirmationPath = dirname(__DIR__, 2) . '/storage/confirmations/' . basename($bookingId) . '.pdf';

if (!file_exists($confirmationPath)) {
    http_response_code(404);
    echo 'Confirmation not found.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="confirmation.pdf"');
header('Content-Length: ' . filesize($confirmationPath));
readfile($confirmationPath);
exit;