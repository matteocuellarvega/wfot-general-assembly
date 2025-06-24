<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../../src/bootstrap.php'; // Use bootstrap

use WFOT\Services\AirtableService; // Use the service

// Define allowed roles for member registration
const ALLOWED_MEMBER_ROLES = [
    'Delegate',
    '1st Alternate',
    '2nd Alternate',
    'Acting Delegate',
    'Acting 1st Alternate',
    'Acting 2nd Alternate',
    'Regional Group Representative'
];

error_log("Registration script started");

// --- Parameter Handling ---
$personId = isset($_GET['person'])
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['person'])
    : null;
$observerEmail = filter_input(INPUT_GET, 'observer', FILTER_VALIDATE_EMAIL) ?: null;
$meetingCode = isset($_GET['meeting'])
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['meeting'])
    : null;
$responseFormat = (isset($_GET['response']) && strcasecmp($_GET['response'], 'json') === 0)
    ? 'json'
    : 'html'; // Default to html

// --- Validation ---
if (empty($meetingCode)) {
    sendResponse(['error' => 'Missing required meeting parameter.'], $responseFormat, null, 400);
}
if (empty($personId) && empty($observerEmail)) {
    sendResponse(['error' => 'Missing required person or observer parameter.'], $responseFormat, null, 400);
}

// --- Meeting Configuration ---
$meetingConfig = getMeetingConfig($meetingCode);
if (!$meetingConfig) {
    sendResponse(['error' => 'This meeting is not accepting registrations at the moment.'], $responseFormat, null, 404);
}
$meetingId = $meetingConfig['id'];
$meetingText = $meetingConfig['text'];
$formBaseUrl = $meetingConfig['formBase'];

// --- Airtable Initialization ---
$airtableService = new AirtableService();
$isDebug = env('DEBUG') === true || strtolower(env('DEBUG')) === 'true'; // Check debug flag

// --- Helper Functions ---

/**
 * Sends response as JSON or HTML (redirect/message).
 *
 * @param array $data Data to send (or error message if key 'error' exists).
 * @param string $format 'json' or 'html'.
 * @param string|null $redirectUrl URL for HTML redirect.
 * @param int $statusCode HTTP status code.
 * @param bool $isDebug Debug mode flag.
 */
function sendResponse(array $data, string $format, ?string $redirectUrl = null, int $statusCode = 200, bool $isDebug = false)
{
    // Set CORS header to allow requests from delegates.wfot.org
    // In a production environment, consider making the allowed origin configurable (e.g., via env variable)
    // if more origins need access in the future.
    header("Access-Control-Allow-Origin: https://delegates.wfot.org");
    // Optionally add other CORS headers if needed (e.g., for different methods or headers)
    // header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    // header("Access-Control-Allow-Headers: Content-Type, Authorization");

    // Handle preflight OPTIONS requests (common with CORS)
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(204); // No Content for OPTIONS
        // Ensure allowed methods/headers are sent if specified above
        exit;
    }

    http_response_code($statusCode);
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode($data);
    } else {
        // HTML Response
        if ($redirectUrl && !$isDebug) { // Only redirect if not debugging
            header('Location: ' . $redirectUrl);
            echo "<h1>Redirecting</h1>";
            echo '<p>If you are not redirected automatically, please click the link: <a href="' . htmlspecialchars($redirectUrl) . '">' . htmlspecialchars($redirectUrl) . '</a></p>';
            echo '<script type="text/javascript">window.location = "' . $redirectUrl . '";</script>';
        } elseif ($redirectUrl && $isDebug) { // Show debug info instead of redirecting
             echo "<h1>Debug Mode: Redirect Prevented</h1>";
             echo '<p>Intended Redirect URL: <a href="' . htmlspecialchars($redirectUrl) . '">' . htmlspecialchars($redirectUrl) . '</a></p>';
             echo '<h2>Response Data:</h2><pre>' . htmlspecialchars(print_r($data, true)) . '</pre>';
        } elseif (isset($data['error'])) {
            echo "<h1>An error occurred</h1>";
            // Allow basic HTML in errors for links (e.g., mailto)
            $errorMessage = filter_var($data['error'], FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_FLAG_NO_ENCODE_QUOTES);
            if ($isDebug && isset($data['debug_details'])) {
                 $errorMessage .= '<br><br><strong>Debug Info:</strong><pre>' . htmlspecialchars($data['debug_details']) . '</pre>';
            }
            echo '<p>' . $errorMessage . '</p>';
        } else {
            // Generic success or info message if needed
             echo "<h1>Success</h1>";
             if (isset($data['message'])) {
                 echo '<p>' . htmlspecialchars($data['message']) . '</p>';
             } else {
                 echo '<p>Operation completed successfully.</p>';
             }
             if ($isDebug) {
                 echo '<h2>Response Data:</h2><pre>' . htmlspecialchars(print_r($data, true)) . '</pre>';
             }
        }
    }
    exit;
}

/**
 * Gets meeting configuration based on code.
 *
 * @param string $code Meeting code (e.g., 'gam2026').
 * @return array|null Configuration array or null if not found.
 */
function getMeetingConfig(string $code): ?array
{
    // In a real app, this might come from a config file or database
    if (strcasecmp($code, 'gam2026') === 0) {
        return [
            'id' => strtoupper($code),
            'text' => '37th WFOT Meeting - General Assembly',
            'formBase' => 'https://forms.wfot.org/m0TnbBEfRiY5NuJL0juS', // Base URL for the registration form
            'scopedView' => 'viw8qJdkirrHUGhOM', // Use the correct view ID for Automations view (scoped to current meeting)
        ];
    }
    return null;
}

/**
 * Fetches a member record by their ID.
 *
 * @param string $personId
 * @param AirtableService $airtableService
 * @return array|null Member record or null.
 */
function getMember(string $personId, AirtableService $airtableService): ?array
{
    $records = $airtableService->all('tblDDpToTMCxgrHBw', [
        'filterByFormula' => sprintf("{ID} = '%s'", $personId),
        'maxRecords' => 1
    ]);
    return $records[0] ?? null;
}

/**
 * Fetches the current (latest created) registration for a member within a specific view.
 *
 * @param string $memberRecordId Member's Airtable Record ID.
 * @param string $scopedViewId The Airtable View ID scoped to the specific meeting.
 * @param AirtableService $airtableService
 * @return array|null Registration record or null.
 */
function getMemberCurrentRegistration(string $memberRecordId, string $scopedViewId, AirtableService $airtableService): ?array
{
    // Filter by the Member's Record ID being present in the 'Members' linked field.
    // Note: FIND() works for checking if a string exists within another string (or array joined as string).
    $filterFormula = sprintf("FIND('%s', ARRAYJOIN({Members}))", $memberRecordId);

    $params = [
        "view" => $scopedViewId, // Use the view specific to the meeting
        "maxRecords" => 1, // We only need the latest one due to sorting
        "filterByFormula" => $filterFormula,
        // Sort by the 'Created' field (fldrIk3dFrDdzSCgB) descending to get the latest first
        "sort" => [['field' => 'Created', 'direction' => 'desc']]
    ];
    $records = $airtableService->all('tblxFb5zXR3ZKtaw9', $params); // Target the Registrations table

    // Return the first record found (the latest one due to sorting) or null
    return $records[0] ?? null;
}

/**
 * Fetches the current registration for an observer email and meeting.
 *
 * @param string $email Observer's email.
 * @param string $meetingId Meeting ID (e.g., 'GAM2026').
 * @param AirtableService $airtableService
 * @return array|null Registration record or null.
 */
function getObserverCurrentRegistration(string $email, string $meetingId, AirtableService $airtableService): ?array
{
     $filterFormula = sprintf("AND({Email} = '%s', {Meeting ID} = '%s')", $email, $meetingId);
     $params = [
        "maxRecords" => 1, // Fetch only one record
        "filterByFormula" => $filterFormula,
        "sort" => [['field' => 'Completed', 'direction' => 'desc'], ['field' => 'Created', 'direction' => 'desc']] // Prioritize completed, then latest
    ];
    $records = $airtableService->all("tblxFb5zXR3ZKtaw9", $params);
    return $records[0] ?? null;
}

/**
 * Creates a new registration record.
 *
 * @param array|null $member Member record (if applicable).
 * @param string|null $email Observer email (if applicable).
 * @param AirtableService $airtableService
 * @param string $meetingText
 * @param string $meetingId
 * @param bool $isDebug Debug mode flag.
 * @return string|null The new registration record ID or null on failure.
 */
function createRegistration(?array $member, ?string $email, AirtableService $airtableService, string $meetingText, string $meetingId, bool $isDebug = false): ?string
{
    $newRegistrationDetails = [
        'Meeting' => $meetingText,
        'Meeting ID' => $meetingId
    ];

    if ($member) {
        $newRegistrationDetails += [
            'First Name' => $member['fields']['First Name'] ?? null,
            'Last Name' => $member['fields']['Last Name'] ?? null,
            'Role' => $member['fields']['Role'] ?? null,
            'Organisation' => $member['fields']['Organisation'] ?? null,
            'Email' => $member['fields']['Email'] ?? null,
            'Members' => [$member['id']], // Link to the member record ID
        ];
    } elseif ($email) {
        $newRegistrationDetails += [
            'Email' => $email,
            'Role' => 'Observer',
            'Attending' => 'Yes'
        ];
    } else {
        return null; // Cannot create registration without member or email
    }

    error_log("Creating registration with details: " . print_r($newRegistrationDetails, true));

    try {
        $newRegistration = $airtableService->create("tblxFb5zXR3ZKtaw9", $newRegistrationDetails);
        return $newRegistration['id'] ?? null;
    } catch (\Exception $e) {
        // Log the error $e->getMessage()
        $logMessage = "Airtable registration creation failed: " . $e->getMessage();
        if ($isDebug) {
            $logMessage .= "\nData: " . print_r($newRegistrationDetails, true);
        }
        error_log($logMessage);
        return null;
    }
}

/**
 * Fetches booking details by its record ID.
 *
 * @param string $bookingId Airtable Record ID of the booking.
 * @param AirtableService $airtableService
 * @return array|null Booking record details or null.
 */
function getBooking(string $bookingId, AirtableService $airtableService): ?array
{
    $record = $airtableService->find('tblETcytPcj835rb0', $bookingId);
    if (!$record) {
        return null;
    }
    // Extract only the needed fields
    return [
        'id' => $record['id'],
        'status' => $record['fields']['Status'] ?? null,
        'paymentStatus' => $record['fields']['Payment Status'] ?? null,
        'paymentMethod' => $record['fields']['Payment Method'] ?? null,
        'confirmation' => $record['fields']['Booking Confirmation'] ?? null
    ];
}


/**
 * Checks if a member's role is allowed to register.
 *
 * @param array $member Member record.
 * @return bool True if role is allowed, false otherwise.
 */
function isMemberRoleAllowed(array $member): bool
{
    $memberRole = $member['fields']['Role'] ?? null;
    return in_array($memberRole, ALLOWED_MEMBER_ROLES, true);
}


// --- Main Logic ---

$responseData = ['registered' => false];
$redirectUrl = null;
$registrationRecord = null;
$memberRecord = null; // Store member record if found

// Pass $isDebug to sendResponse calls
try {
    if ($personId) {
        $memberRecord = getMember($personId, $airtableService);
        if (!$memberRecord) {
            sendResponse(['error' => 'Your record could not be found or has been deactivated. Please contact admin@wfot.org'], $responseFormat, null, 404, $isDebug);
        }
        
        // Check if member's role is allowed to register
        if (!isMemberRoleAllowed($memberRecord)) {
            sendResponse(['error' => 'Registration isn\'t available for your role. If you think this is incorrect, please email admin@wfot.org.'], $responseFormat, null, 403, $isDebug);
        }
        
        // Use the Member's Airtable Record ID and the meeting's scoped view for searching registrations
        $registrationRecord = getMemberCurrentRegistration($memberRecord['fields']['ID'], $meetingConfig['scopedView'], $airtableService);

        if (!$registrationRecord) {
            // No registration found for this member and meeting
            if ($responseFormat === 'html') {
                // Create a new registration and redirect to the form
                // Pass $isDebug to createRegistration
                $newRegistrationId = createRegistration($memberRecord, null, $airtableService, $meetingText, $meetingId, $isDebug);
                if ($newRegistrationId) {
                    $redirectUrl = $formBaseUrl . '/' . $newRegistrationId;
                    // Pass empty data for redirect, sendResponse handles debug output if needed
                    sendResponse([], $responseFormat, $redirectUrl, 200, $isDebug);
                } else {
                     sendResponse(['error' => 'Failed to create a new registration record. Please try again or contact admin@wfot.org.'], $responseFormat, null, 500, $isDebug);
                }
            } else {
                // For JSON, indicate not registered and provide the original link to trigger creation on next HTML visit
                 $responseData['url'] = 'https://general-assembly.wfot.org/registration?meeting=' . $meetingCode . '&person=' . $personId;
                 sendResponse($responseData, $responseFormat, null, 200, $isDebug);
            }
        }
        // Existing registration found
        $responseData['registered'] = true;

    } elseif ($observerEmail) {
        // Ensure getObserverCurrentRegistration also uses appropriate view/sort if needed,
        // currently it filters by email and meeting ID, sorts by Completed then Created.
        $registrationRecord = getObserverCurrentRegistration($observerEmail, $meetingId, $airtableService);

        if (!$registrationRecord) {
            // No registration found for this observer and meeting
            if ($responseFormat === 'html') {
                // Create a new registration and redirect to the form
                $newRegistrationId = createRegistration(null, $observerEmail, $airtableService, $meetingText, $meetingId, $isDebug);
                 if ($newRegistrationId) {
                    $redirectUrl = $formBaseUrl . '/' . $newRegistrationId;
                    sendResponse([], $responseFormat, $redirectUrl, 200, $isDebug); // Redirect immediately
                } else {
                     sendResponse(['error' => 'Failed to create a new observer registration record for ' . htmlspecialchars($observerEmail) . '. Please try again or contact admin@wfot.org.'], $responseFormat, null, 500, $isDebug);
                }
            } else {
                 // For JSON, indicate not registered and provide the original link to trigger creation on next HTML visit
                 $responseData['url'] = 'https://general-assembly.wfot.org/registration?meeting=' . $meetingCode . '&observer=' . urlencode($observerEmail);
                 sendResponse($responseData, $responseFormat, null, 200, $isDebug);
            }
        }
         // Existing registration found
        $responseData['registered'] = true;

        // Special check for observers in HTML mode: if already completed, show message instead of redirecting
        if ($responseFormat === 'html' && isset($registrationRecord['fields']['Completed']) && $registrationRecord['fields']['Completed'] == true) {
             sendResponse(['message' => 'You have already completed registration. Please check your email for a response from WFOT.'], $responseFormat, null, 200, $isDebug);
        }
    }

    // --- Process Found Registration (Common for Member & Observer) ---
    if ($registrationRecord) {
        // ... existing code to populate $responseData ...
        $registrationId = $registrationRecord['id'];
        $responseData['registration'] = [
            'id' => $registrationId,
            'attending' => $registrationRecord['fields']['Attending'] ?? null,
            'completed' => !empty($registrationRecord['fields']['Completed']), // Ensure boolean
            // Add any other relevant registration fields needed in the response
        ];
        $responseData['formUrl'] = $formBaseUrl . '/' . $registrationId;

        // Check for linked booking
        $bookingId = $registrationRecord['fields']['Bookings'][0] ?? null; // fldEaJZqFpN8aqFhC - Get first linked booking ID
        if ($bookingId) {
            $bookingDetails = getBooking($bookingId, $airtableService);
            if ($bookingDetails) {
                $responseData['booking'] = $bookingDetails;
            } else {
                 // Optionally log or add a note if booking fetch failed
                 error_log("Failed to fetch booking details for ID: " . $bookingId);
                 if ($isDebug) {
                     $responseData['booking_fetch_error'] = 'Failed to retrieve booking details for ID ' . $bookingId;
                 }
            }
        }


        if ($responseFormat === 'html') {
            // Redirect to the existing registration form (or show debug info)
            sendResponse($responseData, $responseFormat, $responseData['formUrl'], 200, $isDebug);
        } else {
            // Send JSON data
            sendResponse($responseData, $responseFormat, null, 200, $isDebug);
        }
    }

} catch (\Exception $e) {
    error_log("Registration script error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    $errorData = ['error' => 'An unexpected error occurred. Please contact admin@wfot.org.'];
    if ($isDebug) {
        $errorData['debug_details'] = $e->getMessage() . "\nTrace:\n" . $e->getTraceAsString();
    }
    sendResponse($errorData, $responseFormat, null, 500, $isDebug);
}

// Fallback if logic didn't exit via sendResponse (should not happen)
sendResponse(['error' => 'Invalid request state.'], $responseFormat, null, 400, $isDebug);

?>