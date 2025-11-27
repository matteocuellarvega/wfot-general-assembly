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

if (!$bookingId || !$token) {
    http_response_code(400);
    echo 'Missing parameters.';
    exit;
}

if (!TokenService::check($bookingId, $token)) {
    http_response_code(403);
    echo 'Invalid token.';
    exit;
}

$confirmationPath = dirname(__DIR__, 2) . '/storage/confirmations/' . basename($bookingId) . '.pdf';

$bookingRepo = new BookingRepository();
$booking = $bookingRepo->find($bookingId);

if (!$booking) {
    http_response_code(404);
    echo 'Booking not found.';
    exit;
}

$lastModified = ConfirmationCacheService::extractLastModified($booking);
$metadata = ConfirmationCacheService::loadMetadata($bookingId);
$graceSeconds = (int) (env('CONFIRMATION_GRACE_SECONDS', 5));

if (ConfirmationCacheService::requiresRefresh($metadata, $lastModified, $confirmationPath, $graceSeconds)) {
    regenerateConfirmation($booking, $bookingId, $confirmationPath, $lastModified);
}

if (!file_exists($confirmationPath)) {
    http_response_code(404);
    echo 'Confirmation not found.';
    exit;
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

    $qrCodeDataUri = QrCodeService::generateDataUri($bookingId);

    ob_start();
    include dirname(__DIR__, 2) . '/templates/booking-header.php';
    include dirname(__DIR__, 2) . '/templates/booking_complete.php';
    include dirname(__DIR__, 2) . '/templates/booking-footer.php';
    $html = ob_get_clean();

    PdfService::generateConfirmation($html, $confirmationPath);
    ConfirmationCacheService::storeMetadata($bookingId, $lastModified);
}