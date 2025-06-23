<?php
/*
Plugin Name: WFOT Booking Link AJAX Handler
Description: Handles AJAX requests to generate booking links.
Version: 1.0
Author: WFOT
*/

/*
 * Handles AJAX requests to generate booking links.
 * It checks for the required parameters, validates the request,
 * and returns a JSON response with the booking link or an error message.
 */
add_action('wp_ajax_wfot_get_booking_link', 'wfot_get_booking_link');
add_action('wp_ajax_nopriv_wfot_get_booking_link', 'wfot_get_booking_link');

function wfot_get_booking_link() {
    error_log("wfot_get_booking_link triggered. Request method: " . $_SERVER['REQUEST_METHOD']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error(['error' => 'POST method required.'], 405);
        wp_die();
    }

    $registrationId = isset($_POST['registrationId']) ? sanitize_text_field($_POST['registrationId']) : '';
    error_log("wfot_get_booking_link: Received registrationId: " . $registrationId);
    if (empty($registrationId)) {
        wp_send_json_error(['error' => 'Missing registrationId parameter.'], 400);
        wp_die();
    }

    // Prepare the API endpoint URL (adjust the path if needed)
    $api_url = 'https://general-assembly.wfot.org/api/generate-booking-link.php';
    error_log("wfot_get_booking_link: API endpoint URL: " . $api_url);

    // Get the Bearer token from the environment
    $bearer_token = GENERAL_ASSEMBLY_API_KEY;
    if (!$bearer_token) {
        wp_send_json_error(['error' => 'Server configuration error. Missing API Bearer token.'], 500);
        wp_die();
    }
    error_log("wfot_get_booking_link: Bearer token retrieved successfully.");

    $args = [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $bearer_token,
        ],
        'body'    => json_encode(['registrationId' => $registrationId]),
        'timeout' => 15,
    ];
    error_log("wfot_get_booking_link: Sending request with args: " . print_r($args, true));

    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        error_log("wfot_get_booking_link: Error response: " . $response->get_error_message());
        wp_send_json_error(['error' => $response->get_error_message()], 500);
        wp_die();
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    error_log("wfot_get_booking_link: Received response code: " . $response_code);
    error_log("wfot_get_booking_link: Response body: " . $response_body);

    status_header($response_code);
    header('Content-Type: application/json');
    echo $response_body;
    wp_die();
}
?>