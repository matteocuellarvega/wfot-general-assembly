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

$receiptPath = dirname(__DIR__, 2) . '/storage/receipts/' . basename($bookingId) . '.pdf';

if (!file_exists($receiptPath)) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="receipt.pdf"');
header('Content-Length: ' . filesize($receiptPath));
readfile($receiptPath);
exit;