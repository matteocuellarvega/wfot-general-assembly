<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Repository\RegistrationRepository;
use WFOT\Repository\ItemRepository;
use WFOT\Services\StripeService;
use WFOT\Services\TokenService;

$bookingId = isset($_GET['booking'])
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['booking'])
    : null;

$registrationId = isset($_GET['registration'])
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['registration'])
    : null;

$token = $_GET['tok'] ?? null;
$regenerate = isset($_GET['regenerate']) && $_GET['regenerate'] === 'true';
$editBooking = isset($_GET['edit']) && $_GET['edit'] === 'true';
$sessionId = $_GET['session_id'] ?? null;

$meetingId = null; // Initialize meetingId to null

$bookingRepo = new BookingRepository();
$regRepo = new RegistrationRepository();
$itemRepo = new ItemRepository();

// Handle Stripe session_id parameter (for payment success/cancel redirects)
if ($sessionId) {
    try {
        $stripeService = new StripeService();
        $session = $stripeService->retrieveCheckoutSession($sessionId);
        $bookingId = $session->metadata->booking_id ?? null;
        if ($bookingId) {
            // Redirect to the proper booking URL without session_id
            $redirectUrl = '/bookings/index.php?booking=' . urlencode($bookingId);
            if (isset($_GET['payment'])) {
                $redirectUrl .= '&payment=' . urlencode($_GET['payment']);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
    } catch (Exception $e) {
        error_log("Error retrieving Stripe session $sessionId: " . $e->getMessage());
        http_response_code(400);
        echo 'Invalid session ID';
        exit;
    }
}

if(!$bookingId && !$registrationId){
    http_response_code(400); echo 'Missing parameter'; exit;
}

if($registrationId){
    $validToken = env('DEBUG') === true || TokenService::check($registrationId, $token ?? '');
    if (!$validToken) {
        http_response_code(403); echo 'Invalid token'; exit;
    }
    $reg = $regRepo->find($registrationId);
    // Added check: if the record appears to be a booking (has Payment Status), then treat it as an invalid registration ID.
    if(!$reg || isset($reg['fields']['Payment Status'])){
        http_response_code(404); echo 'Registration not found or invalid ID provided.'; exit;
    }
    $meetingId = $reg['fields']['Meeting ID'] ?? null;
    $booking = $bookingRepo->findByRegistration($registrationId);
    if(!$booking){
        $booking = $bookingRepo->create($registrationId);
        $bookingId = $booking['id'] ?? null;
    } else {
        $bookingId = $booking['id'] ?? null;
    }
} else {
    $validToken = false; // No token validation for direct booking access
    $booking = $bookingRepo->find($bookingId);
    if(!$booking){ http_response_code(404); echo 'Booking not found'; exit; }
    $registrationId = $booking['fields']['Registration'][0] ?? null;
    $reg = $regRepo->find($registrationId);
}

if (
    !$editBooking &&
    (
        $regenerate ||
        ($booking['fields']['Status'] === 'Complete') ||
        (
            ($booking['fields']['Status'] ?? 'Pending') === 'Pending' &&
            ($booking['fields']['Payment Method'] ?? '') === 'Cash'
        )
    )
) {
    $bookedItemIds = $booking['fields']['Booked Items'] ?? [];
    $itemRecords = [];
    $airtableService = new AirtableService();
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX';
    foreach ($bookedItemIds as $bi) {
        $itemRecord = $airtableService->find($bookedItemsTable, $bi);
        if ($itemRecord) {
            $itemRecords[] = $itemRecord;
        }
    }
    $items = $itemRecords;

    $qrCodeDataUri = QrCodeService::generateDataUri($booking['id']);

    ob_start();
    include dirname(__DIR__, 2) . '/templates/booking-header.php';
    include dirname(__DIR__, 2) . '/templates/booking_complete.php';
    include dirname(__DIR__, 2) . '/templates/booking-footer.php';
    $html = ob_get_clean();

    $confirmationDir = dirname(__DIR__, 2) . '/storage/confirmations';
    if (!is_dir($confirmationDir)) {
        mkdir($confirmationDir, 0777, true);
    }
    $confirmationPath = $confirmationDir . '/' . $booking['id'] . '.pdf';

    if (!file_exists($confirmationPath) || $regenerate) {
        PdfService::generateConfirmation($html, $confirmationPath);

        $confirmationToken = TokenService::generate($booking['id']);
        $confirmationUrl = rtrim(env('APP_URL'), '/') . '/bookings/confirmation.php?booking=' . $booking['id'] . '&tok=' . $confirmationToken;
        $bookingRepo->update($booking['id'], ['Confirmation' => $confirmationUrl]);

        $userEmail = $reg['fields']['Email'] ?? null;
        $userName = ($reg['fields']['First Name'] ?? '') . ' ' . ($reg['fields']['Last Name'] ?? '');
        if ($userEmail) {
            EmailService::sendConfirmation($userEmail, $userName, $confirmationPath, $meetingId);
        }
    }

    echo $html;
    exit;
} else {
    // For Pending bookings, fetch already selected items for pre-population.
    $db = new AirtableService(); // Instantiate service once
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX';
    $existingBookedItems = $db->all($bookedItemsTable, [
        'filterByFormula' => sprintf("{Booking}='%s'", $bookingId),
        'fields' => ['Bookable Item ID']
    ]);
    // $selectedItems = array_map(fn($record) => $record['id'], $existingBookedItems);
    $selectedItems = array_map(fn($record) => $record['fields']['Bookable Item ID'] ?? '', $existingBookedItems);
    $selectedPayMethod = $booking['fields']['Payment Method'] ?? '';
    $items = $itemRepo->listForMeeting($meetingId, $reg['fields']['Role']);

    include dirname(__DIR__,2).'/templates/booking-header.php';
    include dirname(__DIR__,2).'/templates/booking_form.php';
    include dirname(__DIR__,2).'/templates/booking-footer.php';
    exit;
}
