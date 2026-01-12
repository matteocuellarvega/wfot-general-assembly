<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\BookingRepository;
use WFOT\Repository\RegistrationRepository;
use WFOT\Services\AirtableService;

const BOOKED_ITEMS_TABLE = 'tbluEJs6UHGhLbvJX';
const CHECKINS_TABLE = 'tbluoEBBrpvvJnWak';
const MEMBER_ORGS_TABLE = 'tbli6ExwLjMLb3Hca';
const PING_HEADER = 'HTTP_X_WFOT_PING';
const CHECKIN_DETAIL_FIELDS = ['Session', 'Check In Date', 'Check In By', 'First Name', 'Last Name'];
const CHECKIN_LIST_FIELDS = ['Session', 'Check In Date', 'Check In By'];

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
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

if ($method === 'GET') {
    $pingValue = $_SERVER[PING_HEADER] ?? '';
    if ($pingValue === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing X-WFOT-Ping header.']);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'message' => 'Attendee API reachable',
        'ping' => $pingValue,
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $payload['action'] ?? 'getDetails';
$bookingId = sanitizeRecordId($payload['bookingId'] ?? null);
$registrationId = sanitizeRecordId($payload['registrationId'] ?? null);

$bookingRepo = new BookingRepository();
$regRepo = new RegistrationRepository();
$airtable = new AirtableService();

switch ($action) {
    case 'getDetails':
        handleGetDetails($bookingId, $registrationId, $bookingRepo, $regRepo, $airtable);
        break;
    case 'checkIn':
        handleCheckIn($payload, $regRepo, $airtable);
        break;
    case 'redeemItem':
        handleRedeemItem($payload, $bookingRepo, $regRepo, $airtable);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        exit;
}

exit;

function handleGetDetails(?string $bookingId, ?string $registrationId, BookingRepository $bookingRepo, RegistrationRepository $regRepo, AirtableService $airtable): void
{
    if (!$bookingId && !$registrationId) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide either bookingId or registrationId.']);
        exit;
    }

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
}

function handleCheckIn(array $payload, RegistrationRepository $regRepo, AirtableService $airtable): void
{
    $registrationId = sanitizeRecordId($payload['registrationId'] ?? null);
    $session = trim((string) ($payload['session'] ?? ''));
    $user = trim((string) ($payload['user'] ?? ''));

    if (!$registrationId || $session === '' || $user === '') {
        http_response_code(400);
        echo json_encode(['error' => 'registrationId, session, and user are required.']);
        exit;
    }

    $registration = $regRepo->find($registrationId);
    if (!$registration) {
        http_response_code(404);
        echo json_encode(['error' => 'Registration not found.']);
        exit;
    }

    $existingCheckins = $airtable->all(CHECKINS_TABLE, [
        'filterByFormula' => sprintf(
            "AND({Session}='%s', FIND('%s', ARRAYJOIN({Registrations}))>0)",
            addslashes($session),
            addslashes($registrationId)
        ),
        'maxRecords' => 1,
        'fields' => CHECKIN_DETAIL_FIELDS,
    ]);

    if (!empty($existingCheckins)) {
        $record = $existingCheckins[0];
        echo json_encode([
            'status' => 'already_checked_in',
            'check_in_id' => $record['id'] ?? null,
            'check_in_date' => getField($record, 'Check In Date'),
            'check_in_by' => getField($record, 'Check In By'),
            'attendee_name' => formatAttendeeName($record),
            'session' => getField($record, 'Session', $session),
        ]);
        return;
    }

    $fields = [
        'Session' => $session,
        'Check In Date' => gmdate('c'),
        'Registrations' => [$registrationId],
        'Check In By' => $user,
    ];

    try {
        $record = $airtable->create(CHECKINS_TABLE, $fields);
        echo json_encode([
            'status' => 'ok',
            'check_in_id' => $record['id'] ?? null,
            'attendee_name' => formatAttendeeName($record),
            'session' => getField($record, 'Session', $session),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to record check-in.']);
    }
}

function handleRedeemItem(array $payload, BookingRepository $bookingRepo, RegistrationRepository $regRepo, AirtableService $airtable): void
{
    $registrationId = sanitizeRecordId($payload['registrationId'] ?? null);
    $bookingId = sanitizeRecordId($payload['bookingId'] ?? null);
    $bookableItemId = trim((string) ($payload['bookableItemId'] ?? ''));
    $user = trim((string) ($payload['user'] ?? ''));

    error_log("Redeem Item called with bookingId: $bookingId, registrationId: $registrationId, bookableItemId: $bookableItemId, user: $user");

    if (!$bookableItemId || $user === '') {
        http_response_code(400);
        echo json_encode(['error' => 'bookableItemId and user are required.']);
        exit;
    }

    if (!$bookingId && !$registrationId) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide bookingId or registrationId.']);
        exit;
    }

    if (!$bookingId && $registrationId) {
        $registration = $regRepo->find($registrationId);
        if (!$registration) {
            http_response_code(404);
            echo json_encode(['error' => 'Registration not found.']);
            exit;
        }
        $bookingId = $registration['fields']['Bookings'][0] ?? null;
        if (!$bookingId) {
            http_response_code(404);
            echo json_encode(['error' => 'No booking linked to registration.']);
            exit;
        }
    }

    $booking = $bookingRepo->find($bookingId);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found.']);
        exit;
    }

    $filter = sprintf(
        "AND({Booking}='%s',{Bookable Item ID}='%s')",
        addslashes($bookingId),
        addslashes($bookableItemId)
    );
    $items = $airtable->all(BOOKED_ITEMS_TABLE, [
        'filterByFormula' => $filter,
        'maxRecords' => 1
    ]);

    if (empty($items)) {
        http_response_code(404);
        echo json_encode(['error' => 'Booked item not found.']);
        exit;
    }

    $item = $items[0];
    $alreadyRedeemed = !empty($item['fields']['Redeemed']);

    if ($alreadyRedeemed) {
        echo json_encode([
            'status' => 'already_redeemed',
            'booked_item_id' => $item['id'],
            'redeemed_by' => getField($item, 'Redeemed By'),
        ]);
        return;
    }

    try {
        $airtable->update(BOOKED_ITEMS_TABLE, $item['id'], [
            'Redeemed' => true,
            'Redeemed By' => $user,
        ]);
        echo json_encode(['status' => 'ok', 'booked_item_id' => $item['id']]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to redeem item.']);
    }
}

function buildResponse(array $registration, ?array $booking, AirtableService $airtable): array
{
    $role = trim((string) ($registration['fields']['Role'] ?? ''));
    $isObserver = strcasecmp($role, 'Observer') === 0;
    $organisation = resolveOrganisation($registration, $isObserver, $airtable);
    $photoUrl = getAttachmentUrl($registration, 'Photo', 'large');

    return [
        'ID' => getField($registration, 'ID', $registration['id']),
        'Title' => getField($registration, 'Title'),
        'First Name' => getField($registration, 'First Name'),
        'Last Name' => getField($registration, 'Last Name'),
        'Organisation' => $organisation,
        'Role' => $role,
        'Photo' => $photoUrl,
        'About You' => getField($registration, 'About You'),
        'Membership Type' => getField($registration, 'Membership Type'),
        'First Time as Delegate' => getField($registration, 'First Time as Delegate'),
        'Last Time as Delegate' => getField($registration, 'Last Time as Delegate'),
        'Mentoring' => getField($registration, 'Mentoring'),
        'Previous Attendance' => getField($registration, 'Previous Attendance'),
        'First Time Attendee' => getField($registration, 'First Time Attendee'),
        'Access Requirements' => getField(
            $registration,
            'Access Requirements (Details)',
            getField($registration, 'Access Requirements')
        ),
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
        'Payment Method' => getField($booking, 'Payment Method'),
        'Payment Amount' => getField($booking, 'Payment Amount'),
        'Payment Status' => getField($booking, 'Payment Status'),
        'Payment Date' => getField($booking, 'Payment Date'),
        'Confirmation' => getField($booking, 'Confirmation'),
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
        'filterByFormula' => sprintf("ARRAYJOIN({Registrations})='%s'", addslashes($registrationId)),
        'fields' => CHECKIN_LIST_FIELDS,
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

function formatAttendeeName(?array $record): string
{
    if (!$record) {
        return '';
    }

    $first = trim((string) getField($record, 'First Name'));
    $last = trim((string) getField($record, 'Last Name'));

    return trim($first . ' ' . $last);
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
        if (is_object($attachment)) {
            $attachment = (array) $attachment;
        }
        if (!is_array($attachment)) {
            continue;
        }
        if ($preferredSize && isset($attachment['thumbnails'])) {
            $thumbnails = $attachment['thumbnails'];
            if (is_object($thumbnails)) {
                $thumbnails = (array) $thumbnails;
            }
            if (is_array($thumbnails) && isset($thumbnails[$preferredSize])) {
                $thumb = $thumbnails[$preferredSize];
                if (is_object($thumb)) {
                    $thumb = (array) $thumb;
                }
                if (is_array($thumb) && isset($thumb['url'])) {
                    return $thumb['url'];
                }
            }
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

function resolveOrganisation(array $registration, bool $isObserver, AirtableService $airtable): string
{
    if ($isObserver) {
        $orgIds = $registration['fields']['Observer Member Organisation'] ?? [];
        if (is_array($orgIds) && !empty($orgIds)) {
            $names = [];
            foreach ($orgIds as $orgId) {
                $orgRecord = $airtable->find(MEMBER_ORGS_TABLE, $orgId);
                if ($orgRecord && !empty($orgRecord['fields']['Name'])) {
                    $names[] = $orgRecord['fields']['Name'];
                }
            }
            if (!empty($names)) {
                return implode(', ', $names);
            }
        }
    }

    return getField($registration, 'Organisation');
}
