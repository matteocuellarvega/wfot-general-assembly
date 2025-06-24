<?php
namespace WFOT\Services;

// Import necessary classes from the new SDK
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Models\OrderIntent;
use PaypalServerSdkLib\Models\OrderRequestBuilder;
use PaypalServerSdkLib\Models\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

class PayPalService
{
    // Change client type hint
    private PaypalServerSdkClient $client;
    private OrdersController $ordersController;

    public function __construct()
    {
        // Use the new SDK's builder pattern for client initialization
        $clientId = env('PAYPAL_CLIENT_ID');
        $clientSecret = env('PAYPAL_CLIENT_SECRET');
        $environment = env('PAYPAL_MODE', 'sandbox') === 'live' ? Environment::PRODUCTION : Environment::SANDBOX;

        $this->client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    $clientId,
                    $clientSecret
                )
            )
            ->environment($environment)
            ->build();

        $this->ordersController = $this->client->getOrdersController();
    }

    public function createOrder(float $amount, string $bookingId): array
    {
        // Build the request using the new SDK's builders
        $orderRequest = OrderRequestBuilder::init(OrderIntent::CAPTURE)
            ->purchaseUnits([
                PurchaseUnitRequestBuilder::init()
                    ->referenceId($bookingId)
                    ->amount(
                        AmountWithBreakdownBuilder::init('USD', number_format($amount, 2, '.', ''))
                    )
                    ->build()
            ])
            ->build();

        // Prepare options array for the controller method
        $options = [
            'body' => $orderRequest,
            'prefer' => 'return=representation' // Optional: Get full representation back
        ];

        // Execute the request using the OrdersController
        $response = $this->ordersController->createOrder($options);

        // Extract the ID from the ApiResponse
        return ['id' => $response->getResult()->getId()];
    }

    public function captureOrder(string $orderId): array
    {
        // Prepare options array for the controller method
        $options = [
            'orderId' => $orderId,
            'prefer' => 'return=representation' // Optional: Get full representation back
        ];

        // Execute the request using the OrdersController
        $response = $this->ordersController->captureOrder($options);

        // Convert the result object to an array
        return json_decode(json_encode($response->getResult()), true);
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
            $uri = $this->baseUrl . '/v1/notifications/verify-webhook-signature';
            $accessToken = $this->getAccessToken();

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $uri);
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
