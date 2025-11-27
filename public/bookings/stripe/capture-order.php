<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

use WFOT\Services\StripeService;
use WFOT\Repository\BookingRepository;

// Enable more detailed error output for debugging
// Remove this in production or use a more secure logging method
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log the incoming request
error_log("Stripe capture request received: " . file_get_contents('php://input'));

// Basic validation
$data = json_decode(file_get_contents('php://input'), true);

// CSRF Protection (if token provided)
$csrfToken = $data['csrf_token'] ?? null;
if ($csrfToken && !validateCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$paymentIntentId = filter_var($data['paymentIntentId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$bookingId = filter_var($data['booking_id'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (empty($paymentIntentId) || !preg_match('/^pi_[a-zA-Z0-9_-]+$/', $paymentIntentId)) {
    error_log("Invalid PaymentIntent ID: $paymentIntentId");
    echo json_encode(['success' => false, 'error' => 'Invalid PaymentIntent ID']);
    exit;
}

if (empty($bookingId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $bookingId)) {
    error_log("Invalid Booking ID: $bookingId");
    echo json_encode(['success' => false, 'error' => 'Invalid Booking ID']);
    exit;
}

$stripe = new StripeService();
$bookingRepo = new BookingRepository();

try {
    // Log that we're attempting to confirm
    error_log("Attempting to confirm Stripe PaymentIntent $paymentIntentId for booking $bookingId");
    
    $intent = $stripe->retrievePaymentIntent($paymentIntentId);

    // Check if payment was successful
    if ($intent->status !== 'succeeded') {
        error_log("Stripe PaymentIntent not succeeded: status=" . $intent->status);
        echo json_encode(['success' => false, 'error' => 'Payment not completed. Status: ' . $intent->status]);
        exit;
    }

    $paymentIntentId = $intent->id;
    $payerEmail = $intent->receipt_email ?? null;
    $paymentAmount = $intent->amount / 100; // Convert from cents

    // Log successful confirmation
    error_log("Successfully confirmed payment: $paymentIntentId for booking $bookingId");

    // Update booking status to Complete and record payment details
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Paid',
        'Payment Reference' => $paymentIntentId,
        'Status' => 'Complete',
        'Payment Date' => date('Y-m-d H:i:s'),
        'Payer Email' => $payerEmail,
        'Payment Amount' => floatval($paymentAmount)
    ]);

    // Return success response
    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log("Error confirming Stripe PaymentIntent $paymentIntentId for booking $bookingId: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An unexpected error occurred while processing your payment. Please try again.',
        'debug' => env('DEBUG') === true ? $e->getMessage() : null
    ]);
    exit;
}
