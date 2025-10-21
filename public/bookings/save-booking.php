<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Services\AirtableService;
use WFOT\Services\StripeService;

// Basic Sanitization
$input = file_get_contents('php://input');
parse_str($input, $post);

// CSRF Protection
$csrfToken = $post['csrf_token'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Enhanced Input Validation and Sanitization
$bookingId = filter_var($post['booking_id'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
if (empty($bookingId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $bookingId)) {
    echo json_encode(['error' => 'Invalid Booking ID']);
    exit;
}

$itemIds = [];
if (isset($post['item']) && is_array($post['item'])) {
    foreach ($post['item'] as $itemId) {
        $sanitizedId = filter_var($itemId, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!empty($sanitizedId) && preg_match('/^[a-zA-Z0-9_-]+$/', $sanitizedId)) {
            $itemIds[] = $sanitizedId;
        }
    }
}

$diet = trim(filter_var($post['diet'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
if (strlen($diet) > 500) {
    echo json_encode(['error' => 'Dietary requirements too long (max 500 characters)']);
    exit;
}

$payMethod = filter_var($post['paymethod'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$allowedPaymentMethods = ['Stripe', 'Cash'];
if (!empty($payMethod) && !in_array($payMethod, $allowedPaymentMethods)) {
    echo json_encode(['error' => 'Invalid payment method']);
    exit;
}

$bookingRepo = new BookingRepository();
$booking = $bookingRepo->find($bookingId);
if (!$booking) {
    echo json_encode(['error' => 'Booking not found']); exit;
}
// Prevent modification if already complete? Optional check:
// if (($booking['fields']['Status'] ?? '') === 'Complete') {
//     echo json_encode(['error' => 'Booking is already complete']); exit;
// }


$db = new AirtableService();
$bookedItemsTable = 'tbluEJs6UHGhLbvJX';
$itemsTable = 'tblT0M8sYqgHq6Tsa';

// --- Modification: Clear existing booked items first ---
// This prevents duplicates if the user goes back and resubmits
$existingBookedItems = $db->all($bookedItemsTable, [
    'filterByFormula' => sprintf("{Booking}='%s'", $bookingId),
    'fields' => ['id'] // Only need IDs to delete
]);
$existingBookedItemIds = array_map(fn($record) => $record['id'], $existingBookedItems);
if (!empty($existingBookedItemIds)) {
    $db->deleteBatch($bookedItemsTable, $existingBookedItemIds);
    // Add a small delay to allow Airtable to process deletions before adding new ones, if needed
    // usleep(500000); // 0.5 seconds delay (optional)
}
// --- End Modification ---


$newBookedItemIds = []; // Keep track of newly created booked item IDs for the update below
foreach ($itemIds as $iid) {
    $it = $db->find($itemsTable, $iid);
    if (!$it) continue;
    $fields = $it['fields'];
    $createdBookedItem = $db->create($bookedItemsTable, [
        'Item' => $fields['Name'],
        'Type' => $fields['Type'],
        'Item Total' => $fields['Cost'],
        'Booking' => [$bookingId],
        'Bookable Item ID' => $iid  // Added to match Booked Items to Bookable Items
    ]);
    if ($createdBookedItem && isset($createdBookedItem['id'])) {
        $newBookedItemIds[] = $createdBookedItem['id'];
    }
}

// Update booking with dietary info and potentially the link to newly booked items
// Linking directly might be redundant if Airtable rollup handles it, but can be explicit:
$updateData = [
    'Dietary Requirements' => $diet,
    'Booked Items' => $newBookedItemIds // Only if direct linking is desired/needed
];

// Only set Payment Method if items were selected (total might be > 0)
if (!empty($itemIds)) {
    $updateData['Payment Method'] = $payMethod;
} else {
    // If no items selected, clear payment method? Or leave as is?
    // Let's clear it for consistency if total becomes 0.
     $updateData['Payment Method'] = null; // Or appropriate empty value for Airtable field type
}

$bookingRepo->update($bookingId, $updateData);


$total = 0.0;
// Fetch the newly created booked items to calculate the total
if (!empty($newBookedItemIds)) {
    $bookedItems = [];
    foreach ($newBookedItemIds as $itemId) {
        $bookedItem = $db->find($bookedItemsTable, $itemId);
        if ($bookedItem && isset($bookedItem['fields']['Item Total'])) {
            $total += floatval($bookedItem['fields']['Item Total']);
        }
        $bookedItems[] = $bookedItem;
    }
}

// NEW CODE: Apply discount from booking if available
$updatedBooking = $bookingRepo->find($bookingId);
$discount = isset($updatedBooking['fields']['Discounts']) ? floatval($updatedBooking['fields']['Discounts']) : 0.0;

// Subtract discount but ensure total doesn't go negative
$totalAfterDiscount = max(0, $total - $discount);

// Store both gross and net totals in the booking for clarity
$bookingRepo->update($bookingId, [
    'Subtotal' => $total,
    'Total' => $totalAfterDiscount
]);

// Use the discounted total for payment processing
$total = $totalAfterDiscount;

// Validate payment method if total > 0
if ($total > 0 && empty($payMethod)) {
    echo json_encode(['error' => 'Payment method is required for bookings with items']);
    exit;
}

// Handle based on the *actual* total
if ($total == 0) {
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Not Required', // Correct status for £0
        'Status' => 'Complete',
        'Payment Method' => null // Clear payment method if total is 0
    ]);
    // Send receipt for £0 booking? Optional. If yes, generate and email here.
    echo json_encode(['payment' => 'None', 'booking_id' => $bookingId]); exit;

} elseif ($payMethod === 'Cash') {
    $bookingRepo->update($bookingId, [
        'Payment Status' => 'Unpaid', // Use 'Unpaid' for Cash
        'Status' => 'Pending'
    ]);
    // Send confirmation (not receipt yet) for cash booking? Optional.
    echo json_encode(['payment' => 'Cash', 'booking_id' => $bookingId]); exit;

} else { // Stripe flow for total > 0
    // Ensure Payment Status is Pending before creating Stripe PaymentIntent
     $bookingRepo->update($bookingId, [
        'Payment Status' => 'Pending',
        'Status' => 'Pending' // Keep status pending until payment is confirmed
    ]);
    $stripe = new StripeService();
    try {
        $intent = $stripe->createPaymentIntent($total, 'USD', $bookingId);
        echo json_encode(['payment' => 'Stripe', 'paymentIntent' => ['id' => $intent->id, 'client_secret' => $intent->client_secret], 'booking_id' => $bookingId]);
    } catch (\Exception $e) {
        // Log the error server-side
        error_log("Stripe PaymentIntent Creation Failed: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to create Stripe payment intent. Please try again.']);
    }
    exit;
}
