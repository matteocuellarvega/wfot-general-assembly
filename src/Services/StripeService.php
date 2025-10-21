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
     * Get the Stripe client for advanced operations
     *
     * @return \Stripe\StripeClient
     */
    public function getClient()
    {
        return $this->stripe;
    }
}
?>