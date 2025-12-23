<?php
namespace WFOT\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Webhook;

class StripeService
{
    private $stripe;

    public function __construct()
    {
        $apiKey = env('STRIPE_SECRET_KEY');
        if (!$apiKey) {
            throw new \Exception("Stripe secret key not configured");
        }
        Stripe::setApiKey($apiKey);
        $this->stripe = new \Stripe\StripeClient($apiKey);
    }

    /**
     * Create a Stripe PaymentIntent
     *
     * @param float $amount Amount in cents (Stripe expects smallest currency unit)
     * @param string $currency Currency code (default: USD)
     * @param string $bookingId Booking ID for metadata
     * @return \Stripe\PaymentIntent PaymentIntent object
     */
    public function createPaymentIntent($amount, $currency = 'USD', $bookingId = null)
    {
        // Validate amount - must be numeric and positive
        $amount = is_numeric($amount) ? (float)$amount : 0;
        if ($amount <= 0) {
            throw new \Exception("Invalid payment amount: $amount");
        }

        // Convert to cents for Stripe (assuming USD or similar)
        $amountInCents = (int)round($amount * 100);

        // Validate currency
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        $params = [
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
            'payment_method_types' => ['card'],
            'metadata' => [
                'booking_id' => $bookingId ?? '',
            ],
            'description' => 'WFOT General Assembly Booking',
        ];

        try {
            $paymentIntent = PaymentIntent::create($params);
            error_log("Stripe PaymentIntent created: " . $paymentIntent->id . " for booking " . $bookingId);
            return $paymentIntent;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Failed to create Stripe PaymentIntent: " . $e->getMessage());
            throw new \Exception("Failed to create payment intent: " . $e->getMessage());
        }
    }

    /**
     * Confirm a PaymentIntent (equivalent to capturing)
     *
     * @param string $paymentIntentId PaymentIntent ID
     * @param string $paymentMethodId Optional payment method ID
     * @return \Stripe\PaymentIntent Confirmed PaymentIntent
     */
    public function confirmPaymentIntent($paymentIntentId, $paymentMethodId = null)
    {
        try {
            $params = [];
            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
            }

            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            $confirmedIntent = $paymentIntent->confirm($params);

            error_log("Stripe PaymentIntent confirmed: " . $confirmedIntent->id);
            return $confirmedIntent;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Failed to confirm Stripe PaymentIntent: " . $e->getMessage());
            throw new \Exception("Failed to confirm payment: " . $e->getMessage());
        }
    }

    /**
     * Retrieve a PaymentIntent
     *
     * @param string $paymentIntentId
     * @return \Stripe\PaymentIntent
     */
    public function retrievePaymentIntent($paymentIntentId)
    {
        try {
            return PaymentIntent::retrieve($paymentIntentId);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Failed to retrieve Stripe PaymentIntent: " . $e->getMessage());
            throw new \Exception("Failed to retrieve payment intent: " . $e->getMessage());
        }
    }

    /**
     * Verify and construct a Stripe webhook event
     *
     * @param string $payload Raw payload
     * @param string $sigHeader Stripe signature header
     * @param string $endpointSecret Webhook endpoint secret
     * @return \Stripe\Event
     */
    public function constructWebhookEvent($payload, $sigHeader, $endpointSecret)
    {
        try {
            return Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log("Stripe webhook signature verification failed: " . $e->getMessage());
            throw new \Exception("Webhook signature verification failed");
        } catch (\Exception $e) {
            error_log("Error constructing Stripe webhook event: " . $e->getMessage());
            throw new \Exception("Invalid webhook payload");
        }
    }

    /**
     * Create a Stripe Checkout Session with itemized line items
     *
     * @param array $items Array of items with 'name' and 'amount' keys
     * @param string $currency Currency code
     * @param string $bookingId Booking ID
     * @param string $successUrl URL to redirect on success
     * @param string $cancelUrl URL to redirect on cancel
     * @return \Stripe\Checkout\Session
     */
    public function createCheckoutSession($items, $currency = 'USD', $bookingId = null, $successUrl = null, $cancelUrl = null, $customerEmail = null)
    {
        // Handle backward compatibility - if $items is a float, convert to single item
        if (is_numeric($items)) {
            $items = [['name' => 'WFOT General Assembly Booking', 'amount' => (float)$items]];
        }

        if (!is_array($items) || empty($items)) {
            throw new \Exception("Invalid items array");
        }

        $lineItems = [];
        $totalAmount = 0;

        foreach ($items as $item) {
            if (!isset($item['name']) || !isset($item['amount'])) {
                throw new \Exception("Each item must have 'name' and 'amount' keys");
            }

            $amount = is_numeric($item['amount']) ? (float)$item['amount'] : 0;
            if ($amount < 0) {
                throw new \Exception("Invalid item amount: {$amount}");
            }

            $amountInCents = (int)round($amount * 100);
            $totalAmount += $amountInCents;

            $lineItems[] = [
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => $item['name'],
                    ],
                    'unit_amount' => $amountInCents,
                ],
                'quantity' => 1,
            ];
        }

        if ($totalAmount <= 0) {
            throw new \Exception("Invalid total payment amount: {$totalAmount}");
        }

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'metadata' => [
                'booking_id' => $bookingId ?? '',
            ],
            'customer_email' => $customerEmail ?? null,
            'success_url' => $successUrl ?? env('APP_URL') . '/booking/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl ?? env('APP_URL') . '/booking/cancel',
            'payment_intent_data' => [
                'description' => 'General Assembly - ' . $bookingId
            ]
        ];

        try {
            $session = \Stripe\Checkout\Session::create($params);
            error_log("Stripe Checkout Session created: " . $session->id . " for booking " . $bookingId);
            return $session;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Failed to create Stripe Checkout Session: " . $e->getMessage());
            throw new \Exception("Failed to create checkout session: " . $e->getMessage());
        }
    }

    /**
     * Retrieve a Checkout Session
     *
     * @param string $sessionId
     * @return \Stripe\Checkout\Session
     */
    public function retrieveCheckoutSession($sessionId)
    {
        try {
            return \Stripe\Checkout\Session::retrieve($sessionId);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Failed to retrieve Stripe Checkout Session: " . $e->getMessage());
            throw new \Exception("Failed to retrieve checkout session: " . $e->getMessage());
        }
    }
}
?>