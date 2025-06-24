<?php
namespace WFOT\Services;

class PayPalService
{
    private $clientId;
    private $clientSecret;
    private $baseUrl;
    private $accessToken;
    
    public function __construct()
    {
        $this->clientId = env('PAYPAL_CLIENT_ID');
        $this->clientSecret = env('PAYPAL_CLIENT_SECRET');
        $this->baseUrl = env('APP_ENV') === 'production'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Get an access token from PayPal API
     * 
     * @return string Access token
     */
    public function getAccessToken()
    {
        // Return cached token if we already have one
        if ($this->accessToken) {
            return $this->accessToken;
        }
        
        // Get a new token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Accept-Language: en_US'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Failed to get PayPal access token: " . $response);
            throw new \Exception("Failed to authenticate with PayPal");
        }
        
        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'];
        
        return $this->accessToken;
    }

    /**
     * Create a PayPal order
     * 
     * @param float $amount Order amount
     * @param string $currency Currency code (default: USD)
     * @param string $bookingId Booking ID for reference
     * @return array Order details
     */
    public function createOrder($amount, $currency = 'USD', $bookingId = null)
    {
        $accessToken = $this->getAccessToken();
        
        // Validate amount - must be numeric and positive
        $amount = is_numeric($amount) ? (float)$amount : 0;
        if ($amount <= 0) {
            throw new \Exception("Invalid order amount: $amount");
        }
        
        // Validate currency - must be a valid 3-letter code
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD'; // Default to USD if invalid
        }
        
        // Validate bookingId - make sure it's a string and doesn't contain problematic characters
        if ($bookingId !== null) {
            $bookingId = preg_replace('/[^a-zA-Z0-9_-]/', '', $bookingId);
        }
        
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'description' => 'WFOT General Assembly Booking'
                ]
            ],
            'application_context' => [
                'brand_name' => 'WFOT General Assembly',
                'landing_page' => 'NO_PREFERENCE',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => env('APP_URL') . '/booking/success',
                'cancel_url' => env('APP_URL') . '/booking/cancel'
            ]
        ];
        
        // Add custom ID if provided
        if ($bookingId) {
            $payload['purchase_units'][0]['custom_id'] = $bookingId;
            
            // Instead of setting custom_id directly, let's use reference_id which has stricter validation
            // and make sure it's a simple string without prefixes
            $payload['purchase_units'][0]['reference_id'] = 'booking_' . $bookingId;
        }
        
        // Debug log the payload for troubleshooting
        error_log("PayPal createOrder payload: " . json_encode($payload));
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/v2/checkout/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 201) {
            error_log("Failed to create PayPal order: " . $response);
            throw new \Exception("Failed to create PayPal order: " . $this->extractErrorMessage($response));
        }
        
        return json_decode($response, true);
    }

    /**
     * Extract a user-friendly error message from PayPal API response
     * 
     * @param string $response JSON response from PayPal
     * @return string Formatted error message
     */
    private function extractErrorMessage($response) 
    {
        $data = json_decode($response, true);
        if (!$data) {
            return "Unknown error";
        }
        
        $message = $data['message'] ?? 'Unknown error';
        
        // If we have details, include the first one
        if (isset($data['details']) && is_array($data['details']) && !empty($data['details'])) {
            $detail = $data['details'][0];
            $field = $detail['field'] ?? '';
            $issue = $detail['issue'] ?? '';
            $description = $detail['description'] ?? '';
            
            if ($description) {
                $message .= ": $description";
            } elseif ($issue) {
                $message .= ": $issue";
                if ($field) {
                    $message .= " (field: $field)";
                }
            }
        }
        
        return $message;
    }

    /**
     * Capture a previously created order
     * 
     * @param string $orderId Order ID to capture
     * @return array Capture details
     */
    public function captureOrder($orderId)
    {
        $accessToken = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . "/v2/checkout/orders/{$orderId}/capture");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 201 && $httpCode !== 200) {
            error_log("Failed to capture PayPal order: " . $response);
            throw new \Exception("Failed to capture PayPal payment");
        }
        
        return json_decode($response, true);
    }

    /**
     * Verify the authenticity of a PayPal webhook
     *
     * @param string $webhookId PayPal Webhook ID
     * @param string $payload The raw payload received from PayPal
     * @param string $authAlgo Auth algorithm from headers
     * @param string $certUrl Certificate URL from headers
     * @param string $transmissionId Transmission ID from headers
     * @param string $transmissionSig Transmission signature from headers
     * @param string $transmissionTime Transmission time from headers
     * @return bool True if signature is verified
     */
    public function verifyWebhookSignature($webhookId, $payload, $authAlgo, $certUrl, $transmissionId, $transmissionSig, $transmissionTime)
    {
        $accessToken = $this->getAccessToken();
        
        $data = [
            'auth_algo' => $authAlgo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($payload, true)
        ];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/v1/notifications/verify-webhook-signature');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("PayPal webhook verification failed with HTTP code: $httpCode, response: $response");
                return false;
            }

            $result = json_decode($response, true);
            return isset($result['verification_status']) && $result['verification_status'] === 'SUCCESS';
        } catch (\Exception $e) {
            error_log("Error during PayPal webhook verification: " . $e->getMessage());
            return false;
        }
    }
}
?>
