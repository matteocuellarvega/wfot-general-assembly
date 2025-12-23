<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Services\AirtableService;
use WFOT\Services\ConfirmationCacheService;
use WFOT\Services\PdfService;
use WFOT\Services\QrCodeService;
use WFOT\Services\TokenService;

$bookingId = $_GET['booking'] ?? null;
$token = $_GET['tok'] ?? null;

function renderError($heading, $message, $code = 400) {
    global $meetingId;
    http_response_code($code);
    $errorHeading = $heading;
    $errorMessage = $message;
    include dirname(__DIR__, 2) . '/templates/booking-header.php';
    include dirname(__DIR__, 2) . '/templates/error.php';
    include dirname(__DIR__, 2) . '/templates/booking-footer.php';
    exit;
}

if (!$bookingId || !$token) {
    renderError('Missing parameters', 'Required parameters are missing from this request.', 400);
    exit;
}

if (!TokenService::check($bookingId, $token)) {
    renderError('Access Denied', 'This request was not properly authenticated.', 403);
    exit;
}

$confirmationPath = dirname(__DIR__, 2) . '/storage/confirmations/' . basename($bookingId) . '.pdf';

$bookingRepo = new BookingRepository();
$booking = $bookingRepo->find($bookingId);

if (!$booking) {
    renderError('Not Found', 'The booking could not be found.', 404);
}

$lastModified = ConfirmationCacheService::extractLastModified($booking);
$metadata = ConfirmationCacheService::loadMetadata($bookingId);
$graceSeconds = (int) (env('CONFIRMATION_GRACE_SECONDS', 5));

if (ConfirmationCacheService::requiresRefresh($metadata, $lastModified, $confirmationPath, $graceSeconds)) {
    regenerateConfirmation($booking, $bookingId, $confirmationPath, $lastModified);
}

if (!file_exists($confirmationPath)) {
    renderError('Confirmation not found', 'The booking confirmation could not be found.', 404);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="confirmation.pdf"');
header('Content-Length: ' . filesize($confirmationPath));
readfile($confirmationPath);
exit;

function regenerateConfirmation(array $booking, string $bookingId, string $confirmationPath, ?string $lastModified): void
{
    $airtableService = new AirtableService();
    $bookedItemsTable = 'tbluEJs6UHGhLbvJX';
    $bookedItemIds = $booking['fields']['Booked Items'] ?? [];
    $items = [];

    foreach ($bookedItemIds as $bookedItemId) {
        $record = $airtableService->find($bookedItemsTable, $bookedItemId);
        if ($record) {
            $items[] = $record;
        }
    }
    $qrCodePayload = json_encode([
        'bookingId' => $bookingId
    ]);
    $qrCodeDataUri = QrCodeService::generateDataUri($qrCodePayload);

    ob_start();
    include dirname(__DIR__, 2) . '/templates/booking-header.php';
    include dirname(__DIR__, 2) . '/templates/booking_complete.php';
    include dirname(__DIR__, 2) . '/templates/booking-footer.php';
    $html = ob_get_clean();

    PdfService::generateConfirmation($html, $confirmationPath);
    ConfirmationCacheService::storeMetadata($bookingId, $lastModified);
}