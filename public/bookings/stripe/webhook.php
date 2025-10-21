<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

use WFOT\Services\StripeService;
use WFOT\Repository\BookingRepository;
use WFOT\Services\PdfService;
use WFOT\Services\EmailService;
use WFOT\Services\AirtableService;

// Initialize services
$stripeService = new StripeService();
$bookingRepo = new BookingRepository();

// Get webhook payload
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Basic logging to server log rather than Airtable
error_log("Stripe webhook received: " . substr($payload, 0, 100) . "...");

// Verify webhook signature
try {
    $webhookSecret = env('STRIPE_WEBHOOK_SECRET');
    if (empty($webhookSecret)) {
        throw new Exception("Stripe webhook secret not configured");
    }
    
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (empty($sigHeader)) {
        throw new Exception("Missing Stripe signature header");
    }
    
    $event = $stripeService->constructWebhookEvent($payload, $sigHeader, $webhookSecret);
} catch (Exception $e) {
    error_log("Stripe webhook error: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized webhook']);
    exit;
}

// Set default response
http_response_code(200);

// Ensure we have valid data
if (!$event || !isset($event->type)) {
    error_log("Invalid Stripe webhook payload structure");
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload structure']);
    exit;
}

// Process based on event type
$eventType = $event->type;
$data = $event->data->object;

// Log just the event type to server log for monitoring
error_log("Processing Stripe webhook event: {$eventType}");

switch ($eventType) {
    case 'checkout.session.completed':
        handleCheckoutSessionCompleted($data, $bookingRepo);
        break;
        
    case 'payment_intent.payment_failed':
        handlePaymentIntentFailed($data, $bookingRepo, $eventType);
        break;
        
    default:
        // Just acknowledge receipt for events we don't explicitly handle
        echo json_encode(['status' => 'success', 'message' => 'Event acknowledged but not processed']);
        break;
}

/**
 * Handle successful checkout session completion
 */
function handleCheckoutSessionCompleted($data, $bookingRepo) {
    $sessionId = $data->id;
    $paymentStatus = $data->payment_status;
    
    if ($paymentStatus !== 'paid') {
        echo json_encode(['status' => 'error', 'message' => 'Payment not completed']);
        return;
    }
    
    $bookingId = $data->metadata->booking_id ?? null;
    
    if (empty($bookingId)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not determine booking ID']);
        return;
    }
    
    // Get booking and verify it's not already paid
    $booking = $bookingRepo->find($bookingId);
    if (!$booking) {
        echo json_encode(['status' => 'error', 'message' => 'Booking not found']);
        return;
    }
    
    $currentStatus = $booking['fields']['Payment Status'] ?? '';
    if ($currentStatus === 'Paid') {
        echo json_encode(['status' => 'success', 'message' => 'Payment already processed']);
        return;
    }
    
    // Update booking
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Paid',
        'Payment Reference' => $sessionId,
        'Status' => 'Complete',
        'Payment Date' => date('Y-m-d'),
        'Payment Amount' => $data->amount_total / 100,
        'Payer Email' => $data->customer_details->email ?? null,
        'Payer Name' => $data->customer_details->name ?? null
    ]);
    
    // Generate and send receipt
    generateAndSendReceipt($bookingId);
    
    echo json_encode(['status' => 'success', 'message' => 'Payment processed successfully']);
}

/**
 * Handle failed payment intent
 */
function handlePaymentIntentFailed($data, $bookingRepo, $eventType) {
    $paymentIntentId = $data->id;
    $bookingId = $data->metadata->booking_id ?? null;
    
    if (empty($bookingId)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not determine booking ID']);
        return;
    }
    
    // Update booking with failure status
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Error',
        'Payment Reference' => $paymentIntentId,
        'Status' => 'Failed',
        'Payment Date' => date('Y-m-d')
    ]);
    
    echo json_encode(['status' => 'success', 'message' => 'Payment failure recorded']);
}

function generateAndSendReceipt($bookingId) {
    $bookingRepo = new BookingRepository();
    $booking = $bookingRepo->find($bookingId);
    if (!$booking) {
        error_log("Failed to find booking $bookingId when generating receipt");
        return false;
    }
    
    // Get meeting ID directly from booking (it's a Lookup field, so it's an array)
    $meetingId = $booking['fields']['Meeting ID'][0] ?? null;
    if (!$meetingId) {
        error_log("No meeting ID found in booking $bookingId");
        return false;
    }
    
    // Send email confirmation (no PDF attachment needed - user can download from confirmation page)
    // Handle Lookup fields that return arrays
    $recipientEmail = $booking['fields']['Email'][0] ?? null;
    $recipientFName = $booking['fields']['First Name'][0] ?? '';
    $recipientLName = $booking['fields']['Last Name'][0] ?? '';
    $recipientName = trim($recipientFName . ' ' . $recipientLName);
    
    if ($recipientEmail) {
        // Generate confirmation URL for the email
        $confirmationToken = WFOT\Services\TokenService::generate($bookingId);
        $confirmationUrl = rtrim(env('APP_URL'), '/') . '/bookings/confirmation.php?booking=' . $bookingId . '&tok=' . $confirmationToken;
        
        // Send confirmation email without PDF attachment
        $emailService = new WFOT\Services\EmailService();
        return $emailService->sendConfirmation($recipientEmail, $recipientName, $confirmationUrl, $meetingId);
    } else {
        error_log("No recipient email found for booking $bookingId");
        return false;
    }
}
