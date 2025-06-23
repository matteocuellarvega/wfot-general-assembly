<?php

header('Content-Type: application/json');

// Only allow POST requests for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'POST method required.']);
    exit;
}

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Services\TokenService;
use WFOT\Repository\RegistrationRepository;

// Bearer token authorization check
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Missing Bearer token.']);
    exit;
}
$providedToken = trim(substr($authHeader, 7));
$expectedToken = env('API_BEARER_TOKEN');
if ($providedToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Invalid token.']);
    exit;
}

// Get registrationId from POST body (assuming JSON payload)
$input = json_decode(file_get_contents('php://input'), true);
$registrationId = $input['registrationId'] ?? null;

if (!$registrationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing registrationId parameter.']);
    exit;
}

// Basic validation: Check if registration exists
$regRepo = new RegistrationRepository();
$registration = $regRepo->find($registrationId);
if (!$registration) {
    http_response_code(404);
    echo json_encode(['error' => 'Registration not found.']);
    exit;
}

try {
    // Generate the token
    $token = TokenService::generate($registrationId);

    // Construct the full URL using the base URL from .env for flexibility
    $baseUrl = rtrim(env('APP_URL'), '/');
    $bookingUrl = $baseUrl . '/bookings?registration=' . urlencode($registrationId) . '&tok=' . urlencode($token);

    http_response_code(200);
    echo json_encode(['bookingUrl' => $bookingUrl]);
} catch (\Exception $e) {
    error_log("Error generating booking link for $registrationId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate booking link.']);
}

exit;
