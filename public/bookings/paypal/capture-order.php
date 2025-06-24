<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

use WFOT\Services\PayPalService;
use WFOT\Repository\BookingRepository;

// Enable more detailed error output for debugging
// Remove this in production or use a more secure logging method
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log the incoming request
error_log("PayPal capture request received: " . file_get_contents('php://input'));

// Basic validation
$data = json_decode(file_get_contents('php://input'), true);
$orderId = filter_var($data['orderID'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$bookingId = filter_var($data['booking_id'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (empty($orderId) || empty($bookingId)) {
    error_log("Missing required data in PayPal capture: orderId=$orderId, bookingId=$bookingId");
    echo json_encode(['success' => false, 'error' => 'Missing required data.']);
    exit;
}

$pp = new PayPalService();
$bookingRepo = new BookingRepository();

try {
    // Log that we're attempting to capture
    error_log("Attempting to capture PayPal order $orderId for booking $bookingId");
    
    $result = $pp->captureOrder($orderId);

    // Log the result for debugging
    error_log("PayPal capture result: " . json_encode($result));

    if (($result['status'] ?? '') !== 'COMPLETED') {
        // Log detailed error if possible
        error_log("PayPal Capture Failed for Order ID $orderId: " . json_encode($result));
        // Provide a user-friendly error message
        $errorDetail = isset($result['details'][0]['description']) ? 
                      $result['details'][0]['description'] : 
                      'Payment could not be completed.';
        echo json_encode(['success' => false, 'error' => "PayPal Error: $errorDetail Please try again or contact support."]);
        exit;
    }

    $captureId = $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';
    $payerEmail = $result['payer']['email_address'] ?? ''; // Get payer email if available
    $payerName = ($result['payer']['name']['given_name'] ?? '') . ' ' . ($result['payer']['name']['surname'] ?? ''); // Get payer name

    // Log successful capture
    error_log("Successfully captured payment: $captureId for booking $bookingId");

    // Update booking status to Complete and record payment details
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Paid',
        'Payment Reference' => $captureId,
        'Status' => 'Complete',
        'Payment Date' => date('Y-m-d H:i:s'), // Use datetime for precision
        'Payer Email' => $payerEmail, // Store payer email if field exists
        'Payer Name' => trim($payerName) // Store payer name if field exists
    ]);

    // Return success response
    // The confirmation email will be generated when the user is redirected back to the booking page
    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log("Error capturing PayPal order $orderId for booking $bookingId: " . $e->getMessage());
    error_log("Exception trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'An unexpected error occurred while processing your payment. Please try again or refresh the page.',
        'debug' => env('APP_ENV') === 'production' ? null : $e->getMessage()
    ]);
    exit;
}
