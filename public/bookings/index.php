<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Repository\RegistrationRepository;
use WFOT\Repository\ItemRepository;
use WFOT\Services\TokenService;
use WFOT\Services\AirtableService; // Make sure this is included
use WFOT\Services\QrCodeService;
use WFOT\Services\PdfService;
use WFOT\Services\EmailService;

$bookingId = $_GET['booking'] ?? null;
$registrationId = $_GET['registration'] ?? null;
$token = $_GET['tok'] ?? null;

$bookingRepo = new BookingRepository();
$regRepo = new RegistrationRepository();
$itemRepo = new ItemRepository();

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

if(($booking['fields']['Status'] ?? 'Pending') === 'Complete'){
    $bookedItemIds = $booking['fields']['Booked Items'] ?? []; // Use a different name to avoid confusion
    $itemRecords=[];
    $airtableService = new AirtableService(); // Instantiate service once
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX'; // Define table ID
    foreach($bookedItemIds as $bi){ // Loop through IDs
        // Correct the find call
        $itemRecord = $airtableService->find($bookedItemsTable, $bi);
        if ($itemRecord) { // Check if the item was found
            $itemRecords[] = $itemRecord;
        }
    }
    $items = $itemRecords; // Assign the fetched records to $items for the template

    $qrCodeDataUri = QrCodeService::generateDataUri($booking['id']);

    ob_start();
    include dirname(__DIR__,2).'/templates/booking-header.php';
    include dirname(__DIR__,2).'/templates/booking_complete.php';
    include dirname(__DIR__,2).'/templates/booking-footer.php';
    $html = ob_get_clean();

    $confirmationDir = dirname(__DIR__, 2) . '/storage/confirmations';
    if (!is_dir($confirmationDir)) {
        mkdir($confirmationDir, 0777, true);
    }
    $confirmationPath = $confirmationDir . '/' . $booking['id'] . '.pdf';

    if (!file_exists($confirmationPath)) {
        PdfService::generateConfirmation($html, $confirmationPath);

        // Generate a token for the public confirmation URL
        $confirmationToken = TokenService::generate($booking['id']);
        // Construct the public URL
        $confirmationUrl = rtrim(env('APP_URL'), '/') . '/bookings/confirmation.php?booking=' . $booking['id'] . '&tok=' . $confirmationToken;
        // Update the booking record in Airtable with the URL
        $bookingRepo->update($booking['id'], ['Confirmation' => $confirmationUrl]);

        // Send email with PDF attachment
        $userEmail = $reg['fields']['Email'] ?? null;
        $userName = ($reg['fields']['First Name'] ?? '') . ' ' . ($reg['fields']['Last Name'] ?? '');
        if ($userEmail) {
            EmailService::sendConfirmation($userEmail, $userName, $confirmationPath);
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
    $selectedItems = array_map(fn($record) => $record['id'], $existingBookedItems);
    $items = $itemRepo->listForMeeting($meetingId, $reg['fields']['Role']);
}

include dirname(__DIR__,2).'/templates/booking-header.php';
include dirname(__DIR__,2).'/templates/booking_form.php';
include dirname(__DIR__,2).'/templates/booking-footer.php';
