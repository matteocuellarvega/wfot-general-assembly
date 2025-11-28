<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Repository\RegistrationRepository;
use WFOT\Services\AirtableService;

const BOOKED_ITEMS_TABLE = 'tbluEJs6UHGhLbvJX';
const CHECKINS_TABLE = 'tbluoEBBrpvvJnWak';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed.']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Missing Bearer token.']);
    exit;
}
$providedToken = trim(substr($authHeader, 7));
$expectedToken = env('API_BEARER_TOKEN');
if ($providedToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Invalid token.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$bookingId = sanitizeRecordId($payload['bookingId'] ?? null);
$registrationId = sanitizeRecordId($payload['registrationId'] ?? null);

if (!$bookingId && !$registrationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide either bookingId or registrationId.']);
    exit;
}

$bookingRepo = new BookingRepository();
$regRepo = new RegistrationRepository();
$airtable = new AirtableService();
$booking = null;

if ($bookingId) {
    $booking = $bookingRepo->find($bookingId);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found.']);
        exit;
    }
    $registrationId = $booking['fields']['Registration'][0] ?? null;
    if (!$registrationId) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking has no linked registration.']);
        exit;
    }
}

$registration = $registrationId ? $regRepo->find($registrationId) : null;
if (!$registration) {
    http_response_code(404);
    echo json_encode(['error' => 'Registration not found.']);
    exit;
}

if (!$booking) {
    $linkedBookingId = $registration['fields']['Bookings'][0] ?? null;
    if ($linkedBookingId) {
        $booking = $bookingRepo->find($linkedBookingId);
    }
}

$response = buildResponse($registration, $booking, $airtable);

echo json_encode($response);
exit;

function buildResponse(array $registration, ?array $booking, AirtableService $airtable): array
{
    $role = trim((string) ($registration['fields']['Role'] ?? ''));
    $isObserver = strcasecmp($role, 'Observer') === 0;
    $organisationField = $isObserver ? 'Observer Member Organisation' : 'Organisation';

    return [
        'ID' => getField($registration, 'ID', $registration['id']),
        'Title' => getField($registration, 'Title'),
        'First Name' => getField($registration, 'First Name'),
        'Last Name' => getField($registration, 'Last Name'),
        'Organisation' => getField($registration, $organisationField),
        'Role' => $role,
        'Photo' => getAttachmentUrl($registration, 'Photo', 'large'),
        'About You' => getField($registration, 'About You'),
        'Membership Type' => getField($registration, 'Membership Type'),
        'First Time as Delegate' => getField($registration, 'First Time as Delegate'),
        'Last Time as Delegate' => getField($registration, 'Last Time as Delegate'),
        'Mentoring' => getField($registration, 'Mentoring'),
        'Previous Attendance' => getField($registration, 'Previous Attendance'),
        'First Time Attendee' => getField($registration, 'First Time Attendee'),
        'Access Requirements' => getField($registration, 'Access Requirements (Details)'),
        'Booking' => buildBookingBlock($booking, $airtable),
        'Check-Ins' => fetchCheckins($registration['id'], $airtable),
    ];
}

function buildBookingBlock(?array $booking, AirtableService $airtable): ?array
{
    if (!$booking) {
        return null;
    }

    return [
        'Booking ID' => $booking['id'],
        'Created' => getField($booking, 'Created'),
        'Status' => getField($booking, 'Status'),
        'Payment Amount' => getField($booking, 'Payment Amount'),
        'Payment Status' => getField($booking, 'Payment Status'),
        'Payment Date' => getField($booking, 'Payment Date'),
        'Dietary Requirements' => getField($booking, 'Dietary Requirements'),
        'Items' => fetchBookedItems($booking, $airtable),
    ];
}

function fetchBookedItems(array $booking, AirtableService $airtable): array
{
    $items = [];
    $bookedItemIds = $booking['fields']['Booked Items'] ?? [];

    foreach ($bookedItemIds as $bookedItemId) {
        $record = $airtable->find(BOOKED_ITEMS_TABLE, $bookedItemId);
        if (!$record) {
            continue;
        }
        $items[] = [
            'Booked Item ID' => $record['id'],
            'Item' => getField($record, 'Item'),
            'Item Total' => getField($record, 'Item Total'),
            'Redeemed' => (bool) ($record['fields']['Redeemed'] ?? false),
        ];
    }

    return $items;
}

function fetchCheckins(string $registrationId, AirtableService $airtable): array
{
    $records = $airtable->all(CHECKINS_TABLE, [
        'filterByFormula' => sprintf("ARRAYJOIN({Registration})='%s'", $registrationId),
    ]);

    $checkins = [];
    foreach ($records as $record) {
        $checkins[] = [
            'Session' => getField($record, 'Session'),
            'Check In Date' => getField($record, 'Check In Date'),
            'Check In By' => getField($record, 'Check In By'),
        ];
    }

    return $checkins;
}

function getField(array $record, string $field, $default = '')
{
    if (!array_key_exists('fields', $record) || !array_key_exists($field, $record['fields'])) {
        return $default;
    }

    $value = $record['fields'][$field];

    if (is_array($value)) {
        $attachmentUrl = extractAttachmentUrl($value);
        if ($attachmentUrl !== null) {
            return $attachmentUrl;
        }
        $scalars = array_filter($value, fn($item) => is_scalar($item));
        if (!empty($scalars)) {
            return implode(', ', array_map('strval', $scalars));
        }
        return $default;
    }

    return $value;
}

function getAttachmentUrl(array $record, string $field, string $preferredSize = 'large'): string
{
    if (!isset($record['fields'][$field]) || !is_array($record['fields'][$field])) {
        return '';
    }
    $url = extractAttachmentUrl($record['fields'][$field], $preferredSize);
    return $url ?? '';
}

function extractAttachmentUrl(array $attachments, ?string $preferredSize = null): ?string
{
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        if ($preferredSize && isset($attachment['thumbnails'][$preferredSize]['url'])) {
            return $attachment['thumbnails'][$preferredSize]['url'];
        }
        if (isset($attachment['url'])) {
            return $attachment['url'];
        }
    }
    return null;
}

function sanitizeRecordId(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $clean = preg_replace('/[^a-zA-Z0-9]/', '', $value);
    return $clean ?: null;
}
