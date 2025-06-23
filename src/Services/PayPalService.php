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
}
?>
