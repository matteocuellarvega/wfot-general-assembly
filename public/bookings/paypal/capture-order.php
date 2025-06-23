<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

use WFOT\Services\PayPalService;
use WFOT\Repository\BookingRepository;
use WFOT\Services\AirtableService;
use WFOT\Services\PdfService;
use WFOT\Services\EmailService;

// Basic validation
$data = json_decode(file_get_contents('php://input'), true);
$orderId = filter_var($data['orderID'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$bookingId = filter_var($data['booking_id'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (empty($orderId) || empty($bookingId)) {
    echo json_encode(['success' => false, 'error' => 'Missing required data.']);
    exit;
}

$pp = new PayPalService();
$bookingRepo = new BookingRepository();

try {
    $result = $pp->captureOrder($orderId);

    if (($result['status'] ?? '') !== 'COMPLETED') {
        // Log detailed error if possible
        error_log("PayPal Capture Failed for Order ID $orderId: " . json_encode($result));
        // Provide a user-friendly error message
        $errorDetail = $result['details'][0]['description'] ?? 'Payment could not be completed.';
        echo json_encode(['success' => false, 'error' => "PayPal Error: $errorDetail Please try again or contact support."]);
        exit;
    }

    $captureId = $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';
    $payerEmail = $result['payer']['email_address'] ?? ''; // Get payer email if available
    $payerName = ($result['payer']['name']['given_name'] ?? '') . ' ' . ($result['payer']['name']['surname'] ?? ''); // Get payer name

    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Paid',
        'Payment Reference' => $captureId,
        'Status' => 'Complete',
        'Payment Date' => date('Y-m-d H:i:s'), // Use datetime for precision
        'Payer Email' => $payerEmail, // Store payer email if field exists
        'Payer Name' => trim($payerName) // Store payer name if field exists
    ]);

    // generate receipt
    // Use AirtableService directly as $db is not defined here otherwise
    $db = new AirtableService();
    $booking = $bookingRepo->find($bookingId); // Re-fetch booking with updated status
    if (!$booking) {
         error_log("Could not find booking $bookingId after successful payment capture.");
         // Don't fail the whole process, but log it. Receipt won't send.
         echo json_encode(['success' => true, 'warning' => 'Payment captured but failed to generate receipt.']);
         exit;
    }

    $itemIds = $booking['fields']['Booked Items'] ?? [];
    $items = [];
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX'; // Define table ID here as well
    foreach ($itemIds as $bi) {
        // Ensure find method exists and works in AirtableService
        $itemRecord = $db->find($bookedItemsTable, $bi);
        if ($itemRecord) {
            $items[] = $itemRecord;
        }
    }

    // Ensure storage directory exists and is writable
    $confirmationDir = dirname(__DIR__, 3) . '/storage/confirmations/';
    if (!is_dir($confirmationDir)) {
        mkdir($confirmationDir, 0775, true);
    }
    $file = $confirmationDir . $bookingId . '.pdf';

    PdfService::generateConfirmation($booking, $items, $file);

    // email - Use email from booking record
    $recipientEmail = $booking['fields']['Email'][0] ?? $payerEmail; // Fallback to payer email if booking email missing
    $recipientName = $booking['fields']['First Name'] ?? trim($payerName); // Fallback to payer name
    $meetingId = $booking['fields']['Meeting ID'] ?? null; // Get meeting ID if exists

    if ($recipientEmail) {
        EmailService::sendConfirmation($recipientEmail, $recipientName, $file, $meetingId);
    } else {
        error_log("No recipient email found for booking $bookingId. Confirmation not sent.");
    }

    // remove file
    @unlink($file);

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log("Error capturing PayPal order $orderId for booking $bookingId: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred while processing your payment. Please contact support.']);
    exit;
}
