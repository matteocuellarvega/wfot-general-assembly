<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Repository\RegistrationRepository;
use WFOT\Repository\ItemRepository;
use WFOT\Services\TokenService;
use WFOT\Services\AirtableService; // Make sure this is included

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
    $validToken = env('DEBUG') || TokenService::check($registrationId, $token ?? '');
    if (!$validToken) {
        http_response_code(403); echo 'Invalid token'; exit;
    }
    $reg = $regRepo->find($registrationId);
    // Added check: if the record appears to be a booking (has Payment Status), then treat it as an invalid registration ID.
    if(!$reg || isset($reg['fields']['Payment Status'])){
        http_response_code(404); echo 'Registration not found or invalid ID provided.'; exit;
    }
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
    include dirname(__DIR__,2).'/templates/booking-header.php';
    include dirname(__DIR__,2).'/templates/booking_complete.php';
    // Pass $validToken to the template
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
    $items = $itemRepo->listForMeeting($reg['fields']['Meeting ID'], $reg['fields']['Role']);
}

include dirname(__DIR__,2).'/templates/booking-header.php';
include dirname(__DIR__,2).'/templates/booking_form.php';
include dirname(__DIR__,2).'/templates/booking-footer.php';
