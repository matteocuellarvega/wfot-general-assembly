<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

use WFOT\Services\PayPalService;
use WFOT\Repository\BookingRepository;
use WFOT\Services\PdfService;
use WFOT\Services\EmailService;
use WFOT\Services\AirtableService;

// Initialize services
$paypalService = new PayPalService();
$bookingRepo = new BookingRepository();

// Get webhook payload
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Basic logging to server log rather than Airtable
error_log("PayPal webhook received: " . substr($payload, 0, 100) . "...");

// Verify webhook signature
try {
    $webhookId = env('PAYPAL_WEBHOOK_ID');
    if (empty($webhookId)) {
        throw new Exception("PayPal webhook ID not configured");
    }
    
    // Case-insensitive header retrieval
    $headerKeys = array_change_key_case(getallheaders(), CASE_UPPER);
    $paypalAuthAlgo = $headerKeys['PAYPAL-AUTH-ALGO'] ?? '';
    $paypalCertUrl = $headerKeys['PAYPAL-CERT-URL'] ?? '';
    $paypalTransmissionId = $headerKeys['PAYPAL-TRANSMISSION-ID'] ?? '';
    $paypalTransmissionSig = $headerKeys['PAYPAL-TRANSMISSION-SIG'] ?? '';
    $paypalTransmissionTime = $headerKeys['PAYPAL-TRANSMISSION-TIME'] ?? '';
    
    if (empty($paypalAuthAlgo) || empty($paypalCertUrl) || empty($paypalTransmissionId) || 
        empty($paypalTransmissionSig) || empty($paypalTransmissionTime)) {
        throw new Exception("Missing required PayPal webhook headers");
    }
    
    $verified = $paypalService->verifyWebhookSignature(
        $webhookId,
        $payload,
        $paypalAuthAlgo,
        $paypalCertUrl,
        $paypalTransmissionId,
        $paypalTransmissionSig,
        $paypalTransmissionTime
    );
    
    if (!$verified) {
        throw new Exception("PayPal webhook signature verification failed");
    }
} catch (Exception $e) {
    error_log("PayPal webhook error: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized webhook']);
    exit;
}

// Parse the webhook payload
$eventData = json_decode($payload, true);

// Set default response
http_response_code(200);

// Ensure we have valid data
if (!isset($eventData['event_type']) || !isset($eventData['resource'])) {
    error_log("Invalid PayPal webhook payload structure");
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload structure']);
    exit;
}

// Process based on event type
$eventType = $eventData['event_type'];
$resource = $eventData['resource'];

// Log just the event type to server log for monitoring
error_log("Processing PayPal webhook event: {$eventType}");

switch ($eventType) {
    case 'PAYMENT.CAPTURE.COMPLETED':
        handlePaymentCaptureCompleted($resource, $bookingRepo);
        break;
        
    case 'PAYMENT.CAPTURE.DENIED':
    case 'PAYMENT.CAPTURE.REFUNDED':
    case 'PAYMENT.CAPTURE.REVERSED':
        handlePaymentCaptureFailed($resource, $bookingRepo, $eventType);
        break;
        
    // Handle checkout events (optional)
    case 'CHECKOUT.ORDER.APPROVED':
        handleCheckoutOrderApproved($resource, $bookingRepo);
        break;
        
    default:
        // Just acknowledge receipt for events we don't explicitly handle
        echo json_encode(['status' => 'success', 'message' => 'Event acknowledged but not processed']);
        break;
}

/**
 * Handle successful payment capture
 */
function handlePaymentCaptureCompleted($resource, $bookingRepo) {
    // Extract data from the resource
    $captureId = $resource['id'] ?? '';
    $status = $resource['status'] ?? '';
    
    if ($status !== 'COMPLETED' || empty($captureId)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid capture status or ID']);
        return;
    }
    
    // Find custom data from the payment to identify our booking
    $bookingId = extractBookingId($resource);
    
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
    
    // Only store the minimum required payment information
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Paid',
        'Payment Reference' => $captureId,
        'Status' => 'Complete',
        'Payment Date' => date('Y-m-d'),
        'Payment Amount' => $resource['amount']['value'] ?? 0,
        'Payment Currency' => $resource['amount']['currency_code'] ?? 'USD',
        'Payee Email' => $resource['payee']['email_address'] ?? null
    ]);
    
    // Generate and send receipt
    generateAndSendReceipt($bookingId);
    
    echo json_encode(['status' => 'success', 'message' => 'Payment processed successfully']);
}

/**
 * Handle failed payment capture
 */
function handlePaymentCaptureFailed($resource, $bookingRepo, $eventType) {
    $captureId = $resource['id'] ?? '';
    $bookingId = extractBookingId($resource);
    
    if (empty($bookingId)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not determine booking ID']);
        return;
    }
    
    // Map event types to payment status values
    $paymentStatus = 'Error'; // Default
    switch ($eventType) {
        case 'PAYMENT.CAPTURE.DENIED':
            $paymentStatus = 'Error';
            break;
        case 'PAYMENT.CAPTURE.REFUNDED':
            $paymentStatus = 'Refunded';
            break;
        case 'PAYMENT.CAPTURE.REVERSED':
            $paymentStatus = 'Void';
            break;
    }
    
    // Update booking with minimal information
    $bookingRepo->update($bookingId, [
        'Payment Status' => $paymentStatus,
        'Payment Reference' => $captureId,
        'Status' => 'Failed',
        'Payment Date' => date('Y-m-d')
    ]);
    
    echo json_encode(['status' => 'success', 'message' => 'Payment status updated']);
}

/**
 * Handle order approval (may be useful for tracking pending payments)
 */
function handleCheckoutOrderApproved($resource, $bookingRepo) {
    $orderId = $resource['id'] ?? '';
    $bookingId = extractBookingId($resource);
    
    if (empty($bookingId)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not determine booking ID']);
        return;
    }
    
    // Update booking with minimal pending information
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Pending',
        'Order ID' => $orderId,
        'Status' => 'Pending'
    ]);
    
    echo json_encode(['status' => 'success', 'message' => 'Order approval recorded']);
}

/**
 * Extract booking ID from different locations in the resource
 */
function extractBookingId($resource) {
    // Try to find booking ID from different possible locations
    
    // Check custom_id field
    if (!empty($resource['custom_id'])) {
        return $resource['custom_id'];
    }
    
    // Check in the purchase units if available
    if (!empty($resource['purchase_units'])) {
        foreach ($resource['purchase_units'] as $unit) {
            if (!empty($unit['custom_id'])) {
                return $unit['custom_id'];
            }
            
            // Check in reference_id
            if (!empty($unit['reference_id']) && strpos($unit['reference_id'], 'booking_') === 0) {
                return substr($unit['reference_id'], 8);
            }
            
            // Check invoice_id
            if (!empty($unit['invoice_id'])) {
                return $unit['invoice_id'];
            }
        }
    }
    
    // Check links for related orders or payment details
    if (!empty($resource['links'])) {
        foreach ($resource['links'] as $link) {
            if (!empty($link['href']) && preg_match('/bookings\/([a-zA-Z0-9]+)/', $link['href'], $matches)) {
                return $matches[1];
            }
        }
    }
    
    return null;
}

/**
 * Generate and send receipt to customer
 */
function generateAndSendReceipt($bookingId) {
    $bookingRepo = new BookingRepository();
    $booking = $bookingRepo->find($bookingId);
    if (!$booking) {
        error_log("Failed to find booking $bookingId when generating receipt");
        return false;
    }
    
    $db = new AirtableService();
    $itemIds = $booking['fields']['Booked Items'] ?? [];
    $items = [];
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX';
    
    foreach ($itemIds as $bi) {
        $itemRecord = $db->find($bookedItemsTable, $bi);
        if ($itemRecord) {
            $items[] = $itemRecord;
        }
    }
    
    // Generate PDF receipt
    $confirmationDir = dirname(__DIR__, 3) . '/storage/confirmations/';
    if (!is_dir($confirmationDir)) {
        mkdir($confirmationDir, 0775, true);
    }
    $file = $confirmationDir . $bookingId . '.pdf';
    
    try {
        PdfService::generateConfirmation($booking, $items, $file);
        
        // Send email
        $recipientEmail = $booking['fields']['Email'][0] ?? null;
        $recipientName = $booking['fields']['First Name'] ?? '';
        $meetingId = $booking['fields']['Meeting ID'] ?? null;
        
        if ($recipientEmail) {
            EmailService::sendConfirmation($recipientEmail, $recipientName, $file, $meetingId);
        } else {
            error_log("No recipient email found for booking $bookingId");
        }
        
        // Clean up file
        @unlink($file);
        return true;
    } catch (Exception $e) {
        error_log("Error generating receipt for booking $bookingId: " . $e->getMessage());
        return false;
    }
}
